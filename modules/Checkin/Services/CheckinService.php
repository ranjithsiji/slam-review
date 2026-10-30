<?php

declare(strict_types=1);

namespace Modules\Checkin\Services;

use Modules\Attendees\Services\BadgeIdService;
use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;

/**
 * Check-in / check-out. Reviewer "login" = check-in.
 *
 * While the gates are open, check-in is self-service: the attendee scans the QR
 * on their badge (or types the ID), sees their name and masked mobile to verify,
 * and confirms. After Gate Close a volunteer (own slam) or admin can do a late
 * check-in, which creates an *unclaimed* check-in (no token) that the
 * attendee's phone claims on its first badge scan.
 *
 * A check-in is bound to one device by session_token_hash; a second device
 * trying the same badge is rejected and sent to the volunteer desk.
 */
class CheckinService
{
    public const GENERIC_FAILURE = 'Check-in failed. Check the ID on your badge, or visit the volunteer desk.';

    /** Phases in which volunteers and admins can late check-in someone (after Gate Close, before Slam Close). */
    public const LATE_PHASES = ['checkin_closed', 'reviewing', 'reviews_complete', 'revision', 'revision_closed'];

    /** 9847123210 → 98••••3210: enough for the attendee to recognise, little for anyone else. */
    public static function maskMobile(?string $m): string
    {
        $m = (string) $m;

        return strlen($m) >= 6 ? substr($m, 0, 2) . str_repeat('•', strlen($m) - 6) . substr($m, -4) : '••••';
    }

    /**
     * "Am I registered?" for the public: which current slams a mobile number is
     * registered for. Deliberately minimal: no name and no badge ID (the ID is
     * the check-in credential). Rate-limited per IP by the route filter.
     *
     * @return list<array{slam_name: string, opens_at: string, venue_name: string, user_type: string}>
     */
    public function registrationLookup(string $rawMobile): array
    {
        $mobile = \Modules\Attendees\Services\AttendeeService::normaliseMobile($rawMobile);
        if ($mobile === null) {
            throw new DomainException('validation', 'Enter a 10-digit Indian mobile number.', 422, ['mobile' => 'Enter a 10-digit mobile number.']);
        }
        $rows = Db::rows(
            "SELECT s.name AS slam_name, s.opens_at, s.venue_name, a.user_type FROM attendees a JOIN slams s ON s.id = a.slam_id
             WHERE a.mobile = ? AND a.is_active = 1 AND s.status NOT IN ('draft','ended') ORDER BY s.opens_at",
            [$mobile],
        );
        service('activityLog')->log(Actor::anonymous(), 'attendee.self_lookup', ['meta' => ['mobile_last4' => substr($mobile, -4), 'found' => count($rows)]]);

        return $rows;
    }

