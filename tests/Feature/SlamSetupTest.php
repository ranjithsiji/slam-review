<?php

declare(strict_types=1);

namespace Tests\Feature;

use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;
use Tests\Support\SlamTestCase;

final class SlamSetupTest extends SlamTestCase
{
    private function base(array $extra = []): array
    {
        return $extra + [
            'name' => 'Open-ended Slam', 'type' => 'regional', 'venue_name' => 'Hall', 'address_line' => 'Road', 'city' => 'Kochi', 'district' => 'Ernakulam',
            'opens_at' => '2026-12-05 04:00:00',
        ];
    }

    public function testOnlyTheStartIsRequiredAndTheHardStopDefaultsToAnSafetyLimit(): void
    {
        $id   = service('slams')->create($this->base(['checkin_opens_at' => '', 'checkin_closes_at' => null, 'reviews_start_at' => '', 'closes_at' => '', 'auto_mode' => 'manual']), $this->admin);
        $slam = Db::row('SELECT * FROM slams WHERE id = ?', [$id]);
        $this->assertNull($slam['checkin_opens_at']);
        $this->assertNull($slam['checkin_closes_at']);
        $this->assertNull($slam['reviews_start_at']);
        $this->assertSame('2026-12-06 04:00:00', $slam['closes_at'], 'blank hard stop = 24 h after Slam open');
        $this->assertSame('manual', $slam['auto_mode']);
        // An explicit hard stop is kept; auto stays the default for programmatic creation.
        $id2 = service('slams')->create($this->base(['name' => 'Second Slam', 'closes_at' => '2026-12-05 12:00:00']), $this->admin);
        $this->assertSame('2026-12-05 12:00:00', Db::value('SELECT closes_at FROM slams WHERE id = ?', [$id2]));
        $this->assertSame('auto', Db::value('SELECT auto_mode FROM slams WHERE id = ?', [$id2]));
    }

    public function testStartIsStillRequiredAndModeIsChecked(): void
    {
        try {
            service('slams')->create($this->base(['opens_at' => '']), $this->admin);
            $this->fail('Expected validation error');
        } catch (DomainException $e) {
            $this->assertArrayHasKey('opens_at', $e->details);
        }
        try {
            service('slams')->create($this->base(['name' => 'Bad Mode Slam', 'auto_mode' => 'sometimes']), $this->admin);
            $this->fail('Expected validation error');
        } catch (DomainException $e) {
            $this->assertArrayHasKey('auto_mode', $e->details);
        }
    }

    public function testManualSlamIsRunByHandWithoutAnyPlannedTimes(): void
    {
        $s = $this->makeSlam(2, 1, 0, ['checkin_opens_at' => null, 'checkin_closes_at' => null, 'reviews_start_at' => null, 'auto_mode' => 'manual']);
        $id = $s['id'];
        $p  = service('slamPhase');
        // Even at and long after the start time nothing moves by itself: the admin presses each button.
        $this->at('2026-11-01 03:00:00', $id);
        $this->at('2026-11-01 08:00:00', $id);
        $this->assertSame('scheduled', $this->phase($id));
        $p->activate($id, $this->admin);
        $this->assertSame('open', $this->phase($id));
        $this->at('2026-11-01 09:00:00', $id);
        $this->assertSame('open', $this->phase($id));
        $p->gateOpen($id, $this->admin);
        $this->assertSame('checkin_open', $this->phase($id));
        $p->advanceNow($id, $this->admin); // close check-in and open the first review
        $this->assertSame('reviewing', $this->phase($id));
        $this->assertNotNull($p->windows()->openWindow($id));
    }

