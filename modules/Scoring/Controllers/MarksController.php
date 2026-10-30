<?php

declare(strict_types=1);

namespace Modules\Scoring\Controllers;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Modules\Core\Controllers\BaseController;
use Modules\Core\Libraries\Db;
use Modules\Scoring\Services\ScoringService;

class MarksController extends BaseController
{
    public function marks(int $slamId): string
    {
        $slam = service('slams')->find($slamId);
        $run  = service('markRuns')->currentRun($slamId);
        service('activityLog')->log($this->actor(), 'marks.viewed', ['slam_id' => $slamId, 'meta' => ['run_id' => $run['id'] ?? null]]);
        // The breakdown shows the current run, or the live provisional values before the first run.
        if ($run !== null) {
            $data    = service('markRuns')->resultsWithDiff((int) $run['id']);
            $rows      = $data['results'];
            $weights   = $data['weights'];
            $questions = $data['questions'];
        } else {
            $weights   = service('scoring')->weights($slamId);
            $calc      = service('scoring')->compute($slamId);
            $rows      = array_map(static fn ($r) => $r + ['breakdown' => ScoringService::breakdownFor($r, $weights)], $calc['results']);
            $questions = $calc['question_weights'];
        }

        return $this->render('Modules\Scoring\Views\marks', [
            'title' => 'Marks — ' . $slam['name'], 'slam' => $slam, 'run' => $run,
            'official' => $run !== null && in_array($slam['status'], ['marks_calculated', 'ended'], true),
            'weights' => $weights, 'currentWeights' => service('scoring')->weights($slamId), 'rows' => $rows, 'questions' => $questions,
            'judging' => service('scoring')->judging($slamId), 'judgeProgress' => service('judgeMarks')->progress($slamId),
            'representatives' => count(service('representatives')->ids($slamId)),
        ]);
    }

    /**
     * Result rows with breakdown and photo: the current mark run, or live provisional values before the first run.
     *
     * @return array{slam: array, run: ?array, rows: list<array>, weights: array, questions: array, official: bool}
     */
    private function scoreData(int $slamId, string $screen): array
    {
        $slam = service('slams')->find($slamId);
        $run  = service('markRuns')->currentRun($slamId);
        if ($run !== null) {
            $data      = service('markRuns')->resultsWithDiff((int) $run['id']);
            $rows      = $data['results'];
            $weights   = $data['weights'];
            $questions = $data['questions'];
        } else {
            $weights   = service('scoring')->weights($slamId);
            $calc      = service('scoring')->compute($slamId);
            $rows      = array_map(static fn ($r) => $r + ['breakdown' => ScoringService::breakdownFor($r, $weights)], $calc['results']);
            $questions = $calc['question_weights'];
        }
        $photos = array_column(Db::rows('SELECT p.id, pr.photo_thumb_path FROM performances p JOIN presenters pr ON pr.id = p.presenter_id WHERE p.slam_id = ?', [$slamId]), 'photo_thumb_path', 'id');
        foreach ($rows as &$r) {
            $r['photo'] = $photos[$r['performance_id']] ?? null;
        }
        unset($r);
        service('activityLog')->log($this->actor(), 'marks.viewed', ['slam_id' => $slamId, 'meta' => ['screen' => $screen, 'run_id' => $run['id'] ?? null]]);

        return [
            'slam' => $slam, 'run' => $run, 'rows' => $rows, 'weights' => $weights, 'questions' => $questions,
            'official' => $run !== null && in_array($slam['status'], ['marks_calculated', 'ended'], true),
        ];
    }

    /** Final presenter scores: one card per presenter that opens to the three groups' marks. */
    public function scores(int $slamId): string
    {
        $d = $this->scoreData($slamId, 'scores');

        return $this->render('Modules\Scoring\Views\scores', $d + ['title' => 'Scores — ' . $d['slam']['name']]);
    }

    /** Presenter leaderboard: ranked, best first. */
    public function leaderboard(int $slamId): string
    {
        $d = $this->scoreData($slamId, 'leaderboard');
        // Ranked presenters first (best to worst), then those without a mark yet in running order.
        usort($d['rows'], static fn ($a, $b) => [$a['rank'] === null, $a['rank'] ?? 0] <=> [$b['rank'] === null, $b['rank'] ?? 0]);

        return $this->render('Modules\Scoring\Views\leaderboard', $d + ['title' => 'Leaderboard — ' . $d['slam']['name'], 'judging' => service('scoring')->judging($slamId), 'judgeProgress' => service('judgeMarks')->progress($slamId)]);
    }