    /**
     * Step 1 of self check-in: validate the badge (throttled like a login) and
     * return what the attendee sees to verify it is them. Checks nothing in.
     *
     * @return array{badge_id: string, name: string, mobile: string, user_type: string, slam_name: string}
     */
    public function selfLookup(string $rawId, string $ip): array
    {
        $throttle = service('throttle');
        $anon     = Actor::anonymous();
        $badge    = BadgeIdService::fromScan($rawId) ?? BadgeIdService::normalise($rawId);
        $wait     = $throttle->lockedFor('reviewer', $ip, $badge);
        if ($wait > 0) {
            service('activityLog')->log($anon, 'checkin.rejected', ['outcome' => 'denied', 'reason' => 'locked_out']);
            throw new DomainException('locked_out', 'Too many attempts. Please wait ' . (int) ceil($wait / 60) . ' minutes or visit the volunteer desk.', 429);
        }
        $att = BadgeIdService::isValidFormat($badge) ? Db::row('SELECT * FROM attendees WHERE badge_id = ? AND is_active = 1', [$badge]) : null;
        if ($att === null) {
            $throttle->fail('reviewer', $ip, $badge, $anon, 'invalid_id');
            service('activityLog')->log($anon, 'checkin.rejected', ['outcome' => 'failure', 'reason' => 'invalid_id']);
            throw new DomainException('invalid_id', self::GENERIC_FAILURE, 401);
        }
        $slamId = (int) $att['slam_id'];
        service('slamPhase')->tick($slamId);
        $slam = service('slams')->find($slamId);
        // A late check-in from the desk can be claimed after Gate Close; otherwise the gate must be open.
        $open = Db::row('SELECT id, session_token_hash FROM checkins WHERE attendee_id = ? AND checked_out_at IS NULL', [$att['id']]);
        $late = false;
        if ($open === null && in_array($slam['status'], self::LATE_PHASES, true)) {
            $late = true; // gate closed, slam running: the phone waits for the volunteer (see requestLate)
        } elseif ($open === null) {
            try {
                $this->assertGateOpen($slam);
            } catch (DomainException $e) {
                service('activityLog')->log($this->attendeeActor($att), 'checkin.rejected', ['slam_id' => $slamId, 'target_type' => 'attendee', 'target_id' => (int) $att['id'], 'outcome' => 'denied', 'reason' => $e->reason]);
                throw $e;
            }
        }

        return [
            'badge_id' => $att['badge_id'], 'name' => $att['name'], 'mobile' => self::maskMobile($att['mobile']),
            'user_type' => $att['user_type'], 'slam_name' => $slam['name'], 'late' => $late,
        ];
    }

    private function attendeeActor(array $att): Actor
    {
        return new Actor($att['user_type'], (int) $att['id'], $att['badge_id'], (int) $att['slam_id']);
    }

