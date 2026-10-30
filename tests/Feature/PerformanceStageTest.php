<?php

declare(strict_types=1);

namespace Tests\Feature;

use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;
use Tests\Support\SlamTestCase;

/** On stage first; the review can open while presenting and runs on after the performance ends. */
final class PerformanceStageTest extends SlamTestCase
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

    public function testPerformanceThenReviewOverlapAndTheNextOneWaits(): void
    {
        $s  = $this->makeSlam(3, 1, 0, ['checkin_opens_at' => null, 'checkin_closes_at' => null, 'reviews_start_at' => null, 'auto_mode' => 'manual']);
        $id = $s['id'];
        [$a, $b, $c] = $s['items'];
        $p = service('slamPhase');
        $this->at('2026-11-01 03:00:00', $id);
        $p->activate($id, $this->admin);
        $p->gateOpen($id, $this->admin);
        $this->expectReason('gate_open', fn () => $p->startPerformance($id, $a, $this->admin));

        // Close the gate and start the first performance in one go: the slam is now "reviewing".
        $this->at('2026-11-01 04:45:00', $id);
        $p->startPerformance($id, null, $this->admin, true);
        $this->assertSame('reviewing', $this->phase($id));
        $this->assertNotNull($this->item($a)['performing_started_at']);
        $this->assertSame('pending', $this->item($a)['status'], 'on stage, but the review is not open yet');
        $this->assertNull($p->windows()->openWindow($id));
        // Live state: reviewers see who is presenting; the public page says "Presenting now".
        $live = service('liveState')->buildShared($id);
        $this->assertSame('Presenter 1', $live['presenting']['presenter_name']);
        $this->assertNull($live['current']);

        // One performance on stage at a time; another review cannot open meanwhile.
        $this->expectReason('performance_running', fn () => $p->startPerformance($id, $b, $this->admin));
        $this->expectReason('performance_running', fn () => $p->openWindow($id, $b, 5, null, $this->admin));

        // The review opens while the presenter is still on stage.
        $this->at('2026-11-01 04:48:00', $id);
        $p->openWindow($id, $a, 6, null, $this->admin);
        $this->assertSame('open', $this->item($a)['status']);
        $this->assertNull($this->item($a)['performing_ended_at']);
        $this->assertTrue(service('liveState')->buildShared($id)['current']['performing']);

        // The performance ends; the review carries on.
        $this->at('2026-11-01 04:50:00', $id);
        $p->endPerformance($id, $a, $this->admin);
        $this->assertNotNull($this->item($a)['performing_ended_at']);
        $this->assertNotNull($p->windows()->openWindow($id), 'the review window keeps running');
        $this->assertFalse(service('liveState')->buildShared($id)['current']['performing']);
        $this->expectReason('not_performing', fn () => $p->endPerformance($id, $a, $this->admin));
        // The next performance cannot start until this review closes.
        $this->expectReason('window_open', fn () => $p->startPerformance($id, $b, $this->admin));

        $p->closeWindow($id, 'done', $this->admin);
        $this->assertSame('closed', $this->item($a)['status']);
        $p->startPerformance($id, $b, $this->admin);

        // Closing a review ends a performance that was still on stage.
        $p->openWindow($id, $b, 5, null, $this->admin);
        $p->closeWindow($id, null, $this->admin);
        $this->assertNotNull($this->item($b)['performing_ended_at']);

        // Skipping the performance that is on stage ends it too.
        $p->startPerformance($id, $c, $this->admin);
        $p->skipItem($id, $c, 'presenter unwell', $this->admin);
        $this->assertSame('skipped', $this->item($c)['status']);
        $this->assertNotNull($this->item($c)['performing_ended_at']);
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM activity_log WHERE slam_id = ? AND action = 'performance.ended'", [$id]), 'only the explicit end is logged as performance.ended');
    }

    public function testOpeningAReviewDirectlyAlsoStartsThePerformance(): void
    {
        $s  = $this->makeSlam(1, 1, 0, ['auto_mode' => 'manual']);
        $id = $s['id'];
        $p  = service('slamPhase');
        $this->at('2026-11-01 03:00:00', $id);
        $p->activate($id, $this->admin);
        $p->gateOpen($id, $this->admin);
        $p->openWindow($id, $s['items'][0], 5, null, $this->admin, true);
        $row = $this->item($s['items'][0]);
        $this->assertNotNull($row['performing_started_at']);
        $this->assertNull($row['performing_ended_at']);
        $this->at('2026-11-01 04:55:00', $id);
        $this->assertNotNull($this->item($s['items'][0])['performing_ended_at'], 'the timer closing the review ends the performance');
    }

    public function testPublicPageShowsPresentingOnlyWhileOnStage(): void
    {
        $s  = $this->makeSlam(2, 1, 0, ['auto_mode' => 'manual']);
        $id = $s['id'];
        $p  = service('slamPhase');
        $this->at('2026-11-01 03:00:00', $id);
        $p->activate($id, $this->admin);
        $p->gateOpen($id, $this->admin);
        $p->startPerformance($id, $s['items'][0], $this->admin, true);
        $status = static fn (array $doc) => array_column($doc['slots'], 'status');
        $pub    = new \Modules\PublicSite\Services\PublicScheduleService();
        $this->assertSame(['presenting', 'upcoming'], $status($pub->document(service('slams')->find($id))));
        $p->openWindow($id, $s['items'][0], 5, null, $this->admin);
        $p->endPerformance($id, $s['items'][0], $this->admin);
        $this->assertSame(['done', 'upcoming'], $status($pub->document(service('slams')->find($id))), 'review still open, but the performance is over');
    }
}
