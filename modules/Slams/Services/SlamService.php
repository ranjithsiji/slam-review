<?php

declare(strict_types=1);

namespace Modules\Slams\Services;

use Modules\ActivityLog\Services\ActivityLogService;
use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;

/**
 * Slam records: details, the four slam times, automation settings, publishing,
 * and the readiness check. Status changes are NOT done here (see SlamPhaseService).
 */
class SlamService
{
    public const DETAIL_FIELDS = [
        'name', 'type', 'slug', 'description', 'venue_name', 'address_line', 'city', 'district', 'state',
        'pincode', 'latitude', 'longitude', 'map_url', 'contact_name', 'contact_phone', 'contact_email',
    ];

    /** Safety limit used when the organiser leaves the hard stop blank. */
    public const DEFAULT_HARD_STOP_HOURS = 24;

    public const TIME_FIELDS = ['opens_at', 'checkin_opens_at', 'checkin_closes_at', 'reviews_start_at', 'closes_at', 'event_ends_at'];

    public const AUTOMATION_FIELDS = [
        'default_review_minutes', 'presentation_minutes', 'changeover_minutes', 'edit_window_minutes', 'close_grace_minutes',
        'default_tea_break_minutes', 'default_lunch_break_minutes',
    ];

    /** @return array<string, mixed> */
    public function find(int $id): array
    {
        $slam = Db::row('SELECT * FROM slams WHERE id = ?', [$id]);
        if ($slam === null) {
            throw new DomainException('not_found', 'Slam not found.', 404);
        }

        return $slam;
    }

    /** @return array<string, mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        return Db::row('SELECT * FROM slams WHERE slug = ?', [$slug]);
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return Db::rows('SELECT * FROM slams ORDER BY opens_at');
    }

    /**
     * @param array<string, mixed> $input  details + times (UTC DB strings) + automation
     */
    public function create(array $input, Actor $actor): int
    {
        $data = $this->validate($input, null);

        return Db::tx(function ($db) use ($data, $actor): int {
            $now                = Clock::db();
            $data['status']     = 'draft';
            $data['created_at'] = $now;
            $data['updated_at'] = $now;
            $db->table('slams')->insert($data);
            $id = (int) $db->insertID();

            // New slams copy the default question templates so template edits never
            // alter an existing slam, and start with default group weights.
            service('questions')->copyTemplatesInto($id);
            $db->table('score_config')->insert([
                'slam_id' => $id, 'w_general' => 40, 'w_judge' => 40, 'w_representative' => 20,
                'updated_by' => $actor->id, 'updated_at' => $now,
            ]);

            service('activityLog')->log($actor, 'slam.created', [
                'slam_id' => $id, 'target_type' => 'slam', 'target_id' => $id, 'new' => $data,
            ]);

            return $id;
        });
    }

    /**
     * The slam an organiser most likely means when they open a slam-less screen
     * (sidebar links): the one asked for, else a running slam, else the next
     * upcoming one, else the latest.
     */
    public function currentId(?int $requested = null): ?int
    {
        if ($requested !== null && Db::value('SELECT id FROM slams WHERE id = ?', [$requested]) !== null) {
            return $requested;
        }
        $running = Db::value("SELECT id FROM slams WHERE status IN ('open','checkin_open','checkin_closed','reviewing','reviews_complete','revision','revision_closed','closed') ORDER BY opens_at DESC LIMIT 1");
        if ($running !== null) {
            return (int) $running;
        }
        $upcoming = Db::value("SELECT id FROM slams WHERE status <> 'ended' AND opens_at >= ? ORDER BY opens_at ASC LIMIT 1", [Clock::addMinutes(Clock::db(), -24 * 60)]);
        $id       = $upcoming ?? Db::value('SELECT id FROM slams ORDER BY opens_at DESC LIMIT 1');

        return $id === null ? null : (int) $id;
    }

    /** @param array<string, mixed> $input */
    public function update(int $id, array $input, Actor $actor): void
    {
        Db::tx(function ($db) use ($id, $input, $actor): void {
            $slam = Db::lockSlam($id);
            if ($slam['status'] === 'ended') {
                throw new DomainException('read_only', 'The event has ended; the slam is read-only.');
            }
            $data = $this->validate($input, $slam);

            $timeChanges = array_intersect_key($data, array_flip(self::TIME_FIELDS));
            [$oldT, $newT] = ActivityLogService::diff($slam, $timeChanges);
            $other = array_diff_key($data, array_flip(self::TIME_FIELDS));
            [$oldO, $newO] = ActivityLogService::diff($slam, $other);

            if ($newT === [] && $newO === []) {
                return;
            }
            $db->table('slams')->where('id', $id)->update($data + ['updated_at' => Clock::db()]);
            if ($newT !== []) {
                service('activityLog')->log($actor, 'slam.times_changed', ['slam_id' => $id, 'target_type' => 'slam', 'target_id' => $id, 'old' => $oldT, 'new' => $newT]);
            }
            if ($newO !== []) {
                service('activityLog')->log($actor, 'slam.updated', ['slam_id' => $id, 'target_type' => 'slam', 'target_id' => $id, 'old' => $oldO, 'new' => $newO]);
            }
            service('publicCache')->purge($id);
        });
    }