    /**
     * Attendee's own check-in (QR landing page button or typed ID).
     *
     * @param array{checkin_id?: int, token?: string}|null $current existing reviewer session, if any
     * @return array{attendee: array<string,mixed>, slam: array<string,mixed>, checkin_id: int, token: string, resumed: bool}
     */
    public function selfCheckin(string $rawId, string $ip, ?array $current = null): array
    {
        $throttle = service('throttle');
        $anon     = Actor::anonymous();
        $badge    = BadgeIdService::fromScan($rawId) ?? BadgeIdService::normalise($rawId);

        $wait = $throttle->lockedFor('reviewer', $ip, $badge);
        if ($wait > 0) {
            service('activityLog')->log($anon, 'checkin.rejected', ['outcome' => 'denied', 'reason' => 'locked_out']);
            throw new DomainException('locked_out', 'Too many attempts. Please wait ' . (int) ceil($wait / 60) . ' minutes or visit the volunteer desk.', 429);
        }

        $att = BadgeIdService::isValidFormat($badge)
            ? Db::row('SELECT * FROM attendees WHERE badge_id = ? AND is_active = 1', [$badge])
            : null;
        if ($att === null) {
            $throttle->fail('reviewer', $ip, $badge, $anon, 'invalid_id');
            service('activityLog')->log($anon, 'checkin.rejected', ['outcome' => 'failure', 'reason' => 'invalid_id']);
            throw new DomainException('invalid_id', self::GENERIC_FAILURE, 401);
        }
        $actor  = $this->attendeeActor($att);
        $slamId = (int) $att['slam_id'];
        service('slamPhase')->tick($slamId);

        try {
            $result = Db::tx(function ($db) use ($att, $actor, $slamId, $current): array {
                $slam = Db::lockSlam($slamId);
                $open = Db::row('SELECT * FROM checkins WHERE attendee_id = ? AND checked_out_at IS NULL FOR UPDATE', [$att['id']]);
                $token = bin2hex(random_bytes(32));
                $hash  = hash('sha256', $token);

                if ($open !== null) {
                    // Same browser coming back: keep its session.
                    if ($current !== null && (int) ($current['checkin_id'] ?? 0) === (int) $open['id']
                        && $open['session_token_hash'] !== null && hash_equals($open['session_token_hash'], hash('sha256', (string) ($current['token'] ?? '')))) {
                        return ['checkin_id' => (int) $open['id'], 'token' => (string) $current['token'], 'resumed' => true, 'slam' => $slam];
                    }
                    if ($open['session_token_hash'] !== null) {
                        throw new DomainException('already_checked_in', 'This badge is already checked in on another phone. Please visit the volunteer desk.', 409);
                    }
                    if (in_array($slam['status'], ['closed', 'marks_calculated', 'ended'], true)) {
                        throw new DomainException('slam_closed', 'The slam has closed. Thank you for coming!', 403);
                    }
                    // Claim a check-in made at the desk (or a late-entry override).
                    $db->table('checkins')->where('id', $open['id'])->update(['session_token_hash' => $hash, 'updated_at' => Clock::db()]);
                    service('activityLog')->log($actor, 'auth.login.success', ['slam_id' => $slamId, 'target_type' => 'checkin', 'target_id' => (int) $open['id'], 'meta' => ['claimed' => true]]);

                    return ['checkin_id' => (int) $open['id'], 'token' => $token, 'resumed' => false, 'slam' => $slam];
                }

                $this->assertGateOpen($slam);
                $db->table('checkins')->insert([
                    'attendee_id' => $att['id'], 'checked_in_at' => Clock::db(), 'checked_in_by_type' => 'self',
                    'session_token_hash' => $hash, 'open_marker' => 1, 'created_at' => Clock::db(), 'updated_at' => Clock::db(),
                ]);
                $id = (int) $db->insertID();
                service('activityLog')->log($actor, 'checkin.created', ['slam_id' => $slamId, 'target_type' => 'attendee', 'target_id' => (int) $att['id'], 'meta' => ['by' => 'self', 'checkin_id' => $id]]);
                service('activityLog')->log($actor, 'auth.login.success', ['slam_id' => $slamId, 'target_type' => 'checkin', 'target_id' => $id]);

                return ['checkin_id' => $id, 'token' => $token, 'resumed' => false, 'slam' => $slam];
            });
        } catch (DomainException $e) {
            $action = $e->reason === 'already_checked_in' ? 'checkin.duplicate_rejected' : 'checkin.rejected';
            service('activityLog')->log($actor, $action, ['slam_id' => $slamId, 'target_type' => 'attendee', 'target_id' => (int) $att['id'], 'outcome' => 'denied', 'reason' => $e->reason]);
            if ($e->reason === 'already_checked_in') {
                service('activityLog')->log($actor, 'auth.session_rejected', ['slam_id' => $slamId, 'outcome' => 'denied', 'reason' => 'second_device']);
            }
            throw $e;
        }
        $throttle->record('reviewer', $ip, $badge, true);

        return $result + ['attendee' => $att];
    }

    // ------------------------------------------------------------------
    // After Gate Close: the phone waits, the volunteer checks the person in, the waiting phone moves on.
    // ------------------------------------------------------------------

    public const REQUEST_MINUTES = 20;   // a request lives this long
    public const SEEN_SECONDS    = 45;   // the desk lists it only while the phone is still on the waiting screen

