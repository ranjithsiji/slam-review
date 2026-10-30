<?php

declare(strict_types=1);

namespace Modules\Schedule\Services;

use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;
use Modules\Slams\Services\SlamPhaseService;

/**
 * The slam schedule: ordered slots (performances and breaks) with planned start
 * times and durations. All writes take the per-slam row lock used by the phase
 * machine. Before the slam goes live everything is editable; from `reviewing`
 * on, only pending slots may change.
 *
 * Draft items (client <-> server) look like:
 *   {id?, item_type, break_type?, performance_id?, label?, planned_start_at (ISO UTC),
 *    duration_minutes, is_pinned, status}
 */
class ScheduleService
{
    public const BREAK_LABELS = ['tea' => 'Tea break', 'lunch' => 'Lunch break', 'other' => 'Break'];

    /** Statuses from which the schedule is in live mode (only pending slots editable). */
    public const LIVE_FROM = 'reviewing';

    /** @return list<array<string, mixed>> */
    public function items(int $slamId): array
    {
        return Db::rows(
            "SELECT si.*, p.topic, p.abstract, p.is_active AS performance_active, pr.id AS presenter_id, pr.name AS presenter_name,
                    pr.designation_or_institution, pr.profile, pr.photo_path, pr.photo_thumb_path, pr.photo_alt,
                    (SELECT COUNT(*) FROM reviews r WHERE r.schedule_item_id = si.id) AS review_count,
                    (SELECT rw.opened_at FROM review_windows rw WHERE rw.schedule_item_id = si.id ORDER BY rw.id DESC LIMIT 1) AS window_opened_at,
                    (SELECT rw.closed_at FROM review_windows rw WHERE rw.schedule_item_id = si.id ORDER BY rw.id DESC LIMIT 1) AS window_closed_at
             FROM schedule_items si
             LEFT JOIN performances p ON p.id = si.performance_id
             LEFT JOIN presenters pr ON pr.id = p.presenter_id
             WHERE si.slam_id = ? ORDER BY si.position, si.id",
            [$slamId],
        );
    }

    public static function isLive(array $slam): bool
    {
        return SlamPhaseService::index($slam['status']) >= SlamPhaseService::index(self::LIVE_FROM);
    }

