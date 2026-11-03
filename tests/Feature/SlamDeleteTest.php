<?php

declare(strict_types=1);

namespace Tests\Feature;

use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;
use Tests\Support\SlamTestCase;

final class SlamDeleteTest extends SlamTestCase
{
    private function slamWithStatus(string $status): int
    {
        $this->seedTemplates();
        $id = (int) $this->makeSlam()['id'];
        db_connect()->table('slams')->where('id', $id)->update(['status' => $status]);

        return $id;
    }

    public function testDeletesAnUnopenedSlamAndKeepsTheActivityLog(): void
    {
        $id = $this->slamWithStatus('scheduled');

        service('slams')->delete($id, $this->admin);

        $this->assertNull(Db::value('SELECT id FROM slams WHERE id = ?', [$id]));
        foreach (['schedule_items', 'performances', 'attendees', 'review_questions', 'score_config'] as $table) {
            $this->assertSame(0, (int) Db::value("SELECT COUNT(*) FROM {$table} WHERE slam_id = ?", [$id]), $table);
        }
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action = 'slam.deleted' AND slam_id = ?", [$id]));
    }

    public function testRefusesASlamThatHasOpened(): void
    {
        $id = $this->slamWithStatus('open');

        try {
            service('slams')->delete($id, $this->admin);
            $this->fail('Expected a refusal');
        } catch (DomainException $e) {
            $this->assertSame('not_deletable', $e->reason);
        }
        $this->assertNotNull(Db::value('SELECT id FROM slams WHERE id = ?', [$id]));
    }
}