    /**
     * The phone asks the desk to check this person in. Needs: gate closed, slam running, nobody checked in yet.
     *
     * @return array{id: int, token: string, code: string, attendee: array<string,mixed>, slam: array<string,mixed>}
     */
    public function requestLate(string $rawId, ?int $replaceRequestId = null): array
    {
        $badge = BadgeIdService::fromScan($rawId) ?? BadgeIdService::normalise($rawId);
        $att   = BadgeIdService::isValidFormat($badge) ? Db::row('SELECT * FROM attendees WHERE badge_id = ? AND is_active = 1', [$badge]) : null;
        if ($att === null) {
            throw new DomainException('invalid_id', self::GENERIC_FAILURE, 401);
        }
        service('slamPhase')->tick((int) $att['slam_id']);
        $slam = service('slams')->find((int) $att['slam_id']);
        if (! in_array($slam['status'], self::LATE_PHASES, true)) {
            $this->assertGateOpen($slam); // explains why not (too early / closed)
            throw new DomainException('self_checkin', 'The gates are open: scan your badge to check in.', 409);
        }
        if (Db::value('SELECT id FROM checkins WHERE attendee_id = ? AND checked_out_at IS NULL', [$att['id']]) !== null) {
            throw new DomainException('already_checked_in', 'This badge is already checked in.', 409);
        }
        $db    = db_connect();
        $token = bin2hex(random_bytes(32));
        $now   = Clock::db();
        if ($replaceRequestId !== null) {
            $db->table('late_checkin_requests')->where('id', $replaceRequestId)->where('attendee_id', $att['id'])->where('status', 'waiting')->update(['status' => 'cancelled']);
        }
        $db->table('late_checkin_requests')->insert([
            'slam_id' => $att['slam_id'], 'attendee_id' => $att['id'], 'token_hash' => hash('sha256', $token), 'code' => (string) random_int(100, 999),
            'status' => 'waiting', 'created_at' => $now, 'seen_at' => $now, 'expires_at' => Clock::addMinutes($now, self::REQUEST_MINUTES),
        ]);
        $id   = (int) $db->insertID();
        $code = (string) Db::value('SELECT code FROM late_checkin_requests WHERE id = ?', [$id]);
        service('activityLog')->log($this->attendeeActor($att), 'checkin.late_requested', ['slam_id' => (int) $att['slam_id'], 'target_type' => 'attendee', 'target_id' => (int) $att['id'], 'meta' => ['request_id' => $id]]);

        return ['id' => $id, 'token' => $token, 'code' => $code, 'attendee' => $att, 'slam' => $slam];
    }

    /**
     * The waiting phone asks what became of its request (also tells the desk it is still on screen).
     *
     * @return array{status: string, checkin_id?: int, attendee?: array<string,mixed>, slam?: array<string,mixed>}
     */
    public function waitingStatus(int $requestId, string $token): array
    {
        $r = Db::row('SELECT * FROM late_checkin_requests WHERE id = ?', [$requestId]);
        if ($r === null || ! hash_equals($r['token_hash'], hash('sha256', $token))) {
            return ['status' => 'unknown'];
        }
        $now = Clock::db();
        if ($r['status'] === 'waiting') {
            if (Clock::parse($r['expires_at']) <= Clock::parse($now)) {
                db_connect()->table('late_checkin_requests')->where('id', $requestId)->update(['status' => 'expired']);

                return ['status' => 'expired'];
            }
            db_connect()->table('late_checkin_requests')->where('id', $requestId)->update(['seen_at' => $now]);

            return ['status' => 'waiting'];
        }
        if ($r['status'] !== 'approved') {
            return ['status' => $r['status']];
        }
        $open = Db::row('SELECT id, session_token_hash FROM checkins WHERE id = ? AND checked_out_at IS NULL', [$r['checkin_id']]);
        if ($open === null || $open['session_token_hash'] === null || ! hash_equals($open['session_token_hash'], $r['token_hash'])) {
            return ['status' => 'cancelled']; // checked out again, or the check-in belongs to another phone
        }

        return [
            'status' => 'approved', 'checkin_id' => (int) $open['id'],
            'attendee' => Db::row('SELECT * FROM attendees WHERE id = ?', [$r['attendee_id']]), 'slam' => service('slams')->find((int) $r['slam_id']),
        ];
    }

    /**
     * Phones waiting for the desk of a slam (still on screen, not expired).
     *
     * @return list<array<string,mixed>>
     */
    public function waitingList(int $slamId): array
    {
        try {
            return $this->waitingListRows($slamId);
        } catch (\CodeIgniter\Database\Exceptions\DatabaseException $e) {
            log_message('error', 'late_checkin_requests unavailable (run the migrations and the grants): ' . $e->getMessage());

            return []; // the desk keeps working without the waiting list
        }
    }

