<?php

declare(strict_types=1);

namespace Tests\Feature;

use Modules\Core\Libraries\Db;
use Modules\PublicSite\Services\PresenterMapService;
use Tests\Support\SlamTestCase;

/** The presenters map: published slams only, each presenter at their institution, OpenStreetMap allowed on that page only. */
final class PresenterMapTest extends SlamTestCase
{
    use \CodeIgniter\Test\FeatureTestTrait;

    public function testEveryPlaceInTheDataFileIsUsableAndMatchingIsByText(): void
    {
        $places = PresenterMapService::places();
        $this->assertGreaterThan(50, count($places));
        foreach ($places as $p) {
            $this->assertNotEmpty($p['match'], $p['name']);
            $this->assertTrue($p['lat'] > -90 && $p['lat'] < 90 && $p['lng'] > -180 && $p['lng'] < 180, $p['name']);
        }
        $i = PresenterMapService::placeFor('Department of Physics, Cochin University of Science and Technology', $places);
        $this->assertSame('Cochin University of Science and Technology', $places[$i]['name']);
        $this->assertNull(PresenterMapService::placeFor('Somewhere Unknown', $places));
        $this->assertNull(PresenterMapService::placeFor(null, $places));
    }

    public function testThePageShowsPublishedPresentersAndAllowsOnlyTheTileServer(): void
    {
        $pub = $this->makeSlam(2, 1, 0);
        $hidden = $this->makeSlam(1, 1, 0);
        $perf = fn (array $s, int $k) => Db::value('SELECT presenter_id FROM performances WHERE id = ?', [$s['perfs'][$k]]);
        db_connect()->table('presenters')->where('id', $perf($pub, 0))->update(['name' => 'Asha Map', 'designation_or_institution' => "Maharaja's College, Ernakulam"]);
        db_connect()->table('presenters')->where('id', $perf($pub, 1))->update(['name' => 'Ravi Nowhere', 'designation_or_institution' => 'A Place Not In The List']);
        db_connect()->table('presenters')->where('id', $perf($hidden, 0))->update(['name' => 'Hidden Person', 'designation_or_institution' => "Maharaja's College, Ernakulam"]);
        service('slams')->setPublished($pub['id'], true, $this->admin);
        cache()->delete('presenter_map_v1');

        $data = (new PresenterMapService())->data();
        $names = array_merge(...array_map(static fn ($p) => array_column($p['people'], 'name'), $data['places']));
        $this->assertContains('Asha Map', $names);
        $this->assertNotContains('Hidden Person', $names, 'an unpublished slam stays off the map');
        $this->assertSame(['Ravi Nowhere'], array_column($data['unplaced'], 'name'));

        $res  = $this->get('map');
        $res->assertOK();
        $body = (string) $res->response()->getBody();
        $this->assertStringContainsString('Asha Map', $body);
        $this->assertStringNotContainsString('Hidden Person', $body);
        $this->assertStringContainsString('https://tile.openstreetmap.org', $res->response()->getHeaderLine('Content-Security-Policy'));
        cache()->delete('presenter_map_v1');
    }

    public function testOtherPagesKeepTheStrictPolicy(): void
    {
        // A test of its own: the test client keeps one response object between requests.
        $this->assertStringNotContainsString('openstreetmap', $this->get('slams')->response()->getHeaderLine('Content-Security-Policy'));
    }
}
