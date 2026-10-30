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
 * A check-in is bound to one device by session_token_hash. A volunteer/admin
 * check-in (or late-entry override) creates an *unclaimed* check-in (no token);
 * the attendee's own phone claims it on its first badge scan. A second device
 * trying the same badge is rejected and sent to the volunteer desk.
 */
class CheckinService
{
    public const GENERIC_FAILURE = 'Check-in failed. Check the ID on your badge, or visit the volunteer desk.';

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
                ? 'Check-in is not open yet. It opens at ' . Clock::ist($at, 'H:i') . ' on ' . Clock::ist($at, 'd M') . '.'
                : 'Check-in is not open yet. Please wait for the organisers to open the gate.', 403, ['opens_at' => Clock::iso($at)]);
        }
        if (in_array($s, ['closed', 'marks_calculated', 'ended'], true)) {
            throw new DomainException('slam_closed', 'The slam has closed. Thank you for coming!', 403);
        }
        throw new DomainException('gate_closed', 'Check-in has closed for this slam. Please visit the volunteer desk.', 403);
    }

    /** Check-in by a volunteer (own slam) or admin from the desk: unclaimed until the phone scans. */
    public function deskCheckin(int $attendeeId, Actor $actor): array
    {
        $att = Db::row('SELECT * FROM attendees WHERE id = ?', [$attendeeId]) ?? throw new DomainException('not_registered', 'Not registered.', 404);
        $this->assertScope($att, $actor);
        if ((int) $att['is_active'] !== 1) {
            throw new DomainException('not_registered', 'This registration is inactive. Please contact the organisers.', 409);
        }
        service('slamPhase')->tick((int) $att['slam_id']);
        try {
            return Db::tx(function ($db) use ($att, $actor): array {
                $slam = Db::lockSlam((int) $att['slam_id']);
                if (Db::row('SELECT id FROM checkins WHERE attendee_id = ? AND checked_out_at IS NULL FOR UPDATE', [$att['id']]) !== null) {
                    throw new DomainException('already_checked_in', 'Already checked in.', 409);
                }
                $this->assertGateOpen($slam);
                $db->table('checkins')->insert([
                    'attendee_id' => $att['id'], 'checked_in_at' => Clock::db(), 'checked_in_by_type' => $actor->type,
                    'checked_in_by' => $actor->id, 'open_marker' => 1, 'created_at' => Clock::db(), 'updated_at' => Clock::db(),
                ]);
                $id = (int) $db->insertID();
                service('activityLog')->log($actor, 'checkin.created', ['slam_id' => (int) $att['slam_id'], 'target_type' => 'attendee', 'target_id' => (int) $att['id'], 'meta' => ['by' => $actor->type, 'checkin_id' => $id]]);

                return ['checkin_id' => $id, 'attendee' => $att];
            });
        } catch (DomainException $e) {
            service('activityLog')->log($actor, $e->reason === 'already_checked_in' ? 'checkin.duplicate_rejected' : 'checkin.rejected', [
                'slam_id' => (int) $att['slam_id'], 'target_type' => 'attendee', 'target_id' => (int) $att['id'], 'outcome' => 'denied', 'reason' => $e->reason,
            ]);
            throw $e;
        }
    }

    /** Admin-only late entry after Gate Close for an already-registered attendee. */
    public function lateOverride(int $attendeeId, string $reason, Actor $actor): array
    {
        if (! $actor->isAdmin()) {
            throw new DomainException('forbidden', 'Only an admin can allow a late entry.', 403);
        }
        if (trim($reason) === '') {
            throw new DomainException('reason_required', 'A reason is required for a late entry.');
        }
        $att = Db::row('SELECT * FROM attendees WHERE id = ? AND is_active = 1', [$attendeeId]) ?? throw new DomainException('not_registered', 'Not registered.', 404);

        return Db::tx(function ($db) use ($att, $reason, $actor): array {
            $slam = Db::lockSlam((int) $att['slam_id']);
            if (! in_array($slam['status'], ['checkin_open', 'checkin_closed', 'reviewing', 'reviews_complete', 'revision', 'revision_closed'], true)) {
                throw new DomainException('not_allowed', 'Late entry is only possible between Gate Close and Slam Close.', 409);
            }
            if (Db::row('SELECT id FROM checkins WHERE attendee_id = ? AND checked_out_at IS NULL FOR UPDATE', [$att['id']]) !== null) {
                throw new DomainException('already_checked_in', 'Already checked in.', 409);
            }
            $db->table('checkins')->insert([
                'attendee_id' => $att['id'], 'checked_in_at' => Clock::db(), 'checked_in_by_type' => 'admin', 'checked_in_by' => $actor->id,
                'is_late_override' => 1, 'open_marker' => 1, 'created_at' => Clock::db(), 'updated_at' => Clock::db(),
            ]);
            $id = (int) $db->insertID();
            service('activityLog')->log($actor, 'checkin.late_override', ['slam_id' => (int) $att['slam_id'], 'target_type' => 'attendee', 'target_id' => (int) $att['id'], 'reason' => $reason, 'meta' => ['checkin_id' => $id]]);

            return ['checkin_id' => $id, 'attendee' => $att];
        });
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