    private function waitingListRows(int $slamId): array
    {
        $now = Clock::db();
        $rows = Db::rows(
            "SELECT r.id, r.code, r.created_at, a.id AS attendee_id, a.name, a.badge_id, a.mobile, a.user_type
               FROM late_checkin_requests r JOIN attendees a ON a.id = r.attendee_id
              WHERE r.slam_id = ? AND r.status = 'waiting' AND r.expires_at > ? AND r.seen_at >= ?
                AND NOT EXISTS (SELECT 1 FROM checkins c WHERE c.attendee_id = a.id AND c.checked_out_at IS NULL AND c.session_token_hash IS NOT NULL)
              ORDER BY r.created_at",
            [$slamId, $now, Clock::addSeconds($now, -self::SEEN_SECONDS)],
        );

        return array_map(static fn ($r) => [
            'id' => (int) $r['id'], 'code' => $r['code'], 'attendee_id' => (int) $r['attendee_id'], 'name' => $r['name'], 'badge_id' => $r['badge_id'],
            'mobile' => self::maskMobile($r['mobile']), 'user_type' => $r['user_type'], 'waiting_since' => Clock::iso($r['created_at']),
        ], $rows);
    }

    /** Waiting requests of one attendee (usually one). */
    private function waitingFor(int $attendeeId): array
    {
        $now = Clock::db();

        return Db::rows("SELECT id, code FROM late_checkin_requests WHERE attendee_id = ? AND status = 'waiting' AND expires_at > ? AND seen_at >= ? ORDER BY id", [$attendeeId, $now, Clock::addSeconds($now, -self::SEEN_SECONDS)]);
    }

    /**
     * The volunteer (or admin) picks a waiting phone from the desk list: the person is checked in and exactly that phone
     * gets the review screen. Works whether or not the person was already checked in at the desk without a phone.
     *
     * @return array{checkin_id: int, attendee: array<string,mixed>, code: string}
     */
    public function approveRequest(int $requestId, Actor $actor): array
    {
        $r = Db::row('SELECT * FROM late_checkin_requests WHERE id = ?', [$requestId]) ?? throw new DomainException('not_found', 'That request is gone.', 404);
        $att = Db::row('SELECT * FROM attendees WHERE id = ?', [$r['attendee_id']]);
        $this->assertScope($att, $actor);
        if ($r['status'] !== 'waiting' || Clock::parse($r['expires_at']) <= Clock::parse(Clock::db())) {
            throw new DomainException('request_closed', 'That phone is no longer waiting. Ask the person to scan their badge again.', 409);
        }
        $open = Db::row('SELECT id, session_token_hash FROM checkins WHERE attendee_id = ? AND checked_out_at IS NULL', [$att['id']]);
        if ($open !== null && $open['session_token_hash'] !== null) {
            throw new DomainException('already_checked_in', 'This person is already checked in on a phone.', 409);
        }
        $checkinId = $open !== null ? (int) $open['id'] : (int) $this->lateCheckin((int) $att['id'], null, $actor, false)['checkin_id'];
        $this->bind($r, $checkinId, $actor);

        return ['checkin_id' => $checkinId, 'attendee' => $att, 'code' => $r['code']];
    }

    /** Bind a waiting phone to a check-in and close the person's other waiting requests. */
    private function bind(array $request, int $checkinId, Actor $actor): void
    {
        $db  = db_connect();
        $now = Clock::db();
        $db->table('checkins')->where('id', $checkinId)->where('session_token_hash IS NULL')->update(['session_token_hash' => $request['token_hash'], 'updated_at' => $now]);
        $db->table('late_checkin_requests')->where('id', $request['id'])->update([
            'status' => 'approved', 'checkin_id' => $checkinId, 'approved_by_type' => $actor->type === 'volunteer' ? 'volunteer' : 'admin', 'approved_by' => $actor->id, 'approved_at' => $now,
        ]);
        $db->table('late_checkin_requests')->where('attendee_id', $request['attendee_id'])->where('status', 'waiting')->where('id <>', $request['id'])->update(['status' => 'cancelled']);
        service('activityLog')->log($actor, 'checkin.phone_linked', ['slam_id' => (int) $request['slam_id'], 'target_type' => 'attendee', 'target_id' => (int) $request['attendee_id'], 'meta' => ['request_id' => (int) $request['id'], 'code' => $request['code'], 'checkin_id' => $checkinId]]);
    }

