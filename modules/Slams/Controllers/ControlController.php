<?php

declare(strict_types=1);

namespace Modules\Slams\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use Modules\Core\Controllers\BaseController;
use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;
use Modules\Slams\Services\ReviewWindowService;
use Modules\Slams\Services\SlamPhaseService;

/** Slam phase control panel (Vue screen) and its JSON API. */
class ControlController extends BaseController
{
    /** The old control panel lives on the Status screen now. */
    public function page(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        return redirect()->to(site_url("admin/slams/{$id}/status"));
    }

    /** Status timeline: where this slam is now, with the next actions on each step's card. */
    public function statusPage(int $id): string
    {
        $slam = service('slams')->find($id);

        return $this->render('Modules\Slams\Views\status', ['title' => 'Status — ' . $slam['name'], 'slam' => $slam]);
    }

    /** Slams that are happening now (Slam Open … Slam Close), for the live bar. */
    public const RUNNING = ['open', 'checkin_open', 'checkin_closed', 'reviewing', 'reviews_complete', 'revision', 'revision_closed', 'closed'];

    public function liveBar(): ResponseInterface
    {
        return $this->api(function () {
            session()->close();
            $only = operator_slam();
            $rows = Db::rows('SELECT id, name, status FROM slams WHERE status IN (' . implode(',', array_fill(0, count(self::RUNNING), '?')) . ')' . ($only !== null ? ' AND id = ?' : '') . ' ORDER BY opens_at', $only !== null ? [...self::RUNNING, $only] : self::RUNNING);
            $out  = [];
            foreach ($rows as $r) {
                $id = (int) $r['id'];
                service('slamPhase')->tick($id);
                $slam = service('slams')->find($id);
                $sum  = service('liveState')->nowSummary($id);
                $out[] = [
                    'paused_since' => $sum['paused_since'], 'drift' => $sum['drift'],
                    'id' => $id, 'name' => $slam['name'], 'status' => $slam['status'], 'label' => $sum['label'],
                    'detail' => $sum['detail'], 'until' => $sum['until'], 'hold' => (bool) $slam['hold_next_step'] || $slam['auto_mode'] === 'manual' ? ($slam['auto_mode'] === 'manual' ? 'manual' : 'held') : null,
                    'url' => site_url("admin/slams/{$id}/status"),
                ];
            }

            return ['server_now' => Clock::iso(Clock::db()), 'slams' => $out];
        });
    }

