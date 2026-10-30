<?php

declare(strict_types=1);

namespace Tests\Feature;

use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;
use Tests\Support\SlamTestCase;

/** Top 5 result sheet: names without positions, A4 PDF, and publishing to the home page. */
final class ResultsTest extends SlamTestCase
{
    public function testTopFiveSheetPublishingAndTwoDecimalAverages(): void
    {
        $s = $this->makeSlam(7, 3, 0);
        $id = $s['id'];
        db_connect()->table('review_questions')->where('slam_id', $id)->where('audience', 'user')->update(['max_score' => 5]);
        $this->at('2026-11-01 03:30:00', $id);
        foreach ($s['users'] as $u) {
            $this->checkIn($u);
        }
        foreach (range(0, 6) as $k) {
            $this->at(date('Y-m-d H:i:s', strtotime('2026-11-01 04:45:00') + $k * 7 * 60), $id);
        }
        foreach (['05:32:00', '05:42:00', '05:47:00'] as $t) {
            $this->at("2026-11-01 {$t}", $id);
        }
        $this->assertContains($this->phase($id), ['closed', 'marks_calculated']);

        // Paper marks give every talk a different level (talk k scores k+1..k+3 out of 5, so averages come out with decimals).
        foreach ($s['items'] as $k => $item) {
            $rows = [];
            foreach ($s['users'] as $u => $user) {
                $rows[] = ['badge_id' => $user['badge_id'], 'scores' => [min(5, 1 + (($k + $u) % 5)), min(5, 1 + (($k * 2 + $u) % 5))]];
            }
            service('paperMarks')->saveAudience($id, (int) $item, $rows, $this->admin);
        }
        $run = service('markRuns')->currentRun($id);
        $rows = service('markRuns')->resultsWithDiff((int) $run['id'])['results'];

        // Display rule: marks out of 100 with two decimals.
        $first = $rows[0];
        $shown = number_format((float) $first['final_mark'] * 10, 2);
        $this->assertMatchesRegularExpression('/^\d{1,3}\.\d{2}$/', $shown);

        $top = service('results')->top($id);
        $this->assertCount(5, $top);                                   // 5 in a regional slam
        $names = array_column($top, 'presenter_name');
        $sorted = $names;
        sort($sorted, SORT_NATURAL | SORT_FLAG_CASE);
        $this->assertSame($sorted, $names, 'alphabetical: no positions implied');
        $best = array_column(array_filter($rows, static fn ($r) => (int) $r['rank'] <= 5), 'presenter_name');
        $this->assertEqualsCanonicalizing($best, $names);

        $pdf = service('resultsPdf')->sheet(service('slams')->find($id), $top, false);
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertStringStartsWith('%PDF', service('resultsPdf')->sheet(service('slams')->find($id), $top, true));

        // Nothing is public until published.
        $this->assertSame([], service('results')->published());
        service('results')->setPublic($id, true, $this->admin);
        $pub = service('results')->published();
        $this->assertCount(1, $pub);
        $this->assertCount(5, $pub[0]['top']);
        service('results')->setPublic($id, false, $this->admin);
        $this->assertSame([], service('results')->published());

        // Cannot publish before marks exist.
        $early = $this->makeSlam(1, 1, 0);
        try {
            service('results')->setPublic($early['id'], true, $this->admin);
            $this->fail('should refuse');
        } catch (DomainException $e) {
            $this->assertSame('forbidden_transition', $e->reason);
        }
    }
}