    /** Normal check-in is only allowed while the gate is open; explain why not otherwise. */
    public function assertGateOpen(array $slam): void
    {
        $s = $slam['status'];
        if ($s === 'checkin_open') {
            return;
        }
        if (in_array($s, ['draft', 'scheduled', 'open'], true)) {
            $at = $slam['checkin_opens_at'];
            throw new DomainException('gate_not_open', $at !== null
                ? 'Check-in is not open yet. It opens at ' . Clock::local($at, 'H:i') . ' on ' . Clock::local($at, 'd M') . '.'
                : 'Check-in is not open yet. Please wait for the organisers to open the gate.', 403, ['opens_at' => Clock::iso($at)]);
        }
        if (in_array($s, ['closed', 'marks_calculated', 'ended'], true)) {
            throw new DomainException('slam_closed', 'The slam has closed. Thank you for coming!', 403);
        }
        throw new DomainException('gate_closed', 'Check-in has closed for this slam. Please visit the volunteer desk.', 403);
    }

    /**
     * Late check-in by a volunteer (own slam) or admin after Gate Close, for an
     * already-registered attendee. Unclaimed until the attendee's phone scans
     * the badge. While the gates are open attendees check themselves in.
     */
    public function lateCheckin(int $attendeeId, ?string $reason, Actor $actor, bool $linkPhone = true): array
    {
        if (! in_array($actor->type, ['volunteer', 'admin'], true)) {
            throw new DomainException('forbidden', 'Only volunteers and admins can do a late check-in.', 403);
        }
        $att = Db::row('SELECT * FROM attendees WHERE id = ?', [$attendeeId]) ?? throw new DomainException('not_registered', 'Not registered.', 404);
        $this->assertScope($att, $actor);
        if ((int) $att['is_active'] !== 1) {
            throw new DomainException('not_registered', 'This registration is inactive. Please contact the organisers.', 409);
        }
        $reason = trim((string) $reason) !== '' ? mb_substr(trim((string) $reason), 0, 255) : 'Arrived after Gate Close';
        service('slamPhase')->tick((int) $att['slam_id']);
        try {
            $made = Db::tx(function ($db) use ($att, $actor, $reason): array {
                $slam = Db::lockSlam((int) $att['slam_id']);
                if ($slam['status'] === 'checkin_open') {
                    throw new DomainException('self_checkin', 'The gates are open: attendees check themselves in by scanning the QR code on their badge.', 409);
                }
                if (! in_array($slam['status'], self::LATE_PHASES, true)) {
                    in_array($slam['status'], ['draft', 'scheduled', 'open'], true)
                        ? $this->assertGateOpen($slam)
                        : throw new DomainException('slam_closed', 'The slam has closed.', 409);
                }
                if (Db::row('SELECT id FROM checkins WHERE attendee_id = ? AND checked_out_at IS NULL FOR UPDATE', [$att['id']]) !== null) {
                    throw new DomainException('already_checked_in', 'Already checked in.', 409);
                }
                $db->table('checkins')->insert([
                    'attendee_id' => $att['id'], 'checked_in_at' => Clock::db(), 'checked_in_by_type' => $actor->type, 'checked_in_by' => $actor->id,
                    'is_late_override' => 1, 'open_marker' => 1, 'created_at' => Clock::db(), 'updated_at' => Clock::db(),
                ]);
                $id = (int) $db->insertID();
                service('activityLog')->log($actor, 'checkin.late_override', [
                    'slam_id' => (int) $att['slam_id'], 'target_type' => 'attendee', 'target_id' => (int) $att['id'], 'reason' => $reason,
                    'meta' => ['checkin_id' => $id, 'by' => $actor->type],
                ]);

                return ['checkin_id' => $id, 'attendee' => $att];
            });
        } catch (DomainException $e) {
            service('activityLog')->log($actor, $e->reason === 'already_checked_in' ? 'checkin.duplicate_rejected' : 'checkin.rejected', [
                'slam_id' => (int) $att['slam_id'], 'target_type' => 'attendee', 'target_id' => (int) $att['id'], 'outcome' => 'denied', 'reason' => $e->reason,
            ]);
            throw $e;
        }
        $phone = ['bound' => false, 'code' => null, 'choices' => []];
        $waiting = $linkPhone ? $this->waitingFor((int) $att['id']) : [];
        if (count($waiting) === 1) {
            $this->bind(Db::row('SELECT * FROM late_checkin_requests WHERE id = ?', [$waiting[0]['id']]), (int) $made['checkin_id'], $actor);
            $phone = ['bound' => true, 'code' => $waiting[0]['code'], 'choices' => []];
        } elseif (count($waiting) > 1) {
            $phone['choices'] = array_map(static fn ($w) => ['id' => (int) $w['id'], 'code' => $w['code']], $waiting); // the desk picks by code
        }

        return $made + ['phone' => $phone];
    }