    public function state(int $id): ResponseInterface
    {
        return $this->api(function () use ($id) {
            session()->close();
            $phase = service('slamPhase');
            $phase->tick($id);
            $slam  = service('slams')->find($id);
            $proj  = $phase->projection($slam);
            $win   = $phase->windows()->openWindow($id);
            $rev   = $phase->windows()->openRevisionWindow($id);
            $live  = service('liveState')->shared($id);

            $items = array_map(static fn ($it) => [
                'id' => (int) $it['id'], 'item_type' => $it['item_type'], 'break_type' => $it['break_type'], 'label' => $it['label'],
                'performance_id' => $it['performance_id'] !== null ? (int) $it['performance_id'] : null,
                'status' => $it['status'], 'is_pinned' => (bool) $it['is_pinned'], 'duration_minutes' => (int) $it['duration_minutes'],
                'planned_start_at' => Clock::iso($it['planned_start_at']), 'original_start_at' => Clock::iso($it['original_start_at'] ?? $it['planned_start_at']), 'projected_start_at' => Clock::iso($it['projected_start_at']),
                'projected_end_at' => Clock::iso($it['projected_end_at']),
                'performing_started_at' => Clock::iso($it['performing_started_at'] ?? null), 'performing_ended_at' => Clock::iso($it['performing_ended_at'] ?? null),
                'window_closes_at' => $it['status'] === 'open' && $it['item_type'] === 'performance' && $it['window_closes_at'] !== null ? Clock::iso(ReviewWindowService::effectiveClose(['closes_at' => $it['window_closes_at'], 'extended_minutes_total' => $it['window_ext']], $slam)) : null,
                'actual_open_at' => Clock::iso($it['window_opened_at'] ?? $it['actual_start_at']), 'actual_close_at' => Clock::iso($it['window_closed_at'] ?? ($it['status'] === 'closed' ? $it['ends_at'] : null)),
            ], $proj['items']);
            $names = [];
            foreach (Db::rows('SELECT p.id, pr.name, p.topic, pr.photo_thumb_path FROM performances p JOIN presenters pr ON pr.id = p.presenter_id WHERE p.slam_id = ?', [$id]) as $r) {
                $names[(int) $r['id']] = ['presenter_name' => $r['name'], 'topic' => $r['topic'], 'thumb' => $r['photo_thumb_path'] ? '/' . $r['photo_thumb_path'] : null];
            }
            foreach ($items as &$it) {
                $it += $it['performance_id'] !== null ? ($names[$it['performance_id']] ?? []) : [];
                $it['reviews'] = (int) Db::value('SELECT COUNT(*) FROM reviews WHERE schedule_item_id = ?', [$it['id']]);
            }
            unset($it);

            $counts = Db::row(
                "SELECT (SELECT COUNT(*) FROM checkins c JOIN attendees a ON a.id = c.attendee_id WHERE a.slam_id = ? AND c.checked_out_at IS NULL AND a.user_type = 'user') AS users_in,
                        (SELECT COUNT(*) FROM checkins c JOIN attendees a ON a.id = c.attendee_id WHERE a.slam_id = ? AND c.checked_out_at IS NULL AND a.user_type = 'judge') AS judges_in,
                        (SELECT COUNT(*) FROM attendees WHERE slam_id = ? AND is_active = 1) AS registered,
                        (SELECT COUNT(DISTINCT r.attendee_id) FROM reviews r JOIN schedule_items si ON si.id = r.schedule_item_id WHERE si.slam_id = ? AND r.edit_count > 0) AS revised",
                [$id, $id, $id, $id],
            );
            // When each phase was first entered (for "done at" on the status timeline).
            $phaseTimes = [];
            foreach (Db::rows('SELECT to_status, MIN(changed_at) AS at FROM slam_phase_log WHERE slam_id = ? GROUP BY to_status', [$id]) as $r) {
                $phaseTimes[$r['to_status']] = Clock::iso($r['at']);
            }
            $alerts = Db::rows("SELECT id, occurred_at, reason, meta FROM activity_log WHERE slam_id = ? AND action = 'phase.alert' ORDER BY id DESC LIMIT 5", [$id]);

            return [
                'server_now' => Clock::iso(Clock::db()),
                'slam' => [
                    'id' => (int) $slam['id'], 'name' => $slam['name'], 'status' => $slam['status'],
                    'status_label' => SlamPhaseService::LABELS[$slam['status']] ?? $slam['status'],
                    'auto_mode' => $slam['auto_mode'], 'hold' => (bool) $slam['hold_next_step'],
                    'opens_at' => Clock::iso($slam['opens_at']), 'checkin_opens_at' => Clock::iso($slam['checkin_opens_at']),
                    'checkin_closes_at' => Clock::iso($slam['checkin_closes_at']), 'reviews_start_at' => Clock::iso($slam['reviews_start_at']),
                    'closes_at' => Clock::iso($slam['closes_at']), 'event_ends_at' => Clock::iso($slam['event_ends_at']),
                    'phase_changed_at' => Clock::iso($slam['phase_changed_at']),
                    'default_review_minutes' => (int) $slam['default_review_minutes'], 'edit_window_minutes' => (int) $slam['edit_window_minutes'],
                    'close_grace_minutes' => (int) $slam['close_grace_minutes'], 'changeover_minutes' => (int) $slam['changeover_minutes'],
                ],
                'presence'    => service('engagement')->presence($id),
                'version'     => service('slamPhase')->stateVersion($id),
                'me'          => ['id' => (int) (session('auth')['id'] ?? 0)],
                'last_change' => $this->lastChange($id),
                'staff'       => $this->staffPresence($id),
                'talk'        => $live['talk'] ?? null,
                'turnout'     => service('engagement')->turnout($id),
                'idle_count'  => in_array($slam['status'], ['reviewing', 'reviews_complete', 'revision'], true) ? service('engagement')->idleCount($id) : 0,
                'phase_times' => $phaseTimes,
                'paused' => $slam['paused_at'] !== null ? ['since' => Clock::iso($slam['paused_at']), 'reason' => $slam['pause_reason']] : null,
                'pauses' => ['count' => (int) Db::value('SELECT COUNT(*) FROM slam_pauses WHERE slam_id = ? AND resumed_at IS NOT NULL', [$id]), 'seconds' => (int) Db::value('SELECT COALESCE(SUM(seconds), 0) FROM slam_pauses WHERE slam_id = ?', [$id])],
                'drift' => $phase->drift($slam, $proj['items']),
                'phases' => array_map(static fn ($p) => ['key' => $p, 'label' => SlamPhaseService::LABELS[$p]], SlamPhaseService::ORDER),
                'readiness' => in_array($slam['status'], ['draft', 'scheduled'], true) ? service('slams')->readiness($id) : [],
                'window' => $win !== null ? [
                    'id' => (int) $win['id'], 'schedule_item_id' => (int) $win['schedule_item_id'], 'opened_at' => Clock::iso($win['opened_at']),
                    'closes_at' => Clock::iso(ReviewWindowService::effectiveClose($win, $slam)), 'extended_minutes' => (int) $win['extended_minutes_total'],
                    'reviews' => (int) Db::value('SELECT COUNT(*) FROM reviews WHERE review_window_id = ?', [$win['id']]),
                ] : null,
                'revision' => $rev !== null ? ['closes_at' => Clock::iso(ReviewWindowService::effectiveClose($rev, $slam)), 'extended_minutes' => (int) $rev['extended_minutes_total']] : null,
                'current' => $live['current'],
                'items' => $items,
                'late_minutes' => $proj['late_minutes'],
                'projected_end' => Clock::iso($proj['projected_end']),
                'past_hard_stop' => $proj['past_hard_stop'],
                'advance_label' => $phase->advanceLabel($slam),
                'next_due_at' => Clock::iso(($n = $phase->nextDueAt($slam)) ? Clock::db($n) : null),
                'counts' => array_map('intval', $counts),
                'alerts' => array_map(static fn ($a) => ['id' => (int) $a['id'], 'at' => str_replace(' ', 'T', substr($a['occurred_at'], 0, 19)) . 'Z', 'reason' => $a['reason'], 'meta' => json_decode((string) $a['meta'], true)], $alerts),
            ];
        });
    }

