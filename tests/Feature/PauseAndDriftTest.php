<?php

declare(strict_types=1);

namespace Tests\Feature;

use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;
use Tests\Support\SlamTestCase;

final class PauseAndDriftTest extends SlamTestCase
{
    private function expectReason(string $reason, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Expected {$reason}");
        } catch (DomainException $e) {
            $this->assertSame($reason, $e->reason, $e->getMessage());
        }
    }

    private function item(int $id): array
    {
        return Db::row('SELECT * FROM schedule_items WHERE id = ?', [$id]);
    }

    private function drift(int $id): ?array
    {
        $slam = service('slams')->find($id);

        return service('slamPhase')->drift($slam, service('slamPhase')->projection($slam)['items']);
    }

    public function testPauseFreezesTimersAndResumeShiftsEverythingByTheLapsedTime(): void
    {
        $s  = $this->makeSlam(3, 1, 0);           // automatic: P1 opens 04:45 for 5 min, P2 planned 04:52, P3 04:59
        $id = $s['id'];
        $p  = service('slamPhase');
        $this->at('2026-11-01 03:30:00', $id);
        $this->checkIn($s['users'][0]);
        $this->at('2026-11-01 04:45:00', $id);
        $this->assertSame('open', $this->item($s['items'][0])['status']);

        $this->at('2026-11-01 04:47:00', $id);
        $p->pause($id, 'power cut', $this->admin);
        $this->assertNotNull(service('slams')->find($id)['paused_at']);
        $this->expectReason('already_paused', fn () => $p->pause($id, null, $this->admin));
        $this->expectReason('paused', fn () => $p->advanceNow($id, $this->admin));
        $this->assertSame('power cut', service('liveState')->buildShared($id)['paused']['reason']);

        // Long past the original deadline (04:50): nothing closes, and nobody can submit.
        $this->at('2026-11-01 04:55:00', $id);
        $this->assertSame('open', $this->item($s['items'][0])['status']);
        $this->assertNotNull(service('liveState')->buildShared($id)['current'], 'the performance stays current while paused');
        $this->expectReason('paused', fn () => service('reviews')->submit($s['users'][0], $s['items'][0], $this->answers($id, 'user', 3), null, $this->actorFor($s['users'][0])));

        // Resume after 13 minutes: the window gets its remaining 3 minutes again, the rest moves 13 minutes.
        Clock::set('2026-11-01 05:00:00');
        $r = $p->resume($id, $this->admin);
        $this->assertSame([780, 13], [$r['seconds'], $r['minutes']]);
        $slam = service('slams')->find($id);
        $this->assertNull($slam['paused_at']);
        $window = $p->windows()->openWindow($id);
        $this->assertSame('2026-11-01 05:03:00', \Modules\Slams\Services\ReviewWindowService::effectiveClose($window, $slam));
        $this->assertSame('2026-11-01 05:05:00', $this->item($s['items'][1])['planned_start_at'], 'P2 was 04:52');
        $this->assertSame('2026-11-01 04:52:00', $this->item($s['items'][1])['original_start_at'], 'the original plan is never rewritten');
        $this->assertSame('2026-11-01 11:43:00', $slam['closes_at'], 'hard stop moves by the pause too');
        $this->assertSame(780, (int) Db::value('SELECT seconds FROM slam_pauses WHERE slam_id = ?', [$id]));

        // The review works again and closes at the new deadline.
        service('reviews')->submit($s['users'][0], $s['items'][0], $this->answers($id, 'user', 3), null, $this->actorFor($s['users'][0]));
        $this->at('2026-11-01 05:02:59', $id);
        $this->assertSame('open', $this->item($s['items'][0])['status']);
        $this->at('2026-11-01 05:03:01', $id);
        $this->assertSame('closed', $this->item($s['items'][0])['status']);
    }

    public function testPinnedSlotsKeepTheirClockTimeAndAreCounted(): void
    {
        $s  = $this->makeSlam(3, 1, 0, ['auto_mode' => 'manual']);
        $id = $s['id'];
        $p  = service('slamPhase');
        db_connect()->table('schedule_items')->where('id', $s['items'][2])->update(['is_pinned' => 1]);
        $this->at('2026-11-01 03:00:00', $id);
        $p->activate($id, $this->admin);
        $p->gateOpen($id, $this->admin);
        $p->pause($id, null, $this->admin);
        Clock::set('2026-11-01 03:10:30');
        $r = $p->resume($id, $this->admin);
        $this->assertSame(11, $r['minutes'], '10.5 minutes round up');
        $this->assertSame(1, $r['shifted']['pinned_not_moved']);
        $this->assertSame(2, $r['shifted']['items']);
        $this->assertSame('2026-11-01 04:59:00', $this->item($s['items'][2])['planned_start_at']);
        $this->expectReason('not_paused', fn () => $p->resume($id, $this->admin));
    }

    public function testBehindAndAheadAgainstTheOriginalPlan(): void
    {
        $s  = $this->makeSlam(3, 1, 0, ['auto_mode' => 'manual']);
        $id = $s['id'];
        $p  = service('slamPhase');
        $this->at('2026-11-01 03:00:00', $id);
        $p->activate($id, $this->admin);
        $p->gateOpen($id, $this->admin);
        $this->assertNull($this->drift($id), 'not measurable while the gates are open');
        $this->at('2026-11-01 04:30:00', $id);
        $p->gateClose($id, $this->admin);

        // Ready early: P1 is planned for 04:45, it is 04:40, so 5 minutes ahead.
        $this->at('2026-11-01 04:40:00', $id);
        $d = $this->drift($id);
        $this->assertSame([-5, 'waiting'], [$d['minutes'], $d['basis']]);

        // Started late: P1 goes on stage at 04:52 instead of 04:45, so 7 minutes behind.
        $this->at('2026-11-01 04:52:00', $id);
        $p->startPerformance($id, $s['items'][0], $this->admin);
        $d = $this->drift($id);
        $this->assertSame([7, 'started'], [$d['minutes'], $d['basis']]);

        // Shifting the remaining slots later never changes the original plan.
        $p->openWindow($id, $s['items'][0], 10, null, $this->admin);
        $this->at('2026-11-01 04:57:00', $id);
        $p->closeWindow($id, null, $this->admin);
        $r = $p->shiftRemaining($id, 7, false, $this->admin);
        $this->assertSame(2, $r['moved']);
        $this->assertSame('2026-11-01 04:59:00', $this->item($s['items'][1])['planned_start_at']);
        $this->assertSame('2026-11-01 04:52:00', $this->item($s['items'][1])['original_start_at']);
        $this->at('2026-11-01 05:00:00', $id);
        $this->assertSame([8, 'waiting'], [$this->drift($id)['minutes'], $this->drift($id)['basis']], 'waiting past its original 04:52');
        $this->expectReason('invalid_minutes', fn () => $p->shiftRemaining($id, 0, false, $this->admin));
        $this->expectReason('invalid_minutes', fn () => $p->shiftRemaining($id, 999, false, $this->admin));
    }

    public function testRescheduleOneSlotWhileRunning(): void
    {
        $s  = $this->makeSlam(3, 1, 0, ['auto_mode' => 'manual']);
        $id = $s['id'];
        $p  = service('slamPhase');
        $this->at('2026-11-01 03:00:00', $id);
        $p->activate($id, $this->admin);
        $p->gateOpen($id, $this->admin);
        [$a, $b, $c] = $s['items'];

        // A 5-minute slot cannot grow into the next one unless the following slots move too.
        $this->expectReason('overlap', fn () => $p->rescheduleItem($id, $b, '2026-11-01 04:52:00', 12, false, $this->admin));
        $this->expectReason('nothing_changed', fn () => $p->rescheduleItem($id, $b, '2026-11-01 04:52:00', 5, true, $this->admin));
        $p->rescheduleItem($id, $b, '2026-11-01 05:02:00', 8, true, $this->admin);   // +10 min start, +3 min longer
        $this->assertSame('2026-11-01 05:02:00', $this->item($b)['planned_start_at']);
        $this->assertSame(8, (int) $this->item($b)['duration_minutes']);
        $this->assertSame('2026-11-01 05:12:00', $this->item($c)['planned_start_at'], 'P3 was 04:59, moved by 10 + 3');
        $this->assertSame('2026-11-01 04:52:00', $this->item($b)['original_start_at']);
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action = 'schedule.item_time_changed' AND JSON_EXTRACT(meta, '$.during') = 'live'"));
        // Slots that already ran cannot be rescheduled.
        $p->startPerformance($id, $a, $this->admin, true);
        $p->openWindow($id, $a, 5, null, $this->admin);
        $this->expectReason('item_not_pending', fn () => $p->rescheduleItem($id, $a, '2026-11-01 05:30:00', null, true, $this->admin));
    }
}