    public function setPublished(int $id, bool $public, Actor $actor): void
    {
        Db::tx(function ($db) use ($id, $public, $actor): void {
            $slam = Db::lockSlam($id);
            $db->table('slams')->where('id', $id)->update([
                'schedule_is_public'    => $public ? 1 : 0,
                'schedule_published_at' => $public ? Clock::db() : $slam['schedule_published_at'],
                'updated_at'            => Clock::db(),
            ]);
            service('activityLog')->log($actor, $public ? 'slam.schedule_published' : 'slam.schedule_unpublished', ['slam_id' => $id, 'target_type' => 'slam', 'target_id' => $id]);
            service('publicCache')->purge($id);
        });
    }

    /**
     * Readiness for Slam Open: performances, schedule items, questions for both
     * audiences and attendees. Returns the list of what is missing (empty = ready).
     *
     * @return list<string>
     */
    public function readiness(int $slamId): array
    {
        $missing = [];
        if ((int) Db::value('SELECT COUNT(*) FROM performances WHERE slam_id = ? AND is_active = 1', [$slamId]) === 0) {
            $missing[] = 'No active performances.';
        }
        if ((int) Db::value("SELECT COUNT(*) FROM schedule_items WHERE slam_id = ? AND item_type = 'performance' AND status <> 'skipped'", [$slamId]) === 0) {
            $missing[] = 'The schedule has no performances.';
        }
        foreach (['user' => 'judges', 'judge' => 'experts'] as $aud => $label) {
            if ((int) Db::value('SELECT COUNT(*) FROM review_questions WHERE slam_id = ? AND audience = ? AND is_active = 1', [$slamId, $aud]) === 0) {
                $missing[] = "No active review questions for {$label}.";
            }
        }
        if ((int) Db::value('SELECT COUNT(*) FROM attendees WHERE slam_id = ? AND is_active = 1', [$slamId]) === 0) {
            $missing[] = 'No registered attendees.';
        }

        return $missing;
    }

