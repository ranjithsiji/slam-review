<?php

declare(strict_types=1);

namespace Modules\Slams\Services;

use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;

/**
 * The slam timeline state machine. Every status change goes through transition();
 * controllers never write slams.status. Timer-driven steps are applied by tick(),
 * which runs lazily on live polls / admin requests and from the per-minute cron.
 * Admin overrides take the same row lock, so a timer and a click cannot both apply.
 */
class SlamPhaseService
{
    public const ORDER = [
        'draft', 'scheduled', 'open', 'checkin_open', 'checkin_closed', 'reviewing', 'reviews_complete',
        'revision', 'revision_closed', 'closed', 'marks_calculated', 'ended',
    ];

    /** Phases in which the hard stop at closes_at applies. */
    public const LIVE = ['open', 'checkin_open', 'checkin_closed', 'reviewing', 'reviews_complete', 'revision', 'revision_closed'];

    /**
     * Allowed moves. X → closed from any live phase other than revision_closed is
     * only allowed with trigger hard_stop. The only backward moves are reopening
     * the check-in gate and (outside this table) recalculating marks.
     */
    public const TRANSITIONS = [
        'draft'            => ['scheduled'],
        'scheduled'        => ['open'],
        'open'             => ['checkin_open', 'closed'],
        'checkin_open'     => ['checkin_closed', 'closed'],
        'checkin_closed'   => ['checkin_open', 'reviewing', 'closed'],
        'reviewing'        => ['reviews_complete', 'closed'],
        'reviews_complete' => ['revision', 'revision_closed', 'closed'],
        'revision'         => ['revision_closed', 'closed'],
        'revision_closed'  => ['closed'],
        'closed'           => ['marks_calculated'],
        'marks_calculated' => ['ended'],
        'ended'            => [],
    ];

    public const LABELS = [
        'draft' => 'Setup (draft)', 'scheduled' => 'Setup (ready)', 'open' => 'Slam Open', 'checkin_open' => 'Gates Open',
        'checkin_closed' => 'Gate Close', 'reviewing' => 'Reviewing', 'reviews_complete' => 'All Review Close',
        'revision' => 'Review Edit', 'revision_closed' => 'Edit Close', 'closed' => 'Slam Close',
        'marks_calculated' => 'Marks Calculated', 'ended' => 'Event Ends',
    ];

    private ReviewWindowService $windows;

    public function __construct(?ReviewWindowService $windows = null)
    {
        $this->windows = $windows ?? new ReviewWindowService();
    }

    public function windows(): ReviewWindowService
    {
        return $this->windows;
    }

    public static function canTransition(string $from, string $to, string $trigger): bool
    {
        if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            return false;
        }
        if ($to === 'closed' && $from !== 'revision_closed' && $trigger !== 'hard_stop') {
            return false;
        }
        if ($from === 'checkin_closed' && $to === 'checkin_open' && $trigger !== 'manual_override') {
            return false; // reopening the gate is an admin action only
        }

