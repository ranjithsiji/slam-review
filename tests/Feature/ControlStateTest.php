<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Test\FeatureTestTrait;
use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\Db;
use Tests\Support\SlamTestCase;

/** The Status screen's data must work in every state, for admins and for a slam operator. */
final class ControlStateTest extends SlamTestCase
{
    use FeatureTestTrait;

    private function state(int $slamId, array $auth): array
    {
        $r = $this->withSession(['auth' => $auth + ['pwd' => (string) Db::value('SELECT password_changed_at FROM admins WHERE id = ?', [$auth['id']])]])
            ->withHeaders(['Accept' => 'application/json'])->get("api/slams/{$slamId}/control-state");
        $this->assertSame(200, $r->response()->getStatusCode(), $r->response()->getBody());

        return json_decode($r->response()->getBody(), true);
    }

    public function testStatusWorksWhileABreakIsRunningForAdminAndOperator(): void
    {
        $s = $this->makeSlam(3, 2, 1, ['auto_mode' => 'manual']);
        $id = $s['id'];
        $admin = $this->admin;
        $tea = service('schedule')->appendItem($id, ['item_type' => 'break', 'break_type' => 'tea', 'label' => 'Tea break', 'duration_minutes' => 15], $admin);
        service('schedule')->reorder($id, [$s['items'][0], $tea, $s['items'][1], $s['items'][2]], true, false, false, $admin);
        Clock::set('2026-11-01 03:00:00');
        $phase = service('slamPhase');
        $phase->activate($id, $admin);
        $phase->gateOpen($id, $admin);
        $phase->gateClose($id, $admin);
        $phase->startPerformance($id, $s['items'][0], $admin);
        $phase->openWindow($id, $s['items'][0], 5, null, $admin);
        $phase->endPerformance($id, $s['items'][0], $admin);
        $phase->closeWindow($id, 'done', $admin);
        $phase->startBreakNow($id, $tea, $admin);

        $adminAuth = ['type' => 'admin', 'id' => (int) $admin->id, 'label' => 'admin', 'name' => 'Admin', 'slam_id' => null, 'role' => 'admin'];
        $st = $this->state($id, $adminAuth);
        $brk = array_values(array_filter($st['items'], static fn ($i) => $i['item_type'] === 'break'))[0];
        $this->assertSame('open', $brk['status']);
        $this->assertNull($brk['window_closes_at']);

        // The same screen for a slam operator bound to this slam.
        $opId = service('auth')->saveAdmin(null, ['username' => 'op.state', 'name' => 'Op', 'role' => 'operator', 'slam_id' => $id, 'password' => 'a-long-password', 'is_active' => 1], $admin);
        $st = $this->state($id, ['type' => 'admin', 'id' => $opId, 'label' => 'op.state', 'name' => 'Op', 'slam_id' => $id, 'role' => 'operator']);
        $this->assertSame('reviewing', $st['slam']['status']);
    }

    public function testStaleScreensAreRefusedWhenAnotherOperatorChangedTheSlam(): void
    {
        $s = $this->makeSlam(2, 1, 0, ['auto_mode' => 'manual']);
        $id = $s['id'];
        $admin = $this->admin;
        $phase = service('slamPhase');
        Clock::set('2026-11-01 03:00:00');
        $phase->activate($id, $admin);
        $auth = ['type' => 'admin', 'id' => (int) $admin->id, 'label' => 'admin', 'name' => 'Admin', 'slam_id' => null, 'role' => 'admin'];
        $viewA = $this->state($id, $auth)['version'];       // operator A looks at the screen
        $phase->gateOpen($id, $admin);                      // operator B opens the gates meanwhile
        $this->assertNotSame($viewA, $phase->stateVersion($id));

        $filter = new \Modules\Core\Filters\FreshStateFilter();
        $post = function (?string $version) use ($id, $filter) {
            $app = config('App');
            $req = new \CodeIgniter\HTTP\IncomingRequest($app, new \CodeIgniter\HTTP\SiteURI($app, "api/slams/{$id}/checkin-gate/close"), $version === null ? '{}' : json_encode(['base_version' => $version]), new \CodeIgniter\HTTP\UserAgent());
            $req->setMethod('POST');
            $req->setHeader('Content-Type', 'application/json');
            $res = $filter->before($req);

            return [$res === null ? 200 : $res->getStatusCode(), $res === null ? null : json_decode($res->getBody(), true)];
        };
        [$code, $body] = $post($viewA);                     // A presses "Close gate" from the old picture
        $this->assertSame([409, 'stale'], [$code, $body['reason']]);
        [$code] = $post(null);                              // clients that send no version are not checked here
        $this->assertSame(200, $code);

        [$code] = $post($phase->stateVersion($id));         // after refreshing, the same action goes through
        $this->assertSame(200, $code);
    }
}