    public function stats(int $slamId): string
    {
        $slam = service('slams')->find($slamId);

        return $this->render('Modules\Scoring\Views\stats', ['title' => 'Statistics — ' . $slam['name'], 'slam' => $slam]);
    }

    /** Chart data: distribution, participation, group comparison, per-question averages. */
    public function statsData(int $slamId): ResponseInterface
    {
        return $this->api(function () use ($slamId) {
            $calc = service('scoring')->compute($slamId);
            $checkedIn = Db::rows(
                'SELECT a.user_type, COUNT(DISTINCT a.id) n FROM attendees a JOIN checkins c ON c.attendee_id = a.id WHERE a.slam_id = ? GROUP BY a.user_type',
                [$slamId],
            );
            $perItem = Db::rows(
                "SELECT si.id, pr.name, COUNT(r.id) reviews FROM schedule_items si JOIN performances p ON p.id = si.performance_id
                 JOIN presenters pr ON pr.id = p.presenter_id LEFT JOIN reviews r ON r.schedule_item_id = si.id
                 WHERE si.slam_id = ? AND si.status IN ('open','closed') GROUP BY si.id, pr.name ORDER BY si.position",
                [$slamId],
            );

            return [
                'distribution' => $calc['distribution'],
                'checked_in' => array_column($checkedIn, 'n', 'user_type'),
                'participation' => $perItem,
                'results' => array_map(static fn ($r) => array_intersect_key($r, array_flip(['presenter_name', 'avg_general', 'avg_representative', 'avg_judge', 'final_mark', 'n_general', 'n_representative', 'n_judge'])), $calc['results']),
                'questions' => $calc['question_weights'],
                'per_question' => array_map(static fn ($r) => ['presenter_name' => $r['presenter_name'], 'q' => $r['per_question']], $calc['results']),
                'counts' => $calc['counts'],
            ];
        });
    }

    public function config(int $slamId): string
    {
        $slam = service('slams')->find($slamId);

        return $this->render('Modules\Scoring\Views\config', [
            'title' => 'Mark configuration — ' . $slam['name'], 'slam' => $slam,
            'cfg' => service('scoring')->weights($slamId),
            'questions' => ['user' => service('questions')->list($slamId, 'user', true), 'judge' => service('questions')->list($slamId, 'judge', true)],
            'judging' => service('scoring')->judging($slamId), 'judgingLocked' => service('scoring')->judgingLockedReason($slam),
            'otherSlams' => array_values(array_filter(service('slams')->all(), static fn ($s) => (int) $s['id'] !== $slamId && service('scoring')->judgingLockedReason($s) === null)),
            'locked' => ['user' => service('questions')->isLocked($slamId, 'user'), 'judge' => service('questions')->isLocked($slamId, 'judge')],
        ]);
    }

    public function saveConfig(int $slamId): RedirectResponse
    {
        return $this->formAction(function () use ($slamId) {
            service('scoring')->updateWeights($slamId, $this->input(), $this->actor());
            foreach ((array) $this->request->getPost('qweight') as $qid => $w) {
                $q = Db::row('SELECT * FROM review_questions WHERE id = ? AND slam_id = ?', [(int) $qid, $slamId]);
                if ($q !== null && (float) $q['weight'] !== (float) $w) {
                    service('questions')->update((int) $qid, ['text' => $q['text'], 'text_ml' => $q['text_ml'], 'help_text' => $q['help_text'], 'weight' => $w, 'is_active' => $q['is_active']], $this->actor());
                }
            }

            return site_url("admin/slams/{$slamId}/mark-config");
        }, 'Mark configuration saved. Existing mark runs are unchanged; recalculate to apply.');
    }

