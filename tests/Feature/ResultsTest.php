<?php

declare(strict_types=1);

namespace Tests\Feature;

use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;
use Tests\Support\SlamTestCase;

/** Top 5 result sheet: names without positions, A4 PDF, and publishing to the home page. */
final class ResultsTest extends SlamTestCase
{
    use \CodeIgniter\Test\FeatureTestTrait;

    public function testChooseFiveFinalistsAndPrintThemByName(): void
    {
        $s   = $this->makeSlam(6, 1, 0);
        $sid = $s['id'];
        $svc = new \Modules\Scoring\Services\FinalistsService();
        $perfs = $s['perfs'];
        try {
            $svc->save($sid, [], $this->admin, null);
            $this->fail('nobody');
        } catch (\Modules\Core\Libraries\DomainException $e) {
            $this->assertStringContainsString('at least one', $e->getMessage());
        }
        // Any number: six, or three, no reason needed.
        $svc->save($sid, array_slice($perfs, 0, 6), $this->admin, null);
        $this->assertCount(6, $svc->chosen($sid));
        $svc->save($sid, array_slice($perfs, 0, 3), $this->admin, null);
        $this->assertCount(3, $svc->chosen($sid));
        $other = $this->makeSlam(1, 1, 0);
        try {
            $svc->save($sid, [...array_slice($perfs, 0, 4), $other['perfs'][0]], $this->admin, null);
            $this->fail('another slam');
        } catch (\Modules\Core\Libraries\DomainException $e) {
            $this->assertStringContainsString('this slam', $e->getMessage());
        }
        $svc->save($sid, array_slice($perfs, 1, 5), $this->admin, 'tie decided by the experts');
        $names = array_column($svc->chosen($sid), 'name');
        $this->assertCount(5, $names);
        $sorted = $names; sort($sorted, SORT_STRING | SORT_FLAG_CASE);
        $this->assertSame($sorted, $names, 'alphabetical');
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action = 'results.finalists_saved' AND reason = 'tie decided by the experts'"));

        $row  = Db::row('SELECT id, username, password_changed_at FROM admins WHERE id = ?', [(int) $this->admin->id]);
        $auth = ['type' => 'admin', 'id' => (int) $row['id'], 'username' => $row['username'], 'name' => 'Admin', 'label' => 'Admin', 'role' => 'admin', 'pwd' => (string) $row['password_changed_at']];
        $page = (string) $this->withSession(['auth' => $auth])->get("admin/slams/{$sid}/finalists")->response()->getBody();
        $this->assertStringContainsString('Selected for the final', $page);
        $pdf = $this->withSession(['auth' => $auth])->get("admin/slams/{$sid}/finalists.pdf")->response();
        $this->assertSame(200, $pdf->getStatusCode());
        $this->assertStringStartsWith('%PDF', (string) $pdf->getBody());
        // The published result is the chosen finalists (the expert panel has the final say), names only.
        $top = service('results')->top($sid);
        $this->assertSame($svc->chosen($sid) === [] ? [] : array_column($svc->chosen($sid), 'name'), array_column($top, 'presenter_name'));
        $this->assertSame([null], array_values(array_unique(array_column($top, 'rank'))), 'no positions');
        db_connect()->table('slams')->where('id', $sid)->update(['status' => 'closed']);
        service('results')->setPublic($sid, true, $this->admin);   // allowed without a marks run, finalists are chosen
        $pub = array_values(array_filter(service('results')->published(), static fn ($x) => (int) $x['slam']['id'] === $sid));
        $this->assertCount(1, $pub);
        $this->assertCount(count($svc->chosen($sid)), $pub[0]['top']);
        $poster = (string) $this->withSession(['auth' => $auth])->get("admin/slams/{$sid}/finalists/poster")->response()->getBody();
        $this->assertStringContainsString('data-poster=', $poster);
        $this->assertStringContainsString(esc($svc->chosen($sid)[0]['name'], 'attr'), $poster);
        // An operator of this slam can use it too.
        $op = service('auth')->saveAdmin(null, ['username' => 'op.final', 'name' => 'Op', 'role' => 'operator', 'slam_id' => $sid, 'password' => 'a-long-password', 'is_active' => 1], $this->admin);
        $opAuth = ['type' => 'admin', 'id' => $op, 'label' => 'Op', 'role' => 'operator', 'slam_id' => $sid, 'pwd' => (string) Db::value('SELECT password_changed_at FROM admins WHERE id = ?', [$op])];
        $this->assertSame(200, $this->withSession(['auth' => $opAuth])->get("admin/slams/{$sid}/finalists")->response()->getStatusCode());
    }

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

    public function testFinalResultsAreRankedWithPositionsAndMarks(): void
    {
        $s = $this->makeSlam(4, 3, 0, ['type' => 'final']);
        $id = $s['id'];
        db_connect()->table('review_questions')->where('slam_id', $id)->where('audience', 'user')->update(['max_score' => 5]);
        $this->at('2026-11-01 03:30:00', $id);
        foreach ($s['users'] as $u) {
            $this->checkIn($u);
        }
        foreach (range(0, 3) as $k) {
            $this->at(date('Y-m-d H:i:s', strtotime('2026-11-01 04:45:00') + $k * 7 * 60), $id);
        }
        foreach (['05:11:00', '05:21:00', '05:26:00'] as $t) {
            $this->at("2026-11-01 {$t}", $id);
        }
        $this->assertContains($this->phase($id), ['closed', 'marks_calculated']);
        // Talk k gets higher marks the later it is: distinct levels, so the order is clear.
        foreach ($s['items'] as $k => $item) {
            $rows = array_map(static fn ($user) => ['badge_id' => $user['badge_id'], 'scores' => [min(5, 1 + $k), min(5, 1 + $k)]], $s['users']);
            service('paperMarks')->saveAudience($id, (int) $item, $rows, $this->admin);
        }

        $top = service('results')->top($id);
        $this->assertCount(3, $top);                                            // podium of three in the final
        $this->assertSame([1, 2, 3], array_column($top, 'rank'));               // positions, best first (not alphabetical)
        $marks = array_column($top, 'final_mark');
        $this->assertSame($marks, array_values(array_reverse(array_values(array_reverse($marks)))));
        $this->assertGreaterThan($marks[1], $marks[0]);
        $all = service('results')->ranking($id);
        $this->assertCount(4, $all);
        $this->assertSame([1, 2, 3, 4], array_column($all, 'rank'));
        $this->assertEqualsWithDelta(80.0, $all[0]['final_mark'] * 10, 1e-6);   // talk 4: 4+4... = 8/10 → 80.00 out of 100
        $this->assertStringStartsWith('%PDF', service('resultsPdf')->sheet(service('slams')->find($id), $top, false));
        // The regional rule is unchanged: names only, alphabetical.
        $regional = $this->makeSlam(1, 1, 0);
        $this->assertSame([], service('results')->top($regional['id']));
    }
}