    /**
     * Close an attendee's open check-in.
     * $type: manual (attendee themself) | forced (volunteer/admin, reason required).
     */
    public function checkout(int $attendeeId, string $type, Actor $actor, ?string $reason = null): void
    {
        $att = Db::row('SELECT * FROM attendees WHERE id = ?', [$attendeeId]) ?? throw new DomainException('not_found', 'Attendee not found.', 404);
        if ($type === 'forced') {
            $this->assertScope($att, $actor);
            if ($reason === null || trim($reason) === '') {
                throw new DomainException('reason_required', 'A reason is required for a forced check-out.');
            }
            $slam = service('slams')->find((int) $att['slam_id']);
            if ($actor->isVolunteer() && in_array($slam['status'], ['closed', 'marks_calculated', 'ended'], true)) {
                throw new DomainException('read_only', 'The slam is closed; the volunteer panel is read-only.', 403);
            }
        }
        Db::tx(function ($db) use ($att, $type, $actor, $reason): void {
            $open = Db::row('SELECT * FROM checkins WHERE attendee_id = ? AND checked_out_at IS NULL FOR UPDATE', [$att['id']]);
            if ($open === null) {
                throw new DomainException('not_checked_in', 'Not checked in.', 409);
            }
            $db->table('checkins')->where('id', $open['id'])->update([
                'checked_out_at' => Clock::db(), 'open_marker' => null, 'checkout_type' => $type,
                'checked_out_by_type' => $type === 'manual' ? 'self' : $actor->type, 'checked_out_by' => $type === 'manual' ? null : $actor->id,
                'checkout_reason' => $reason !== null ? mb_substr($reason, 0, 255) : null, 'updated_at' => Clock::db(),
            ]);
            service('activityLog')->log($actor, $type === 'manual' ? 'checkout.manual' : 'checkout.forced', [
                'slam_id' => (int) $att['slam_id'], 'target_type' => 'attendee', 'target_id' => (int) $att['id'], 'reason' => $reason,
                'meta' => ['checkin_id' => (int) $open['id']],
            ]);
        });
    }

    /** End any open check-in (ID reissued, attendee deactivated). Caller may be inside a tx. */
    public function endSessionsFor(int $attendeeId, Actor $actor, string $reason): void
    {
        $open = Db::row('SELECT * FROM checkins WHERE attendee_id = ? AND checked_out_at IS NULL', [$attendeeId]);
        if ($open === null) {
            return;
        }
        db_connect()->table('checkins')->where('id', $open['id'])->update([
            'checked_out_at' => Clock::db(), 'open_marker' => null, 'checkout_type' => 'forced',
            'checked_out_by_type' => $actor->isAdmin() ? 'admin' : 'system', 'checked_out_by' => $actor->id, 'checkout_reason' => $reason, 'updated_at' => Clock::db(),
        ]);
        $slamId = (int) Db::value('SELECT slam_id FROM attendees WHERE id = ?', [$attendeeId]);
        service('activityLog')->log($actor, 'checkout.forced', ['slam_id' => $slamId, 'target_type' => 'attendee', 'target_id' => $attendeeId, 'reason' => $reason]);
    }

