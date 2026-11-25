<?php

declare(strict_types=1);

namespace Tests\Modules\Slams;

use Modules\Core\Libraries\Db;
use Tests\Support\SlamTestCase;

/** slam:sync-programme: an unopened slam's timetable follows the published programme; a talk left out is taken out. */
final class SyncProgrammeTest extends SlamTestCase
{
    public function testATalkMissingFromTheProgrammeIsTakenOutAndTheRestMove(): void
    {
        $s  = $this->makeSlam(3, 1, 0);
        $id = $s['id'];
        $this->assertSame('scheduled', $this->phase($id));
        $prog = ['talks' => [
            ['name' => 'Presenter 1', 'start' => '2026-11-01 04:45:00', 'end' => '2026-11-01 04:57:00'],
            ['name' => 'Presenter 3', 'start' => '2026-11-01 04:57:00', 'end' => '2026-11-01 05:09:00'],
        ], 'breaks' => [], 'extras' => []];
        $file = tempnam(sys_get_temp_dir(), 'prog');
        file_put_contents($file, json_encode($prog));
        $slug = (string) Db::value('SELECT slug FROM slams WHERE id = ?', [$id]);

        command("slam:sync-programme {$slug} {$file} --dry-run");
        $this->assertSame(1, (int) Db::value('SELECT is_active FROM performances WHERE id = ?', [$s['perfs'][1]]), 'a dry run changes nothing');

        command("slam:sync-programme {$slug} {$file}");
        $this->assertSame(0, (int) Db::value('SELECT is_active FROM performances WHERE id = ?', [$s['perfs'][1]]));
        $rows = Db::rows("SELECT si.planned_start_at, pr.name FROM schedule_items si JOIN performances p ON p.id = si.performance_id JOIN presenters pr ON pr.id = p.presenter_id
                           WHERE si.slam_id = ? AND p.is_active = 1 ORDER BY si.position", [$id]);
        $this->assertSame([['planned_start_at' => '2026-11-01 04:45:00', 'name' => 'Presenter 1'], ['planned_start_at' => '2026-11-01 04:57:00', 'name' => 'Presenter 3']], $rows);
        unlink($file);
    }

    public function testAddMissingBringsInAPresenterFromAnotherSlamAtTheEnd(): void
    {
        $other = $this->makeSlam(1, 0, 0);
        $moved = (int) Db::value('SELECT presenter_id FROM performances WHERE id = ?', [$other['perfs'][0]]);
        db_connect()->table('presenters')->where('id', $moved)->update(['name' => 'Moved Presenter']);
        db_connect()->table('performances')->where('id', $other['perfs'][0])->update(['topic' => 'Moved topic', 'is_active' => 0]);

        $s  = $this->makeSlam(2, 1, 0);
        $id = $s['id'];
        $prog = ['talks' => [
            ['name' => 'Presenter 1', 'start' => '2026-11-01 04:45:00', 'end' => '2026-11-01 04:57:00'],
            ['name' => 'Presenter 2', 'start' => '2026-11-01 04:57:00', 'end' => '2026-11-01 05:09:00'],
            ['name' => 'Moved Presenter', 'start' => '2026-11-01 05:09:00', 'end' => '2026-11-01 05:21:00'],
        ], 'breaks' => [], 'extras' => []];
        $file = tempnam(sys_get_temp_dir(), 'prog');
        file_put_contents($file, json_encode($prog));
        $slug = (string) Db::value('SELECT slug FROM slams WHERE id = ?', [$id]);

        command("slam:sync-programme {$slug} {$file}");
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM performances WHERE slam_id = ? AND presenter_id = ?', [$id, $moved]), 'without --add-missing nothing changes');
        command("slam:sync-programme {$slug} {$file} --add-missing --dry-run");
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM performances WHERE slam_id = ? AND presenter_id = ?', [$id, $moved]), 'a dry run changes nothing');

        command("slam:sync-programme {$slug} {$file} --add-missing");
        $rows = Db::rows("SELECT si.planned_start_at, si.status, pr.name, p.topic, p.is_active FROM schedule_items si JOIN performances p ON p.id = si.performance_id JOIN presenters pr ON pr.id = p.presenter_id
                           WHERE si.slam_id = ? ORDER BY si.position", [$id]);
        $this->assertSame(['Presenter 1', 'Presenter 2', 'Moved Presenter'], array_column($rows, 'name'));
        $this->assertSame('2026-11-01 05:09:00', $rows[2]['planned_start_at']);
        $this->assertSame(['pending', 'Moved topic', '1'], [$rows[2]['status'], $rows[2]['topic'], (string) $rows[2]['is_active']]);
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM activity_log WHERE slam_id = ? AND action = 'performance.added_from_existing_presenter'", [$id]));
        command("slam:sync-programme {$slug} {$file} --add-missing");
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM performances WHERE slam_id = ? AND presenter_id = ?', [$id, $moved]), 'running it again adds nothing');
        unlink($file);
    }
}
