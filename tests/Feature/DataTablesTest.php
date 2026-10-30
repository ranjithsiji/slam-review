<?php

declare(strict_types=1);

namespace Tests\Feature;

use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;
use Tests\Support\SlamTestCase;

final class DataTablesTest extends SlamTestCase
{
    private function expectReason(string $reason, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Expected {$reason}");
        } catch (DomainException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    public function testPagingCapAndContract(): void
    {
        $s   = $this->makeSlam(1, 120, 0);
        $res = service('dataTables')->handle('attendees', ['draw' => 7, 'start' => 0, 'length' => 1000, 'filters' => ['slam_id' => $s['id']]], $this->admin);
        $this->assertSame(7, $res['draw']);
        $this->assertSame(120, $res['recordsTotal']);
        $this->assertCount(100, $res['data']);
        $this->assertSame(['id', 'name', 'badge_id', 'user_type', 'mobile', 'email', 'checked_in', 'badge_print_count', 'import_batch_id', 'is_active'], array_keys($res['data'][0]));
    }

    public function testUnknownColumnFilterAndUnsortableAreRejected(): void
    {
        $s  = $this->makeSlam(1, 2, 0);
        $dt = service('dataTables');
        $this->expectReason('invalid_column', fn () => $dt->handle('attendees', ['filters' => ['slam_id' => $s['id']], 'columns' => [['data' => 'password_hash']]], $this->admin));
        $this->expectReason('invalid_order', fn () => $dt->handle('attendees', ['filters' => ['slam_id' => $s['id']], 'columns' => [['data' => 'mobile']], 'order' => [['column' => 0, 'dir' => 'asc']]], $this->admin));
        $this->expectReason('invalid_filter', fn () => $dt->handle('attendees', ['filters' => ['slam_id' => $s['id'], 'x' => 1]], $this->admin));
        $this->expectReason('filter_required', fn () => $dt->handle('attendees', [], $this->admin));
        $this->expectReason('unknown_table', fn () => $dt->handle('nope', [], $this->admin));
    }

    public function testInjectionStringsAreHarmless(): void
    {
        $s  = $this->makeSlam(1, 3, 0);
        $dt = service('dataTables');
        foreach (["' OR 1=1 --", '"; DROP TABLE attendees; --', '%_%', "\\' UNION SELECT password_hash FROM admins --"] as $evil) {
            $res = $dt->handle('attendees', ['filters' => ['slam_id' => $s['id']], 'search' => ['value' => $evil]], $this->admin);
            $this->assertSame(0, $res['recordsFiltered'], $evil);
        }
        // Direction strings are normalised, never interpolated.
        $res = $dt->handle('attendees', ['filters' => ['slam_id' => $s['id']], 'columns' => [['data' => 'name']], 'order' => [['column' => 0, 'dir' => 'desc; DROP TABLE x']]], $this->admin);
        $this->assertSame(3, $res['recordsTotal']);
        $this->assertSame(3, (int) Db::value('SELECT COUNT(*) FROM attendees WHERE slam_id = ?', [$s['id']]));
    }

    public function testVolunteerSeesOnlyOwnSlamAndReviewerIsRefused(): void
    {
        $a  = $this->makeSlam(1, 3, 0);
        $b  = $this->makeSlam(1, 4, 0);
        $vol = new Actor('volunteer', 1, 'v', $a['id']);
        // Asking for another slam's rows still returns only the volunteer's slam (scope AND filter).
        $res = service('dataTables')->handle('attendee-search', ['filters' => ['slam_id' => $b['id'], 'q' => 'Person']], $vol);
        $this->assertSame(0, $res['recordsFiltered']);
        $this->assertSame([], $res['data']);
        $res = service('dataTables')->handle('attendee-search', ['filters' => ['slam_id' => $a['id'], 'q' => 'Person']], $vol);
        $this->assertSame(3, $res['recordsFiltered']);
        $this->assertStringStartsWith('••••••', $res['data'][0]['mobile']);
        // Admin-only tables are refused for volunteers; reviewers get nothing.
        $this->expectReason('forbidden', fn () => service('dataTables')->handle('attendees', ['filters' => ['slam_id' => $a['id']]], $vol));
        $this->expectReason('forbidden', fn () => service('dataTables')->handle('checkins', ['filters' => ['slam_id' => $a['id']]], $this->actorFor($a['users'][0])));
        // Volunteers only see check-in/out entries in the activity log.
        $res = service('dataTables')->handle('activity-log', [], $vol);
        foreach ($res['data'] as $row) {
            $this->assertMatchesRegularExpression('/^check(in|out)\./', $row['action']);
        }
    }

    public function testExportIsAdminOnlyFilteredAndCsvSafe(): void
    {
        $s = $this->makeSlam(1, 2, 1);
        db_connect()->table('attendees')->where('id', $s['users'][0]['id'])->update(['name' => '=HYPERLINK("x")']);
        $csv = service('dataTables')->exportCsv('attendees', ['filters' => ['slam_id' => $s['id'], 'user_type' => 'user']], $this->admin);
        $lines = array_filter(explode("\n", trim($csv)));
        $this->assertCount(3, $lines); // header + 2 users (judge filtered out)
        $this->assertStringNotContainsString('=HYPERLINK', $csv);
        $this->expectReason('forbidden', fn () => service('dataTables')->exportCsv('checkins', ['filters' => ['slam_id' => $s['id']]], new Actor('volunteer', 1, 'v', $s['id'])));
    }

    public function testProvisionalMarksSortInSql(): void
    {
        $s = $this->makeSlam(2, 1, 0);
        $res = service('dataTables')->handle('marks', ['filters' => ['slam_id' => $s['id'], 'run_id' => 'provisional'], 'columns' => [['data' => 'presenter_name']], 'order' => [['column' => 0, 'dir' => 'desc']]], $this->admin);
        $this->assertSame(['Presenter 2', 'Presenter 1'], array_column($res['data'], 'presenter_name'));
        $this->assertSame(1, (int) $res['data'][0]['provisional']);
    }
}