    public function testReviewersTableFlagsCompleteReviewersAndRepresentatives(): void
    {
        $s  = $this->makeSlam(2, 3, 1);
        $id = $s['id'];
        $this->at('2026-11-01 03:30:00', $id);
        foreach ($s['users'] as $u) {
            $this->checkIn($u);
        }
        $this->at('2026-11-01 04:45:00', $id);
        $sub = fn (array $a, int $item) => service('reviews')->submit($a, $item, $this->answers($id, 'user', 3), null, $this->actorFor($a));
        $sub($s['users'][0], $s['items'][0]);
        $sub($s['users'][1], $s['items'][0]);
        $this->at('2026-11-01 04:52:00', $id);
        $sub($s['users'][0], $s['items'][1]); // only user 0 reviewed both
        $this->at('2026-11-01 04:57:00', $id);

        $dt   = service('dataTables');
        $rows = fn (array $f) => array_column($dt->handle('attendees', ['filters' => ['slam_id' => $id, 'user_type' => 'user'] + $f, 'length' => 50], $this->admin)['data'], null, 'id');
        $all  = $rows([]);
        $this->assertSame([2, 1, 0], [(int) $all[$s['users'][0]['id']]['reviews'], (int) $all[$s['users'][1]['id']]['reviews'], (int) $all[$s['users'][2]['id']]['reviews']]);
        $this->assertSame([1, 0, 0], array_map(static fn ($u) => (int) $all[$u['id']]['complete'], $s['users']));
        $this->assertSame([1, 0, 0], array_map(static fn ($u) => (int) $all[$u['id']]['can_make_rep'], $s['users']));
        $this->assertSame([(int) $s['users'][0]['id']], array_keys($rows(['complete' => '1'])));
        $this->assertCount(2, $rows(['complete' => '0']));

        service('representatives')->add($id, (int) $s['users'][0]['id'], $this->admin);
        $after = $rows([]);
        $this->assertSame(1, (int) $after[$s['users'][0]['id']]['is_representative']);
        $this->assertSame(0, (int) $after[$s['users'][0]['id']]['can_make_rep'], 'already a representative');
        $this->assertSame([(int) $s['users'][0]['id']], array_keys($rows(['representative' => '1'])));
        $this->assertCount(2, $rows(['representative' => '0']));
    }

    public function testSidebarSlamResolution(): void
    {
        $this->assertNull(service('slams')->currentId());
        $a = $this->makeSlam(1, 1, 0);
        $b = $this->makeSlam(1, 1, 0);
        $this->assertSame($a['id'], service('slams')->currentId(), 'the next upcoming slam');
        $this->assertSame($b['id'], service('slams')->currentId($b['id']), 'an explicit slam wins');
        $this->assertSame($a['id'], service('slams')->currentId(9999), 'unknown id falls back');
        $this->at('2026-11-01 03:30:00', $b['id']);
        $this->assertSame($b['id'], service('slams')->currentId(), 'a running slam beats an upcoming one');
    }

    public function testPresentersJoinThePoolFirstAndAreScheduledLater(): void
    {
        $s  = $this->makeSlam(1, 1, 0);
        $id = $s['id'];
        // Added through the presenter form: no schedule time, no slot.
        $r = service('presenters')->addToSlam($id, ['name' => 'Pool Presenter', 'topic' => 'Pooled talk', 'abstract' => 'A'], $this->admin, null, false);
        $this->assertNull($r['schedule_item_id']);
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM schedule_items WHERE performance_id = ?', [$r['performance_id']]));

        $dt   = service('dataTables');
        $rows = fn (array $f) => $dt->handle('performances', ['filters' => ['slam_id' => $id] + $f, 'length' => 50], $this->admin)['data'];
        $this->assertSame(['Pool Presenter'], array_column($rows(['scheduled' => '0']), 'presenter_name'), 'only the pool presenter is unscheduled');
        $this->assertCount(1, $rows(['scheduled' => '1']));
        $this->assertNull($rows(['scheduled' => '0'])[0]['planned_start_at']);

        // Building the schedule from the pool places them.
        service('schedule')->appendItem($id, ['item_type' => 'performance', 'performance_id' => $r['performance_id']], $this->admin);
        $this->assertSame([], $rows(['scheduled' => '0']));
        $this->assertCount(2, $rows(['scheduled' => '1']));
    }
}
