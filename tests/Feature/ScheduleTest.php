<?php

declare(strict_types=1);

namespace Tests\Feature;

use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;
use Modules\Schedule\Services\ScheduleService;
use Tests\Support\SlamTestCase;

final class ScheduleTest extends SlamTestCase
{
    private function draft(int $slamId): array
    {
        $svc = service('schedule');

        return $svc->toDraft(Db::rows('SELECT * FROM schedule_items WHERE slam_id = ? ORDER BY position', [$slamId]));
    }

    public function testValidationCatchesOverlapGateAndLimit(): void
    {
        $s    = $this->makeSlam(2);
        $slam = service('slams')->find($s['id']);
        $d    = $this->draft($s['id']);
        $d[1]['planned_start_at'] = '2026-11-01T04:47:00Z'; // overlaps slot 1 (04:45–04:50)
        $r = service('schedule')->validate($slam, service('schedule')->normalise($d));
        $this->assertSame('Overlaps the previous slot (starts before it ends).', $r['errors'][0]['message']);

        $d = $this->draft($s['id']);
        $d[0]['planned_start_at'] = '2026-11-01T04:00:00Z'; // before Gate Close
        $r = service('schedule')->validate($slam, service('schedule')->normalise($d));
        $this->assertStringContainsString('Gate Close', $r['errors'][0]['message']);

        $d = $this->draft($s['id']);
        $d[1]['planned_start_at'] = '2026-11-01T11:25:00Z'; // + edit window passes closes_at
        $r = service('schedule')->validate($slam, service('schedule')->normalise($d));
        $this->assertStringContainsString('runs past', $r['errors'][0]['message']);
    }

    public function testReflowKeepsPinnedAndBreaksHaveNoChangeover(): void
    {
        $s    = $this->makeSlam(3);
        $slam = service('slams')->find($s['id']);
        $d    = service('schedule')->normalise($this->draft($s['id']));
        // Insert a pinned lunch at 05:30 after slot 2 and a 10-minute tea break after slot 1.
        $tea   = ['id' => null, 'item_type' => 'break', 'break_type' => 'tea', 'performance_id' => null, 'label' => 'Tea', 'planned_start_at' => '2026-11-01 04:00:00', 'duration_minutes' => 10, 'is_pinned' => false, 'status' => 'pending'];
        $lunch = ['id' => null, 'item_type' => 'break', 'break_type' => 'lunch', 'performance_id' => null, 'label' => 'Lunch', 'planned_start_at' => '2026-11-01 05:30:00', 'duration_minutes' => 30, 'is_pinned' => true, 'status' => 'pending'];
        $items = [$d[0], $tea, $d[1], $lunch, $d[2]];
        $r     = service('schedule')->reflow($slam, $items);
        $this->assertSame([], $r['errors']);
        $t = array_column($r['items'], 'planned_start_at');
        $this->assertSame(['2026-11-01 04:45:00', '2026-11-01 04:50:00', '2026-11-01 05:00:00', '2026-11-01 05:30:00', '2026-11-01 06:00:00'], $t);

        // A pinned slot that cannot fit is reported.
        $lunch['planned_start_at'] = '2026-11-01 04:52:00';
        $r = service('schedule')->reflow($slam, [$d[0], $tea, $d[1], $lunch, $d[2]]);
        $this->assertStringContainsString('Pinned slot cannot fit', $r['errors'][0]['message']);
    }

    public function testReorderPreviewEqualsAppliedResult(): void
    {
        $s       = $this->makeSlam(3);
        $order   = [$s['items'][2], $s['items'][0], $s['items'][1]];
        $preview = service('schedule')->reorder($s['id'], $order, true, false, true, $this->admin);
        $this->assertTrue($preview['preview']);
        $applied = service('schedule')->reorder($s['id'], $order, true, false, false, $this->admin);
        $this->assertSame($preview['changes'], $applied['changes']);
        $rows = Db::rows('SELECT id, planned_start_at FROM schedule_items WHERE slam_id = ? ORDER BY position', [$s['id']]);
        $this->assertSame($order, array_map('intval', array_column($rows, 'id')));
        $this->assertSame('2026-11-01 04:45:00', $rows[0]['planned_start_at']);
        $this->assertSame('2026-11-01 04:52:00', $rows[1]['planned_start_at']);
    }

    public function testStaleBaseVersionGets409AndSnapshotRestores(): void
    {
        $s    = $this->makeSlam(2);
        $slam = service('slams')->find($s['id']);
        $v    = (int) $slam['schedule_version'];
        $res  = service('schedule')->saveDraft($s['id'], $this->draft($s['id']), $v, $this->admin, 'first');
        $this->assertSame($v + 1, $res['version']);
        try {
            service('schedule')->saveDraft($s['id'], $this->draft($s['id']), $v, $this->admin);
            $this->fail('expected conflict');
        } catch (DomainException $e) {
            $this->assertSame('version_conflict', $e->reason);
            $this->assertSame(409, $e->status);
            $this->assertSame($v + 1, $e->details['current_version']);
        }
        $restored = service('schedule')->restoreDraft($s['id'], $v + 1, $this->admin);
        $res2     = service('schedule')->saveDraft($s['id'], $restored, $v + 1, $this->admin, 'restored');
        $this->assertSame($v + 2, $res2['version']);
    }

