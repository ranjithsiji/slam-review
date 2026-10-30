<?php

declare(strict_types=1);

namespace Tests\Feature;

use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;
use Modules\Slams\Services\SlamPhaseService;
use Tests\Support\SlamTestCase;

final class PhaseTimelineTest extends SlamTestCase
{
    public function testTransitionTableAllowsOnlyForwardMovesAndGateReopen(): void
    {
        $this->assertTrue(SlamPhaseService::canTransition('scheduled', 'open', 'timer'));
        $this->assertTrue(SlamPhaseService::canTransition('checkin_closed', 'checkin_open', 'manual_override'));
        $this->assertFalse(SlamPhaseService::canTransition('checkin_closed', 'checkin_open', 'timer'));
        $this->assertFalse(SlamPhaseService::canTransition('reviewing', 'checkin_open', 'manual_override'));
        $this->assertFalse(SlamPhaseService::canTransition('open', 'reviewing', 'manual_override'));
        $this->assertFalse(SlamPhaseService::canTransition('reviewing', 'closed', 'manual_override'));
        $this->assertTrue(SlamPhaseService::canTransition('reviewing', 'closed', 'hard_stop'));
        $this->assertTrue(SlamPhaseService::canTransition('reviews_complete', 'revision_closed', 'manual_override'));
        $this->assertFalse(SlamPhaseService::canTransition('ended', 'marks_calculated', 'manual_override'));
        $this->assertFalse(SlamPhaseService::canTransition('marks_calculated', 'closed', 'manual_override'));
    }

    public function testFullAutomaticChain(): void
    {
        $s  = $this->makeSlam(2);
        $id = $s['id'];
        $this->assertSame('scheduled', $this->phase($id));

        $this->at('2026-11-01 02:59:59', $id);
        $this->assertSame('scheduled', $this->phase($id));
        $this->at('2026-11-01 03:00:00', $id);
        $this->assertSame('open', $this->phase($id));
        $this->at('2026-11-01 03:30:00', $id);
        $this->assertSame('checkin_open', $this->phase($id));
        $this->at('2026-11-01 04:30:00', $id);
        $this->assertSame('checkin_closed', $this->phase($id));

        // First performance opens at its planned start, for its full duration.
        $this->at('2026-11-01 04:44:59', $id);
        $this->assertSame('checkin_closed', $this->phase($id));
        $this->at('2026-11-01 04:45:00', $id);
        $this->assertSame('reviewing', $this->phase($id));
        $w = Db::row('SELECT * FROM review_windows WHERE schedule_item_id = ?', [$s['items'][0]]);
        $this->assertSame('2026-11-01 04:50:00', $w['closes_at']);

        // Window closes at its deadline; next opens after the changeover at its planned time (04:52).
        $this->at('2026-11-01 04:50:00', $id);
        $this->assertNotNull(Db::value('SELECT closed_at FROM review_windows WHERE id = ?', [$w['id']]));
        $this->assertSame('closed', Db::value('SELECT status FROM schedule_items WHERE id = ?', [$s['items'][0]]));
        $this->at('2026-11-01 04:51:59', $id);
        $this->assertSame('pending', Db::value('SELECT status FROM schedule_items WHERE id = ?', [$s['items'][1]]));
        $this->at('2026-11-01 04:52:00', $id);
        $this->assertSame('open', Db::value('SELECT status FROM schedule_items WHERE id = ?', [$s['items'][1]]));

        // Last window closes → All Review Close → edit window opens automatically for 10 min.
        $this->at('2026-11-01 04:57:00', $id);
        $this->assertSame('revision', $this->phase($id));
        $this->at('2026-11-01 05:07:00', $id);
        $this->assertSame('revision_closed', $this->phase($id));
        // Slam Close after the 5-minute grace, then the first marks run.
        $this->at('2026-11-01 05:11:59', $id);
        $this->assertSame('revision_closed', $this->phase($id));
        $this->at('2026-11-01 05:12:00', $id);
        $this->assertSame('marks_calculated', $this->phase($id));
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM mark_runs WHERE slam_id = ? AND is_current = 1', [$id]));

        // Every change is in the phase log (draft→scheduled plus 9 timeline steps).
        $this->assertSame(10, (int) Db::value('SELECT COUNT(*) FROM slam_phase_log WHERE slam_id = ?', [$id]));
    }

    public function testRunningLateOpensAfterCloseAndChangeoverNotOverlapping(): void
    {
        $s  = $this->makeSlam(2);
        $id = $s['id'];
        $this->at('2026-11-01 04:30:00', $id);
        $this->at('2026-11-01 04:45:00', $id);
        service('slamPhase')->extendWindow($id, 4, $this->admin); // window now ends 04:54
        $this->at('2026-11-01 04:53:00', $id);
        $this->assertSame('pending', Db::value('SELECT status FROM schedule_items WHERE id = ?', [$s['items'][1]]));
        $this->at('2026-11-01 04:54:00', $id);
        $this->at('2026-11-01 04:55:59', $id);
        $this->assertSame('pending', Db::value('SELECT status FROM schedule_items WHERE id = ?', [$s['items'][1]]));
        $this->at('2026-11-01 04:56:00', $id);
        $this->assertSame('open', Db::value('SELECT status FROM schedule_items WHERE id = ?', [$s['items'][1]]));
        // Full duration from the actual open.
        $this->assertSame('2026-11-01 05:01:00', Db::value('SELECT closes_at FROM review_windows WHERE schedule_item_id = ?', [$s['items'][1]]));
        $proj = service('slamPhase')->projection(service('slams')->find($id));
        $this->assertSame(0, $proj['late_minutes']); // nothing pending any more
    }