    /** Judges' marking for this slam, optionally applied to other slams too (bulk). */
    public function saveJudging(int $slamId): RedirectResponse
    {
        return $this->formAction(function () use ($slamId) {
            $ids = array_merge([$slamId], array_map('intval', (array) $this->request->getPost('apply_to')));
            $r   = service('scoring')->updateJudging($ids, $this->input(), $this->actor());
            if (isset($r['skipped'][$slamId])) {
                throw new \Modules\Core\Libraries\DomainException('forbidden_transition', $r['skipped'][$slamId], 409);
            }
            if ($r['skipped'] !== []) {
                session()->setFlashdata('warning', count($r['skipped']) . ' other slam(s) were not changed because reviewing has started there.');
            }

            return site_url("admin/slams/{$slamId}/mark-config");
        }, "Judges' marking saved.");
    }

    /** End-of-slam judging: enter each judge's consolidated mark per performance. */
    public function judgeMarks(int $slamId): string
    {
        $slam  = service('slams')->find($slamId);
        $grid  = service('judgeMarks')->grid($slamId);
        $judge = (int) ($this->request->getGet('judge') ?? ($grid['judges'][0]['id'] ?? 0));

        return $this->render('Modules\Scoring\Views\judge_marks', [
            'title' => "Judges' marks — " . $slam['name'], 'slam' => $slam, 'judge' => $judge,
            'locked' => service('judgeMarks')->lockedReason($slam), 'run' => service('markRuns')->currentRun($slamId),
        ] + $grid);
    }

    public function saveJudgeMarks(int $slamId): RedirectResponse
    {
        $judge = (int) $this->request->getPost('judge_id');

        return $this->formAction(function () use ($slamId, $judge) {
            $r = service('judgeMarks')->save($slamId, $judge, (array) $this->request->getPost('marks'), $this->actor());
            session()->setFlashdata('saved_counts', $r);
            if (in_array(service('slams')->find($slamId)['status'], ['closed', 'marks_calculated'], true) && ($r['saved'] + $r['removed']) > 0) {
                session()->setFlashdata('info', 'Marks recalculated from the weights.');
            }

            return site_url("admin/slams/{$slamId}/judge-marks?judge={$judge}");
        }, "Judge's marks saved.");
    }

    /** Audience representatives: the random draw, the selected panel and the full pool. */
    public function representatives(int $slamId): string
    {
        $slam = service('slams')->find($slamId);
        $reps = service('representatives');

        return $this->render('Modules\Scoring\Views\representatives', [
            'title' => 'Audience representatives — ' . $slam['name'], 'slam' => $slam,
            'count' => $reps->count($slamId), 'selected' => count($reps->ids($slamId)),
            'pool' => count(service('scoring')->completeReviewerIds($slamId)),
            'locked' => $reps->lockedReason($slam),
            'lastDraw' => Db::row("SELECT occurred_at, actor_label, meta FROM activity_log WHERE slam_id = ? AND action = 'representatives.drawn' ORDER BY id DESC LIMIT 1", [$slamId]),
        ]);
    }

    public function drawRepresentatives(int $slamId): ResponseInterface
    {
        $in = $this->input();

        return $this->api(function () use ($slamId, $in) {
            $r = service('representatives')->draw($slamId, (int) ($in['count'] ?? 0), $this->actor());

            return $r + ['message' => sprintf('%d representative(s) drawn from %d people who reviewed every performance.', count($r['selected']), $r['pool'])];
        });
    }

    public function addRepresentative(int $slamId, int $attendeeId): ResponseInterface
    {
        return $this->api(function () use ($slamId, $attendeeId) {
            service('representatives')->add($slamId, $attendeeId, $this->actor());

            return ['message' => 'Added to the representatives.'];
        });
    }

    public function removeRepresentative(int $slamId, int $attendeeId): ResponseInterface
    {
        $in = $this->input();

        return $this->api(function () use ($slamId, $attendeeId, $in) {
            service('representatives')->remove($slamId, $attendeeId, $this->actor(), isset($in['reason']) ? (string) $in['reason'] : null);

            return ['message' => 'Removed from the representatives.'];
        });
    }

    public function run(int $runId): string
    {
        $data = service('markRuns')->resultsWithDiff($runId);
        $slam = service('slams')->find((int) $data['run']['slam_id']) ?? throw PageNotFoundException::forPageNotFound();

        return $this->render('Modules\Scoring\Views\run', ['title' => 'Mark run #' . $runId, 'slam' => $slam] + $data);
    }
}