        return true;
    }

    public static function index(string $status): int
    {
        return (int) array_search($status, self::ORDER, true);
    }

    /**
     * Apply one status change. Caller holds the slam lock inside a transaction.
     *
     * @return array<string, mixed> updated slam row
     */
    public function transition(array $slam, string $to, string $trigger, Actor $actor, ?string $note = null): array
    {
        $from = $slam['status'];
        if (! self::canTransition($from, $to, $trigger)) {
            throw new DomainException('forbidden_transition', sprintf('Cannot move from %s to %s.', self::LABELS[$from] ?? $from, self::LABELS[$to] ?? $to), 409);
        }
        $db   = db_connect();
        $now  = Clock::db();
        $data = ['status' => $to, 'phase_changed_at' => $now, 'updated_at' => $now];
        if ($to === 'open' && $slam['activated_at'] === null) {
            $data['activated_at'] = $now;
        }
        if ($to === 'ended') {
            $data['ended_at'] = $now;
        }
        $db->table('slams')->where('id', $slam['id'])->update($data);
        $db->table('slam_phase_log')->insert([
            'slam_id' => $slam['id'], 'from_status' => $from, 'to_status' => $to, 'changed_at' => $now,
            'changed_by_type' => $actor->phaseActorType(), 'changed_by_id' => $actor->isAdmin() ? $actor->id : null,
            'trigger' => $trigger, 'note' => $note !== null ? mb_substr($note, 0, 500) : null,
        ]);
        $log = service('activityLog');
        $log->log($actor, 'slam.phase_changed', [
            'slam_id' => (int) $slam['id'], 'target_type' => 'slam', 'target_id' => (int) $slam['id'],
            'old' => ['status' => $from], 'new' => ['status' => $to], 'meta' => ['trigger' => $trigger], 'reason' => $note,
        ]);

        $slam = array_merge($slam, $data);
        $this->onEnter($slam, $from, $to, $trigger, $actor);
        service('publicCache')->purge((int) $slam['id']);

        return $slam;
    }

    /** Side effects of entering a phase. */
    private function onEnter(array $slam, string $from, string $to, string $trigger, Actor $actor): void
    {
        $log = service('activityLog');
        $sid = (int) $slam['id'];
        switch ($to) {
            case 'open':
                // The plan as it stands when the slam goes live is the baseline "behind/ahead" is measured against.
                db_connect()->query('UPDATE schedule_items SET original_start_at = planned_start_at WHERE slam_id = ?', [$sid]);
                $log->log($actor, 'slam.activated', ['slam_id' => $sid, 'target_type' => 'slam', 'target_id' => $sid]);
                break;
            case 'checkin_open':
                $log->log($actor, $from === 'checkin_closed' ? 'checkin_gate.reopened' : 'checkin_gate.opened', ['slam_id' => $sid, 'target_type' => 'slam', 'target_id' => $sid]);
                break;
            case 'checkin_closed':
                $log->log($actor, 'checkin_gate.closed', ['slam_id' => $sid, 'target_type' => 'slam', 'target_id' => $sid]);
                break;
            case 'revision_closed':
                $log->log($actor, 'scores.locked', ['slam_id' => $sid, 'target_type' => 'slam', 'target_id' => $sid, 'reason' => $from === 'reviews_complete' ? 'edit skipped' : 'edit closed']);
                break;
            case 'closed':
                // Everything still open ends now.
                $reason = $trigger === 'hard_stop' ? 'hard_stop' : 'manual';
                $this->windows->close($slam, $actor, $reason);
                $this->windows->closeRevision($slam, $actor, $reason);
                $this->endRunningBreak($slam);
                if ($trigger === 'hard_stop') {
                    $log->log($actor, 'slam.hard_stop', ['slam_id' => $sid, 'target_type' => 'slam', 'target_id' => $sid, 'meta' => ['from' => $from]]);
                    if (in_array($from, ['open', 'checkin_open', 'checkin_closed', 'reviewing', 'reviews_complete', 'revision'], true)) {
                        $log->log($actor, 'scores.locked', ['slam_id' => $sid, 'target_type' => 'slam', 'target_id' => $sid, 'reason' => 'hard_stop']);
                    }
                }
                service('checkins')->autoCheckoutAll($sid, $actor);
                $log->log($actor, 'slam.completed', ['slam_id' => $sid, 'target_type' => 'slam', 'target_id' => $sid]);
                break;
            case 'ended':
                db_connect()->table('mark_runs')->where('slam_id', $sid)->where('is_current', 1)->update(['is_final' => 1]);
                $log->log($actor, 'event.ended', ['slam_id' => $sid, 'target_type' => 'slam', 'target_id' => $sid]);
                break;
        }
    }

    // ------------------------------------------------------------------
    // Automation
    // ------------------------------------------------------------------

    /**
     * Apply whatever is due for one slam.
     *
     * Poll path ($blocking = false): compare the clock with the cached next-due
     * time and return at once if nothing is due; otherwise try a non-blocking
     * named lock so exactly one request does the work while the others just read
     * state. Cron and admin requests use the blocking path.
     *
     * @return list<string> applied step names
     */
    public function tick(int $slamId, bool $blocking = false): array
    {
        $cache = cache();
        $key   = service('publicCache')->key('tickcheck', $slamId);
        $now   = Clock::now()->getTimestamp();
        if (! $blocking) {
            $nextAt = $cache->get($key);
            if (is_int($nextAt) && $now < $nextAt) {
                return [];
            }
        }

        $slam = Db::row('SELECT * FROM slams WHERE id = ?', [$slamId]);
        if ($slam === null || $slam['paused_at'] !== null) {
            return []; // a paused slam applies nothing by itself
        }
        if ($this->nextDue($slam) === null) {
            // Cache when the next thing can become due (re-check at least once a minute).
            $at = $this->nextDueAt($slam);
            $nextAt = min($at?->getTimestamp() ?? PHP_INT_MAX, $now + 60);
            $cache->save($key, $nextAt, max(1, $nextAt - $now));

            return [];
        }

        $db       = db_connect();
        $lockName = 'slam_tick_' . $slamId;
        $got      = (int) Db::value('SELECT GET_LOCK(?, ?)', [$lockName, $blocking ? 10 : 0]);
        if ($got !== 1) {
            return []; // another request is applying it right now
        }
        try {
            $applied = $this->applyAllDue($slamId);
        } finally {
            $db->query('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
        $cache->delete($key);
        if ($applied !== []) {
            service('publicCache')->purge($slamId);
        }

        return $applied;
    }

    /** @return list<string> */
    private function applyAllDue(int $slamId): array
    {
        return Db::tx(function () use ($slamId): array {
            $applied = [];
            for ($i = 0; $i < 30; $i++) {
                $slam = Db::lockSlam($slamId);
                $due  = $this->nextDue($slam);
                if ($due === null) {
                    break;
                }
                $this->applyDue($slam, $due);
                $applied[] = $due['step'];
                if ($due['step'] === 'readiness_alert' || $due['step'] === 'calc_marks') {
                    break;
                }
            }

            return $applied;
        });
    }

    /**
     * Earliest future moment something may become due (for the poll cache), or
     * null if nothing is time-driven. Mirrors the checks in nextDue().
     */
    public function nextDueAt(array $slam): ?\DateTimeImmutable
    {
        if ($slam['paused_at'] !== null) {
            return null;
        }
        $sid  = (int) $slam['id'];
        $cand = [];
        if (in_array($slam['status'], self::LIVE, true)) {
            $cand[] = Clock::parse($slam['closes_at']);
        }
        if (($w = $this->windows->openWindow($sid)) !== null) {
            $cand[] = Clock::parse(ReviewWindowService::effectiveClose($w, $slam));
        }
        if (($b = $this->runningBreak($sid)) !== null) {
            $cand[] = Clock::parse($b['ends_at']);
        }
        if ($slam['status'] === 'revision' && ($rw = $this->windows->openRevisionWindow($sid)) !== null) {
            $cand[] = Clock::parse(ReviewWindowService::effectiveClose($rw, $slam));
        }
        if ($slam['auto_mode'] === 'auto' && (int) $slam['hold_next_step'] === 0) {
            $add = static function (?string $v) use (&$cand): void {
                if ($v !== null) {
                    $cand[] = Clock::parse($v);
                }
            };
            match ($slam['status']) {
                'scheduled'        => $add($slam['opens_at']),
                'open'             => $add($slam['checkin_opens_at']),
                'checkin_open'     => $add($slam['checkin_closes_at']),
                'revision_closed'  => $add(Clock::addMinutes($slam['phase_changed_at'] ?? Clock::db(), (int) $slam['close_grace_minutes'])),
                'marks_calculated' => $add($slam['event_ends_at']),
                default            => null,
            };
            $pm   = (int) ($slam['presentation_minutes'] ?? 0);
            $perf = $pm > 0 && in_array($slam['status'], ['checkin_closed', 'reviewing'], true) ? $this->performingItem($sid) : null;
            if ($perf !== null) {
                if ($perf['status'] === 'pending' && $w === null) {
                    $cand[] = Clock::parse(Clock::addMinutes($perf['performing_started_at'], intdiv($pm, 2)));
                }
                $cand[] = Clock::parse(Clock::addMinutes($perf['performing_started_at'], $pm));
            } elseif (in_array($slam['status'], ['checkin_closed', 'reviewing'], true) && $w === null && $b === null
                && ($next = $this->nextPendingItem($sid)) !== null) {
                $cand[] = Clock::parse($this->dueAt($slam, $next));
            }
        }

        return $cand === [] ? null : min($cand);
    }

    /** Tick every slam that may have something due (cron). */
    public function tickAll(): array
    {
        $out = [];
        $ids = Db::rows("SELECT id FROM slams WHERE status NOT IN ('draft','ended')");
        foreach ($ids as $r) {
            try {
                $steps = $this->tick((int) $r['id'], true);
                if ($steps !== []) {
                    $out[(int) $r['id']] = $steps;
                }
            } catch (\Throwable $e) {
                $ref = bin2hex(random_bytes(4));
                log_message('error', "[{$ref}] tick failed for slam {$r['id']}: " . $e->getMessage());
                service('activityLog')->log(Actor::system(), 'system.error', ['slam_id' => (int) $r['id'], 'outcome' => 'failure', 'meta' => ['ref' => $ref, 'where' => 'tick']]);
            }
        }

        return $out;
    }

    /**
     * What should happen next for this slam right now, or null. Deadlines (window
     * ends, hard stop) always apply; starting a new step needs auto mode and no hold.
     *
     * @return array{step: string, item?: array<string, mixed>, trigger?: string, due_at?: string}|null
     */
    public function nextDue(array $slam): ?array
    {
        $now    = Clock::now();
        $status = $slam['status'];
        $sid    = (int) $slam['id'];

        if (in_array($status, self::LIVE, true) && $now >= Clock::parse($slam['closes_at'])) {
            return ['step' => 'hard_stop'];
        }

        $window = $this->windows->openWindow($sid);
        if ($window !== null && $now >= Clock::parse(ReviewWindowService::effectiveClose($window, $slam))) {
            return ['step' => 'close_window'];
        }
        $break = $this->runningBreak($sid);
        if ($break !== null && $now >= Clock::parse($break['ends_at'])) {
            return ['step' => 'end_break', 'item' => $break];
        }
        if ($status === 'revision') {
            $rw = $this->windows->openRevisionWindow($sid);
            if ($rw === null || $now >= Clock::parse(ReviewWindowService::effectiveClose($rw, $slam))) {
                return ['step' => 'close_revision'];
            }
        }

        if ($slam['auto_mode'] !== 'auto' || (int) $slam['hold_next_step'] === 1) {
            return null;
        }

        // Automatic presentation timing: review opens at half time, the presentation ends at full time.
        $pm = (int) ($slam['presentation_minutes'] ?? 0);
        if ($pm > 0 && in_array($status, ['checkin_closed', 'reviewing'], true) && ($perf = $this->performingItem($sid)) !== null) {
            $half = Clock::addMinutes($perf['performing_started_at'], intdiv($pm, 2));
            $end  = Clock::addMinutes($perf['performing_started_at'], $pm);
            if ($perf['status'] === 'pending' && $window === null && $now >= Clock::parse($half)) {
                return ['step' => 'open_review_mid', 'item' => $perf, 'due_at' => $half, 'trigger' => 'timer'];
            }
            if ($now >= Clock::parse($end)) {
                return ['step' => 'end_performance', 'item' => $perf];
            }
        }

        switch ($status) {
            case 'scheduled':
                if ($now < Clock::parse($slam['opens_at'])) {
                    return null;
                }
                if (service('slams')->readiness($sid) === []) {
                    return ['step' => 'open_slam'];
                }
                $alerted = $slam['readiness_alerted_at'];

                return ($alerted === null || Clock::diffSeconds($alerted, Clock::db()) >= 600) ? ['step' => 'readiness_alert'] : null;

            case 'open':
                return ($slam['checkin_opens_at'] !== null && $now >= Clock::parse($slam['checkin_opens_at'])) ? ['step' => 'gate_open'] : null;

            case 'checkin_open':
                return ($slam['checkin_closes_at'] !== null && $now >= Clock::parse($slam['checkin_closes_at'])) ? ['step' => 'gate_close'] : null;

            case 'checkin_closed':
            case 'reviewing':
                if ($window !== null || $break !== null || ($pm > 0 && $this->performingItem($sid) !== null)) {
                    return null;
                }
                $next = $this->nextPendingItem($sid);
                if ($next === null) {
                    return $status === 'reviewing' ? ['step' => 'reviews_complete'] : null;
                }
                $dueAt = $this->dueAt($slam, $next);
                if ($now < Clock::parse($dueAt)) {
                    return null;
                }

                return [
                    'step'    => $next['item_type'] === 'break' ? 'start_break' : ($pm > 0 ? 'start_performance' : 'open_item'),
                    'item'    => $next,
                    'due_at'  => $dueAt,
                    'trigger' => $dueAt === $next['planned_start_at'] ? 'timer' : 'auto',
                ];

            case 'reviews_complete':
                return ['step' => (int) $slam['edit_window_minutes'] === 0 ? 'skip_revision' : 'open_revision'];

            case 'revision_closed':
                $since = $slam['phase_changed_at'] ?? Clock::db();

                return Clock::diffSeconds($since, Clock::db()) >= (int) $slam['close_grace_minutes'] * 60 ? ['step' => 'close_slam'] : null;

            case 'closed':
                return ['step' => 'calc_marks'];

            case 'marks_calculated':
                return ($slam['event_ends_at'] !== null && $now >= Clock::parse($slam['event_ends_at'])) ? ['step' => 'end_event'] : null;
        }

        return null;
    }

    private function applyDue(array $slam, array $due): void
    {
        $sys = Actor::system();
        $log = service('activityLog');
        $sid = (int) $slam['id'];

        switch ($due['step']) {
            case 'hard_stop':
                $this->transition($slam, 'closed', 'hard_stop', $sys, 'Hard stop at slam close time');
                break;
            case 'close_window':
                $this->windows->close($slam, $sys, 'timer');
                break;
            case 'end_break':
                db_connect()->table('schedule_items')->where('id', $due['item']['id'])->update(['status' => 'closed', 'updated_at' => Clock::db()]);
                break;
            case 'close_revision':
                $this->windows->closeRevision($slam, $sys, 'timer');
                $this->transition($slam, 'revision_closed', 'timer', $sys);
                break;
            case 'open_slam':
                $this->transition($slam, 'open', 'timer', $sys);
                break;
            case 'readiness_alert':
                db_connect()->table('slams')->where('id', $sid)->update(['readiness_alerted_at' => Clock::db()]);
                $log->log($sys, 'phase.alert', ['slam_id' => $sid, 'target_type' => 'slam', 'target_id' => $sid, 'reason' => 'readiness_failed', 'meta' => ['missing' => service('slams')->readiness($sid)]]);
                break;
            case 'gate_open':
                $this->transition($slam, 'checkin_open', 'timer', $sys);
                break;
            case 'gate_close':
                $this->transition($slam, 'checkin_closed', 'timer', $sys);
                break;
            case 'open_review_mid':
                // Half time: the review opens for the slot's review length, counted from now.
                $close = $this->windows->resolveCloseTime($slam, (int) $due['item']['duration_minutes'], null);
                $this->windows->open($slam, $due['item'], $close, $sys, 'timer');
                break;
            case 'end_performance':
                db_connect()->table('schedule_items')->where('id', $due['item']['id'])->update(['performing_ended_at' => Clock::db(), 'updated_at' => Clock::db()]);
                $log->log($sys, 'performance.ended', ['slam_id' => $sid, 'target_type' => 'schedule_item', 'target_id' => (int) $due['item']['id'], 'meta' => ['trigger' => 'timer']]);
                break;
            case 'start_performance':
                $this->skipBreaksBefore($sid, $due['item'], $sys);
                if ($slam['status'] === 'checkin_closed') {
                    $slam = $this->transition($slam, 'reviewing', $due['trigger'], $sys, 'First performance started');
                }
                $this->beginPerformance($slam, $due['item'], $sys);
                break;
            case 'open_item':
            case 'start_break':
                $lateBy = Clock::diffSeconds($due['due_at'], Clock::db());
                if ($lateBy > 120) {
                    // Server or cron was down: resume from now, never open a window whose time passed.
                    $log->log($sys, 'phase.alert', ['slam_id' => $sid, 'reason' => 'late_resume', 'meta' => ['late_by_seconds' => $lateBy, 'schedule_item_id' => (int) $due['item']['id']]]);
                }
                if ($due['step'] === 'start_break') {
                    $this->startBreak($slam, $due['item']);
                } else {
                    $close = $this->windows->resolveCloseTime($slam, (int) $due['item']['duration_minutes'], null);
                    if ($slam['status'] === 'checkin_closed') {
                        $slam = $this->transition($slam, 'reviewing', $due['trigger'], $sys);
                    }
                    $this->windows->open($slam, $due['item'], $close, $sys, $due['trigger']);
                }
                break;
            case 'reviews_complete':
                $this->transition($slam, 'reviews_complete', 'auto', $sys);
                break;
            case 'open_revision':
                $close = $this->windows->resolveCloseTime($slam, (int) $slam['edit_window_minutes'], null);
                $this->windows->openRevision($slam, $close, $sys, 'auto');
                $this->transition($slam, 'revision', 'auto', $sys);
                break;
            case 'skip_revision':
                $this->transition($slam, 'revision_closed', 'auto', $sys, 'Edit window is 0 minutes (skipped)');
                break;
            case 'close_slam':
                $this->transition($slam, 'closed', 'auto', $sys);
                break;
            case 'calc_marks':
                service('markRuns')->calculateLocked($slam, $sys, 'Automatic run after Slam Close', 'auto');
                break;
            case 'end_event':
                $this->transition($slam, 'ended', 'timer', $sys);
                break;
        }
    }

    // ------------------------------------------------------------------
    // Schedule helpers used by the automation and the projection
    // ------------------------------------------------------------------

    public function nextPendingItem(int $slamId): ?array
    {
        return Db::row(
            "SELECT si.* FROM schedule_items si
             LEFT JOIN performances p ON p.id = si.performance_id
             WHERE si.slam_id = ? AND si.status = 'pending' AND (si.item_type = 'break' OR p.is_active = 1)
             ORDER BY si.position, si.id LIMIT 1",
            [$slamId],
        );
    }

    public function runningBreak(int $slamId): ?array
    {
        return Db::row("SELECT * FROM schedule_items WHERE slam_id = ? AND item_type = 'break' AND status = 'open' LIMIT 1", [$slamId]);
    }

    /**
     * When $item may start: the later of its planned start and the end of the
     * previous item that actually ran (+ changeover between two performances).
     */
    public function dueAt(array $slam, array $item): string
    {
        $prev = Db::row(
            "SELECT si.*, (SELECT MAX(rw.closed_at) FROM review_windows rw WHERE rw.schedule_item_id = si.id) AS window_closed_at
             FROM schedule_items si WHERE si.slam_id = ? AND si.status = 'closed' AND si.position <= ? AND si.id <> ?
             ORDER BY si.position DESC, si.id DESC LIMIT 1",
            [$slam['id'], $item['position'], $item['id']],
        );
        $due = $item['planned_start_at'];
        if ($prev !== null) {
            if ($prev['item_type'] === 'performance' && $prev['window_closed_at'] !== null) {
                $gap  = $item['item_type'] === 'performance' ? (int) $slam['changeover_minutes'] : 0;
                $prevEnd = Clock::addMinutes($prev['window_closed_at'], $gap);
            } else {
                $prevEnd = $prev['ends_at'] ?? $prev['planned_start_at'];
            }
            if (Clock::parse($prevEnd) > Clock::parse($due)) {
                $due = $prevEnd;
            }
        }

        return $due;
    }

    private function startBreak(array $slam, array $item): void
    {
        $now = Clock::db();
        db_connect()->table('schedule_items')->where('id', $item['id'])->update([
            'status' => 'open', 'actual_start_at' => $now, 'ends_at' => Clock::addMinutes($now, (int) $item['duration_minutes']), 'updated_at' => $now,
        ]);
    }

    private function endRunningBreak(array $slam): void
    {
        db_connect()->table('schedule_items')->where('slam_id', $slam['id'])->where('item_type', 'break')->where('status', 'open')
            ->update(['status' => 'closed', 'ends_at' => Clock::db(), 'updated_at' => Clock::db()]);
    }

    /**
     * Projected timeline for the control panel / public page: each remaining item's
     * projected start and end if the event continues from now.
     *
     * @return array{items: list<array<string,mixed>>, late_minutes: int, projected_end: ?string, past_hard_stop: bool}
     */
    public function projection(array $slam): array
    {
        $sid   = (int) $slam['id'];
        $items = Db::rows(
            "SELECT si.*, p.is_active AS perf_active,
                    (SELECT rw.opened_at FROM review_windows rw WHERE rw.schedule_item_id = si.id ORDER BY rw.id DESC LIMIT 1) AS window_opened_at,
                    (SELECT rw.closed_at FROM review_windows rw WHERE rw.schedule_item_id = si.id ORDER BY rw.id DESC LIMIT 1) AS window_closed_at,
                    (SELECT rw.closes_at FROM review_windows rw WHERE rw.schedule_item_id = si.id ORDER BY rw.id DESC LIMIT 1) AS window_closes_at,
                    (SELECT rw.extended_minutes_total FROM review_windows rw WHERE rw.schedule_item_id = si.id ORDER BY rw.id DESC LIMIT 1) AS window_ext
             FROM schedule_items si LEFT JOIN performances p ON p.id = si.performance_id
             WHERE si.slam_id = ? ORDER BY si.position, si.id",
            [$sid],
        );
        $now      = Clock::now();
        $cursor   = null;   // end of the last item that ran / is running
        $prevType = null;
        $late     = 0;
        $firstPendingSeen = false;
        foreach ($items as &$it) {
            $plannedEnd = Clock::addMinutes($it['planned_start_at'], (int) $it['duration_minutes']);
            if ($it['status'] === 'skipped' || ($it['item_type'] === 'performance' && (int) $it['perf_active'] === 0)) {
                $it['projected_start_at'] = null;
                $it['projected_end_at']   = null;
                continue;
            }
            if ($it['status'] === 'closed') {
                $start = $it['window_opened_at'] ?? $it['actual_start_at'] ?? $it['planned_start_at'];
                $end   = $it['window_closed_at'] ?? $it['ends_at'] ?? $plannedEnd;
            } elseif ($it['status'] === 'open') {
                $start = $it['window_opened_at'] ?? $it['actual_start_at'];
                $end   = $it['item_type'] === 'performance'
                    ? ReviewWindowService::effectiveClose(['closes_at' => $it['window_closes_at'], 'extended_minutes_total' => $it['window_ext']], $slam)
                    : $it['ends_at'];
            } else {
                $start = $it['planned_start_at'];
                if ($cursor !== null) {
                    $gap   = ($prevType === 'performance' && $it['item_type'] === 'performance') ? (int) $slam['changeover_minutes'] : 0;
                    $earliest = Clock::addMinutes($cursor, $gap);
                    if (Clock::parse($earliest) > Clock::parse($start)) {
                        $start = $earliest;
                    }
                }
                if ($now > Clock::parse($start) && in_array($slam['status'], ['checkin_closed', 'reviewing', 'checkin_open', 'open', 'scheduled'], true)) {
                    $start = Clock::db($now);
                }
                $end = Clock::addMinutes($start, (int) $it['duration_minutes']);
                if (! $firstPendingSeen) {
                    $firstPendingSeen = true;
                    $late = max(0, intdiv(Clock::diffSeconds($it['planned_start_at'], $start), 60));
                }
            }
            $it['projected_start_at'] = $start;
            $it['projected_end_at']   = $end;
            $cursor   = $end;
            $prevType = $it['item_type'];
        }
        unset($it);

        $projectedEnd = $cursor;
        $past = false;
        if ($projectedEnd !== null) {
            $withEdit = Clock::addMinutes($projectedEnd, (int) $slam['edit_window_minutes']);
            $past     = Clock::parse($withEdit) > Clock::parse($slam['closes_at']);
        }

        return ['items' => $items, 'late_minutes' => $late, 'projected_end' => $projectedEnd, 'past_hard_stop' => $past];
    }

    // ------------------------------------------------------------------
    // Admin overrides. Each takes the lock, checks guards, applies, logs.
    // ------------------------------------------------------------------

    /** @param callable(array): mixed $fn */
    private function override(int $slamId, Actor $actor, callable $fn, bool $allowPaused = false): array
    {
        if (! $actor->isAdmin()) {
            throw new DomainException('forbidden', 'Only an admin can do this.', 403);
        }
        // Apply anything already due first, so the admin acts on the real state.
        $this->tick($slamId, true);
        $result = Db::tx(function () use ($slamId, $fn, $allowPaused): array {
            $slam = Db::lockSlam($slamId);
            if ($slam['paused_at'] !== null && ! $allowPaused) {
                throw new DomainException('paused', 'The slam is paused. Resume it first.', 409);
            }
            $fn($slam);

            return Db::lockSlam($slamId);
        });
        service('publicCache')->purge($slamId);

        return $result;
    }

    public function markReady(int $slamId, Actor $actor): array
    {
        return $this->override($slamId, $actor, fn (array $s) => $this->transition($s, 'scheduled', 'manual_override', $actor));
    }

    public function activate(int $slamId, Actor $actor): array
    {
        return $this->override($slamId, $actor, function (array $s) use ($actor): void {
            $missing = service('slams')->readiness((int) $s['id']);
            if ($missing !== []) {
                throw new DomainException('not_ready', 'The slam is not ready: ' . implode(' ', $missing), 422, ['missing' => $missing]);
            }
            $this->transition($s, 'open', 'manual_override', $actor);
        });
    }

    public function gateOpen(int $slamId, Actor $actor): array
    {
        return $this->override($slamId, $actor, fn (array $s) => $this->transition($s, 'checkin_open', 'manual_override', $actor));
    }

    public function gateClose(int $slamId, Actor $actor): array
    {
        return $this->override($slamId, $actor, fn (array $s) => $this->transition($s, 'checkin_closed', 'manual_override', $actor));
    }

    /** Move the gate close time (extend or shorten) while the gate is open. */
    public function gateExtend(int $slamId, string $newCloseUtc, Actor $actor): array
    {
        return $this->override($slamId, $actor, function (array $s) use ($newCloseUtc, $actor): void {
            if ($s['status'] !== 'checkin_open') {
                throw new DomainException('gate_not_open', 'The check-in gate is not open.');
            }
            $new = Clock::parse($newCloseUtc);
            if ($new <= Clock::now() || $new >= Clock::parse($s['closes_at'])) {
                throw new DomainException('invalid_close_time', 'The new gate close time must be in the future and before slam close.');
            }
            db_connect()->table('slams')->where('id', $s['id'])->update(['checkin_closes_at' => Clock::db($new), 'updated_at' => Clock::db()]);
            service('activityLog')->log($actor, 'checkin_gate.extended', [
                'slam_id' => (int) $s['id'], 'target_type' => 'slam', 'target_id' => (int) $s['id'],
                'old' => ['checkin_closes_at' => $s['checkin_closes_at']], 'new' => ['checkin_closes_at' => Clock::db($new)],
            ]);
        });
    }

    public function gateReopen(int $slamId, string $reason, ?string $newCloseUtc, Actor $actor): array
    {
        return $this->override($slamId, $actor, function (array $s) use ($reason, $newCloseUtc, $actor): void {
            if (trim($reason) === '') {
                throw new DomainException('reason_required', 'A reason is required to reopen the gate.');
            }
            if ($s['status'] !== 'checkin_closed') {
                throw new DomainException('forbidden_transition', 'The gate can only be reopened before the first review window opens.', 409);
            }
            $close = null;
            if ($newCloseUtc !== null && $newCloseUtc !== '') {
                $c = Clock::parse($newCloseUtc);
                if ($c <= Clock::now() || $c >= Clock::parse($s['closes_at'])) {
                    throw new DomainException('invalid_close_time', 'The new gate close time must be in the future and before slam close.');
                }
                $close = Clock::db($c);
            }
            // Without a new close time the gate is closed by hand (otherwise the passed time would re-close it at once).
            db_connect()->table('slams')->where('id', $s['id'])->update(['checkin_closes_at' => $close]);
            $s['checkin_closes_at'] = $close;
            $this->transition($s, 'checkin_open', 'manual_override', $actor, $reason);
        });
    }

    /**
     * The presenter walks on stage. The review can be opened while the
     * performance is running (and keeps going after it ends). Only one
     * performance is on stage at a time, and not while another is being reviewed.
     */
    public function startPerformance(int $slamId, ?int $itemId, Actor $actor, bool $closeGateFirst = false): array
    {
        return $this->override($slamId, $actor, function (array $s) use ($itemId, $actor, $closeGateFirst): void {
            if ($closeGateFirst && $s['status'] === 'checkin_open') {
                $s = $this->transition($s, 'checkin_closed', 'manual_override', $actor, 'Close check-in and start the first performance');
            }
            if (! in_array($s['status'], ['checkin_closed', 'reviewing'], true)) {
                throw new DomainException('gate_open', $s['status'] === 'checkin_open'
                    ? 'Close the check-in gate before starting a performance.'
                    : 'Performances can only be started between Gate Close and All Review Close.', 409);
            }
            $item = $itemId !== null
                ? Db::row('SELECT * FROM schedule_items WHERE id = ? AND slam_id = ?', [$itemId, $s['id']])
                : $this->firstPendingPerformance((int) $s['id']);
            if ($item === null || $item['item_type'] !== 'performance' || $item['status'] !== 'pending') {
                throw new DomainException('item_not_pending', 'Only a pending performance can be started.');
            }
            if ($item['performing_started_at'] !== null && $item['performing_ended_at'] === null) {
                throw new DomainException('performance_running', 'This performance is already on stage.');
            }
            if ($this->windows->openWindow((int) $s['id']) !== null) {
                throw new DomainException('window_open', 'Close the open review window before starting the next performance.', 409);
            }
            $other = Db::value('SELECT id FROM schedule_items WHERE slam_id = ? AND id <> ? AND performing_started_at IS NOT NULL AND performing_ended_at IS NULL', [$s['id'], $item['id']]);
            if ($other !== null) {
                throw new DomainException('performance_running', 'End the performance that is on stage before starting the next one.', 409);
            }
            $this->endRunningBreak($s);
            $this->skipBreaksBefore((int) $s['id'], $item, $actor);
            if ($s['status'] === 'checkin_closed') {
                $s = $this->transition($s, 'reviewing', 'manual_override', $actor, 'First performance started');
            }
            $this->beginPerformance($s, $item, $actor);
        });
    }

    private function beginPerformance(array $slam, array $item, Actor $actor): void
    {
        db_connect()->table('schedule_items')->where('id', $item['id'])->update(['performing_started_at' => Clock::db(), 'performing_ended_at' => null, 'updated_at' => Clock::db()]);
        service('activityLog')->log($actor, 'performance.started', ['slam_id' => (int) $slam['id'], 'target_type' => 'schedule_item', 'target_id' => (int) $item['id']]);
    }

    /**
     * A short fingerprint of everything an operator's buttons depend on (phase, mode, pause, every item's state and
     * times, the open windows, break end). Screens send the version they were looking at; an action made from a stale
     * screen is refused (see FreshStateFilter), so two people never act on different pictures of the slam.
     */
    public function stateVersion(int $slamId): string
    {
        $slam  = Db::row('SELECT status, auto_mode, hold_next_step, paused_at, schedule_version, closes_at FROM slams WHERE id = ?', [$slamId]) ?? [];
        $items = Db::rows("SELECT id, status, planned_start_at, duration_minutes, performing_started_at, performing_ended_at, ends_at FROM schedule_items WHERE slam_id = ? ORDER BY id", [$slamId]);
        $win   = Db::row('SELECT rw.id, rw.closes_at, rw.extended_minutes_total, rw.closed_at FROM review_windows rw JOIN schedule_items si ON si.id = rw.schedule_item_id WHERE si.slam_id = ? ORDER BY rw.id DESC LIMIT 1', [$slamId]);
        $rev   = Db::row('SELECT id, closes_at, closed_at FROM revision_windows WHERE slam_id = ? ORDER BY id DESC LIMIT 1', [$slamId]);

        return substr(md5(json_encode([$slam, $items, $win, $rev])), 0, 12);
    }

    /** The performance on stage right now, if any. */
    private function performingItem(int $slamId): ?array
    {
        return Db::row("SELECT * FROM schedule_items WHERE slam_id = ? AND item_type = 'performance' AND performing_started_at IS NOT NULL AND performing_ended_at IS NULL AND status IN ('pending','open') LIMIT 1", [$slamId]);
    }

    /** The presenter leaves the stage; an open review window keeps running. */
    public function endPerformance(int $slamId, int $itemId, Actor $actor): array
    {
        return $this->override($slamId, $actor, function (array $s) use ($itemId, $actor): void {
            $item = Db::row('SELECT * FROM schedule_items WHERE id = ? AND slam_id = ?', [$itemId, $s['id']]);
            if ($item === null || $item['performing_started_at'] === null || $item['performing_ended_at'] !== null) {
                throw new DomainException('not_performing', 'This performance is not on stage.');
            }
            db_connect()->table('schedule_items')->where('id', $itemId)->update(['performing_ended_at' => Clock::db(), 'updated_at' => Clock::db()]);
            service('activityLog')->log($actor, 'performance.ended', ['slam_id' => (int) $s['id'], 'target_type' => 'schedule_item', 'target_id' => $itemId]);
        });
    }

    /** Open a performance's review window by hand (defaults to the next pending one). */
    public function openWindow(int $slamId, ?int $itemId, ?int $minutes, ?string $closesAtUtc, Actor $actor, bool $closeGateFirst = false): array
    {
        return $this->override($slamId, $actor, function (array $s) use ($itemId, $minutes, $closesAtUtc, $actor, $closeGateFirst): void {
            if ($closeGateFirst && $s['status'] === 'checkin_open') {
                $s = $this->transition($s, 'checkin_closed', 'manual_override', $actor, 'Close check-in and open review');
            }
            if (! in_array($s['status'], ['checkin_closed', 'reviewing'], true)) {
                throw new DomainException('gate_open', $s['status'] === 'checkin_open'
                    ? 'Close the check-in gate before opening a review window.'
                    : 'Review windows can only be opened during the reviewing phase.', 409);
            }
            $item = $itemId !== null
                ? Db::row('SELECT * FROM schedule_items WHERE id = ? AND slam_id = ?', [$itemId, $s['id']])
                : $this->firstPendingPerformance((int) $s['id']);
            if ($item === null) {
                throw new DomainException('no_pending_item', 'There is no pending performance to open.');
            }
            $close = $this->windows->resolveCloseTime($s, $minutes ?? (int) $item['duration_minutes'], $closesAtUtc);
            // Another performance still on stage? It must end first (the same one may be reviewed while presenting).
            $other = Db::value('SELECT id FROM schedule_items WHERE slam_id = ? AND id <> ? AND performing_started_at IS NOT NULL AND performing_ended_at IS NULL', [$s['id'], $item['id']]);
            if ($other !== null) {
                throw new DomainException('performance_running', 'End the performance that is on stage before opening another review.', 409);
            }
            $this->endRunningBreak($s);
            if ($item['item_type'] === 'performance') {
                $this->skipBreaksBefore((int) $s['id'], $item, $actor);
            }
            if ($s['status'] === 'checkin_closed') {
                $s = $this->transition($s, 'reviewing', 'manual_override', $actor);
            }
            $this->windows->open($s, $item, $close, $actor, 'manual_override');
            service('activityLog')->log($actor, 'phase.override_advance', ['slam_id' => (int) $s['id'], 'meta' => ['step' => 'open_window', 'schedule_item_id' => (int) $item['id']]]);
        });
    }

    public function extendWindow(int $slamId, int $minutes, Actor $actor): array
    {
        return $this->override($slamId, $actor, function (array $s) use ($minutes, $actor): void {
            $this->windows->extend($s, $minutes, $actor);
            service('activityLog')->log($actor, 'phase.override_extend', ['slam_id' => (int) $s['id'], 'meta' => ['what' => 'review_window', 'minutes' => $minutes]]);
        });
    }

    public function closeWindow(int $slamId, ?string $note, Actor $actor): array
    {
        return $this->override($slamId, $actor, function (array $s) use ($note, $actor): void {
            if ($this->windows->close($s, $actor, 'manual', $note) === null) {
                throw new DomainException('no_open_window', 'No review window is open.');
            }
        });
    }

    public function skipItem(int $slamId, int $itemId, string $reason, Actor $actor): array
    {
        return $this->override($slamId, $actor, function (array $s) use ($itemId, $reason, $actor): void {
            if (trim($reason) === '') {
                throw new DomainException('reason_required', 'A reason is required to skip.');
            }
            $item = Db::row('SELECT * FROM schedule_items WHERE id = ? AND slam_id = ?', [$itemId, $s['id']]);
            if ($item === null || $item['status'] !== 'pending') {
                throw new DomainException('item_not_pending', 'Only a pending item can be skipped.');
            }
            db_connect()->query("UPDATE schedule_items SET status = 'skipped', performing_ended_at = COALESCE(performing_ended_at, ?), updated_at = ? WHERE id = ?", [$item['performing_started_at'] !== null ? Clock::db() : null, Clock::db(), $itemId]);
            service('activityLog')->log($actor, 'schedule.item_skipped', ['slam_id' => (int) $s['id'], 'target_type' => 'schedule_item', 'target_id' => $itemId, 'reason' => $reason]);
            $this->bumpScheduleVersion($s);
        });
    }

    public function extendBreak(int $slamId, int $itemId, int $minutes, Actor $actor): array
    {
        return $this->override($slamId, $actor, function (array $s) use ($itemId, $minutes, $actor): void {
            $item = Db::row("SELECT * FROM schedule_items WHERE id = ? AND slam_id = ? AND item_type = 'break'", [$itemId, $s['id']]);
            if ($item === null || ! in_array($item['status'], ['pending', 'open'], true)) {
                throw new DomainException('not_a_break', 'Only a pending or running break can be extended.');
            }
            if ($minutes < 1 || $minutes > 120) {
                throw new DomainException('invalid_minutes', 'Extension must be 1–120 minutes.');
            }
            $upd = ['duration_minutes' => (int) $item['duration_minutes'] + $minutes, 'updated_at' => Clock::db()];
            if ($item['status'] === 'open') {
                $upd['ends_at'] = Clock::addMinutes($item['ends_at'], $minutes);
            }
            db_connect()->table('schedule_items')->where('id', $itemId)->update($upd);
            // Shift later pending, unpinned planned times; warn about pinned ones in the response via validation.
            service('schedule')->shiftPendingAfter($s, (int) $item['position'], $minutes, false);
            service('activityLog')->log($actor, 'schedule.break_extended', ['slam_id' => (int) $s['id'], 'target_type' => 'schedule_item', 'target_id' => $itemId, 'meta' => ['minutes' => $minutes]]);
            $this->bumpScheduleVersion($s);
        });
    }

    /**
     * Manual mode: start a tea/lunch break now. Only between performances (reviewing phase), with nothing on
     * stage, no review open and no other break running. Breaks have no review window.
     */
    public function startBreakNow(int $slamId, int $itemId, Actor $actor): array
    {
        return $this->override($slamId, $actor, function (array $s) use ($itemId, $actor): void {
            if ($s['status'] !== 'reviewing') {
                throw new DomainException('forbidden_transition', 'A break can be started once the first performance has begun and before reviewing is over.', 409);
            }
            $item = Db::row("SELECT * FROM schedule_items WHERE id = ? AND slam_id = ? AND item_type = 'break'", [$itemId, $s['id']]);
            if ($item === null || $item['status'] !== 'pending') {
                throw new DomainException('item_not_pending', 'Only a pending break can be started.');
            }
            if ($this->windows->openWindow((int) $s['id']) !== null) {
                throw new DomainException('window_open', 'Close the open review window before starting a break.', 409);
            }
            if (Db::value('SELECT id FROM schedule_items WHERE slam_id = ? AND performing_started_at IS NOT NULL AND performing_ended_at IS NULL', [$s['id']]) !== null) {
                throw new DomainException('performance_running', 'End the performance that is on stage before starting a break.', 409);
            }
            $this->endRunningBreak($s);
            $this->skipBreaksBefore((int) $s['id'], $item, $actor);
            $this->startBreak($s, $item);
            service('activityLog')->log($actor, 'schedule.break_started', ['slam_id' => (int) $s['id'], 'target_type' => 'schedule_item', 'target_id' => $itemId, 'meta' => ['label' => $item['label']]]);
        });
    }

    /** Starting an item that is later in the running order passes over the breaks still pending before it (logged). */
    private function skipBreaksBefore(int $slamId, array $item, Actor $actor): void
    {
        foreach (Db::rows("SELECT id, label FROM schedule_items WHERE slam_id = ? AND item_type = 'break' AND status = 'pending' AND position < ? AND id <> ?", [$slamId, $item['position'], $item['id']]) as $b) {
            db_connect()->table('schedule_items')->where('id', $b['id'])->update(['status' => 'skipped', 'updated_at' => Clock::db()]);
            service('activityLog')->log($actor, 'schedule.item_skipped', ['slam_id' => $slamId, 'target_type' => 'schedule_item', 'target_id' => (int) $b['id'], 'reason' => 'Next performance started first', 'meta' => ['label' => $b['label']]]);
        }
    }

    public function endBreak(int $slamId, int $itemId, Actor $actor): array
    {
        return $this->override($slamId, $actor, function (array $s) use ($itemId, $actor): void {
            $item = Db::row("SELECT * FROM schedule_items WHERE id = ? AND slam_id = ? AND item_type = 'break'", [$itemId, $s['id']]);
            if ($item === null || $item['status'] !== 'open') {
                throw new DomainException('break_not_running', 'This break is not in progress.');
            }
            db_connect()->table('schedule_items')->where('id', $itemId)->update(['status' => 'closed', 'ends_at' => Clock::db(), 'updated_at' => Clock::db()]);
            service('activityLog')->log($actor, 'schedule.break_ended_early', ['slam_id' => (int) $s['id'], 'target_type' => 'schedule_item', 'target_id' => $itemId]);
            // The next performance then opens at once (subject to guards) if it is due by its planned time,
            // or the admin uses Advance now.
        });
    }

    /** End reviewing early: skip remaining pending items, then All Review Close. */
    public function endReviewing(int $slamId, string $reason, Actor $actor): array
    {
        return $this->override($slamId, $actor, function (array $s) use ($reason, $actor): void {
            if ($s['status'] !== 'reviewing') {
                throw new DomainException('forbidden_transition', 'Reviewing is not in progress.', 409);
            }
            if (trim($reason) === '') {
                throw new DomainException('reason_required', 'A reason is required to skip the remaining performances.');
            }
            if ($this->windows->openWindow((int) $s['id']) !== null) {
                throw new DomainException('window_already_open', 'Close the open review window first.');
            }
            $pending = Db::rows("SELECT id FROM schedule_items WHERE slam_id = ? AND status = 'pending'", [$s['id']]);
            foreach ($pending as $p) {
                db_connect()->query("UPDATE schedule_items SET status = 'skipped', performing_ended_at = IF(performing_started_at IS NOT NULL, COALESCE(performing_ended_at, ?), NULL), updated_at = ? WHERE id = ?", [Clock::db(), Clock::db(), $p['id']]);
                service('activityLog')->log($actor, 'schedule.item_skipped', ['slam_id' => (int) $s['id'], 'target_type' => 'schedule_item', 'target_id' => (int) $p['id'], 'reason' => $reason]);
            }
            $this->endRunningBreak($s);
            $this->transition($s, 'reviews_complete', 'manual_override', $actor, $reason);
        });
    }

    public function revisionOpen(int $slamId, ?int $minutes, ?string $closesAtUtc, Actor $actor): array
    {
        return $this->override($slamId, $actor, function (array $s) use ($minutes, $closesAtUtc, $actor): void {
            if ($s['status'] !== 'reviews_complete') {
                throw new DomainException('forbidden_transition', 'The edit window can only open after All Review Close.', 409);
            }
            $close = $this->windows->resolveCloseTime($s, $minutes ?? max(1, (int) $s['edit_window_minutes']), $closesAtUtc);
            $this->windows->openRevision($s, $close, $actor, 'manual_override');
            $this->transition($s, 'revision', 'manual_override', $actor);
        });
    }

    public function revisionExtend(int $slamId, int $minutes, Actor $actor): array
    {
        return $this->override($slamId, $actor, function (array $s) use ($minutes, $actor): void {
            if ($s['status'] !== 'revision') {
                throw new DomainException('no_open_window', 'The edit window is not open.');
            }
            $this->windows->extendRevision($s, $minutes, $actor);
            service('activityLog')->log($actor, 'phase.override_extend', ['slam_id' => (int) $s['id'], 'meta' => ['what' => 'revision_window', 'minutes' => $minutes]]);
        });
    }

    public function revisionClose(int $slamId, Actor $actor): array
    {
        return $this->override($slamId, $actor, function (array $s) use ($actor): void {
            if ($s['status'] !== 'revision') {
                throw new DomainException('no_open_window', 'The edit window is not open.');
            }
            $this->windows->closeRevision($s, $actor, 'manual');
            $this->transition($s, 'revision_closed', 'manual_override', $actor);
        });
    }

    public function revisionSkip(int $slamId, string $reason, Actor $actor): array
    {
        return $this->override($slamId, $actor, function (array $s) use ($reason, $actor): void {
            if (trim($reason) === '') {
                throw new DomainException('reason_required', 'A reason is required to skip the edit phase.');
            }
            $this->transition($s, 'revision_closed', 'manual_override', $actor, 'Edit skipped: ' . $reason);
        });
    }

    public function closeSlam(int $slamId, Actor $actor): array
    {
        $slam = $this->override($slamId, $actor, fn (array $s) => $this->transition($s, 'closed', 'manual_override', $actor));
        $this->tick($slamId, true); // first marks run follows automatically (unless held / manual)

        return service('slams')->find($slamId);
    }

    public function endEvent(int $slamId, Actor $actor): array
    {
        return $this->override($slamId, $actor, fn (array $s) => $this->transition($s, 'ended', 'manual_override', $actor));
    }

    public function setHold(int $slamId, bool $hold, Actor $actor, ?string $reason = null): array
    {
        return $this->override($slamId, $actor, function (array $s) use ($hold, $actor, $reason): void {
            db_connect()->table('slams')->where('id', $s['id'])->update(['hold_next_step' => $hold ? 1 : 0, 'updated_at' => Clock::db()]);
            service('activityLog')->log($actor, $hold ? 'phase.hold' : 'phase.resume', ['slam_id' => (int) $s['id'], 'target_type' => 'slam', 'target_id' => (int) $s['id'], 'reason' => $reason]);
        }, true);
    }

    public function setMode(int $slamId, string $mode, Actor $actor): array
    {
        if (! in_array($mode, ['auto', 'manual'], true)) {
            throw new DomainException('validation', 'Mode must be auto or manual.');
        }

        return $this->override($slamId, $actor, function (array $s) use ($mode, $actor): void {
            db_connect()->table('slams')->where('id', $s['id'])->update(['auto_mode' => $mode, 'updated_at' => Clock::db()]);
            service('activityLog')->log($actor, 'phase.mode_changed', ['slam_id' => (int) $s['id'], 'target_type' => 'slam', 'target_id' => (int) $s['id'], 'old' => ['auto_mode' => $s['auto_mode']], 'new' => ['auto_mode' => $mode]]);
        }, true);
    }

    /** Phases in which a slam can be paused (something is running or about to). */
    public const PAUSABLE = ['open', 'checkin_open', 'checkin_closed', 'reviewing', 'reviews_complete', 'revision', 'revision_closed'];

    /**
     * Pause the whole slam (a power cut, a technical problem, a long delay):
     * nothing starts or ends by itself, reviewers cannot submit and every timer
     * is frozen until resume() moves them by the length of the pause.
     */
    public function pause(int $slamId, ?string $reason, Actor $actor): array
    {
        $result = $this->override($slamId, $actor, function (array $s) use ($reason, $actor): void {
            if ($s['paused_at'] !== null) {
                throw new DomainException('already_paused', 'The slam is already paused.', 409);
            }
            if (! in_array($s['status'], self::PAUSABLE, true)) {
                throw new DomainException('not_pausable', 'A slam can be paused only while it is running.', 409);
            }
            $reason = $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 255) : null;
            $now    = Clock::db();
            db_connect()->table('slams')->where('id', $s['id'])->update(['paused_at' => $now, 'pause_reason' => $reason, 'updated_at' => $now]);
            db_connect()->table('slam_pauses')->insert(['slam_id' => $s['id'], 'paused_at' => $now, 'reason' => $reason, 'paused_by' => $actor->id]);
            service('activityLog')->log($actor, 'slam.paused', ['slam_id' => (int) $s['id'], 'target_type' => 'slam', 'target_id' => (int) $s['id'], 'reason' => $reason]);
        }, true);
        service('publicCache')->purge($slamId);

        return $result;
    }

    /**
     * Resume after a pause. The lapsed time is calculated and everything that
     * was waiting moves later by it: the running review/edit window, a running
     * break, the gate close time, the hard stop, and every pending slot (pinned
     * ones stay at their clock time and are reported).
     *
     * @return array{seconds: int, minutes: int, shifted: array<string, mixed>}
     */
    public function resume(int $slamId, Actor $actor): array
    {
        $info = ['seconds' => 0, 'minutes' => 0, 'shifted' => []];
        $this->override($slamId, $actor, function (array $s) use ($actor, &$info): void {
            if ($s['paused_at'] === null) {
                throw new DomainException('not_paused', 'The slam is not paused.', 409);
            }
            $db      = db_connect();
            $secs    = max(0, Clock::now()->getTimestamp() - Clock::parse($s['paused_at'])->getTimestamp());
            $mins    = (int) ceil($secs / 60);
            $sid     = (int) $s['id'];
            $shifted = [];
            $this->ensureBaseline($sid);

            if (($w = $this->windows->openWindow($sid)) !== null) {
                $db->query('UPDATE review_windows SET closes_at = DATE_ADD(closes_at, INTERVAL ? SECOND) WHERE id = ?', [$secs, $w['id']]);
                $shifted['review_window'] = true;
            }
            if (($rw = $this->windows->openRevisionWindow($sid)) !== null) {
                $db->query('UPDATE revision_windows SET closes_at = DATE_ADD(closes_at, INTERVAL ? SECOND) WHERE id = ?', [$secs, $rw['id']]);
                $shifted['edit_window'] = true;
            }
            $db->query("UPDATE schedule_items SET ends_at = DATE_ADD(ends_at, INTERVAL ? SECOND) WHERE slam_id = ? AND item_type = 'break' AND status = 'open' AND ends_at IS NOT NULL", [$secs, $sid]);
            $db->query("UPDATE schedule_items SET performing_started_at = DATE_ADD(performing_started_at, INTERVAL ? SECOND) WHERE slam_id = ? AND performing_started_at IS NOT NULL AND performing_ended_at IS NULL", [$secs, $sid]);
            $upd = ['paused_at' => null, 'pause_reason' => null, 'updated_at' => Clock::db(), 'closes_at' => Clock::addMinutes($s['closes_at'], $mins)];
            $shifted['hard_stop_minutes'] = $mins;
            if ($s['status'] === 'checkin_open' && $s['checkin_closes_at'] !== null) {
                $upd['checkin_closes_at'] = Clock::addSeconds($s['checkin_closes_at'], $secs);
                $shifted['gate_close'] = true;
            }
            if ($s['event_ends_at'] !== null) {
                $upd['event_ends_at'] = Clock::addMinutes($s['event_ends_at'], $mins);
            }
            if ($s['reviews_start_at'] !== null && Clock::parse($s['reviews_start_at']) > Clock::parse($s['paused_at'])) {
                $upd['reviews_start_at'] = Clock::addMinutes($s['reviews_start_at'], $mins);
            }
            $db->table('slams')->where('id', $sid)->update($upd);
            $r = $this->shiftPending($sid, $mins, false);
            $shifted['items'] = $r['moved'];
            $shifted['pinned_not_moved'] = $r['pinned'];
            if ($r['moved'] > 0 || $r['pinned'] > 0) {
                $this->bumpScheduleVersion($s);
            }
            $db->query('UPDATE slam_pauses SET resumed_at = ?, seconds = ?, resumed_by = ?, shifted = ? WHERE slam_id = ? AND resumed_at IS NULL ORDER BY id DESC LIMIT 1', [Clock::db(), $secs, $actor->id, json_encode($shifted), $sid]);
            service('activityLog')->log($actor, 'slam.resumed', ['slam_id' => $sid, 'target_type' => 'slam', 'target_id' => $sid, 'meta' => ['seconds' => $secs, 'minutes_shifted' => $mins, 'shifted' => $shifted]]);
            $info = ['seconds' => $secs, 'minutes' => $mins, 'shifted' => $shifted];
        }, true);
        service('publicCache')->purge($slamId);
        cache()->delete(service('publicCache')->key('tickcheck', $slamId));

        return $info;
    }

    /** The plan as it stood when the slam went live, for items that have none yet. */
    private function ensureBaseline(int $slamId): void
    {
        db_connect()->query('UPDATE schedule_items SET original_start_at = planned_start_at WHERE slam_id = ? AND original_start_at IS NULL', [$slamId]);
    }

    /** @return array{moved: int, pinned: int} pending slots moved by N minutes (negative = earlier) */
    private function shiftPending(int $slamId, int $minutes, bool $includePinned, int $afterPosition = -1): array
    {
        $moved = 0;
        $pinned = 0;
        foreach (Db::rows("SELECT * FROM schedule_items WHERE slam_id = ? AND status = 'pending' AND position > ? ORDER BY position", [$slamId, $afterPosition]) as $r) {
            if ((int) $r['is_pinned'] === 1 && ! $includePinned) {
                $pinned++;
                continue;
            }
            db_connect()->table('schedule_items')->where('id', $r['id'])->update(['planned_start_at' => Clock::addMinutes($r['planned_start_at'], $minutes), 'updated_at' => Clock::db()]);
            $moved++;
        }

        return ['moved' => $moved, 'pinned' => $pinned];
    }

    /** Move every remaining (pending) slot earlier or later by N minutes while the slam runs. */
    public function shiftRemaining(int $slamId, int $minutes, bool $includePinned, Actor $actor): array
    {
        if ($minutes === 0 || abs($minutes) > 240) {
            throw new DomainException('invalid_minutes', 'Shift by 1 to 240 minutes (negative moves earlier).');
        }
        $out = ['moved' => 0, 'pinned' => 0];
        $result = $this->override($slamId, $actor, function (array $s) use ($minutes, $includePinned, $actor, &$out): void {
            $this->ensureBaseline((int) $s['id']);
            $out = $this->shiftPending((int) $s['id'], $minutes, $includePinned);
            if ($out['moved'] === 0) {
                throw new DomainException('nothing_to_shift', 'There is nothing left to move.');
            }
            $this->bumpScheduleVersion($s);
            service('activityLog')->log($actor, 'schedule.shifted', ['slam_id' => (int) $s['id'], 'meta' => ['minutes' => $minutes, 'moved' => $out['moved'], 'pinned_not_moved' => $out['pinned']]]);
        });

        return $out + ['slam' => $result];
    }

    /**
     * Change one pending slot while the slam runs: its start time and/or length,
     * optionally moving the slots after it by the same amount.
     */
    public function rescheduleItem(int $slamId, int $itemId, string $newStartUtc, ?int $duration, bool $shiftFollowing, Actor $actor): array
    {
        return $this->override($slamId, $actor, function (array $s) use ($itemId, $newStartUtc, $duration, $shiftFollowing, $actor): void {
            $item = Db::row('SELECT * FROM schedule_items WHERE id = ? AND slam_id = ?', [$itemId, $s['id']]);
            if ($item === null || $item['status'] !== 'pending') {
                throw new DomainException('item_not_pending', 'Only a slot that has not started can be rescheduled.');
            }
            $newDur = $duration ?? (int) $item['duration_minutes'];
            if ($newDur < 1 || $newDur > 600) {
                throw new DomainException('invalid_minutes', 'Duration must be 1 to 600 minutes.');
            }
            $startDelta = (int) round((Clock::parse($newStartUtc)->getTimestamp() - Clock::parse($item['planned_start_at'])->getTimestamp()) / 60);
            $durDelta   = $newDur - (int) $item['duration_minutes'];
            if ($startDelta === 0 && $durDelta === 0) {
                throw new DomainException('nothing_changed', 'Nothing to change.');
            }
            $this->ensureBaseline((int) $s['id']);
            if (! $shiftFollowing) {
                $next = Db::row("SELECT planned_start_at FROM schedule_items WHERE slam_id = ? AND status = 'pending' AND position > ? ORDER BY position LIMIT 1", [$s['id'], $item['position']]);
                if ($next !== null && Clock::parse(Clock::addMinutes($item['planned_start_at'], $startDelta + $newDur)) > Clock::parse($next['planned_start_at'])) {
                    throw new DomainException('overlap', 'This would run into the next slot. Move the following slots too.', 422);
                }
            }
            db_connect()->table('schedule_items')->where('id', $itemId)->update([
                'planned_start_at' => Clock::addMinutes($item['planned_start_at'], $startDelta), 'duration_minutes' => $newDur, 'updated_at' => Clock::db(),
            ]);
            $moved = 0;
            if ($shiftFollowing && $startDelta + $durDelta !== 0) {
                $moved = $this->shiftPending((int) $s['id'], $startDelta + $durDelta, false, (int) $item['position'])['moved'];
            }
            $this->bumpScheduleVersion($s);
            service('activityLog')->log($actor, 'schedule.item_time_changed', [
                'slam_id' => (int) $s['id'], 'target_type' => 'schedule_item', 'target_id' => $itemId,
                'old' => ['planned_start_at' => $item['planned_start_at'], 'duration_minutes' => (int) $item['duration_minutes']],
                'new' => ['planned_start_at' => Clock::addMinutes($item['planned_start_at'], $startDelta), 'duration_minutes' => $newDur],
                'meta' => ['following_moved' => $moved, 'during' => 'live'],
            ]);
        });
    }

    /**
     * How far the slam is from its original plan, in minutes (positive = behind,
     * negative = ahead): the running (or last started) performance's real start
     * against its original start; while waiting for the next one, now against its
     * original start. Null when it cannot be measured yet.
     *
     * @param list<array<string,mixed>> $items rows of projection()['items']
     * @return array{minutes: int, basis: string, original: string, started: ?string, item_id: int}|null
     */
    public function drift(array $slam, array $items): ?array
    {
        if (! in_array($slam['status'], ['checkin_closed', 'reviewing'], true)) {
            return null;
        }
        $live = array_values(array_filter($items, static fn ($i) => $i['item_type'] === 'performance' && $i['status'] !== 'skipped' && (int) ($i['perf_active'] ?? 1) === 1));
        $orig = static fn (array $i): string => $i['original_start_at'] ?? $i['planned_start_at'];
        foreach ($live as $i) {
            if ($i['status'] === 'open' || (! empty($i['performing_started_at']) && empty($i['performing_ended_at']))) {
                $started = $i['performing_started_at'] ?? $i['actual_start_at'];

                return ['minutes' => (int) round((Clock::parse($started)->getTimestamp() - Clock::parse($orig($i))->getTimestamp()) / 60),
                    'basis' => 'started', 'original' => Clock::iso($orig($i)), 'started' => Clock::iso($started), 'item_id' => (int) $i['id']];
            }
        }
        foreach ($live as $i) {
            if ($i['status'] === 'pending') {
                return ['minutes' => (int) round((Clock::now()->getTimestamp() - Clock::parse($orig($i))->getTimestamp()) / 60),
                    'basis' => 'waiting', 'original' => Clock::iso($orig($i)), 'started' => null, 'item_id' => (int) $i['id']];
            }
        }

        return null;
    }

    /** What "Advance now" would do in the current state (for the button label). */
    public function advanceLabel(array $slam): ?string
    {
        $sid = (int) $slam['id'];

        return match ($slam['status']) {
            'draft'            => 'Mark ready',
            'scheduled'        => 'Open slam now',
            'open'             => 'Open check-in gate now',
            'checkin_open'     => 'Close check-in and open review',
            'checkin_closed', 'reviewing' => $this->windows->openWindow($sid) !== null
                ? 'Close review window now'
                : ($this->runningBreak($sid) !== null ? 'End break now'
                    : ($this->firstPendingPerformance($sid) !== null ? 'Open next performance now' : ($slam['status'] === 'reviewing' ? 'All reviews closed' : null))),
            'reviews_complete' => 'Open edit window now',
            'revision'         => 'Close edit window now',
            'revision_closed'  => 'Close slam now',
            'closed'           => 'Calculate marks now',
            default            => null,
        };
    }

    /** Start the next step immediately. */
    public function advanceNow(int $slamId, Actor $actor): array
    {
        $slam = service('slams')->find($slamId);
        $sid  = (int) $slam['id'];
        service('activityLog')->log($actor, 'phase.override_advance', ['slam_id' => $sid, 'meta' => ['from' => $slam['status']]]);

        switch ($slam['status']) {
            case 'draft': return $this->markReady($sid, $actor);
            case 'scheduled': return $this->activate($sid, $actor);
            case 'open': return $this->gateOpen($sid, $actor);
            case 'checkin_open': return $this->openWindow($sid, null, null, null, $actor, true);
            case 'checkin_closed':
            case 'reviewing':
                if ($this->windows->openWindow($sid) !== null) {
                    return $this->closeWindow($sid, 'Advance now', $actor);
                }
                $break = $this->runningBreak($sid);
                if ($break !== null) {
                    return $this->endBreak($sid, (int) $break['id'], $actor);
                }
                if ($this->firstPendingPerformance($sid) !== null) {
                    return $this->openWindow($sid, null, null, null, $actor);
                }

                return $this->override($sid, $actor, fn (array $s) => $this->transition($s, 'reviews_complete', 'manual_override', $actor));
            case 'reviews_complete': return $this->revisionOpen($sid, null, null, $actor);
            case 'revision': return $this->revisionClose($sid, $actor);
            case 'revision_closed': return $this->closeSlam($sid, $actor);
            case 'closed':
                service('markRuns')->calculate($sid, $actor, null);

                return service('slams')->find($sid);
        }
        throw new DomainException('nothing_to_advance', 'There is no next step to start.');
    }

    /** First pending performance (skipping pending breaks, which an admin opening a window passes over). */
    public function firstPendingPerformance(int $slamId): ?array
    {
        return Db::row(
            "SELECT si.* FROM schedule_items si JOIN performances p ON p.id = si.performance_id
             WHERE si.slam_id = ? AND si.status = 'pending' AND si.item_type = 'performance' AND p.is_active = 1
             ORDER BY si.position, si.id LIMIT 1",
            [$slamId],
        );
    }

    private function bumpScheduleVersion(array $slam): void
    {
        db_connect()->query('UPDATE slams SET schedule_version = schedule_version + 1 WHERE id = ?', [$slam['id']]);
    }
}