    /**
     * Validate and normalise input. $current is the existing row on update.
     *
     * @param array<string, mixed>      $input
     * @param array<string, mixed>|null $current
     * @return array<string, mixed>
     */
    public function validate(array $input, ?array $current): array
    {
        $errors = [];
        $data   = [];

        foreach (self::DETAIL_FIELDS as $f) {
            if (! array_key_exists($f, $input)) {
                continue;
            }
            $v        = is_string($input[$f]) ? trim($input[$f]) : $input[$f];
            $data[$f] = ($v === '' && ! in_array($f, ['name', 'slug', 'venue_name', 'address_line', 'city', 'district', 'state'], true)) ? null : $v;
        }
        if ($current === null || array_key_exists('name', $data)) {
            $name = (string) ($data['name'] ?? '');
            if (mb_strlen($name) < 3 || mb_strlen($name) > 150) {
                $errors['name'] = 'Name must be 3–150 characters.';
            }
        }
        if (isset($data['type']) && ! in_array($data['type'], ['regional', 'final'], true)) {
            $errors['type'] = 'Type must be regional or final.';
        }
        if (array_key_exists('slug', $data) || $current === null) {
            $slug = strtolower((string) ($data['slug'] ?? ''));
            if ($slug === '') {
                $slug = self::slugify((string) ($data['name'] ?? $current['name'] ?? 'slam') . '-' . substr((string) ($input['opens_at'] ?? $current['opens_at'] ?? ''), 0, 10));
            }
            if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) || strlen($slug) > 80) {
                $errors['slug'] = 'Slug may contain lowercase letters, digits and dashes (max 80).';
            } elseif (Db::value('SELECT id FROM slams WHERE slug = ? AND id <> ?', [$slug, $current['id'] ?? 0]) !== null) {
                $errors['slug'] = 'This slug is already used by another slam.';
            }
            $data['slug'] = $slug;
        }
        if (! empty($data['map_url']) && ! preg_match('#^https?://#i', (string) $data['map_url'])) {
            $errors['map_url'] = 'Map link must start with http:// or https://';
        }
        if (! empty($data['contact_email']) && ! filter_var($data['contact_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['contact_email'] = 'Invalid email.';
        }
        if (! empty($data['pincode']) && ! preg_match('/^\d{6}$/', (string) $data['pincode'])) {
            $errors['pincode'] = 'Pincode must be 6 digits.';
        }
        foreach (['latitude' => 90, 'longitude' => 180] as $f => $max) {
            if (isset($data[$f]) && $data[$f] !== null && (! is_numeric($data[$f]) || abs((float) $data[$f]) > $max)) {
                $errors[$f] = 'Invalid coordinate.';
            }
        }
        foreach (self::AUTOMATION_FIELDS as $f) {
            if (! array_key_exists($f, $input) || $input[$f] === '' || $input[$f] === null) {
                continue;
            }
            if (! ctype_digit((string) $input[$f]) || (int) $input[$f] > 600) {
                $errors[$f] = 'Must be a whole number of minutes (0–600).';
            } else {
                $data[$f] = (int) $input[$f];
            }
        }
        foreach (['default_review_minutes', 'default_tea_break_minutes', 'default_lunch_break_minutes'] as $f) {
            if (isset($data[$f]) && $data[$f] < 1) {
                $errors[$f] = 'Must be at least 1 minute.';
            }
        }

        if (array_key_exists('auto_mode', $input) && $input['auto_mode'] !== '') {
            if (! in_array($input['auto_mode'], ['auto', 'manual'], true)) {
                $errors['auto_mode'] = 'Choose manual or automatic.';
            } else {
                $data['auto_mode'] = $input['auto_mode'];
            }
        }

        // Times: merge with current values, then validate the whole chain.
        $times = [];
        foreach (self::TIME_FIELDS as $f) {
            if (array_key_exists($f, $input)) {
                $v         = $input[$f];
                $times[$f] = ($v === '' || $v === null) ? null : Clock::db(Clock::parse((string) $v));
                $data[$f]  = $times[$f];
            } else {
                $times[$f] = $current[$f] ?? null;
            }
        }
        // Nobody can predict when a slam will end: a blank hard stop defaults to a
        // safety limit 24 hours after Slam open (the admin closes the slam by hand).
        if (empty($times['closes_at']) && ! empty($times['opens_at']) && (array_key_exists('closes_at', $input) || $current === null)) {
            $times['closes_at'] = $data['closes_at'] = Clock::addMinutes($times['opens_at'], self::DEFAULT_HARD_STOP_HOURS * 60);
        }
        $errors += $this->validateTimes($times, $current);

        if ($errors !== []) {
            throw new DomainException('validation', 'Please correct the highlighted fields.', 422, $errors);
        }

        return $data;
    }

    /**
     * opens_at ≤ checkin_opens_at < checkin_closes_at ≤ reviews_start_at < closes_at.
     *
     * @param array<string, string|null>       $t
     * @param array<string, mixed>|null        $current
     * @return array<string, string>
     */
    public function validateTimes(array $t, ?array $current): array
    {
        $e = [];
        if (empty($t['opens_at'])) {
            $e['opens_at'] = 'Slam open time is required.';
        }
        if (empty($t['closes_at'])) {
            $e['closes_at'] = 'Slam close time is required.';
        }
        if ($e !== []) {
            return $e;
        }
        $ts  = static fn (?string $v): ?int => $v === null ? null : Clock::parse($v)->getTimestamp();
        $o   = $ts($t['opens_at']);
        $ci  = $ts($t['checkin_opens_at'] ?? null);
        $cc  = $ts($t['checkin_closes_at'] ?? null);
        $rs  = $ts($t['reviews_start_at'] ?? null);
        $c   = $ts($t['closes_at']);
        $end = $ts($t['event_ends_at'] ?? null);

        if ($c <= $o) {
            $e['closes_at'] = 'Slam close must be after slam open.';
        }
        if ($ci !== null && $ci < $o) {
            $e['checkin_opens_at'] = 'Gates open cannot be before slam open.';
        }
        if ($ci !== null && $cc !== null && $cc <= $ci) {
            $e['checkin_closes_at'] = 'Gate close must be after gates open.';
        }
        if ($cc !== null && $cc < $o) {
            $e['checkin_closes_at'] = 'Gate close cannot be before slam open.';
        }
        if ($cc !== null && $cc >= $c) {
            $e['checkin_closes_at'] = 'Gate close must be before slam close.';
        }
        if ($rs !== null) {
            if ($cc !== null && $rs < $cc) {
                $e['reviews_start_at'] = 'Reviews cannot start before gate close.';
            }
            if ($rs < $o || $rs >= $c) {
                $e['reviews_start_at'] = 'Reviews start must be between slam open and slam close.';
            }
        }
        if ($end !== null && $end < $c) {
            $e['event_ends_at'] = 'Event end cannot be before slam close.';
        }

        // A time that already passed cannot be moved to another past time.
        if ($current !== null) {
            $now = Clock::now()->getTimestamp();
            foreach (self::TIME_FIELDS as $f) {
                $old = $ts($current[$f] ?? null);
                $new = $ts($t[$f] ?? null);
                if ($old !== null && $new !== null && $old !== $new && $old <= $now && $new <= $now) {
                    $e[$f] = 'This time has passed; it can only be moved to a future time.';
                }
            }
        }

        return $e;
    }

    public static function slugify(string $text): string
    {
        $s = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: ''), '-'));

        return substr($s === '' ? 'slam' : $s, 0, 80);
    }
}