    public function testHoldStopsNextStartButNotRunningDeadline(): void
    {
        $s  = $this->makeSlam(2);
        $id = $s['id'];
        $this->at('2026-11-01 04:45:00', $id);
        service('slamPhase')->setHold($id, true, $this->admin);
        $this->at('2026-11-01 04:50:00', $id); // running window still closes on its deadline
        $this->assertSame('closed', Db::value('SELECT status FROM schedule_items WHERE id = ?', [$s['items'][0]]));
        $this->at('2026-11-01 05:10:00', $id); // but the next one does not start
        $this->assertSame('pending', Db::value('SELECT status FROM schedule_items WHERE id = ?', [$s['items'][1]]));
        service('slamPhase')->setHold($id, false, $this->admin);
        $this->at('2026-11-01 05:10:00', $id);
        $this->assertSame('open', Db::value('SELECT status FROM schedule_items WHERE id = ?', [$s['items'][1]]));
    }

    public function testManualModeWaitsForAdmin(): void
    {
        Clock::set('2026-11-01 02:00:00');
        $s  = $this->makeSlam(1);
        $id = $s['id'];
        service('slamPhase')->setMode($id, 'manual', $this->admin);
        $this->at('2026-11-01 03:40:00', $id);
        $this->assertSame('scheduled', $this->phase($id));
        service('slamPhase')->advanceNow($id, $this->admin);
        $this->assertSame('open', $this->phase($id));
    }

    public function testHardStopClosesEverythingAndChecksOut(): void
    {
        $s  = $this->makeSlam(1);
        $id = $s['id'];
        $this->at('2026-11-01 03:30:00', $id);
        $this->checkIn($s['users'][0]);
        $this->at('2026-11-01 04:45:00', $id);
        $this->assertSame('reviewing', $this->phase($id));
        service('slamPhase')->setHold($id, true, $this->admin);
        $this->at('2026-11-01 11:30:00', $id);
        // Hard stop forces closed; marks wait because the slam is held.
        $this->assertSame('closed', $this->phase($id));
        $this->assertSame('hard_stop', Db::value("SELECT `trigger` FROM slam_phase_log WHERE slam_id = ? AND to_status = 'closed'", [$id]));
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM checkins WHERE checked_out_at IS NULL'));
        $this->assertSame('auto', Db::value('SELECT checkout_type FROM checkins LIMIT 1'));
    }

    public function testReviewWindowCannotOpenWhileGateOpen(): void
    {
        $s  = $this->makeSlam(1);
        $id = $s['id'];
        $this->at('2026-11-01 03:30:00', $id);
        $this->expectException(DomainException::class);
        service('slamPhase')->openWindow($id, null, 5, null, $this->admin);
    }

    public function testCloseCheckinAndOpenReviewInOneClick(): void
    {
        $s  = $this->makeSlam(1);
        $id = $s['id'];
        $this->at('2026-11-01 03:30:00', $id);
        service('slamPhase')->openWindow($id, null, 7, null, $this->admin, true);
        $this->assertSame('reviewing', $this->phase($id));
        $this->assertSame('2026-11-01 03:37:00', Db::value('SELECT closes_at FROM review_windows'));
    }

    public function testGateReopenNeedsReasonAndOnlyBeforeReviewing(): void
    {
        $s  = $this->makeSlam(1);
        $id = $s['id'];
        $this->at('2026-11-01 04:30:00', $id);
        try {
            service('slamPhase')->gateReopen($id, '', null, $this->admin);
            $this->fail('reason required');
        } catch (DomainException $e) {
            $this->assertSame('reason_required', $e->reason);
        }
        service('slamPhase')->gateReopen($id, 'Bus arrived late', null, $this->admin);
        $this->assertSame('checkin_open', $this->phase($id));
        $this->assertNull(Db::value('SELECT checkin_closes_at FROM slams WHERE id = ?', [$id]));
    }

    public function testReadinessBlocksAutomaticOpen(): void
    {
        $s  = $this->makeSlam(1);
        $id = $s['id'];
        db_connect()->table('attendees')->where('slam_id', $id)->update(['is_active' => 0]);
        $this->at('2026-11-01 03:01:00', $id);
        $this->assertSame('scheduled', $this->phase($id));
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action = 'phase.alert' AND reason = 'readiness_failed'"));
    }

    public function testWindowCappedAtHardStop(): void
    {
        $s  = $this->makeSlam(1, 1, 1);
        $id = $s['id'];
        db_connect()->table('slams')->where('id', $id)->update(['closes_at' => '2026-11-01 04:48:00']);
        // Open by hand for 30 minutes: the close is capped at the slam hard stop.
        $this->at('2026-11-01 04:30:00', $id);
        service('slamPhase')->openWindow($id, null, 30, null, $this->admin);
        $w = Db::row('SELECT * FROM review_windows');
        $this->assertSame('2026-11-01 04:48:00', $w['closes_at']);
    }

    public function testTickOnPollIsCheapWhenNothingDue(): void
    {
        $s  = $this->makeSlam(1);
        Clock::set('2026-11-01 02:00:00');
        $this->assertSame([], service('slamPhase')->tick($s['id']));
        $this->assertSame('2026-11-01 03:00:00', Clock::db(service('slamPhase')->nextDueAt(service('slams')->find($s['id']))));
    }
}
