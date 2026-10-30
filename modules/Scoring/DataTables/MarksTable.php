<?php

declare(strict_types=1);

namespace Modules\Scoring\DataTables;

use Modules\Core\DataTables\TableDefinition;
use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;
use Modules\Scoring\Services\MarkRunService;
use Modules\Scoring\Services\ScoringService;

/**
 * Marks per performance. Reads the current mark run, or a specific run, or —
 * while provisional — a temporary table computed from the current answers, so
 * sorting and paging always happen in SQL.
 */
class MarksTable extends TableDefinition
{
    public function roles(): array
    {
        return ['admin'];
    }

    public function prepare(Actor $actor, array $filters): void
    {
        $slamId = filter_var($filters['slam_id'] ?? null, FILTER_VALIDATE_INT);
        if ($slamId === false) {
            throw new DomainException('filter_required', "Filter 'slam_id' is required.", 422);
        }
        $runId = $filters['run_id'] ?? 'current';
        $db    = db_connect();
        $db->query('DROP TEMPORARY TABLE IF EXISTS tmp_marks');
        $db->query('CREATE TEMPORARY TABLE tmp_marks (performance_id INT UNSIGNED, presenter_name VARCHAR(120), topic VARCHAR(200),
            avg_general DOUBLE NULL, avg_representative DOUBLE NULL, avg_judge DOUBLE NULL,
            c_general DOUBLE NULL, c_representative DOUBLE NULL, c_judge DOUBLE NULL, final_mark DOUBLE NULL, `rank` INT NULL,
            n_general INT, n_representative INT, n_judge INT, provisional TINYINT(1)) ENGINE=MEMORY DEFAULT CHARSET=utf8mb4');

        $run = $runId === 'provisional' ? null
            : ($runId === 'current' ? service('markRuns')->currentRun($slamId) : Db::row('SELECT * FROM mark_runs WHERE id = ? AND slam_id = ?', [(int) $runId, $slamId]));
        if ($run !== null) {
            $weights = MarkRunService::snapshotWeights($run);
            $rows    = Db::rows('SELECT mr.*, pr.name AS presenter_name, p.topic FROM mark_results mr JOIN performances p ON p.id = mr.performance_id
                JOIN presenters pr ON pr.id = p.presenter_id WHERE mr.mark_run_id = ?', [$run['id']]);
        } else {
            $weights = service('scoring')->weights($slamId);
            $rows    = service('scoring')->compute($slamId)['results'];
        }
        foreach ($rows as $r) {
            $b = ScoringService::breakdownFor($r, $weights)['groups'];
            $db->table('tmp_marks')->insert([
                'performance_id' => $r['performance_id'], 'presenter_name' => $r['presenter_name'], 'topic' => $r['topic'],
                'avg_general' => $r['avg_general'], 'avg_representative' => $r['avg_representative'], 'avg_judge' => $r['avg_judge'],
                'c_general' => $b['general']['contribution'], 'c_representative' => $b['representative']['contribution'], 'c_judge' => $b['judge']['contribution'],
                'final_mark' => $r['final_mark'], 'rank' => $r['rank'], 'n_general' => $r['n_general'], 'n_representative' => $r['n_representative'],
                'n_judge' => $r['n_judge'], 'provisional' => $run === null ? 1 : 0,
            ]);
        }
    }

    public function from(): string
    {
        return 'tmp_marks m';
    }

    public function columns(): array
    {
        return [
            'rank'           => ['sql' => 'm.`rank`', 'order' => true],
            'performance_id' => ['sql' => 'm.performance_id'],
            'presenter_name' => ['sql' => 'm.presenter_name', 'search' => true, 'order' => true],
            'topic'          => ['sql' => 'm.topic', 'search' => true, 'order' => true],
            'avg_general'        => ['sql' => 'm.avg_general', 'order' => true],
            'avg_representative' => ['sql' => 'm.avg_representative', 'order' => true],
            'avg_judge'          => ['sql' => 'm.avg_judge', 'order' => true],
            'c_general'          => ['sql' => 'm.c_general'],
            'c_representative'   => ['sql' => 'm.c_representative'],
            'c_judge'            => ['sql' => 'm.c_judge'],
            'final_mark'         => ['sql' => 'm.final_mark', 'order' => true],
            'n_general'          => ['sql' => 'm.n_general', 'order' => true],
            'n_representative'   => ['sql' => 'm.n_representative', 'order' => true],
            'n_judge'            => ['sql' => 'm.n_judge', 'order' => true],
            'provisional'    => ['sql' => 'm.provisional'],
        ];
    }

    public function filters(): array
    {
        // Consumed by prepare(); they do not filter rows of the temporary table.
        return ['slam_id' => static fn ($v) => ['1=1', []], 'run_id' => static fn ($v) => ['1=1', []]];
    }

    public function defaultOrder(): array
    {
        return ['rank', 'asc'];
    }

    public function transform(array $row): array
    {
        foreach (['avg_general', 'avg_representative', 'avg_judge', 'c_general', 'c_representative', 'c_judge', 'final_mark'] as $k) {
            $row[$k] = $row[$k] === null ? null : round((float) $row[$k], 2);
        }

        return $row;
    }
}
