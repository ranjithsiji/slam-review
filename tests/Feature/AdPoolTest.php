<?php

declare(strict_types=1);

namespace Tests\Feature;

use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;
use Modules\Core\Libraries\OperatorAccess;
use Tests\Support\SlamTestCase;

/** Break ads: a slam's own ads plus the pool ads it chose; the pool is for full admins. */
final class AdPoolTest extends SlamTestCase
{
    use \CodeIgniter\Test\FeatureTestTrait;

    public function testASlamShowsItsOwnAdsThenThePoolAdsItChose(): void
    {
        $a = $this->makeSlam(1, 1, 0);
        $b = $this->makeSlam(1, 1, 0);
        $ads = service('ads');
        $own  = $ads->save($a['id'], null, ['kind' => 'text', 'headline' => 'Own ad of A', 'is_active' => 1], null, $this->admin);
        $p1   = $ads->save(null, null, ['kind' => 'text', 'headline' => 'Pool one', 'is_active' => 1, 'sort_order' => 1], null, $this->admin);
        $p2   = $ads->save(null, null, ['kind' => 'text', 'headline' => 'Pool two', 'is_active' => 1, 'sort_order' => 2], null, $this->admin);
        $this->assertSame(['Own ad of A'], array_column($ads->forBreak($a['id']), 'headline'), 'pool ads show only when chosen');
        $this->assertSame([], $ads->forBreak($b['id']));

        $ads->choosePool($a['id'], [$p2, $p1], $this->admin);
        $ads->choosePool($b['id'], [$p1], $this->admin);
        $this->assertSame(['Own ad of A', 'Pool two', 'Pool one'], array_column($ads->forBreak($a['id']), 'headline'), 'own first, then the chosen order');
        $this->assertSame(['Pool one'], array_column($ads->forBreak($b['id']), 'headline'));
        $this->assertSame([['id' => $a['id'], 'name' => 'Test Slam'], ['id' => $b['id'], 'name' => 'Test Slam']], array_values(array_filter($ads->pool(), static fn ($x) => (int) $x['id'] === $p1))[0]['slams']);

        // Switching a pool ad off hides it at every slam; deleting it removes it from every slam.
        $ads->save(null, $p1, ['kind' => 'text', 'headline' => 'Pool one', 'is_active' => 0], null, $this->admin);
        $this->assertSame([], $ads->forBreak($b['id']));
        $ads->delete(null, $p2, $this->admin);
        $this->assertSame(['Own ad of A'], array_column($ads->forBreak($a['id']), 'headline'));
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM slam_ads WHERE ad_id = ?', [$p2]));

        // A slam's own ad cannot be "chosen" by another slam, and the pool screen cannot edit it.
        try {
            $ads->choosePool($b['id'], [$own], $this->admin);
            $this->fail('only pool ads can be chosen');
        } catch (DomainException $e) {
            $this->assertSame('validation', $e->reason);
        }
        $this->assertNull($ads->find(null, $own));
        $this->assertNull($ads->find($b['id'], $own));
    }

    public function testThePoolScreenIsForAdminsAndTheSlamScreenShowsThePool(): void
    {
        $s = $this->makeSlam(1, 1, 0);
        service('ads')->save(null, null, ['kind' => 'text', 'headline' => 'Pooled sponsor', 'is_active' => 1], null, $this->admin);
        $req = static function (string $path) {
            $app = config('App');
            $r   = new \CodeIgniter\HTTP\IncomingRequest($app, new \CodeIgniter\HTTP\SiteURI($app, $path), null, new \CodeIgniter\HTTP\UserAgent());
            $r->setMethod('GET');

            return $r;
        };
        $this->assertFalse(OperatorAccess::allows($req('admin/ads'), $s['id']), 'operators cannot reach the pool');
        $this->assertTrue(OperatorAccess::allows($req("admin/slams/{$s['id']}/ads"), $s['id']), 'operators manage their own slam\'s ads');

        $auth = ['type' => 'admin', 'id' => $this->admin->id, 'label' => 'admin', 'role' => 'admin', 'slam_id' => null, 'pwd' => (string) Db::value('SELECT password_changed_at FROM admins WHERE id = ?', [$this->admin->id])];
        $page = (string) $this->withSession(['auth' => $auth])->get("admin/slams/{$s['id']}/ads")->response()->getBody();
        $this->assertStringContainsString('From the ad pool', $page);
        $this->assertStringContainsString('Pooled sponsor', $page);
        $pool = (string) $this->withSession(['auth' => $auth])->get('admin/ads')->response()->getBody();
        $this->assertStringContainsString('Ad pool (1)', $pool);
        $this->assertStringContainsString('Not chosen by any slam yet.', $pool);
    }
}
