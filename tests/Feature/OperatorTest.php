<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;
use Modules\Core\Libraries\OperatorAccess;
use Modules\Core\Libraries\OperatorMode;
use Tests\Support\SlamTestCase;

final class OperatorTest extends SlamTestCase
{
    private function req(string $method, string $path, array $post = []): IncomingRequest
    {
        $app = config('App');
        $r   = new IncomingRequest($app, new SiteURI($app, $path), null, new UserAgent());
        $r->setMethod($method);
        $r->setGlobal('post', $post);

        return $r;
    }

    private function allowed(int $slam, string $method, string $path, array $post = []): bool
    {
        return OperatorAccess::allows($this->req($method, $path, $post), $slam);
    }

    public function testOperatorAccountIsBoundToOneSlamAndSignsInWithIt(): void
    {
        $s    = $this->makeSlam(1, 1, 1);
        $auth = service('auth');
        $bad  = null;
        try {
            $auth->saveAdmin(null, ['username' => 'op.none', 'name' => 'No Slam', 'role' => 'operator', 'password' => 'a-long-password', 'is_active' => 1], $this->admin);
        } catch (DomainException $e) {
            $bad = array_keys($e->details);
        }
        $this->assertSame(['slam_id'], $bad);

        $id = $auth->saveAdmin(null, ['username' => 'op.one', 'name' => 'Operator One', 'role' => 'operator', 'slam_id' => $s['id'], 'password' => 'a-long-password', 'is_active' => 1], $this->admin);
        $this->assertSame(['operator', $s['id']], [Db::value('SELECT role FROM admins WHERE id = ?', [$id]), (int) Db::value('SELECT slam_id FROM admins WHERE id = ?', [$id])]);
        $session = $auth->login('admin', 'op.one', 'a-long-password', '10.1.1.1');
        $this->assertSame(['operator', $s['id'], 'admin'], [$session['role'], $session['slam_id'], $session['type']]);

        // An administrator keeps no slam.
        $adm = $auth->saveAdmin(null, ['username' => 'adm.two', 'name' => 'Admin Two', 'role' => 'admin', 'slam_id' => $s['id'], 'password' => 'a-long-password', 'is_active' => 1], $this->admin);
        $this->assertNull(Db::value('SELECT slam_id FROM admins WHERE id = ?', [$adm]));
        $this->assertTrue((new Actor('admin', $id, 'op.one', $s['id'], 'operator'))->isOperator());
    }

    public function testLastAdministratorCannotBecomeAnOperator(): void
    {
        $s    = $this->makeSlam(1, 1, 1);
        $me   = (int) $this->admin->id;
        $name = (string) Db::value('SELECT username FROM admins WHERE id = ?', [$me]);
        $other = service('auth')->saveAdmin(null, ['username' => 'adm.other', 'name' => 'Other Admin', 'password' => 'a-long-password', 'is_active' => 1], $this->admin);
        $thrown = null;
        try {
            service('auth')->saveAdmin($other, ['username' => 'adm.other', 'name' => 'Other Admin', 'role' => 'operator', 'slam_id' => $s['id'], 'is_active' => 1], new Actor('admin', 999999, 'x'));
            service('auth')->saveAdmin($me, ['username' => $name, 'name' => 'Admin', 'role' => 'operator', 'slam_id' => $s['id'], 'is_active' => 1], new Actor('admin', 999999, 'x'));
        } catch (DomainException $e) {
            $thrown = $e->details;
        }
        $this->assertArrayHasKey('role', (array) $thrown);
        $this->assertSame('operator', Db::value('SELECT role FROM admins WHERE id = ?', [$other]));
    }

