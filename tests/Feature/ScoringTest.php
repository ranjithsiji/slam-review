<?php

declare(strict_types=1);

namespace Tests\Feature;

use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;
use Modules\Scoring\Services\ScoringService;
use Tests\Support\SlamTestCase;

final class ScoringTest extends SlamTestCase
{
    private const W = ['w_general' => 40, 'w_representative' => 20, 'w_judge' => 40];

    public function testFinalMarkRenormalisesMissingGroups(): void
    {
        $b = ScoringService::breakdown(['general' => 6.0, 'representative' => null, 'judge' => 8.0], self::W);
        $this->assertEqualsWithDelta(7.0, $b['final'], 1e-9); // (40·6 + 40·8) / 80
        $this->assertEqualsWithDelta(0.5, $b['groups']['judge']['share'], 1e-9);
        $this->assertEqualsWithDelta(4.0, $b['groups']['judge']['contribution'], 1e-9);
        $this->assertNull($b['groups']['representative']['share']);
        $this->assertEqualsWithDelta(8.0, ScoringService::breakdown(['general' => null, 'representative' => null, 'judge' => 8.0], self::W)['final'], 1e-9);
        $this->assertEqualsWithDelta((40 * 6 + 20 * 9 + 40 * 8) / 100, ScoringService::breakdown(['general' => 6.0, 'representative' => 9.0, 'judge' => 8.0], self::W)['final'], 1e-9);
        $this->assertNull(ScoringService::breakdown(['general' => null, 'representative' => null, 'judge' => null], self::W)['final']);
        // A zero-weight group never counts, even with votes.
        $this->assertEqualsWithDelta(6.0, ScoringService::breakdown(['general' => 6.0, 'representative' => 1.0, 'judge' => null], ['w_general' => 100, 'w_representative' => 0, 'w_judge' => 0])['final'], 1e-9);
    }

    public function testReviewMarkIsScaledToTen(): void
    {
        $five = [1 => ['weight' => 1.0, 'max_score' => 5], 2 => ['weight' => 1.0, 'max_score' => 5]];
        $this->assertEqualsWithDelta(7.0, ScoringService::reviewMark([1 => 3, 2 => 4], $five), 1e-9); // 3 + 4
        $this->assertEqualsWithDelta(10.0, ScoringService::reviewMark([1 => 5, 2 => 5], $five), 1e-9);
        $mixed = [1 => ['weight' => 3.0, 'max_score' => 10], 2 => ['weight' => 1.0, 'max_score' => 5]];
        $this->assertEqualsWithDelta(10 * (3 * 8 + 1 * 2) / (3 * 10 + 1 * 5), ScoringService::reviewMark([1 => 8, 2 => 2], $mixed), 1e-9);
        // Judge criteria 3 + 3 + 3 + 1 with weight 1: the marks simply add up (decimals allowed).
        $judge = [1 => ['weight' => 1.0, 'max_score' => 3], 2 => ['weight' => 1.0, 'max_score' => 3], 3 => ['weight' => 1.0, 'max_score' => 3], 4 => ['weight' => 1.0, 'max_score' => 1]];
        $this->assertEqualsWithDelta(7.7, ScoringService::reviewMark([1 => 2.5, 2 => 2.0, 3 => 2.4, 4 => 0.8], $judge), 1e-9);
        $this->assertNull(ScoringService::reviewMark([9 => 5], $five));
    }

    public function testRankTiesShareRankAndOrderByJudgesThenRepresentativesThenGeneral(): void
    {
        $r = ScoringService::rank([
            ['final_mark' => 7.0, 'avg_judge' => 6.0, 'avg_representative' => 9.0, 'avg_general' => 8.0],
            ['final_mark' => 7.0, 'avg_judge' => 8.0, 'avg_representative' => 5.0, 'avg_general' => 6.0],
            ['final_mark' => 7.0, 'avg_judge' => 6.0, 'avg_representative' => 9.5, 'avg_general' => 1.0],
            ['final_mark' => 9.0, 'avg_judge' => 9.0, 'avg_representative' => 9.0, 'avg_general' => 9.0],
            ['final_mark' => null, 'avg_judge' => null, 'avg_representative' => null, 'avg_general' => null],
        ]);
        $this->assertSame([1, 2, 2, 2, null], array_column($r, 'rank'));
        $this->assertSame([9.0, 8.0, 6.0, 6.0], array_slice(array_column($r, 'avg_judge'), 0, 4));
        $this->assertSame(9.5, $r[2]['avg_representative']);
    }

