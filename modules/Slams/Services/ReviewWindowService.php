<?php

declare(strict_types=1);

namespace Modules\Slams\Services;

use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;

/**
 * Review windows (one per performance opening) and the slam-level edit window.
 * effective close = closes_at + extended_minutes_total, never later than the
 * slam's hard stop (slams.closes_at). Correctness comes from comparing this with
 * the server clock on every read and write, not from the cron job.
 *
 * Callers must hold the slam row lock (Db::lockSlam) for every write here.
 */
class ReviewWindowService
{
    public static function effectiveClose(array $window, array $slam): string
    {
        $eff = Clock::addMinutes($window['closes_at'], (int) $window['extended_minutes_total']);

        return Clock::parse($eff) > Clock::parse($slam['closes_at']) ? $slam['closes_at'] : $eff;
    }

    public static function isRunning(?array $window, array $slam): bool
    {
        return $window !== null && $window['closed_at'] === null
            && Clock::now() < Clock::parse(self::effectiveClose($window, $slam));
    }

    /** The open (not yet closed) review window of a slam, if any. */
    public function openWindow(int $slamId): ?array
    {
        return Db::row(
            'SELECT rw.*, si.performance_id, si.slam_id FROM review_windows rw
             JOIN schedule_items si ON si.id = rw.schedule_item_id
             WHERE si.slam_id = ? AND rw.closed_at IS NULL ORDER BY rw.id DESC LIMIT 1',
            [$slamId],
        );
    }

    public function openRevisionWindow(int $slamId): ?array
    {
        return Db::row('SELECT * FROM revision_windows WHERE slam_id = ? AND closed_at IS NULL ORDER BY id DESC LIMIT 1', [$slamId]);
    }

    public function latestRevisionWindow(int $slamId): ?array
    {
        return Db::row('SELECT * FROM revision_windows WHERE slam_id = ? ORDER BY id DESC LIMIT 1', [$slamId]);
    }

    /**
     * Resolve the requested close time: N minutes from now, or an absolute UTC time.
     * Capped at the slam's hard stop.
     */
    public function resolveCloseTime(array $slam, ?int $minutes, ?string $closesAt): string
    {
        $now = Clock::now();
        if ($closesAt !== null && $closesAt !== '') {
            $close = Clock::parse($closesAt);
        } else {
            $minutes ??= (int) $slam['default_review_minutes'];
            if ($minutes < 1 || $minutes > 240) {
                throw new DomainException('invalid_duration', 'Duration must be 1–240 minutes.');
            }
            $close = $now->modify("+{$minutes} minutes");
        }
        if ($close <= $now) {
            throw new DomainException('invalid_close_time', 'The close time must be in the future.');
        }
        $hard = Clock::parse($slam['closes_at']);
        if ($close > $hard) {
            $close = $hard;
        }
        if ($close <= $now) {
            throw new DomainException('hard_stop', 'The slam has reached its hard stop.');
        }

        return Clock::db($close);
    }

    /** Open a review window for a performance item (status → open). */
    public function open(array $slam, array $item, string $closesAt, Actor $actor, string $trigger): int
    {
        $db = db_connect();
        if ($item['item_type'] !== 'performance' || $item['status'] !== 'pending') {
            throw new DomainException('item_not_pending', 'Only a pending performance can be opened.');
        }
        if ($this->openWindow((int) $slam['id']) !== null) {
            throw new DomainException('window_already_open', 'Another review window is already open.');
        }
        $now = Clock::db();
        $db->table('review_windows')->insert([
            'schedule_item_id' => $item['id'], 'opened_at' => $now, 'closes_at' => $closesAt, 'extended_minutes_total' => 0,
        ]);
        $id = (int) $db->insertID();
        // Opening the review also means the performance has started (if not started by hand already).
        $db->query('UPDATE schedule_items SET status = ?, actual_start_at = ?, performing_started_at = COALESCE(performing_started_at, ?), updated_at = ? WHERE id = ?', ['open', $now, $now, $now, $item['id']]);
        service('activityLog')->log($actor, 'window.opened', [
            'slam_id' => (int) $slam['id'], 'target_type' => 'review_window', 'target_id' => $id,
            'new' => ['schedule_item_id' => (int) $item['id'], 'closes_at' => $closesAt], 'meta' => ['trigger' => $trigger],
        ]);

        return $id;
    }

    /** Extend (positive) or shorten (negative) the open review window. */
    public function extend(array $slam, int $minutes, Actor $actor): array
    {
        $w = $this->openWindow((int) $slam['id']);
        if ($w === null || ! self::isRunning($w, $slam)) {
            throw new DomainException('no_open_window', 'No review window is open.');
        }
        $newTotal = (int) $w['extended_minutes_total'] + $minutes;
        $newEff   = Clock::addMinutes($w['closes_at'], $newTotal);
        if ($minutes === 0 || abs($minutes) > 120) {
            throw new DomainException('invalid_minutes', 'Extension must be between -120 and 120 minutes (not 0).');
        }
        if (Clock::parse($newEff) <= Clock::now()) {
            throw new DomainException('invalid_minutes', 'Shortening would close the window in the past; close it instead.');
        }
        if ($minutes > 0 && Clock::parse(self::effectiveClose($w, $slam)) >= Clock::parse($slam['closes_at'])) {
            throw new DomainException('hard_stop', 'The window already ends at the slam hard stop.');
        }
        $db = db_connect();
        $db->table('review_windows')->where('id', $w['id'])->update(['extended_minutes_total' => $newTotal]);
        $db->table('review_extensions')->insert([
            'review_window_id' => $w['id'], 'minutes' => $minutes, 'by_admin_id' => $actor->isAdmin() ? $actor->id : null, 'at' => Clock::db(),
        ]);
        $w['extended_minutes_total'] = $newTotal;
        $eff = self::effectiveClose($w, $slam);
        service('activityLog')->log($actor, 'window.extended', [
            'slam_id' => (int) $slam['id'], 'target_type' => 'review_window', 'target_id' => (int) $w['id'],
            'meta' => ['minutes' => $minutes, 'new_close' => $eff],
        ]);

        return $w;
    }