    private function utcFromIstInput(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $v = (string) $v;

        // ISO with zone from the client, or a datetime-local value in the display timezone.
        return preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $v) ? Clock::db(Clock::parse($v)) : Clock::fromLocalInput($v);
    }

    public function action(int $id, string $what): ResponseInterface
    {
        $in    = $this->input();
        $actor = $this->actor();
        $p     = service('slamPhase');

        return $this->api(function () use ($id, $what, $in, $actor, $p) {
            $result = match ($what) {
                'ready'         => $p->markReady($id, $actor),
                'open'          => $p->activate($id, $actor),
                'end-reviewing' => $p->endReviewing($id, (string) ($in['reason'] ?? ''), $actor),
                'close'         => $p->closeSlam($id, $actor),
                'calculate'     => ['run_id' => service('markRuns')->calculate($id, $actor, isset($in['note']) ? (string) $in['note'] : null)],
                'end'           => ($in['confirm'] ?? '') === 'END'
                    ? $p->endEvent($id, $actor)
                    : throw new DomainException('confirm_required', 'Type END to confirm. Ending the event cannot be undone.'),
                'hold'          => $p->setHold($id, true, $actor, isset($in['reason']) ? (string) $in['reason'] : null),
                'resume'        => $p->setHold($id, false, $actor),
                'mode'          => $p->setMode($id, (string) ($in['mode'] ?? ''), $actor),
                'advance'       => $p->advanceNow($id, $actor),
                default         => throw new DomainException('not_found', 'Unknown action.', 404),
            };

            return ['ok' => true] + (isset($result['run_id']) ? ['run_id' => $result['run_id']] : []);
        });
    }

    public function gate(int $id, string $what): ResponseInterface
    {
        $in    = $this->input();
        $actor = $this->actor();
        $p     = service('slamPhase');

        return $this->api(function () use ($id, $what, $in, $actor, $p) {
            match ($what) {
                'open'   => $p->gateOpen($id, $actor),
                'close'  => $p->gateClose($id, $actor),
                'extend' => $p->gateExtend($id, $this->utcFromIstInput($in['closes_at'] ?? null) ?? Clock::addMinutes(service('slams')->find($id)['checkin_closes_at'] ?? Clock::db(), (int) ($in['minutes'] ?? 0)), $actor),
                'reopen' => $p->gateReopen($id, (string) ($in['reason'] ?? ''), $this->utcFromIstInput($in['closes_at'] ?? null), $actor),
                default  => throw new DomainException('not_found', 'Unknown action.', 404),
            };

            return ['ok' => true];
        });
    }

    public function window(int $id, string $what): ResponseInterface
    {
        $in    = $this->input();
        $actor = $this->actor();
        $p     = service('slamPhase');

        return $this->api(function () use ($id, $what, $in, $actor, $p) {
            match ($what) {
                'open'   => $p->openWindow(
                    $id,
                    isset($in['schedule_item_id']) ? (int) $in['schedule_item_id'] : null,
                    isset($in['minutes']) && $in['minutes'] !== '' ? (int) $in['minutes'] : null,
                    $this->utcFromIstInput($in['closes_at'] ?? null),
                    $actor,
                    ! empty($in['close_gate']),
                ),
                'extend' => $p->extendWindow($id, (int) ($in['minutes'] ?? 0), $actor),
                'close'  => $p->closeWindow($id, isset($in['note']) ? (string) $in['note'] : null, $actor),
                default  => throw new DomainException('not_found', 'Unknown action.', 404),
            };

            return ['ok' => true];
        });
    }

    public function revision(int $id, string $what): ResponseInterface
    {
        $in    = $this->input();
        $actor = $this->actor();
        $p     = service('slamPhase');

        return $this->api(function () use ($id, $what, $in, $actor, $p) {
            match ($what) {
                'open'   => $p->revisionOpen($id, isset($in['minutes']) && $in['minutes'] !== '' ? (int) $in['minutes'] : null, $this->utcFromIstInput($in['closes_at'] ?? null), $actor),
                'extend' => $p->revisionExtend($id, (int) ($in['minutes'] ?? 0), $actor),
                'close'  => $p->revisionClose($id, $actor),
                'skip'   => $p->revisionSkip($id, (string) ($in['reason'] ?? ''), $actor),
                default  => throw new DomainException('not_found', 'Unknown action.', 404),
            };

            return ['ok' => true];
        });
    }

    /** Start / end a performance on stage (the review window is separate). */
    public function perform(int $id, int $itemId, string $what): ResponseInterface
    {
        $in    = $this->input();
        $actor = $this->actor();
        $p     = service('slamPhase');

        return $this->api(function () use ($id, $itemId, $what, $in, $actor, $p) {
            match ($what) {
                'start' => $p->startPerformance($id, $itemId > 0 ? $itemId : null, $actor, ! empty($in['close_gate'])),
                'end'   => $p->endPerformance($id, $itemId, $actor),
                default => throw new DomainException('not_found', 'Unknown action.', 404),
            };

            return ['ok' => true];
        });
    }

    /** Pause / resume the whole slam; shift or reschedule while it runs. */
    public function timing(int $id, string $what): ResponseInterface
    {
        $in    = $this->input();
        $actor = $this->actor();
        $p     = service('slamPhase');

        return $this->api(function () use ($id, $what, $in, $actor, $p) {
            return match ($what) {
                'pause'  => $p->pause($id, isset($in['reason']) ? (string) $in['reason'] : null, $actor) + ['ok' => true],
                'resume' => $p->resume($id, $actor) + ['ok' => true],
                'shift'  => ['ok' => true] + array_intersect_key($p->shiftRemaining($id, (int) ($in['minutes'] ?? 0), ! empty($in['include_pinned']), $actor), ['moved' => 1, 'pinned' => 1]),
                default  => throw new DomainException('not_found', 'Unknown action.', 404),
            };
        });
    }

    public function reschedule(int $id, int $itemId): ResponseInterface
    {
        $in = $this->input();

        return $this->api(function () use ($id, $itemId, $in) {
            $start = $this->utcFromIstInput($in['start'] ?? null) ?? throw new DomainException('validation', 'Choose the new start time.', 422);
            service('slamPhase')->rescheduleItem($id, $itemId, $start, isset($in['duration']) && $in['duration'] !== '' ? (int) $in['duration'] : null, ! empty($in['shift_following']), $this->actor());

            return ['ok' => true];
        });
    }

    public function breakStart(int $id, int $itemId): ResponseInterface
    {
        return $this->api(function () use ($id, $itemId) {
            service('slamPhase')->startBreakNow($id, $itemId, $this->actor());

            return ['ok' => true];
        });
    }

    public function skip(int $id, int $itemId): ResponseInterface
    {
        $in = $this->input();

        return $this->api(function () use ($id, $itemId, $in) {
            service('slamPhase')->skipItem($id, $itemId, (string) ($in['reason'] ?? ''), $this->actor());

            return ['ok' => true];
        });
    }

    public function times(int $id): ResponseInterface
    {
        $in = $this->input();

        return $this->api(function () use ($id, $in) {
            $times = [];
            foreach (\Modules\Slams\Services\SlamService::TIME_FIELDS as $f) {
                if (array_key_exists($f, $in)) {
                    $times[$f] = $this->utcFromIstInput($in[$f]);
                }
            }
            service('slams')->update($id, $times, $this->actor());

            return ['ok' => true];
        });
    }

    /** The latest control action on this slam, so other screens can say "Priya opened the review". */
    private function lastChange(int $id): ?array
    {
        $r = Db::row(
            "SELECT id, actor_type, actor_id, actor_label, action, reason FROM activity_log
              WHERE slam_id = ? AND action IN ('slam.phase_changed','window.opened','window.extended','window.closed.manual','window.closed.timer','performance.started','performance.ended','schedule.break_started','schedule.break_ended_early','schedule.item_skipped','slam.paused','slam.resumed','phase.mode_changed','phase.hold','phase.resume','checkin_gate.opened','checkin_gate.closed','schedule.shifted')
              ORDER BY id DESC LIMIT 1",
            [$id],
        );

        return $r === null ? null : ['id' => (int) $r['id'], 'by_id' => $r['actor_type'] === 'admin' ? (int) $r['actor_id'] : null, 'by' => $r['actor_type'] === 'system' ? 'the timer' : $r['actor_label'], 'action' => $r['action']];
    }

    /** Who else has this slam's Status screen open (heartbeat in the cache, no database writes). */
    private function staffPresence(int $id): array
    {
        $me = (int) (session('auth')['id'] ?? 0);
        $cache = cache();
        $cache->save("staff_seen_{$id}_{$me}", time(), 90);
        $out = [];
        foreach (Db::rows("SELECT id, name, role FROM admins WHERE is_active = 1 AND (role = 'admin' OR slam_id = ?)", [$id]) as $a) {
            if ((int) $a['id'] !== $me && $cache->get("staff_seen_{$id}_{$a['id']}") !== null) {
                $out[] = ['name' => $a['name'], 'role' => $a['role'] === 'operator' ? 'operator' : 'admin'];
            }
        }

        return $out;
    }
}