    private function expectReason(string $reason, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Expected {$reason}");
        } catch (DomainException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    public function testEndToEndThreeGroupsRepresentativesAndRun(): void
    {
        $s  = $this->makeSlam(2, 3, 1);
        $id = $s['id'];
        // Audience questions are worth 5 marks each.
        db_connect()->table('review_questions')->where('slam_id', $id)->where('audience', 'user')->update(['max_score' => 5]);
        [$u0, $u1, $u2] = $s['users'];
        $judge = $s['judges'][0];
        $reps  = service('representatives');

        $this->at('2026-11-01 03:30:00', $id);
        foreach ([...$s['users'], $judge] as $a) {
            $this->checkIn($a);
        }
        $this->at('2026-11-01 04:45:00', $id);
        $sub = function (array $a, int $item, array $scores): void {
            $qs  = service('questions')->active((int) $a['slam_id'], $a['user_type']);
            $ans = array_map(static fn ($q, $sc) => ['question_id' => (int) $q['id'], 'score' => $sc], $qs, $scores);
            service('reviews')->submit($a, $item, $ans, null, $this->actorFor($a));
        };
        // 6 is out of range for a 5-mark question.
        $this->expectReason('invalid_score', fn () => $sub($u0, $s['items'][0], [6, 1]));
        // The panel cannot be drawn while performances are still being reviewed.
        $this->expectReason('forbidden_transition', fn () => $reps->draw($id, 2, $this->admin));

        // Performance 1: u0 4+5 = 9, u1 2+3 = 5, u2 5+5 = 10, judge 6.
        $sub($u0, $s['items'][0], [4, 5]);
        $sub($u1, $s['items'][0], [2, 3]);
        $sub($u2, $s['items'][0], [5, 5]);
        $sub($judge, $s['items'][0], [6, 6, 6]);
        $this->at('2026-11-01 04:52:00', $id);
        // Performance 2: u0 3+3 = 6, u1 5+4 = 9. u2 and the judge skip it.
        $sub($u0, $s['items'][1], [3, 3]);
        $sub($u1, $s['items'][1], [5, 4]);
        $this->at('2026-11-01 04:57:00', $id);
        $this->assertContains($this->phase($id), ['reviews_complete', 'revision']);

        $pool = [(int) $u0['id'], (int) $u1['id']];
        $this->assertSame($pool, service('scoring')->completeReviewerIds($id));
        // A seeded draw is reproducible and picks only from the pool.
        $a = $reps->draw($id, 1, $this->admin, 12345);
        $b = $reps->draw($id, 1, $this->admin, 12345);
        $this->assertSame($a['selected'], $b['selected']);
        $this->assertContains($a['selected'][0], $pool);
        // Asking for more than the pool takes everyone.
        $this->assertSame($pool, $reps->draw($id, 5, $this->admin)['selected']);
        $this->assertSame(5, $reps->count($id));

        // Admin edits: only complete reviewers can be added.
        $reps->remove($id, (int) $u1['id'], $this->admin, 'left early');
        $this->expectReason('not_eligible', fn () => $reps->add($id, (int) $u2['id'], $this->admin));
        $this->expectReason('not_eligible', fn () => $reps->add($id, (int) $judge['id'], $this->admin));
        $this->expectReason('already_selected', fn () => $reps->add($id, (int) $u0['id'], $this->admin));
        $this->assertSame([(int) $u0['id']], $reps->ids($id));
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM activity_log WHERE slam_id = ? AND action = 'representatives.removed' AND reason = 'left early'", [$id]));

        // Representatives leave the general group: P1 general = (5 + 10) / 2, representative = 9, judge = 6.
        $byPerf = array_column(service('scoring')->compute($id)['results'], null, 'performance_id');
        $p1     = $byPerf[$s['perfs'][0]];
        $this->assertEqualsWithDelta(7.5, $p1['avg_general'], 1e-9);
        $this->assertEqualsWithDelta(9.0, $p1['avg_representative'], 1e-9);
        $this->assertEqualsWithDelta(6.0, $p1['avg_judge'], 1e-9);
        $this->assertSame([2, 1, 1], [$p1['n_general'], $p1['n_representative'], $p1['n_judge']]);
        $this->assertEqualsWithDelta((40 * 7.5 + 20 * 9 + 40 * 6) / 100, $p1['final_mark'], 1e-9);
        // P2 has no judge reviews: (40·9 + 20·6) / 60.
        $this->assertEqualsWithDelta(8.0, $byPerf[$s['perfs'][1]]['final_mark'], 1e-9);
        $this->assertSame(1, $byPerf[$s['perfs'][1]]['rank']);

        // Finish the slam: the automatic run snapshots the numbers and the panel.
        $this->at('2026-11-01 05:07:00', $id);
        $this->at('2026-11-01 05:12:00', $id);
        $this->assertSame('marks_calculated', $this->phase($id));
        $run = service('markRuns')->currentRun($id);
        $this->assertSame([(int) $u0['id']], json_decode($run['representatives_snapshot'], true));
        $res = Db::row('SELECT * FROM mark_results WHERE mark_run_id = ? AND performance_id = ?', [$run['id'], $s['perfs'][0]]);
        $this->assertEqualsWithDelta(7.2, (float) $res['final_mark'], 1e-4);
        $this->assertSame(2, (int) $res['n_general']);

        // Changing weights or the panel later never alters the run; recalculating makes a new current run.
        service('scoring')->updateWeights($id, ['w_general' => 50, 'w_representative' => 0, 'w_judge' => 50], $this->admin);
        $reps->add($id, (int) $u1['id'], $this->admin);
        $this->assertEqualsWithDelta(7.2, (float) Db::value('SELECT final_mark FROM mark_results WHERE id = ?', [$res['id']]), 1e-4);
        $newRun = service('markRuns')->calculate($id, $this->admin, 'weights corrected');
        $diff   = service('markRuns')->resultsWithDiff($newRun);
        $this->assertSame((int) $run['id'], (int) $diff['previous_run_id']);
        $this->assertSame(['w_general' => 50.0, 'w_representative' => 0.0, 'w_judge' => 50.0], $diff['weights']);
        $p1new = array_column($diff['results'], null, 'performance_id')[$s['perfs'][0]];
        // P1 now: general = u2 10 (u0 and u1 are representatives), judge 6 → (50·10 + 50·6) / 100.
        $this->assertEqualsWithDelta(8.0, (float) $p1new['final_mark'], 1e-4);
        $this->assertEqualsWithDelta(5.0, $p1new['breakdown']['groups']['general']['contribution'], 1e-4); // 10 × 50 %
        $this->assertNull($p1new['breakdown']['groups']['representative']['share']);

        service('slamPhase')->endEvent($id, $this->admin);
        $this->assertSame(1, (int) Db::value('SELECT is_final FROM mark_runs WHERE id = ?', [$newRun]));
        $this->expectReason('forbidden_transition', fn () => $reps->draw($id, 2, $this->admin));
        $this->expectException(DomainException::class);
        service('markRuns')->calculate($id, $this->admin, null);
    }

    public function testWeightsMustSumTo100(): void
    {
        $s = $this->makeSlam(1);
        $this->expectException(DomainException::class);
        service('scoring')->updateWeights($s['id'], ['w_general' => 50, 'w_representative' => 20, 'w_judge' => 40], $this->admin);
    }
}
