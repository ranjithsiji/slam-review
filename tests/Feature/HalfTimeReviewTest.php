<?php

declare(strict_types=1);

namespace Tests\Feature;

use Modules\Core\Libraries\Db;
use Tests\Support\SlamTestCase;

/** Automatic mode: the talk starts on schedule, the review opens at half time and runs from that moment. */
final class HalfTimeReviewTest extends SlamTestCase
{
    private function itemStatus(int $item): string
    {
        return (string) Db::value('SELECT status FROM schedule_items WHERE id = ?', [$item]);
    }

    public function testReviewOpensAtHalfTimeAndPresentationEndsAtFullTime(): void
    {
        $s = $this->makeSlam(2, 2, 0, ['presentation_minutes' => 10]);
        $id = $s['id'];
        [$p1, $p2] = $s['items'];
        $this->at('2026-11-01 04:44:00', $id);
        $this->assertSame('checkin_closed', $this->phase($id));

        // 04:45 the first talk is on stage; no review yet.
        $this->at('2026-11-01 04:45:00', $id);
        $this->assertSame('reviewing', $this->phase($id));
        $this->assertNotNull(Db::value('SELECT performing_started_at FROM schedule_items WHERE id = ?', [$p1]));
        $this->assertSame('pending', $this->itemStatus($p1));
        $this->at('2026-11-01 04:49:59', $id);
        $this->assertSame('pending', $this->itemStatus($p1));

        // Half time (04:50): the review opens for 5 minutes counted from now; the talk is still on stage.
        $this->at('2026-11-01 04:50:00', $id);
        $this->assertSame('open', $this->itemStatus($p1));
        $this->assertNull(Db::value('SELECT performing_ended_at FROM schedule_items WHERE id = ?', [$p1]));
        $this->assertSame('2026-11-01 04:55:00', Db::value('SELECT closes_at FROM review_windows WHERE schedule_item_id = ?', [$p1]));

        // Full time (04:55): the presentation ends and the review closes.
        $this->at('2026-11-01 04:55:00', $id);
        $this->assertNotNull(Db::value('SELECT performing_ended_at FROM schedule_items WHERE id = ?', [$p1]));
        $this->assertSame('closed', $this->itemStatus($p1));

        // The next talk starts after the changeover (04:57), not before.
        $this->at('2026-11-01 04:56:00', $id);
        $this->assertNull(Db::value('SELECT performing_started_at FROM schedule_items WHERE id = ?', [$p2]));
        $this->at('2026-11-01 04:57:00', $id);
        $this->assertNotNull(Db::value('SELECT performing_started_at FROM schedule_items WHERE id = ?', [$p2]));
        $this->assertSame('pending', $this->itemStatus($p2));
    }

    public function testPauseMovesHalfTimeAndManualModeNeverOpensByItself(): void
    {
        $s = $this->makeSlam(1, 1, 0, ['presentation_minutes' => 10]);
        $id = $s['id'];
        $p1 = $s['items'][0];
        $this->at('2026-11-01 04:45:00', $id);
        $this->at('2026-11-01 04:47:00', $id);
        service('slamPhase')->pause($id, 'mic problem', $this->admin);
        \Modules\Core\Libraries\Clock::set('2026-11-01 04:50:00');       // three minutes pass while paused
        service('slamPhase')->resume($id, $this->admin);
        $this->at('2026-11-01 04:50:00', $id);
        $this->assertSame('pending', $this->itemStatus($p1), 'half time moved by the pause');
        $this->at('2026-11-01 04:52:59', $id);
        $this->assertSame('pending', $this->itemStatus($p1));
        $this->at('2026-11-01 04:53:00', $id);
        $this->assertSame('open', $this->itemStatus($p1));

        // Manual mode: the operator opens everything by hand; nothing happens at half time.
        $m = $this->makeSlam(1, 1, 0, ['presentation_minutes' => 10, 'auto_mode' => 'manual']);
        $this->at('2026-11-01 04:30:00', $m['id']);
        service('slamPhase')->activate($m['id'], $this->admin);
        service('slamPhase')->gateOpen($m['id'], $this->admin);
        service('slamPhase')->gateClose($m['id'], $this->admin);
        service('slamPhase')->startPerformance($m['id'], $m['items'][0], $this->admin);
        $this->at('2026-11-01 05:30:00', $m['id']);
        $this->assertSame('pending', $this->itemStatus($m['items'][0]));
        $this->assertNull(Db::value('SELECT performing_ended_at FROM schedule_items WHERE id = ?', [$m['items'][0]]));
    }
}