    /** Convert DB rows into draft items. */
    public function toDraft(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'id'               => (int) $r['id'],
            'item_type'        => $r['item_type'],
            'break_type'       => $r['break_type'],
            'performance_id'   => $r['performance_id'] !== null ? (int) $r['performance_id'] : null,
            'label'            => $r['label'],
            'planned_start_at' => Clock::iso($r['planned_start_at']),
            'duration_minutes' => (int) $r['duration_minutes'],
            'is_pinned'        => (bool) $r['is_pinned'],
            'status'           => $r['status'],
        ], $rows);
    }

    /**
     * Normalise client draft items into internal form (DB time strings).
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public function normalise(array $items): array
    {
        $out = [];
        foreach (array_values($items) as $i => $it) {
            if (! is_array($it)) {
                throw new DomainException('validation', "Row {$i} is not an object.");
            }
            $type = ($it['item_type'] ?? 'performance') === 'break' ? 'break' : 'performance';
            try {
                $start = Clock::db(Clock::parse((string) ($it['planned_start_at'] ?? '')));
            } catch (\Throwable) {
                throw new DomainException('validation', 'Row ' . ($i + 1) . ' has an invalid start time.');
            }
            $breakType = $type === 'break' ? (in_array($it['break_type'] ?? '', ['tea', 'lunch', 'other'], true) ? $it['break_type'] : 'other') : null;
            $label     = isset($it['label']) ? trim((string) $it['label']) : null;
            $out[] = [
                'id'               => isset($it['id']) && $it['id'] !== null && $it['id'] !== '' ? (int) $it['id'] : null,
                'item_type'        => $type,
                'break_type'       => $breakType,
                'performance_id'   => $type === 'performance' ? (int) ($it['performance_id'] ?? 0) : null,
                'label'            => $type === 'break' ? (($label === null || $label === '') ? self::BREAK_LABELS[$breakType] : mb_substr($label, 0, 150)) : null,
                'planned_start_at' => $start,
                'duration_minutes' => (int) ($it['duration_minutes'] ?? 0),
                'is_pinned'        => ! empty($it['is_pinned']),
                'status'           => in_array($it['status'] ?? 'pending', ['pending', 'open', 'closed', 'skipped'], true) ? ($it['status'] ?? 'pending') : 'pending',
            ];
        }

        return $out;
    }

    /**
     * Validate a full ordered list. Returns errors (block saving) and warnings.
     *
     * @param list<array<string, mixed>> $items normalised
     * @return array{errors: list<array{index:int,message:string}>, warnings: list<array{index:int,message:string}>}
     */
    public function validate(array $slam, array $items): array
    {
        $errors   = [];
        $warnings = [];
        $perfs    = [];
        foreach (Db::rows('SELECT id, is_active FROM performances WHERE slam_id = ?', [$slam['id']]) as $p) {
            $perfs[(int) $p['id']] = (int) $p['is_active'];
        }
        $gate   = Clock::parse($slam['checkin_closes_at'] ?? $slam['opens_at']);
        $limit  = Clock::parse($slam['closes_at']);
        $seen   = [];
        $prevEnd = null;
        $active = [];

        foreach ($items as $i => $it) {
            if ($it['duration_minutes'] < 1) {
                $errors[] = ['index' => $i, 'message' => 'Duration must be at least 1 minute.'];
            }
            if ($it['item_type'] === 'performance') {
                $pid = $it['performance_id'];
                if (! isset($perfs[$pid])) {
                    $errors[] = ['index' => $i, 'message' => 'Unknown performance for this slam.'];
                } elseif (isset($seen[$pid])) {
                    $errors[] = ['index' => $i, 'message' => 'This presenter is already in the schedule.'];
                }
                $seen[$pid] = true;
                if ($it['duration_minutes'] > 0 && $it['duration_minutes'] < 2) {
                    $warnings[] = ['index' => $i, 'message' => 'Very short review window.'];
                } elseif ($it['duration_minutes'] > 30) {
                    $warnings[] = ['index' => $i, 'message' => 'Very long review window.'];
                }
            }
            if ($it['status'] === 'skipped') {
                continue;
            }
            $start = Clock::parse($it['planned_start_at']);
            $end   = $start->modify('+' . $it['duration_minutes'] . ' minutes');
            if ($prevEnd !== null && $start < $prevEnd) {
                $errors[] = ['index' => $i, 'message' => 'Overlaps the previous slot (starts before it ends).'];
            }
            if ($start < $gate && $it['status'] === 'pending') {
                $errors[] = ['index' => $i, 'message' => 'Starts before Gate Close.'];
            }
            $prevEnd  = $end;
            $active[] = $i;
        }

        if ($prevEnd !== null) {
            $withEdit = $prevEnd->modify('+' . (int) $slam['edit_window_minutes'] . ' minutes');
            $last     = end($active);
            if ($withEdit > $limit) {
                $errors[] = ['index' => (int) $last, 'message' => 'The schedule plus the edit window runs past the slam close time.'];
            } elseif ($limit->getTimestamp() - $withEdit->getTimestamp() < 600) {
                $warnings[] = ['index' => (int) $last, 'message' => 'Less than 10 minutes margin before the slam close time.'];
            }
        }
        if ($active !== []) {
            if ($items[$active[0]]['item_type'] === 'break') {
                $warnings[] = ['index' => $active[0], 'message' => 'The schedule starts with a break.'];
            }
            $last = end($active);
            if ($items[$last]['item_type'] === 'break') {
                $warnings[] = ['index' => (int) $last, 'message' => 'The schedule ends with a break.'];
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * Re-flow times from $fromIndex: each slot starts at the previous slot's end
     * (+ changeover between two performances); pinned and locked slots keep their
     * time, and an error is reported if one cannot fit.
     *
     * @param list<array<string, mixed>> $items normalised
     * @return array{items: list<array<string, mixed>>, errors: list<array{index:int,message:string}>}
     */
    public function reflow(array $slam, array $items, int $fromIndex = 0, bool $movePinned = false): array
    {
        $errors   = [];
        $prev     = null;
        $change   = (int) $slam['changeover_minutes'];
        foreach ($items as $i => &$it) {
            if ($it['status'] === 'skipped') {
                continue;
            }
            if ($i > $fromIndex && $prev !== null) {
                $gap      = ($prev['item_type'] === 'performance' && $it['item_type'] === 'performance') ? $change : 0;
                $earliest = Clock::addMinutes(Clock::addMinutes($prev['planned_start_at'], (int) $prev['duration_minutes']), $gap);
                $fixed    = $it['status'] !== 'pending' || ($it['is_pinned'] && ! $movePinned);
                if ($fixed) {
                    if (Clock::parse($it['planned_start_at']) < Clock::parse($earliest)) {
                        $errors[] = ['index' => $i, 'message' => $it['status'] === 'pending'
                            ? 'Pinned slot cannot fit: the previous slots end after its pinned time.'
                            : 'A slot that already ran cannot move.'];
                    }
                } else {
                    $it['planned_start_at'] = $earliest;
                }
            }
            $prev = $it;
        }
        unset($it);

        return ['items' => $items, 'errors' => $errors];
    }

    /**
     * In live mode, slots that are open/closed/skipped must be unchanged, keep their
     * relative order, and no pending slot may be placed before the last one that ran.
     */
    private function assertLiveChangesAllowed(array $slam, array $items, array $existing): void
    {
        if (! self::isLive($slam)) {
            return;
        }
        if (SlamPhaseService::index($slam['status']) > SlamPhaseService::index('reviewing')) {
            throw new DomainException('schedule_locked', 'The schedule can no longer change in this phase.', 409);
        }
        $byId       = array_column($existing, null, 'id');
        $lockedSeen = [];
        $lastRanIdx = -1;
        foreach ($items as $i => $it) {
            $old = $it['id'] !== null ? ($byId[$it['id']] ?? null) : null;
            if ($old !== null && $old['status'] !== 'pending') {
                $same = $old['planned_start_at'] === $it['planned_start_at'] && (int) $old['duration_minutes'] === $it['duration_minutes']
                    && $old['item_type'] === $it['item_type'] && (int) $old['performance_id'] === (int) $it['performance_id']
                    && $old['status'] === $it['status'];
                if (! $same) {
                    throw new DomainException('locked_slot', 'Slots that are open, closed or skipped cannot be changed.', 409, ['index' => $i]);
                }
                $lockedSeen[] = (int) $old['id'];
                if ($old['status'] !== 'skipped') {
                    $lastRanIdx = $i;
                }
            } elseif ($it['status'] !== 'pending' && $it['status'] !== 'skipped') {
                throw new DomainException('locked_slot', 'Only pending slots can be added or changed while the slam is live.', 409, ['index' => $i]);
            }
        }
        foreach ($items as $i => $it) {
            if ($it['status'] === 'pending' && $i < $lastRanIdx) {
                throw new DomainException('locked_slot', 'A pending slot cannot be placed before a slot that already ran.', 409, ['index' => $i]);
            }
        }
        $expected = array_values(array_map(static fn ($r) => (int) $r['id'], array_filter($existing, static fn ($r) => $r['status'] !== 'pending')));
        if ($lockedSeen !== $expected) {
            throw new DomainException('locked_slot', 'Slots that already ran must stay in the schedule in their order.', 409);
        }
    }

    /**
     * Save the whole draft (schedule maker). Optimistic concurrency through
     * schedule_version: a stale base_version gets a 409 with the current version.
     *
     * @return array{version: int, warnings: list<array{index:int,message:string}>}
     */
    public function saveDraft(int $slamId, array $rawItems, int $baseVersion, Actor $actor, ?string $note = null, array $meta = []): array
    {
        $items = $this->normalise($rawItems);

        try {
            $result = Db::tx(function ($db) use ($slamId, $items, $baseVersion, $actor, $note, $meta): array {
                $slam = Db::lockSlam($slamId);
                if ((int) $slam['schedule_version'] !== $baseVersion) {
                    throw new DomainException('version_conflict', 'Another admin saved the schedule first.', 409, ['current_version' => (int) $slam['schedule_version']]);
                }
                $existing = Db::rows('SELECT * FROM schedule_items WHERE slam_id = ? ORDER BY position, id', [$slamId]);
                $this->assertLiveChangesAllowed($slam, $items, $existing);
                $check = $this->validate($slam, $items);
                if ($check['errors'] !== []) {
                    throw new DomainException('invalid_schedule', 'The schedule has errors.', 422, $check);
                }

                $byId     = array_column($existing, null, 'id');
                $keepIds  = [];
                $now      = Clock::db();
                foreach ($items as $i => $it) {
                    $row = [
                        'slam_id' => $slamId, 'item_type' => $it['item_type'], 'break_type' => $it['break_type'],
                        'performance_id' => $it['performance_id'], 'label' => $it['label'], 'position' => ($i + 1) * 10,
                        'planned_start_at' => $it['planned_start_at'], 'duration_minutes' => $it['duration_minutes'],
                        'is_pinned' => $it['is_pinned'] ? 1 : 0, 'updated_at' => $now,
                    ];
                    if ($it['id'] !== null && isset($byId[$it['id']])) {
                        $old = $byId[$it['id']];
                        if (in_array($old['status'], ['pending', 'skipped'], true) && in_array($it['status'], ['pending', 'skipped'], true)) {
                            $row['status'] = $it['status'];
                        }
                        $db->table('schedule_items')->where('id', $it['id'])->update($row);
                        $keepIds[] = $it['id'];
                    } else {
                        $row['status']     = $it['status'] === 'skipped' ? 'skipped' : 'pending';
                        $row['created_at'] = $now;
                        $db->table('schedule_items')->insert($row);
                        $keepIds[] = (int) $db->insertID();
                    }
                }
                foreach ($existing as $old) {
                    if (in_array((int) $old['id'], $keepIds, true)) {
                        continue;
                    }
                    $this->assertRemovable($old);
                    $db->table('schedule_items')->where('id', $old['id'])->delete();
                    service('activityLog')->log($actor, 'schedule.item_removed', ['slam_id' => $slamId, 'target_type' => 'schedule_item', 'target_id' => (int) $old['id']]);
                }

                $version = $baseVersion + 1;
                $db->table('slams')->where('id', $slamId)->update(['schedule_version' => $version, 'updated_at' => $now]);
                $this->writeSnapshot($slamId, $version, $actor, $note);
                service('activityLog')->log($actor, 'schedule.saved', [
                    'slam_id' => $slamId, 'target_type' => 'slam', 'target_id' => $slamId,
                    'meta' => $meta + ['version' => $version, 'items' => count($items),
                        'breaks' => count(array_filter($items, static fn ($x) => $x['item_type'] === 'break'))],
                ]);

                return ['version' => $version, 'warnings' => $check['warnings']];
            });
        } catch (DomainException $e) {
            if ($e->reason === 'version_conflict') {
                service('activityLog')->log($actor, 'schedule.conflict_detected', ['slam_id' => $slamId, 'outcome' => 'denied', 'reason' => 'version_conflict', 'meta' => ['base_version' => $baseVersion]]);
            }
            throw $e;
        }
        service('publicCache')->purge($slamId);

        return $result;
    }

    private function assertRemovable(array $item): void
    {
        if ($item['status'] !== 'pending' && $item['status'] !== 'skipped') {
            throw new DomainException('locked_slot', 'A slot that is open or has run cannot be removed.', 409);
        }
        if ((int) Db::value('SELECT COUNT(*) FROM reviews WHERE schedule_item_id = ?', [$item['id']]) > 0
            || (int) Db::value('SELECT COUNT(*) FROM review_windows WHERE schedule_item_id = ?', [$item['id']]) > 0) {
            throw new DomainException('has_reviews', 'A performance that has reviews cannot be removed; skip it instead.', 409);
        }
    }

    private function writeSnapshot(int $slamId, int $version, Actor $actor, ?string $note): void
    {
        $items = $this->toDraft(Db::rows('SELECT * FROM schedule_items WHERE slam_id = ? ORDER BY position, id', [$slamId]));
        db_connect()->table('schedule_snapshots')->insert([
            'slam_id' => $slamId, 'version' => $version, 'saved_at' => Clock::db(),
            'saved_by' => $actor->isAdmin() ? $actor->id : null, 'note' => $note !== null ? mb_substr($note, 0, 255) : null,
            'snapshot' => json_encode($items, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** Suggested start for a new slot appended at the end. */
    public function nextStart(array $slam, array $items, string $newType = 'performance'): string
    {
        $last = null;
        foreach ($items as $it) {
            if (($it['status'] ?? 'pending') !== 'skipped') {
                $last = $it;
            }
        }
        if ($last === null) {
            return $slam['reviews_start_at'] ?? $slam['checkin_closes_at'] ?? Clock::addMinutes($slam['opens_at'], 60);
        }
        $gap = ($last['item_type'] === 'performance' && $newType === 'performance') ? (int) $slam['changeover_minutes'] : 0;

        return Clock::addMinutes(Clock::addMinutes(Clock::db(Clock::parse($last['planned_start_at'])), (int) $last['duration_minutes']), $gap);
    }

    /**
     * Append one slot (presenter form, break quick-add via API). Planned start
     * defaults to the next free time; duration to the slam defaults.
     */
    public function appendItem(int $slamId, array $data, Actor $actor): int
    {
        $id = Db::tx(function ($db) use ($slamId, $data, $actor): int {
            $slam = Db::lockSlam($slamId);
            if (self::isLive($slam) && SlamPhaseService::index($slam['status']) > SlamPhaseService::index('reviewing')) {
                throw new DomainException('schedule_locked', 'The schedule can no longer change in this phase.', 409);
            }
            $existing = $this->normalise($this->toDraft(Db::rows('SELECT * FROM schedule_items WHERE slam_id = ? ORDER BY position, id', [$slamId])));
            $type     = ($data['item_type'] ?? 'performance') === 'break' ? 'break' : 'performance';
            $bt       = $type === 'break' ? ($data['break_type'] ?? 'other') : null;
            $duration = (int) ($data['duration_minutes'] ?? 0);
            if ($duration < 1) {
                $duration = $type === 'break'
                    ? (int) ($bt === 'lunch' ? $slam['default_lunch_break_minutes'] : $slam['default_tea_break_minutes'])
                    : (int) $slam['default_review_minutes'];
            }
            $start = ! empty($data['planned_start_at']) ? Clock::db(Clock::parse((string) $data['planned_start_at'])) : $this->nextStart($slam, $existing, $type);
            $new   = $this->normalise([[
                'item_type' => $type, 'break_type' => $bt, 'performance_id' => $data['performance_id'] ?? null,
                'label' => $data['label'] ?? null, 'planned_start_at' => $start, 'duration_minutes' => $duration,
                'is_pinned' => ! empty($data['is_pinned']), 'status' => 'pending',
            ]])[0];
            $all   = [...$existing, $new];
            $check = $this->validate($slam, $all);
            $mine  = array_values(array_filter($check['errors'], static fn ($e) => $e['index'] === count($all) - 1));
            if ($mine !== []) {
                throw new DomainException('invalid_schedule', $mine[0]['message'], 422, $check);
            }
            $pos = (int) Db::value('SELECT COALESCE(MAX(position), 0) FROM schedule_items WHERE slam_id = ?', [$slamId]) + 10;
            $db->table('schedule_items')->insert([
                'slam_id' => $slamId, 'item_type' => $new['item_type'], 'break_type' => $new['break_type'],
                'performance_id' => $new['performance_id'], 'label' => $new['label'], 'position' => $pos,
                'planned_start_at' => $new['planned_start_at'], 'duration_minutes' => $new['duration_minutes'],
                'is_pinned' => $new['is_pinned'] ? 1 : 0, 'status' => 'pending', 'created_at' => Clock::db(), 'updated_at' => Clock::db(),
            ]);
            $id      = (int) $db->insertID();
            $version = (int) $slam['schedule_version'] + 1;
            $db->table('slams')->where('id', $slamId)->update(['schedule_version' => $version]);
            if (! self::isLive($slam)) {
                $this->writeSnapshot($slamId, $version, $actor, 'Slot added');
            }
            service('activityLog')->log($actor, $type === 'break' ? 'schedule.break_added' : 'schedule.item_added', [
                'slam_id' => $slamId, 'target_type' => 'schedule_item', 'target_id' => $id,
                'new' => ['planned_start_at' => $new['planned_start_at'], 'duration_minutes' => $new['duration_minutes'], 'performance_id' => $new['performance_id']],
            ]);

            return $id;
        });
        service('publicCache')->purge($slamId);

        return $id;
    }

    /** Edit one slot's time, duration, pin or label (PUT /api/schedule/items/{id}). */
    public function updateItem(int $itemId, array $data, Actor $actor): void
    {
        $item = Db::row('SELECT * FROM schedule_items WHERE id = ?', [$itemId]) ?? throw new DomainException('not_found', 'Slot not found.', 404);
        $draft = $this->toDraft(Db::rows('SELECT * FROM schedule_items WHERE slam_id = ? ORDER BY position, id', [$item['slam_id']]));
        foreach ($draft as &$d) {
            if ($d['id'] === $itemId) {
                foreach (['planned_start_at', 'duration_minutes', 'is_pinned', 'label'] as $f) {
                    if (array_key_exists($f, $data)) {
                        $d[$f] = $data[$f];
                    }
                }
            }
        }
        unset($d);
        $slam = service('slams')->find((int) $item['slam_id']);
        $this->saveDraft((int) $item['slam_id'], $draft, (int) $slam['schedule_version'], $actor, 'Slot edited');
        $action = array_key_exists('is_pinned', $data) && (bool) $data['is_pinned'] !== (bool) $item['is_pinned']
            ? ($data['is_pinned'] ? 'schedule.item_pinned' : 'schedule.item_unpinned')
            : ($item['item_type'] === 'break' ? 'schedule.break_updated' : 'schedule.item_time_changed');
        service('activityLog')->log($actor, $action, [
            'slam_id' => (int) $item['slam_id'], 'target_type' => 'schedule_item', 'target_id' => $itemId,
            'old' => array_intersect_key($item, $data), 'new' => $data,
        ]);
    }

    public function deleteItem(int $itemId, Actor $actor): void
    {
        $item  = Db::row('SELECT * FROM schedule_items WHERE id = ?', [$itemId]) ?? throw new DomainException('not_found', 'Slot not found.', 404);
        $this->assertRemovable($item);
        $draft = array_values(array_filter(
            $this->toDraft(Db::rows('SELECT * FROM schedule_items WHERE slam_id = ? ORDER BY position, id', [$item['slam_id']])),
            static fn ($d) => $d['id'] !== $itemId,
        ));
        $slam = service('slams')->find((int) $item['slam_id']);
        $this->saveDraft((int) $item['slam_id'], $draft, (int) $slam['schedule_version'], $actor, 'Slot removed');
    }

    /**
     * Reorder by a new list of item ids. With $reflow the times re-flow from the
     * first slot; with $keepTimes each position keeps its old start (presenters are
     * swapped; durations must match). $preview returns the changes without saving.
     *
     * @param list<int> $order
     */
    public function reorder(int $slamId, array $order, bool $reflow, bool $keepTimes, bool $preview, Actor $actor): array
    {
        $slam  = service('slams')->find($slamId);
        $rows  = Db::rows('SELECT * FROM schedule_items WHERE slam_id = ? ORDER BY position, id', [$slamId]);
        $draft = $this->normalise($this->toDraft($rows));
        $byId  = array_column($draft, null, 'id');
        if (count($order) !== count($draft) || array_diff(array_keys($byId), array_map('intval', $order)) !== []) {
            throw new DomainException('validation', 'The new order must list every slot exactly once.');
        }
        $new = array_map(static fn ($id) => $byId[(int) $id], array_values($order));
        $errors = [];
        if ($keepTimes) {
            foreach ($new as $k => &$it) {
                if ($it['id'] !== $draft[$k]['id']) {
                    if ($it['duration_minutes'] !== $draft[$k]['duration_minutes']) {
                        $errors[] = ['index' => $k, 'message' => 'Durations differ; "keep slot times" only works for equal durations.'];
                    }
                    $it['planned_start_at'] = $draft[$k]['planned_start_at'];
                }
            }
            unset($it);
        } elseif ($reflow) {
            $firstStart = $draft[0]['planned_start_at'] ?? null;
            if ($firstStart !== null && $new[0]['status'] === 'pending' && ! $new[0]['is_pinned']) {
                $new[0]['planned_start_at'] = $firstStart;
            }
            $r      = $this->reflow($slam, $new, 0);
            $new    = $r['items'];
            $errors = $r['errors'];
        }
        $changes = [];
        foreach ($new as $k => $it) {
            $old = $byId[$it['id']];
            $changes[] = ['id' => $it['id'], 'old_position' => array_search($it['id'], array_column($draft, 'id'), true) + 1, 'new_position' => $k + 1,
                'old_start' => Clock::iso($old['planned_start_at']), 'new_start' => Clock::iso($it['planned_start_at'])];
        }
        $check = $this->validate($slam, $new);
        $errors = [...$errors, ...$check['errors']];
        if ($preview || $errors !== []) {
            return ['preview' => true, 'changes' => $changes, 'errors' => $errors, 'warnings' => $check['warnings'], 'items' => $this->draftOut($new)];
        }
        $res = $this->saveDraft($slamId, $this->draftOut($new), (int) $slam['schedule_version'], $actor, 'Reordered');
        service('activityLog')->log($actor, 'schedule.reordered', ['slam_id' => $slamId, 'meta' => ['changes' => $changes, 'reflow' => $reflow, 'keep_times' => $keepTimes]]);

        return ['preview' => false, 'changes' => $changes, 'errors' => [], 'warnings' => $res['warnings'], 'version' => $res['version']];
    }

    /** Shift all remaining pending planned times by the current delay. */
    public function reflowRemaining(int $slamId, bool $includePinned, bool $preview, Actor $actor): array
    {
        $slam  = service('slams')->find($slamId);
        $proj  = service('slamPhase')->projection($slam);
        $delay = $proj['late_minutes'];
        $draft = $this->normalise($this->toDraft(Db::rows('SELECT * FROM schedule_items WHERE slam_id = ? ORDER BY position, id', [$slamId])));
        $changes = [];
        foreach ($draft as &$it) {
            if ($it['status'] === 'pending' && $delay > 0 && (! $it['is_pinned'] || $includePinned)) {
                $new = Clock::addMinutes($it['planned_start_at'], $delay);
                $changes[] = ['id' => $it['id'], 'old_start' => Clock::iso($it['planned_start_at']), 'new_start' => Clock::iso($new)];
                $it['planned_start_at'] = $new;
            }
        }
        unset($it);
        $check = $this->validate($slam, $draft);
        if ($preview || $changes === [] || $check['errors'] !== []) {
            return ['preview' => true, 'delay_minutes' => $delay, 'changes' => $changes, 'errors' => $check['errors'], 'warnings' => $check['warnings']];
        }
        $res = $this->saveDraft($slamId, $this->draftOut($draft), (int) $slam['schedule_version'], $actor, 'Re-flow remaining');
        service('activityLog')->log($actor, 'schedule.reflowed', ['slam_id' => $slamId, 'meta' => ['delay_minutes' => $delay, 'include_pinned' => $includePinned, 'changes' => count($changes)]]);

        return ['preview' => false, 'delay_minutes' => $delay, 'changes' => $changes, 'errors' => [], 'warnings' => $res['warnings'], 'version' => $res['version']];
    }

    /** Shift later pending slots by N minutes (break extended). Caller holds the lock. */
    public function shiftPendingAfter(array $slam, int $position, int $minutes, bool $includePinned): void
    {
        $rows = Db::rows("SELECT * FROM schedule_items WHERE slam_id = ? AND position > ? AND status = 'pending' ORDER BY position", [$slam['id'], $position]);
        foreach ($rows as $r) {
            if ((int) $r['is_pinned'] === 1 && ! $includePinned) {
                continue;
            }
            db_connect()->table('schedule_items')->where('id', $r['id'])->update(['planned_start_at' => Clock::addMinutes($r['planned_start_at'], $minutes)]);
        }
    }

    /**
     * Generate wizard. Returns a full draft (preview); the client saves it.
     *
     * settings: first_start, duration, changeover, tea {mode: none|every|at, every_n, at, minutes},
     *           lunch {at|null, minutes}, order: listed|alpha|shuffle, seed, mode: replace|append
     */
    public function generate(int $slamId, array $settings, Actor $actor): array
    {
        $slam      = service('slams')->find($slamId);
        $rows      = Db::rows('SELECT * FROM schedule_items WHERE slam_id = ? ORDER BY position, id', [$slamId]);
        $current   = $this->normalise($this->toDraft($rows));
        $mode      = ($settings['mode'] ?? 'replace') === 'append' ? 'append' : 'replace';
        $duration  = max(1, (int) ($settings['duration'] ?? $slam['default_review_minutes']));
        $change    = max(0, (int) ($settings['changeover'] ?? $slam['changeover_minutes']));
        $slamCopy  = $slam;
        $slamCopy['changeover_minutes'] = $change;

        $kept = $mode === 'append' ? $current : array_values(array_filter($current, static fn ($it) => $it['status'] !== 'pending' || $it['is_pinned']));
        $scheduled = array_flip(array_filter(array_map(static fn ($it) => $it['performance_id'], $kept)));
        $perfs = Db::rows(
            'SELECT p.id, pr.name FROM performances p JOIN presenters pr ON pr.id = p.presenter_id WHERE p.slam_id = ? AND p.is_active = 1 ORDER BY p.id',
            [$slamId],
        );
        $perfs = array_values(array_filter($perfs, static fn ($p) => ! isset($scheduled[(int) $p['id']])));

        $order = $settings['order'] ?? 'listed';
        $seed  = null;
        if ($order === 'alpha') {
            $coll = new \Collator('ml_IN');
            usort($perfs, static fn ($a, $b) => $coll->compare($a['name'], $b['name']));
        } elseif ($order === 'shuffle') {
            $seed  = isset($settings['seed']) && $settings['seed'] !== '' ? (int) $settings['seed'] : random_int(1, 2_000_000_000);
            $perfs = self::seededShuffle($perfs, $seed);
        }

        $cursor = ! empty($settings['first_start']) ? Clock::db(Clock::parse((string) $settings['first_start']))
            : ($slam['reviews_start_at'] ?? $slam['checkin_closes_at'] ?? Clock::addMinutes($slam['opens_at'], 60));
        if ($mode === 'append' && $kept !== []) {
            $cursor = $this->nextStart($slamCopy, $kept);
        }

        $tea   = $settings['tea'] ?? ['mode' => 'none'];
        $lunch = $settings['lunch'] ?? [];
        $teaAt = ($tea['mode'] ?? '') === 'at' && ! empty($tea['at']) ? Clock::db(Clock::parse((string) $tea['at'])) : null;
        $lunchAt = ! empty($lunch['at']) ? Clock::db(Clock::parse((string) $lunch['at'])) : null;
        $teaMin  = max(1, (int) ($tea['minutes'] ?? $slam['default_tea_break_minutes']));
        $lunchMin = max(1, (int) ($lunch['minutes'] ?? $slam['default_lunch_break_minutes']));
        $everyN  = ($tea['mode'] ?? '') === 'every' ? max(1, (int) ($tea['every_n'] ?? 4)) : 0;

        $new = [];
        $prevType = null;
        $count = 0;
        $addBreak = static function (string $type, int $min, ?string $pinnedAt) use (&$new, &$cursor, &$prevType): void {
            $start = $pinnedAt !== null && Clock::parse($pinnedAt) > Clock::parse($cursor) ? $pinnedAt : $cursor;
            $new[] = ['id' => null, 'item_type' => 'break', 'break_type' => $type, 'performance_id' => null, 'label' => self::BREAK_LABELS[$type],
                'planned_start_at' => $start, 'duration_minutes' => $min, 'is_pinned' => $pinnedAt !== null, 'status' => 'pending'];
            $cursor   = Clock::addMinutes($start, $min);
            $prevType = 'break';
        };
        foreach ($perfs as $i => $p) {
            $start = $prevType === 'performance' ? Clock::addMinutes($cursor, $change) : $cursor;
            if ($lunchAt !== null && Clock::parse(Clock::addMinutes($start, $duration)) > Clock::parse($lunchAt)) {
                $addBreak('lunch', $lunchMin, $lunchAt);
                $lunchAt = null;
                $start   = $cursor;
            }
            if ($teaAt !== null && Clock::parse(Clock::addMinutes($start, $duration)) > Clock::parse($teaAt)) {
                $addBreak('tea', $teaMin, $teaAt);
                $teaAt = null;
                $start = $cursor;
            }
            $new[] = ['id' => null, 'item_type' => 'performance', 'break_type' => null, 'performance_id' => (int) $p['id'], 'label' => null,
                'planned_start_at' => $start, 'duration_minutes' => $duration, 'is_pinned' => false, 'status' => 'pending'];
            $cursor   = Clock::addMinutes($start, $duration);
            $prevType = 'performance';
            $count++;
            if ($everyN > 0 && $count % $everyN === 0 && $i < count($perfs) - 1) {
                $addBreak('tea', $teaMin, null);
            }
        }

        // Merge with kept (locked or pinned) slots by time, then re-flow around pins.
        $all = [...$kept, ...$new];
        usort($all, static fn ($a, $b) => strcmp($a['planned_start_at'], $b['planned_start_at']) ?: (($a['id'] === null) <=> ($b['id'] === null)));
        $r     = $this->reflow($slamCopy, $all, 0);
        $check = $this->validate($slamCopy, $r['items']);

        service('activityLog')->log($actor, 'schedule.generated', ['slam_id' => $slamId, 'meta' => ['settings' => $settings, 'seed' => $seed, 'new_slots' => count($new)]]);

        return ['items' => $this->draftOut($r['items']), 'seed' => $seed, 'errors' => [...$r['errors'], ...$check['errors']], 'warnings' => $check['warnings']];
    }

    /** Deterministic Fisher–Yates with a Mulberry32 PRNG (same seed → same order). */
    public static function seededShuffle(array $list, int $seed): array
    {
        $state = $seed & 0xFFFFFFFF;
        $next  = static function () use (&$state): float {
            $state = ($state + 0x6D2B79F5) & 0xFFFFFFFF;
            $t     = $state;
            $t     = self::imul($t ^ ($t >> 15), $t | 1);
            $t     = ($t ^ ($t + self::imul($t ^ ($t >> 7), $t | 61))) & 0xFFFFFFFF;

            return (($t ^ ($t >> 14)) & 0xFFFFFFFF) / 4294967296;
        };
        $list = array_values($list);
        for ($i = count($list) - 1; $i > 0; $i--) {
            $j = (int) floor($next() * ($i + 1));
            [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
        }

        return $list;
    }

    private static function imul(int $a, int $b): int
    {
        $a &= 0xFFFFFFFF;
        $b &= 0xFFFFFFFF;
        $lo = (($a & 0xFFFF) * $b) & 0xFFFFFFFF;
        $hi = ((($a >> 16) * $b) & 0xFFFF) << 16;

        return ($lo + $hi) & 0xFFFFFFFF;
    }

    /** Normalised (DB time) items → client draft (ISO time). */
    public function draftOut(array $items): array
    {
        return array_map(static fn ($it) => ['planned_start_at' => Clock::iso($it['planned_start_at'])] + $it, $items);
    }

    /** @return list<array<string, mixed>> */
    public function snapshots(int $slamId): array
    {
        return Db::rows(
            'SELECT s.id, s.version, s.saved_at, s.note, a.name AS saved_by_name FROM schedule_snapshots s
             LEFT JOIN admins a ON a.id = s.saved_by WHERE s.slam_id = ? ORDER BY s.version DESC',
            [$slamId],
        );
    }

    /** Restore = a new draft from a snapshot (saved later as a new version). */
    public function restoreDraft(int $slamId, int $version, Actor $actor): array
    {
        $slam = service('slams')->find($slamId);
        if (self::isLive($slam)) {
            throw new DomainException('schedule_locked', 'History restore is only available before the slam goes live.', 409);
        }
        $snap = Db::row('SELECT * FROM schedule_snapshots WHERE slam_id = ? AND version = ?', [$slamId, $version])
            ?? throw new DomainException('not_found', 'Version not found.', 404);
        $items    = json_decode($snap['snapshot'], true) ?: [];
        $existing = array_flip(array_map(static fn ($r) => (int) $r['id'], Db::rows('SELECT id FROM schedule_items WHERE slam_id = ?', [$slamId])));
        foreach ($items as &$it) {
            if ($it['id'] !== null && ! isset($existing[(int) $it['id']])) {
                $it['id'] = null;
            }
            $it['status'] = $it['status'] === 'skipped' ? 'skipped' : 'pending';
        }
        unset($it);
        service('activityLog')->log($actor, 'schedule.snapshot_restored', ['slam_id' => $slamId, 'meta' => ['version' => $version]]);

        return $items;
    }

    /**
     * Copy slot durations, breaks and pinned times (shifted to this slam's date)
     * from another slam, applied to this slam's own performances in order.
     */
    public function copyStructure(int $toSlamId, int $fromSlamId, Actor $actor): array
    {
        $to   = service('slams')->find($toSlamId);
        $from = service('slams')->find($fromSlamId);
        $src  = $this->normalise($this->toDraft(Db::rows("SELECT * FROM schedule_items WHERE slam_id = ? AND status <> 'skipped' ORDER BY position, id", [$fromSlamId])));
        $shift = intdiv(Clock::parse($to['opens_at'])->getTimestamp() - Clock::parse($from['opens_at'])->getTimestamp(), 60);
        $perfs = Db::rows(
            "SELECT p.id FROM performances p LEFT JOIN schedule_items si ON si.performance_id = p.id
             WHERE p.slam_id = ? AND p.is_active = 1 ORDER BY COALESCE(si.position, 999999), p.id",
            [$toSlamId],
        );
        $first = $to['reviews_start_at'] ?? $to['checkin_closes_at'] ?? Clock::addMinutes($to['opens_at'], 60);
        $out   = [];
        $pi    = 0;
        foreach ($src as $it) {
            $pinnedStart = $it['is_pinned'] ? Clock::addMinutes($it['planned_start_at'], $shift) : null;
            if ($it['item_type'] === 'break') {
                $out[] = ['id' => null, 'item_type' => 'break', 'break_type' => $it['break_type'], 'performance_id' => null, 'label' => $it['label'],
                    'planned_start_at' => $pinnedStart ?? $first, 'duration_minutes' => $it['duration_minutes'], 'is_pinned' => $it['is_pinned'], 'status' => 'pending'];
            } elseif (isset($perfs[$pi])) {
                $out[] = ['id' => null, 'item_type' => 'performance', 'break_type' => null, 'performance_id' => (int) $perfs[$pi++]['id'], 'label' => null,
                    'planned_start_at' => $pinnedStart ?? $first, 'duration_minutes' => $it['duration_minutes'], 'is_pinned' => $it['is_pinned'], 'status' => 'pending'];
            }
        }
        for (; $pi < count($perfs); $pi++) {
            $out[] = ['id' => null, 'item_type' => 'performance', 'break_type' => null, 'performance_id' => (int) $perfs[$pi]['id'], 'label' => null,
                'planned_start_at' => $first, 'duration_minutes' => (int) $to['default_review_minutes'], 'is_pinned' => false, 'status' => 'pending'];
        }
        if ($out !== [] && ! $out[0]['is_pinned']) {
            $out[0]['planned_start_at'] = $first;
        }
        $r = $this->reflow($to, $out, 0);
        // Keep ids of this slam's existing pending slots for the same performance so a save updates them.
        $existing = [];
        foreach (Db::rows("SELECT id, performance_id FROM schedule_items WHERE slam_id = ? AND item_type = 'performance'", [$toSlamId]) as $e) {
            $existing[(int) $e['performance_id']] = (int) $e['id'];
        }
        foreach ($r['items'] as &$it) {
            if ($it['performance_id'] !== null && isset($existing[$it['performance_id']])) {
                $it['id'] = $existing[$it['performance_id']];
            }
        }
        unset($it);
        service('activityLog')->log($actor, 'schedule.copied_from_slam', ['slam_id' => $toSlamId, 'meta' => ['from_slam_id' => $fromSlamId]]);

        return ['items' => $this->draftOut($r['items']), 'errors' => $r['errors']];
    }
}