    public function testLiveModeRejectsChangesToLockedSlots(): void
    {
        $s = $this->makeSlam(2);
        $this->at('2026-11-01 04:30:00', $s['id']);
        $this->at('2026-11-01 04:45:00', $s['id']);
        $d = $this->draft($s['id']);
        $d[0]['duration_minutes'] = 9; // open slot
        $slam = service('slams')->find($s['id']);
        try {
            service('schedule')->saveDraft($s['id'], $d, (int) $slam['schedule_version'], $this->admin);
            $this->fail('expected locked_slot');
        } catch (DomainException $e) {
            $this->assertSame('locked_slot', $e->reason);
        }
        // Pending slots can still change.
        $d = $this->draft($s['id']);
        $d[1]['duration_minutes'] = 8;
        $res = service('schedule')->saveDraft($s['id'], $d, (int) $slam['schedule_version'], $this->admin);
        $this->assertSame(8, (int) Db::value('SELECT duration_minutes FROM schedule_items WHERE id = ?', [$s['items'][1]]));
        $this->assertGreaterThan((int) $slam['schedule_version'], $res['version']);
    }

    public function testSeededShuffleIsReproducible(): void
    {
        $list = range(1, 20);
        $a    = ScheduleService::seededShuffle($list, 12345);
        $b    = ScheduleService::seededShuffle($list, 12345);
        $c    = ScheduleService::seededShuffle($list, 54321);
        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
        $this->assertNotSame($list, $a);
        sort($a);
        $this->assertSame($list, $a);
    }

    public function testGenerateWithTeaBreakEveryTwo(): void
    {
        $s   = $this->makeSlam(4);
        $gen = service('schedule')->generate($s['id'], [
            'first_start' => '2026-11-01T05:00:00Z', 'duration' => 6, 'changeover' => 1,
            'tea' => ['mode' => 'every', 'every_n' => 2, 'minutes' => 10], 'order' => 'shuffle', 'seed' => 42, 'mode' => 'replace',
        ], $this->admin);
        $this->assertSame([], $gen['errors']);
        $types = array_column($gen['items'], 'item_type');
        $this->assertSame(['performance', 'performance', 'break', 'performance', 'performance'], $types);
        $this->assertSame('2026-11-01T05:00:00Z', $gen['items'][0]['planned_start_at']);
        $this->assertSame('2026-11-01T05:07:00Z', $gen['items'][1]['planned_start_at']);
        $this->assertSame('2026-11-01T05:13:00Z', $gen['items'][2]['planned_start_at']); // tea right after, no changeover
        $this->assertSame('2026-11-01T05:23:00Z', $gen['items'][3]['planned_start_at']);
        $again = service('schedule')->generate($s['id'], ['first_start' => '2026-11-01T05:00:00Z', 'duration' => 6, 'changeover' => 1, 'tea' => ['mode' => 'every', 'every_n' => 2, 'minutes' => 10], 'order' => 'shuffle', 'seed' => 42], $this->admin);
        $this->assertSame(array_column($gen['items'], 'performance_id'), array_column($again['items'], 'performance_id'));
    }

    public function testBreakExtensionShiftsLaterSlotsAndBreakShowsAsCurrent(): void
    {
        $s    = $this->makeSlam(2);
        $slam = service('slams')->find($s['id']);
        $d    = $this->draft($s['id']);
        array_splice($d, 1, 0, [[
            'id' => null, 'item_type' => 'break', 'break_type' => 'tea', 'label' => 'Tea break',
            'planned_start_at' => '2026-11-01T04:50:00Z', 'duration_minutes' => 15, 'is_pinned' => false, 'status' => 'pending',
        ]]);
        $d[2]['planned_start_at'] = '2026-11-01T05:05:00Z';
        service('schedule')->saveDraft($s['id'], $d, (int) $slam['schedule_version'], $this->admin);
        $this->at('2026-11-01 04:45:00', $s['id']);
        $this->at('2026-11-01 04:50:00', $s['id']);
        $live = service('liveState')->buildShared($s['id']);
        $this->assertSame('break', $live['current']['kind']);
        $this->assertSame('2026-11-01T05:05:00Z', $live['current']['ends_at']);
        $breakId = (int) Db::value("SELECT id FROM schedule_items WHERE item_type = 'break'");
        service('slamPhase')->extendBreak($s['id'], $breakId, 5, $this->admin);
        $this->assertSame('2026-11-01 05:10:00', Db::value('SELECT planned_start_at FROM schedule_items WHERE id = ?', [$s['items'][1]]));
        $this->at('2026-11-01 05:09:59', $s['id']);
        $this->assertSame('pending', Db::value('SELECT status FROM schedule_items WHERE id = ?', [$s['items'][1]]));
        $this->at('2026-11-01 05:10:00', $s['id']);
        $this->assertSame('open', Db::value('SELECT status FROM schedule_items WHERE id = ?', [$s['items'][1]]));
    }

    public function testCannotRemovePerformanceWithReviews(): void
    {
        $s = $this->makeSlam(1);
        $this->at('2026-11-01 03:30:00', $s['id']);
        $this->checkIn($s['users'][0]);
        $this->at('2026-11-01 04:45:00', $s['id']);
        service('reviews')->submit($s['users'][0], $s['items'][0], $this->answers($s['id'], 'user', 5), null, $this->actorFor($s['users'][0]));
        $this->expectException(DomainException::class);
        service('schedule')->deleteItem($s['items'][0], $this->admin);
    }
}
