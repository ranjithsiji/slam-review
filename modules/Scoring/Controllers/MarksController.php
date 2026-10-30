<?php

declare(strict_types=1);

namespace Modules\Scoring\Controllers;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Modules\Core\Controllers\BaseController;
use Modules\Core\Libraries\Db;

class MarksController extends BaseController
{
    public function marks(int $slamId): string
    {
        $slam = service('slams')->find($slamId);
        $run  = service('markRuns')->currentRun($slamId);
        service('activityLog')->log($this->actor(), 'marks.viewed', ['slam_id' => $slamId, 'meta' => ['run_id' => $run['id'] ?? null]]);

        return $this->render('Modules\Scoring\Views\marks', [
            'title' => 'Marks — ' . $slam['name'], 'slam' => $slam, 'run' => $run,
            'official' => $run !== null && in_array($slam['status'], ['marks_calculated', 'ended'], true),
            'weights' => service('scoring')->weights($slamId),
        ]);
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
            $dist = array_fill(1, 10, ['user' => 0, 'judge' => 0]);
            foreach (Db::rows(
                'SELECT a.user_type, ans.score, COUNT(*) n FROM review_answers ans JOIN reviews r ON r.id = ans.review_id
                 JOIN attendees a ON a.id = r.attendee_id WHERE a.slam_id = ? GROUP BY a.user_type, ans.score',
                [$slamId],
            ) as $r) {
                $dist[(int) $r['score']][$r['user_type']] = (int) $r['n'];
            }
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
                'distribution' => $dist,
                'checked_in' => array_column($checkedIn, 'n', 'user_type'),
                'participation' => $perItem,
                'results' => array_map(static fn ($r) => array_intersect_key($r, array_flip(['presenter_name', 'avg_user', 'avg_judge', 'avg_complete', 'final_mark', 'n_user', 'n_judge', 'n_complete'])), $calc['results']),
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
            'cfg' => Db::row('SELECT * FROM score_config WHERE slam_id = ?', [$slamId]),
            'questions' => ['user' => service('questions')->active($slamId, 'user'), 'judge' => service('questions')->active($slamId, 'judge')],
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

    public function complete(int $slamId): string
    {
        $slam = service('slams')->find($slamId);

        return $this->render('Modules\Scoring\Views\complete', ['title' => 'Complete reviewers — ' . $slam['name'], 'slam' => $slam]);
    }

    public function run(int $runId): string
    {
        $data = service('markRuns')->resultsWithDiff($runId);
        $slam = service('slams')->find((int) $data['run']['slam_id']) ?? throw PageNotFoundException::forPageNotFound();

        return $this->render('Modules\Scoring\Views\run', ['title' => 'Mark run #' . $runId, 'slam' => $slam] + $data);
    }
}