    public function testOperatorReachesOnlyTheirOwnSlam(): void
    {
        $a = $this->makeSlam(2, 1, 1);
        $b = $this->makeSlam(1, 1, 1);
        $A = $a['id'];
        $B = $b['id'];

        foreach (["admin/slams/{$A}/status", "admin/slams/{$A}/scores", "admin/slams/{$A}/schedule", "admin/slams/{$A}/judges", "admin/slams/{$A}/edit"] as $p) {
            $this->assertTrue($this->allowed($A, 'GET', $p), $p);
            $this->assertFalse($this->allowed($A, 'GET', str_replace((string) $A, (string) $B, $p)), "other slam: {$p}");
        }
        $this->assertTrue($this->allowed($A, 'POST', "api/slams/{$A}/marks/calculate"));
        $this->assertFalse($this->allowed($A, 'POST', "api/slams/{$B}/marks/calculate"));
        $this->assertFalse($this->allowed($A, 'POST', "api/slams/{$B}/timing/pause"));
        $this->assertFalse($this->allowed($A, 'PUT', "api/slams/{$B}/schedule"));

        // Not-reviewing screen and bulk check-out: their own slam only.
        $this->assertTrue($this->allowed($A, 'GET', "admin/slams/{$A}/not-reviewing"));
        $this->assertFalse($this->allowed($A, 'GET', "admin/slams/{$B}/not-reviewing"));
        $this->assertTrue($this->allowed($A, 'POST', 'api/volunteer/idle-checkout')); // the controller takes the slam from the session

        // Global screens stay closed.
        foreach (['admin/slams', 'admin/slams/new', 'admin/users', 'admin/users/new', 'admin/settings', 'admin/volunteers', 'admin/activity-log', 'admin/presenters', 'admin/qr', 'admin/questions/templates', 'admin/reviewers'] as $p) {
            $this->assertFalse($this->allowed($A, 'GET', $p), $p);
        }
        $this->assertFalse($this->allowed($A, 'POST', 'admin/slams/create'));
        $this->assertFalse($this->allowed($A, 'POST', 'admin/users/1/reset-password'));
        $this->assertTrue($this->allowed($A, 'GET', 'admin/account'));
        $this->assertTrue($this->allowed($A, 'POST', 'operator/mode'));

        // Objects are resolved to their slam.
        $myAtt    = (int) $a['users'][0]['id'];
        $theirAtt = (int) $b['users'][0]['id'];
        $this->assertTrue($this->allowed($A, 'GET', "admin/attendees/{$myAtt}/edit"));
        $this->assertFalse($this->allowed($A, 'GET', "admin/attendees/{$theirAtt}/edit"));
        $this->assertFalse($this->allowed($A, 'POST', "admin/attendees/{$theirAtt}/reissue-id"));
        $this->assertFalse($this->allowed($A, 'POST', "api/force-checkout/{$theirAtt}"));
        $this->assertTrue($this->allowed($A, 'POST', "api/force-checkout/{$myAtt}"));
        $this->assertFalse($this->allowed($A, 'POST', 'api/checkin/late-override', ['attendee_id' => $theirAtt]));
        $this->assertTrue($this->allowed($A, 'POST', 'api/checkin/late-override', ['attendee_id' => $myAtt]));

        $this->assertTrue($this->allowed($A, 'PUT', 'api/schedule/items/' . $a['items'][0]));
        $this->assertFalse($this->allowed($A, 'PUT', 'api/schedule/items/' . $b['items'][0]));
        $this->assertFalse($this->allowed($A, 'DELETE', 'api/schedule/items/' . $b['items'][0]));
        $this->assertTrue($this->allowed($A, 'GET', 'admin/performances/' . $a['perfs'][0] . '/edit'));
        $this->assertFalse($this->allowed($A, 'GET', 'admin/performances/' . $b['perfs'][0] . '/edit'));

        // Request bodies must name their own slam.
        $this->assertTrue($this->allowed($A, 'POST', 'admin/attendees/import/preview', ['slam_id' => $A]));
        $this->assertFalse($this->allowed($A, 'POST', 'admin/attendees/import/preview', ['slam_id' => $B]));
        $this->assertFalse($this->allowed($A, 'POST', 'admin/attendees/import/preview', []));
        $this->assertFalse($this->allowed($A, 'POST', 'api/questions', ['slam_id' => 'template']));
    }

    public function testOperatorTablesNeedTheirOwnSlamFilter(): void
    {
        $a = $this->makeSlam(1, 1, 1);
        $b = $this->makeSlam(1, 1, 1);
        $call = function (string $table, array $filters, int $slam): bool {
            $r = $this->req('POST', "api/tables/{$table}");
            $r->setHeader('Content-Type', 'application/json');
            $r->setBody(json_encode(['filters' => $filters]));

            return OperatorAccess::allows($r, $slam);
        };
        $this->assertTrue($call('attendees', ['slam_id' => $a['id']], $a['id']));
        $this->assertFalse($call('attendees', ['slam_id' => $b['id']], $a['id']));
        $this->assertFalse($call('attendees', [], $a['id']));
        $this->assertFalse($call('admins', ['slam_id' => $a['id']], $a['id']));
        $this->assertFalse($call('volunteers', ['slam_id' => $a['id']], $a['id']));
        // The Status screen's live activity: their own slam's log only.
        $this->assertTrue($call('activity-log', ['slam_id' => $a['id']], $a['id']));
        $this->assertFalse($call('activity-log', ['slam_id' => $b['id']], $a['id']));
        $this->assertFalse($call('activity-log', [], $a['id']));
        $this->assertFalse($call('presenters', ['slam_id' => $a['id']], $a['id']));
        $this->assertTrue($call('marks', ['slam_id' => $a['id']], $a['id']));
    }

    public function testModeFollowsThePhaseUntilTheOperatorChooses(): void
    {
        $s = $this->makeSlam(1, 1, 1);
        $this->at('2026-11-01 04:45:00', $s['id']);
        $session = session();
        $session->remove('op_mode');
        $this->assertSame(OperatorMode::RUN, OperatorMode::current($s['id']));   // the slam is live
        OperatorMode::set('prepare');
        $this->assertSame(OperatorMode::PREPARE, OperatorMode::current($s['id']));
        $session->remove('op_mode');
        db_connect()->table('slams')->where('id', $s['id'])->update(['status' => 'scheduled']);
        $this->assertSame(OperatorMode::PREPARE, OperatorMode::current($s['id']));
        // Closed slams open in the marks mode; opening a marks-only screen switches to it, shared screens do not.
        $session->remove('op_mode');
        db_connect()->table('slams')->where('id', $s['id'])->update(['status' => 'closed']);
        $this->assertSame(OperatorMode::AFTER, OperatorMode::current($s['id']));
        OperatorMode::set('prepare');
        OperatorMode::syncFromPath($s['id'], "/admin/slams/{$s['id']}/leaderboard");
        $this->assertSame(OperatorMode::AFTER, OperatorMode::current($s['id']));
        OperatorMode::set('run');
        OperatorMode::syncFromPath($s['id'], "/admin/slams/{$s['id']}/status");
        $this->assertSame(OperatorMode::RUN, OperatorMode::current($s['id']));
        foreach ([...OperatorMode::TABS['prepare'], ...OperatorMode::TABS['run'], ...OperatorMode::TABS['after']] as $key) {
            $this->assertArrayHasKey($key, OperatorMode::LINKS, $key);
        }
    }
}