    /** Close the open review window (timer, manual, or hard stop). */
    public function close(array $slam, Actor $actor, string $reason, ?string $note = null): ?array
    {
        $w = $this->openWindow((int) $slam['id']);
        if ($w === null) {
            return null;
        }
        $db = db_connect();
        // A timer close is stamped at the deadline itself, so the changeover is
        // measured from the real end even if the tick runs a little late.
        $closedAt = $reason === 'timer' ? self::effectiveClose($w, $slam) : Clock::db();
        if (Clock::parse($closedAt) > Clock::now()) {
            $closedAt = Clock::db();
        }
        $db->table('review_windows')->where('id', $w['id'])->update(['closed_at' => $closedAt, 'closed_reason' => $reason]);
        // Closing the review ends the performance too, if it was still running.
        $db->query('UPDATE schedule_items SET status = ?, performing_ended_at = COALESCE(performing_ended_at, ?), updated_at = ? WHERE id = ?', ['closed', $closedAt, Clock::db(), $w['schedule_item_id']]);
        $action = $reason === 'manual' ? 'window.closed.manual' : 'window.closed.timer';
        service('activityLog')->log($actor, $action, [
            'slam_id' => (int) $slam['id'], 'target_type' => 'review_window', 'target_id' => (int) $w['id'],
            'reason' => $reason === 'hard_stop' ? 'hard_stop' : $note,
            'meta' => ['closed_at' => $closedAt, 'reviews' => (int) Db::value('SELECT COUNT(*) FROM reviews WHERE review_window_id = ?', [$w['id']])],
        ]);

        return $w;
    }

    public function openRevision(array $slam, string $closesAt, Actor $actor, string $trigger): int
    {
        if ($this->openRevisionWindow((int) $slam['id']) !== null) {
            throw new DomainException('window_already_open', 'The edit window is already open.');
        }
        if ($this->openWindow((int) $slam['id']) !== null) {
            throw new DomainException('window_already_open', 'A review window is still open.');
        }
        $db = db_connect();
        $db->table('revision_windows')->insert([
            'slam_id' => $slam['id'], 'opened_at' => Clock::db(), 'closes_at' => $closesAt, 'extended_minutes_total' => 0,
        ]);
        $id = (int) $db->insertID();
        service('activityLog')->log($actor, 'revision.opened', [
            'slam_id' => (int) $slam['id'], 'target_type' => 'revision_window', 'target_id' => $id,
            'new' => ['closes_at' => $closesAt], 'meta' => ['trigger' => $trigger],
        ]);

        return $id;
    }

    public function extendRevision(array $slam, int $minutes, Actor $actor): void
    {
        $w = $this->openRevisionWindow((int) $slam['id']);
        if ($w === null || ! self::isRunning($w, $slam)) {
            throw new DomainException('no_open_window', 'The edit window is not open.');
        }
        if ($minutes === 0 || abs($minutes) > 120) {
            throw new DomainException('invalid_minutes', 'Extension must be between -120 and 120 minutes (not 0).');
        }
        $newTotal = (int) $w['extended_minutes_total'] + $minutes;
        if (Clock::parse(Clock::addMinutes($w['closes_at'], $newTotal)) <= Clock::now()) {
            throw new DomainException('invalid_minutes', 'Shortening would close the window in the past; close it instead.');
        }
        $db = db_connect();
        $db->table('revision_windows')->where('id', $w['id'])->update(['extended_minutes_total' => $newTotal]);
        $db->table('review_extensions')->insert([
            'revision_window_id' => $w['id'], 'minutes' => $minutes, 'by_admin_id' => $actor->isAdmin() ? $actor->id : null, 'at' => Clock::db(),
        ]);
        $w['extended_minutes_total'] = $newTotal;
        service('activityLog')->log($actor, 'revision.extended', [
            'slam_id' => (int) $slam['id'], 'target_type' => 'revision_window', 'target_id' => (int) $w['id'],
            'meta' => ['minutes' => $minutes, 'new_close' => self::effectiveClose($w, $slam)],
        ]);
    }

    public function closeRevision(array $slam, Actor $actor, string $reason): void
    {
        $w = $this->openRevisionWindow((int) $slam['id']);
        if ($w === null) {
            return;
        }
        $closedAt = $reason === 'timer' ? self::effectiveClose($w, $slam) : Clock::db();
        if (Clock::parse($closedAt) > Clock::now()) {
            $closedAt = Clock::db();
        }
        db_connect()->table('revision_windows')->where('id', $w['id'])->update(['closed_at' => $closedAt, 'closed_reason' => $reason]);
        service('activityLog')->log($actor, $reason === 'manual' ? 'revision.closed.manual' : 'revision.closed.timer', [
            'slam_id' => (int) $slam['id'], 'target_type' => 'revision_window', 'target_id' => (int) $w['id'],
            'reason' => $reason === 'hard_stop' ? 'hard_stop' : null,
        ]);
    }
}