    /** Slam Close: everyone still checked in is checked out. Caller holds the lock. */
    public function autoCheckoutAll(int $slamId, Actor $actor): int
    {
        $open = Db::rows('SELECT c.id, c.attendee_id FROM checkins c JOIN attendees a ON a.id = c.attendee_id WHERE a.slam_id = ? AND c.checked_out_at IS NULL', [$slamId]);
        foreach ($open as $c) {
            db_connect()->table('checkins')->where('id', $c['id'])->update([
                'checked_out_at' => Clock::db(), 'open_marker' => null, 'checkout_type' => 'auto', 'checked_out_by_type' => 'system', 'updated_at' => Clock::db(),
            ]);
            service('activityLog')->log($actor, 'checkout.auto', ['slam_id' => $slamId, 'target_type' => 'attendee', 'target_id' => (int) $c['attendee_id']]);
        }

        return count($open);
    }

    /**
     * Validate a reviewer session on every request: the check-in must still be
     * open, bound to this session's token, and the attendee active with the same ID.
     *
     * @param array<string, mixed> $s session 'reviewer' payload
     * @return array{attendee: array<string,mixed>, checkin: array<string,mixed>}|null
     */
    public function validateSession(array $s): ?array
    {
        $row = Db::row(
            'SELECT c.*, a.id AS a_id, a.slam_id, a.badge_id, a.name, a.user_type, a.is_active
             FROM checkins c JOIN attendees a ON a.id = c.attendee_id WHERE c.id = ?',
            [(int) ($s['checkin_id'] ?? 0)],
        );
        if ($row === null || $row['checked_out_at'] !== null || (int) $row['is_active'] !== 1
            || $row['badge_id'] !== ($s['badge_id'] ?? null) || $row['session_token_hash'] === null
            || ! hash_equals($row['session_token_hash'], hash('sha256', (string) ($s['token'] ?? '')))) {
            return null;
        }

        return [
            'attendee' => ['id' => (int) $row['a_id'], 'slam_id' => (int) $row['slam_id'], 'badge_id' => $row['badge_id'], 'name' => $row['name'], 'user_type' => $row['user_type']],
            'checkin'  => $row,
        ];
    }

    /** Current check-in status of an attendee (for lookup screens). */
    public function status(int $attendeeId): array
    {
        $open = Db::row('SELECT * FROM checkins WHERE attendee_id = ? AND checked_out_at IS NULL', [$attendeeId]);
        $last = $open ?? Db::row('SELECT * FROM checkins WHERE attendee_id = ? ORDER BY id DESC LIMIT 1', [$attendeeId]);

        return [
            'checked_in'     => $open !== null,
            'claimed'        => $open !== null && $open['session_token_hash'] !== null,
            'checked_in_at'  => Clock::iso($last['checked_in_at'] ?? null),
            'checked_out_at' => Clock::iso($last['checked_out_at'] ?? null),
            'checkout_type'  => $last['checkout_type'] ?? null,
            'state'          => $open !== null ? 'checked_in' : ($last === null ? 'never' : ($last['checkout_type'] === 'forced' ? 'forced_out' : 'checked_out')),
        ];
    }

    /** Volunteers only act on their own slam; enforced here as well as in the filter. */
    public function assertScope(array $attendee, Actor $actor): void
    {
        if ($actor->isAdmin()) {
            return;
        }
        if (! $actor->isVolunteer() || (int) $attendee['slam_id'] !== (int) $actor->slamId) {
            throw new DomainException('forbidden', 'Not allowed for this slam.', 403);
        }
    }
}
