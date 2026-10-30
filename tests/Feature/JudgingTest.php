<?php

declare(strict_types=1);

namespace Tests\Feature;

use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;
use Tests\Support\SlamTestCase;

final class JudgingTest extends SlamTestCase
{
    private function expectReason(string $reason, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Expected {$reason}");
        } catch (DomainException $e) {
            $this->assertSame($reason, $e->reason, $e->getMessage());
        }
    }

    /** Judge criteria as the organisers set them: 3 + 3 + 3 + 1. */
    private function useCriteria(int $slamId): void
    {
        $qs = service('questions')->list($slamId, 'judge');
        foreach ([3, 3, 3] as $i => $max) {
            db_connect()->table('review_questions')->where('id', $qs[$i]['id'])->update(['max_score' => $max]);
        }
        db_connect()->table('review_questions')->insert(['slam_id' => $slamId, 'audience' => 'judge', 'kind' => 'criterion', 'text' => 'Audience response', 'weight' => 1, 'max_score' => 1, 'position' => 40, 'is_active' => 1]);
        db_connect()->table('review_questions')->where('slam_id', $slamId)->where('audience', 'user')->update(['max_score' => 5]);
    }

    private function submit(array $a, int $item, array $scores): array
    {
        $qs  = service('questions')->active((int) $a['slam_id'], $a['user_type']);
        $ans = array_map(static fn ($q, $sc) => ['question_id' => (int) $q['id'], 'score' => $sc], $qs, $scores);

        return service('reviews')->submit($a, $item, $ans, null, $this->actorFor($a));
    }

    public function testLiveDetailedJudgesUseOneDecimalAndAudienceWholeMarks(): void
    {
        $s = $this->makeSlam(1, 1, 1);
        $this->useCriteria($s['id']);
        [$user, $judge] = [$s['users'][0], $s['judges'][0]];
        $this->at('2026-11-01 03:30:00', $s['id']);
        $this->checkIn($user);
        $this->checkIn($judge);
        $this->at('2026-11-01 04:45:00', $s['id']);

        foreach ([[0, 2, 2, 1], [2.45, 2, 2, 1], [3.1, 2, 2, 1], [2, 2, 2, 1.2], ['2,5', 2, 2, 1]] as $bad) {
            $this->expectReason('invalid_score', fn () => $this->submit($judge, $s['items'][0], $bad));
        }
        $this->expectReason('invalid_score', fn () => $this->submit($user, $s['items'][0], [4.5, 3]));

        $this->submit($judge, $s['items'][0], [2.5, '2.0', 2.4, 0.8]);
        $this->submit($user, $s['items'][0], [4, 3]);
        $this->assertSame(['0.8', '2.0', '2.4', '2.5'], array_map('strval', array_column(Db::rows('SELECT ans.score FROM review_answers ans JOIN reviews r ON r.id = ans.review_id WHERE r.attendee_id = ? ORDER BY ans.score', [$judge['id']]), 'score')));

        $res = service('scoring')->compute($s['id'])['results'][0];
        $this->assertEqualsWithDelta(7.7, $res['avg_judge'], 1e-9);   // 2.5 + 2.0 + 2.4 + 0.8
        $this->assertEqualsWithDelta(7.0, $res['avg_general'], 1e-9); // 4 + 3
    }

    public function testLiveConsolidatedJudgesGiveOneOverallMark(): void
    {
        $s     = $this->makeSlam(1, 1, 1, ['judge_mode' => 'consolidated']);
        $judge = $s['judges'][0];
        $qs    = service('questions')->active($s['id'], 'judge');
        $this->assertCount(1, $qs);
        $this->assertSame('total', $qs[0]['kind']);
        // The criteria list (editor, templates) never shows the overall-mark question.
        $this->assertNotContains('total', array_column(service('questions')->list($s['id'], 'judge'), 'kind'));

        $this->at('2026-11-01 03:30:00', $s['id']);
        $this->checkIn($judge);
        $this->at('2026-11-01 04:45:00', $s['id']);
        $state = service('liveState')->forReviewer($judge + ['name' => $judge['name']])['body'];
        $this->assertSame(0.1, $state['questions'][0]['step']);
        $this->assertFalse($state['judge_offline']);
        $this->submit($judge, $s['items'][0], [8.5]);
        $this->assertEqualsWithDelta(8.5, service('scoring')->compute($s['id'])['results'][0]['avg_judge'], 1e-9);
    }

    public function testEndOfSlamJudgesAreEnteredByTheAdmin(): void
    {
        $s  = $this->makeSlam(2, 1, 2, ['judge_timing' => 'end_of_slam']);
        $id = $s['id'];
        [$j1, $j2] = $s['judges'];
        $marks = service('judgeMarks');

        $this->at('2026-11-01 03:30:00', $id);
        $this->checkIn($s['users'][0]);
        $this->checkIn($j1);
        $this->at('2026-11-01 04:45:00', $id);
        // Judges cannot review on their phones; their screen says so.
        $state = service('liveState')->forReviewer($j1 + ['name' => $j1['name']])['body'];
        $this->assertTrue($state['judge_offline']);
        $this->assertSame([], $state['questions']);
        $this->expectReason('judge_marks_offline', fn () => $this->submit($j1, $s['items'][0], [8]));
        // Nor can the admin enter marks before every performance has been reviewed.
        $this->expectReason('forbidden_transition', fn () => $marks->save($id, (int) $j1['id'], [$s['items'][0] => '7'], $this->admin));

        $this->submit($s['users'][0], $s['items'][0], [6, 6]);
        $this->at('2026-11-01 04:52:00', $id);
        $this->at('2026-11-01 04:57:00', $id);
        $this->assertContains($this->phase($id), ['reviews_complete', 'revision']);

        $this->expectReason('validation', fn () => $marks->save($id, (int) $j1['id'], [$s['items'][0] => '0'], $this->admin));
        $this->expectReason('validation', fn () => $marks->save($id, (int) $j1['id'], [$s['items'][0] => '10.5'], $this->admin));
        $this->expectReason('not_found', fn () => $marks->save($id, (int) $s['users'][0]['id'], [$s['items'][0] => '7'], $this->admin));

        $this->assertSame(['saved' => 2, 'removed' => 0], $marks->save($id, (int) $j1['id'], [$s['items'][0] => '7.5', $s['items'][1] => '9'], $this->admin));
        $marks->save($id, (int) $j2['id'], [$s['items'][0] => '8,5', $s['items'][1] => ''], $this->admin);
        $this->assertSame(['entered' => 3, 'expected' => 4], $marks->progress($id));

        $byPerf = array_column(service('scoring')->compute($id)['results'], null, 'performance_id');
        $this->assertEqualsWithDelta(8.0, $byPerf[$s['perfs'][0]]['avg_judge'], 1e-9); // (7.5 + 8.5) / 2
        $this->assertSame(2, $byPerf[$s['perfs'][0]]['n_judge']);
        $this->assertEqualsWithDelta(9.0, $byPerf[$s['perfs'][1]]['avg_judge'], 1e-9);

        // Corrections keep history; clearing removes the mark.
        $this->assertSame(['saved' => 1, 'removed' => 0], $marks->save($id, (int) $j1['id'], [$s['items'][0] => '7.0', $s['items'][1] => '9'], $this->admin));
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM review_revisions rr JOIN reviews r ON r.id = rr.review_id WHERE r.attendee_id = ?', [$j1['id']]));
        $this->assertSame(['saved' => 0, 'removed' => 1], $marks->save($id, (int) $j1['id'], [$s['items'][1] => ''], $this->admin));
        $this->assertSame(['entered' => 2, 'expected' => 4], $marks->progress($id));
        $this->assertSame((int) $this->admin->id, (int) Db::value('SELECT entered_by FROM reviews WHERE attendee_id = ? AND schedule_item_id = ?', [$j1['id'], $s['items'][0]]));
        $this->assertSame(4, (int) Db::value("SELECT COUNT(*) FROM activity_log WHERE slam_id = ? AND action = 'judge_marks.entered'", [$id]));

        // Still possible after Slam Close and the automatic marks run; recalculation picks it up.
        $this->at('2026-11-01 05:07:00', $id);
        $this->at('2026-11-01 05:12:00', $id);
        $this->assertSame('marks_calculated', $this->phase($id));
        $marks->save($id, (int) $j2['id'], [$s['items'][1] => '6'], $this->admin);
        $run = service('markRuns')->calculate($id, $this->admin, 'late judge marks');
        $this->assertEqualsWithDelta(6.0, (float) Db::value('SELECT avg_judge FROM mark_results WHERE mark_run_id = ? AND performance_id = ?', [$run, $s['perfs'][1]]), 1e-4);

        service('slamPhase')->endEvent($id, $this->admin);
        $this->expectReason('forbidden_transition', fn () => $marks->save($id, (int) $j2['id'], [$s['items'][1] => '7'], $this->admin));
    }

    public function testJudgingIsSetBeforeReviewingAndCanBeAppliedInBulk(): void
    {
        $a = $this->makeSlam(1, 1, 1);
        $b = $this->makeSlam(1, 1, 1);
        $c = $this->makeSlam(1, 1, 1);
        $scoring = service('scoring');
        $this->expectReason('validation', fn () => $scoring->updateJudging([$a['id']], ['judge_timing' => 'whenever'], $this->admin));

        // Slam C starts reviewing; A and B are still before the first performance.
        $this->at('2026-11-01 04:45:00', $c['id']);
        $this->assertSame('reviewing', $this->phase($c['id']));
        $r = $scoring->updateJudging([$a['id'], $b['id'], $c['id']], ['judge_timing' => 'live', 'judge_mode' => 'consolidated'], $this->admin);
        $this->assertSame([$a['id'], $b['id']], $r['updated']);
        $this->assertArrayHasKey($c['id'], $r['skipped']);
        $this->assertSame(['timing' => 'live', 'mode' => 'consolidated', 'consolidated' => true], $scoring->judging($b['id']));
        $this->assertSame(['timing' => 'live', 'mode' => 'detailed', 'consolidated' => false], $scoring->judging($c['id']));
        $this->assertSame(2, (int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action = 'score_config.judging_changed'"));
        // New slams default to end-of-slam judging.
        $id = service('slams')->create([
            'name' => 'New', 'type' => 'final', 'slug' => 'new-slam', 'venue_name' => 'Hall', 'address_line' => 'Road', 'city' => 'Kochi', 'district' => 'Ernakulam',
            'opens_at' => '2026-12-01 03:00:00', 'closes_at' => '2026-12-01 11:30:00',
        ], $this->admin);
        $this->assertSame('end_of_slam', $scoring->judging($id)['timing']);
        $this->assertSame('total', service('questions')->active($id, 'judge')[0]['kind']);
    }
}
