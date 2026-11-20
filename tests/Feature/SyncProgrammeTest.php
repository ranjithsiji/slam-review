<?php

declare(strict_types=1);

namespace Tests\Feature;

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
}
