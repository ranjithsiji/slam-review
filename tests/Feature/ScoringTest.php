<?php

declare(strict_types=1);

namespace Tests\Feature;

use Modules\Core\Libraries\Db;
use Modules\Scoring\Services\ScoringService;
use Tests\Support\SlamTestCase;

final class ScoringTest extends SlamTestCase
{
    public function testFinalMarkRenormalisesMissingGroups(): void
    {
        $w = ['w_user' => 40, 'w_judge' => 40, 'w_complete' => 20];
        $this->assertEqualsWithDelta(7.0, ScoringService::finalMark(6.0, 8.0, null, $w), 1e-9); // (40·6+40·8)/80
        $this->assertEqualsWithDelta(8.0, ScoringService::finalMark(null, 8.0, null, $w), 1e-9);
        $this->assertEqualsWithDelta((40 * 6 + 40 * 8 + 20 * 9) / 100, ScoringService::finalMark(6.0, 8.0, 9.0, $w), 1e-9);
        $this->assertNull(ScoringService::finalMark(null, null, null, $w));
    }

    public function testRankTiesShareRankAndOrderByJudgesThenUsers(): void
    {
        $r = ScoringService::rank([
            ['final_mark' => 7.0, 'avg_judge' => 6.0, 'avg_user' => 8.0],
            ['final_mark' => 7.0, 'avg_judge' => 8.0, 'avg_user' => 6.0],
            ['final_mark' => 9.0, 'avg_judge' => 9.0, 'avg_user' => 9.0],
            ['final_mark' => null, 'avg_judge' => null, 'avg_user' => null],
        ]);
        $this->assertSame([1, 2, 2, null], array_column($r, 'rank'));
        $this->assertSame(8.0, $r[1]['avg_judge']);
    }

    public function testEndToEndMarksWithWeightsCompleteReviewersAndRun(): void
    {
        $s  = $this->makeSlam(2, 3, 1);
        $id = $s['id'];
        // Weight user Q1 three times as much as Q2.
        $uq = service('questions')->active($id, 'user');
        db_connect()->table('review_questions')->where('id', $uq[0]['id'])->update(['weight' => 3]);
        $this->at('2026-11-01 03:30:00', $id);
        foreach ([...$s['users'], ...$s['judges']] as $a) {
            $this->checkIn($a);
        }
        $this->at('2026-11-01 04:45:00', $id);
        $sub = function (array $a, int $item, array $scores): void {
            $qs  = service('questions')->active((int) $a['slam_id'], $a['user_type']);
            $ans = array_map(static fn ($q, $sc) => ['question_id' => (int) $q['id'], 'score' => $sc], $qs, $scores);
            service('reviews')->submit($a, $item, $ans, null, $this->actorFor($a));
        };
        // Performance 1: user0 (10,2) → (30+2)/4 = 8; user1 (4,8) → (12+8)/4 = 5; judge (6,6,6) → 6.
        $sub($s['users'][0], $s['items'][0], [10, 2]);
        $sub($s['users'][1], $s['items'][0], [4, 8]);
        $sub($s['judges'][0], $s['items'][0], [6, 6, 6]);
        $this->at('2026-11-01 04:52:00', $id);
        // Performance 2: only user0 → complete reviewer.
        $sub($s['users'][0], $s['items'][1], [7, 7]);

        $calc = service('scoring')->compute($id);
        $byPerf = array_column($calc['results'], null, 'performance_id');
        $p1 = $byPerf[$s['perfs'][0]];
        $this->assertEqualsWithDelta(6.5, $p1['avg_user'], 1e-9);
        $this->assertEqualsWithDelta(6.0, $p1['avg_judge'], 1e-9);
        $this->assertEqualsWithDelta(8.0, $p1['avg_complete'], 1e-9); // only user0 reviewed both
        $this->assertEqualsWithDelta((40 * 6.5 + 40 * 6 + 20 * 8) / 100, $p1['final_mark'], 1e-9);
        $p2 = $byPerf[$s['perfs'][1]];
        $this->assertSame(0, $p2['n_judge']);
        $this->assertEqualsWithDelta(7.0, $p2['final_mark'], 1e-9); // renormalised over user + complete
        $this->assertSame([(int) $s['users'][0]['id']], service('scoring')->completeReviewerIds($id));

        // Finish the slam: the automatic run snapshots these numbers.
        $this->at('2026-11-01 04:57:00', $id);
        $this->at('2026-11-01 05:07:00', $id);
        $this->at('2026-11-01 05:12:00', $id);
        $this->assertSame('marks_calculated', $this->phase($id));
        $run = service('markRuns')->currentRun($id);
        $res = Db::row('SELECT * FROM mark_results WHERE mark_run_id = ? AND performance_id = ?', [$run['id'], $s['perfs'][0]]);
        $this->assertEqualsWithDelta(6.6, (float) $res['final_mark'], 1e-4);

        // Changing weights later never alters the existing run; recalculation makes a new current run.
        service('scoring')->updateWeights($id, ['w_user' => 50, 'w_judge' => 50, 'w_complete_reviewer' => 0], $this->admin);
        $this->assertEqualsWithDelta(6.6, (float) Db::value('SELECT final_mark FROM mark_results WHERE id = ?', [$res['id']]), 1e-4);
        $newRun = service('markRuns')->calculate($id, $this->admin, 'weights corrected');
        $this->assertNotSame((int) $run['id'], $newRun);
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM mark_runs WHERE slam_id = ? AND is_current = 1', [$id]));
        $diff = service('markRuns')->resultsWithDiff($newRun);
        $this->assertSame((int) $run['id'], (int) $diff['previous_run_id']);

        service('slamPhase')->endEvent($id, $this->admin);
        $this->assertSame(1, (int) Db::value('SELECT is_final FROM mark_runs WHERE id = ?', [$newRun]));
        $this->expectException(\Modules\Core\Libraries\DomainException::class);
        service('markRuns')->calculate($id, $this->admin, null);
    }

    public function testWeightsMustSumTo100(): void
    {
        $s = $this->makeSlam(1);
        $this->expectException(\Modules\Core\Libraries\DomainException::class);
        service('scoring')->updateWeights($s['id'], ['w_user' => 50, 'w_judge' => 40, 'w_complete_reviewer' => 20], $this->admin);
    }
}
