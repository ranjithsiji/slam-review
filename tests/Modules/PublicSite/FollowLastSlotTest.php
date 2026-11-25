<?php

declare(strict_types=1);

namespace Tests\Modules\PublicSite;

use CodeIgniter\Test\CIUnitTestCase;
use Modules\PublicSite\Services\PublicScheduleService;

/** The programme items after the last talk (evening tea, valedictory) move with the last slot; opening items stay. */
final class FollowLastSlotTest extends CIUnitTestCase
{
    private array $extras = [
        ['placement' => 'before', 'item_type' => 'item', 'label' => 'Registration', 'starts_at' => '2026-11-28 03:30:00', 'ends_at' => '2026-11-28 04:15:00'],
        ['placement' => 'after', 'item_type' => 'break', 'label' => 'Evening tea', 'starts_at' => '2026-11-28 10:45:00', 'ends_at' => '2026-11-28 10:57:00'],
        ['placement' => 'after', 'item_type' => 'heading', 'label' => 'Valedictory', 'starts_at' => null, 'ends_at' => null],
        ['placement' => 'after', 'item_type' => 'item', 'label' => 'Certificates', 'starts_at' => '2026-11-28 10:57:00', 'ends_at' => '2026-11-28 11:27:00'],
    ];

    public function testAfterItemsStartWhenTheLastSlotEnds(): void
    {
        // Talks every 12 minutes (10 + 2 changeover); the last one ends 10:51, its slot with the changeover 10:53.
        $slots = [['planned_start_at' => '2026-11-28T10:29:00Z', 'duration_minutes' => 10], ['planned_start_at' => '2026-11-28T10:41:00Z', 'duration_minutes' => 10]];
        $out   = PublicScheduleService::followLastSlot($slots, $this->extras);
        $this->assertSame('2026-11-28 03:30:00', $out[0]['starts_at'], 'opening items keep their time');
        $this->assertSame(['2026-11-28 10:53:00', '2026-11-28 11:05:00'], [$out[1]['starts_at'], $out[1]['ends_at']]);
        $this->assertNull($out[2]['starts_at']);
        $this->assertSame(['2026-11-28 11:05:00', '2026-11-28 11:35:00'], [$out[3]['starts_at'], $out[3]['ends_at']]);
    }

    public function testARealEndMovesThemEarlierToo(): void
    {
        $slots = [['planned_start_at' => '2026-11-28T10:41:00Z', 'duration_minutes' => 10, 'started_at' => '2026-11-28T10:30:00Z', 'ended_at' => '2026-11-28T10:40:00Z']];
        $out   = PublicScheduleService::followLastSlot($slots, $this->extras);
        $this->assertSame('2026-11-28 10:40:00', $out[1]['starts_at']);
    }

    public function testNoSlotsLeavesThemAlone(): void
    {
        $this->assertSame($this->extras, PublicScheduleService::followLastSlot([], $this->extras));
    }
}
