<?php

declare(strict_types=1);

namespace Tests\Feature;

use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\Clock;
use Tests\Support\SlamTestCase;

/**
 * The whole slam day in the organisers' order, in manual mode:
 * slam start, gates open, reviewers and judges check in, gates close, then per performance
 * "presentation starts, review opens mid-presentation, presentation ends, review ends", with a tea break
 * and a lunch break in between, slam ends, judges' marks are entered, marks are calculated by the software
 * from the weights, and the final leaderboard has marks and percentages.
 */
final class EventOrderTest extends SlamTestCase
{
    /** Audience scores per (user index, performance index): two questions of 5 marks. */
    private function scores(int $u, int $p): array
    {
        return [1 + (($u + $p) % 5), 1 + (($u * 2 + $p) % 5)];
    }

    private function vote(array $a, int $item, array $scores): void
    {
        $qs  = service('questions')->active((int) $a['slam_id'], $a['user_type']);
        $ans = [];
        foreach ($qs as $i => $q) {
            $ans[] = ['question_id' => (int) $q['id'], 'score' => $scores[$i]];
        }
        service('reviews')->submit($a, $item, $ans, null, $this->actorFor($a));
    }

    public function testFullEventInTheOrganisersOrder(): void
    {
        $s = $this->makeSlam(5, 6, 2, ['auto_mode' => 'manual', 'judge_timing' => 'end_of_slam']);
        $id = $s['id'];
        // The audience's two questions are 5 marks each (the organisers' rule), so a review is out of 10.
        db_connect()->table('review_questions')->where('slam_id', $id)->where('audience', 'user')->update(['max_score' => 5]);
        $phase = service('slamPhase');
        $sched = service('schedule');
        $admin = $this->admin;

        // A tea break after the 2nd and a lunch break after the 4th performance.
        $tea   = $sched->appendItem($id, ['item_type' => 'break', 'break_type' => 'tea', 'label' => 'Tea break', 'duration_minutes' => 15], $admin);
        $lunch = $sched->appendItem($id, ['item_type' => 'break', 'break_type' => 'lunch', 'label' => 'Lunch break', 'duration_minutes' => 45], $admin);
        [$p1, $p2, $p3, $p4, $p5] = $s['items'];
        $sched->reorder($id, [$p1, $p2, $tea, $p3, $p4, $lunch, $p5], true, false, false, $admin);

        // Manual mode: nothing starts by itself, even when the planned times arrive.
        $this->at('2026-11-01 03:00:00', $id);
        $this->assertSame('scheduled', $this->phase($id));
        $this->at('2026-11-01 05:30:00', $id);
        $this->assertSame('scheduled', $this->phase($id));

        // 1. Slam start
        Clock::set('2026-11-01 03:00:00');
        $phase->activate($id, $admin);
        $this->assertSame('open', $this->phase($id));
        // 2. Gates open, 3. reviewers and judges check in
        $phase->gateOpen($id, $admin);
        $this->assertSame('checkin_open', $this->phase($id));
        foreach ([...$s['users'], ...$s['judges']] as $a) {
            $this->checkIn($a);
        }
        $this->assertSame([6, 2], [service('engagement')->presence($id)['users']['inside'], service('engagement')->presence($id)['judges']['inside']]);
        // 4. Gates close
        $phase->gateClose($id, $admin);
        $this->assertSame('checkin_closed', $this->phase($id));

        $runningOrder = [$p1, $p2, 'tea', $p3, $p4, 'lunch', $p5];
        $perfIndex = 0;
        foreach ($runningOrder as $step) {
            if ($step === 'tea' || $step === 'lunch') {
                $brk = $step === 'tea' ? $tea : $lunch;
                $this->assertNotNull(Db::row("SELECT id FROM schedule_items WHERE id = ? AND status = 'pending'", [$brk]));
                $phase->startBreakNow($id, $brk, $admin);
                $this->assertSame($brk, (int) $phase->runningBreak($id)['id'], "{$step} break running");
                $phase->endBreak($id, $brk, $admin);
                $this->assertNull($phase->runningBreak($id));
                $this->assertSame('closed', Db::value('SELECT status FROM schedule_items WHERE id = ?', [$brk]));
                continue;
            }
            // 5. Presentation starts
            $phase->startPerformance($id, $step, $admin);
            $this->assertSame('reviewing', $this->phase($id));
            $this->assertNotNull(Db::value('SELECT performing_started_at FROM schedule_items WHERE id = ?', [$step]));
            // 6. Review starts in the middle of the presentation
            $phase->openWindow($id, $step, 5, null, $admin);
            $this->assertSame('open', Db::value('SELECT status FROM schedule_items WHERE id = ?', [$step]));
            $this->assertNull(Db::value('SELECT performing_ended_at FROM schedule_items WHERE id = ?', [$step]), 'still on stage');
            foreach ($s['users'] as $u => $a) {
                $this->vote($a, $step, $this->scores($u, $perfIndex));
            }
            $t = service('engagement')->turnout($id);
            $this->assertSame([6, 6, 100], [$t['checked_in'], $t['voted'], $t['percent']]);
            // 7. Presentation ends (the review is still open), 8. review ends
            $phase->endPerformance($id, $step, $admin);
            $this->assertNotNull(Db::value('SELECT performing_ended_at FROM schedule_items WHERE id = ?', [$step]));
            $this->assertSame('open', Db::value('SELECT status FROM schedule_items WHERE id = ?', [$step]));
            $phase->closeWindow($id, 'review over', $admin);
            $this->assertSame('closed', Db::value('SELECT status FROM schedule_items WHERE id = ?', [$step]));
            $perfIndex++;
        }
        $this->assertSame(5, (int) Db::value("SELECT COUNT(*) FROM schedule_items WHERE slam_id = ? AND item_type = 'performance' AND status = 'closed'", [$id]));

        // Slam ends: all reviews closed, edit window skipped, slam closed (the first marks run follows by itself).
        $phase->advanceNow($id, $admin);
        $this->assertSame('reviews_complete', $this->phase($id));
        $phase->revisionSkip($id, 'No edits', $admin);
        $phase->closeSlam($id, $admin);
        $this->assertContains($this->phase($id), ['closed', 'marks_calculated']);

        // Mark entry: the judges' marks, per performance. Calculation follows by itself.
        $marks = service('judgeMarks');
        $judgeMark = static fn (int $j, int $p) => 4.0 + (($j * 3 + $p * 2) % 6) + 0.5;   // 4.5 … 9.5
        foreach ($s['judges'] as $j => $judge) {
            $in = [];
            foreach ([$p1, $p2, $p3, $p4, $p5] as $p => $item) {
                $in[$item] = (string) $judgeMark($j, $p);
            }
            $marks->save($id, (int) $judge['id'], $in, $admin);
        }
        $this->assertSame(['entered' => 10, 'expected' => 10], $marks->progress($id));
        $run = service('markRuns')->currentRun($id);
        $this->assertNotNull($run);
        $this->assertSame('Automatic: marks entered', $run['note']);

        // Final leaderboard: independent expectation from the weights (40 / 20 / 40, no representatives drawn).
        $w = service('scoring')->weights($id);
        $expected = [];
        foreach ([$p1, $p2, $p3, $p4, $p5] as $p => $item) {
            $g = array_sum(array_map(fn ($u) => array_sum($this->scores($u, $p)), range(0, 5))) / 6;
            $j = array_sum(array_map(fn ($jj) => $judgeMark($jj, $p), [0, 1])) / 2;
            $expected[(int) Db::value('SELECT performance_id FROM schedule_items WHERE id = ?', [$item])] = ($w['w_general'] * $g + $w['w_judge'] * $j) / ($w['w_general'] + $w['w_judge']);
        }
        $rows = service('markRuns')->resultsWithDiff((int) $run['id'])['results'];
        $this->assertCount(5, $rows);
        foreach ($rows as $r) {
            $this->assertEqualsWithDelta($expected[(int) $r['performance_id']], (float) $r['final_mark'], 1e-3, 'final mark for ' . $r['presenter_name']);
            $this->assertEqualsWithDelta($expected[(int) $r['performance_id']] * 10, (float) $r['final_mark'] * 10, 1e-2); // percentage
        }
        arsort($expected);
        $order = array_keys($expected);
        $byRank = $rows;
        usort($byRank, static fn ($a, $b) => $a['rank'] <=> $b['rank']);
        $this->assertSame($order, array_map(static fn ($r) => (int) $r['performance_id'], $byRank), 'leaderboard order');
        $this->assertSame(1, (int) $byRank[0]['rank']);
    }
}
