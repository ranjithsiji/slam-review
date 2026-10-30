<?php

declare(strict_types=1);

namespace Modules\Scoring\Services;

use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;

/**
 * Immutable marks snapshots (mark_runs + mark_results). The newest run is
 * is_current and feeds the official Marks screen; older runs stay for audit.
 */
class MarkRunService
{
    /** Admin "Calculate marks" / "Recalculate". */
    public function calculate(int $slamId, Actor $actor, ?string $note): int
    {
        if (! $actor->isAdmin()) {
            throw new DomainException('forbidden', 'Only an admin can calculate marks.', 403);
        }
        $runId = Db::tx(function () use ($slamId, $actor, $note): int {
            $slam = Db::lockSlam($slamId);

            return $this->calculateLocked($slam, $actor, $note, 'manual_override');
        });
        service('publicCache')->purge($slamId);

        return $runId;
    }

    /** Caller holds the slam lock inside a transaction. */
    public function calculateLocked(array $slam, Actor $actor, ?string $note, string $trigger): int
    {
        if (! in_array($slam['status'], ['closed', 'marks_calculated'], true)) {
            throw new DomainException('forbidden_transition', $slam['status'] === 'ended'
                ? 'The event has ended; marks can no longer be recalculated.'
                : 'Marks can be calculated only after Slam Close.', 409);
        }
        $db      = db_connect();
        $calc    = service('scoring')->compute((int) $slam['id']);
        $isFirst = Db::value('SELECT id FROM mark_runs WHERE slam_id = ? LIMIT 1', [$slam['id']]) === null;

        $db->table('mark_runs')->where('slam_id', $slam['id'])->update(['is_current' => 0]);
        $db->table('mark_runs')->insert([
            'slam_id' => $slam['id'], 'calculated_at' => Clock::db(), 'calculated_by' => $actor->isAdmin() ? $actor->id : null,
            'weights_snapshot' => json_encode($calc['weights']), 'question_weights_snapshot' => json_encode($calc['question_weights'], JSON_UNESCAPED_UNICODE),
            'counts_snapshot' => json_encode($calc['counts']), 'representatives_snapshot' => json_encode($calc['representatives']), 'is_current' => 1, 'is_final' => 0,
            'note' => $note !== null ? mb_substr($note, 0, 255) : null,
        ]);
        $runId = (int) $db->insertID();
        foreach ($calc['results'] as $r) {
            $db->table('mark_results')->insert([
                'mark_run_id' => $runId, 'performance_id' => $r['performance_id'],
                'avg_general' => $r['avg_general'], 'avg_representative' => $r['avg_representative'], 'avg_judge' => $r['avg_judge'],
                'final_mark' => $r['final_mark'] !== null ? round($r['final_mark'], 4) : null, 'rank' => $r['rank'],
                'n_general' => $r['n_general'], 'n_representative' => $r['n_representative'], 'n_judge' => $r['n_judge'],
                'per_question' => json_encode($r['per_question']),
            ]);
        }
        service('activityLog')->log($actor, $isFirst ? 'marks.calculated' : 'marks.recalculated', [
            'slam_id' => (int) $slam['id'], 'target_type' => 'mark_run', 'target_id' => $runId, 'reason' => $note,
            'meta' => ['weights' => $calc['weights'], 'counts' => $calc['counts'], 'trigger' => $trigger],
        ]);
        if ($slam['status'] === 'closed') {
            service('slamPhase')->transition($slam, 'marks_calculated', $trigger, $actor);
        }

        return $runId;
    }

    public function currentRun(int $slamId): ?array
    {
        return Db::row('SELECT * FROM mark_runs WHERE slam_id = ? AND is_current = 1', [$slamId]);
    }

    /** Results of a run with the change from the previous run. */
    public function resultsWithDiff(int $runId): array
    {
        $run  = Db::row('SELECT * FROM mark_runs WHERE id = ?', [$runId]) ?? throw new DomainException('not_found', 'Run not found.', 404);
        $prev = Db::row('SELECT id FROM mark_runs WHERE slam_id = ? AND id < ? ORDER BY id DESC LIMIT 1', [$run['slam_id'], $runId]);
        $old  = [];
        if ($prev !== null) {
            foreach (Db::rows('SELECT performance_id, final_mark, `rank` FROM mark_results WHERE mark_run_id = ?', [$prev['id']]) as $r) {
                $old[(int) $r['performance_id']] = $r;
            }
        }
        $rows = Db::rows(
            'SELECT mr.*, p.topic, pr.name AS presenter_name FROM mark_results mr JOIN performances p ON p.id = mr.performance_id
             JOIN presenters pr ON pr.id = p.presenter_id WHERE mr.mark_run_id = ? ORDER BY mr.`rank` IS NULL, mr.`rank`, mr.id',
            [$runId],
        );
        $weights = self::snapshotWeights($run);
        foreach ($rows as &$r) {
            $r['breakdown'] = ScoringService::breakdownFor($r, $weights);
            $o = $old[(int) $r['performance_id']] ?? null;
            $r['prev_final'] = $o['final_mark'] ?? null;
            $r['prev_rank']  = $o['rank'] ?? null;
            $r['delta']      = ($o !== null && $o['final_mark'] !== null && $r['final_mark'] !== null) ? round((float) $r['final_mark'] - (float) $o['final_mark'], 4) : null;
        }
        unset($r);

        $questions = json_decode((string) $run['question_weights_snapshot'], true) ?: [];

        return ['run' => $run, 'weights' => $weights, 'questions' => $questions, 'previous_run_id' => $prev['id'] ?? null, 'results' => $rows];
    }

    /** Weights stored with a run (runs made before the three-group model used w_user / w_complete). */
    public static function snapshotWeights(array $run): array
    {
        $w = json_decode((string) $run['weights_snapshot'], true) ?: [];

        return [
            'w_general'        => (float) ($w['w_general'] ?? $w['w_user'] ?? 0),
            'w_representative' => (float) ($w['w_representative'] ?? $w['w_complete'] ?? 0),
            'w_judge'          => (float) ($w['w_judge'] ?? 0),
        ];
    }
}
