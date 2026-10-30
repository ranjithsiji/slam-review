<?php

declare(strict_types=1);

namespace Modules\Scoring\DataTables;

use Modules\Core\DataTables\TableDefinition;
use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\DomainException;

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
        $runId  = $filters['run_id'] ?? 'current';
        $db     = db_connect();
        $db->query('DROP TEMPORARY TABLE IF EXISTS tmp_marks');
        $db->query('CREATE TEMPORARY TABLE tmp_marks (performance_id INT UNSIGNED, presenter_name VARCHAR(120), topic VARCHAR(200),
            avg_user DOUBLE NULL, avg_judge DOUBLE NULL, avg_complete DOUBLE NULL, final_mark DOUBLE NULL, `rank` INT NULL,
            n_user INT, n_judge INT, n_complete INT, provisional TINYINT(1)) ENGINE=MEMORY DEFAULT CHARSET=utf8mb4');

        $run = $runId === 'provisional' ? null
            : ($runId === 'current' ? service('markRuns')->currentRun($slamId) : \Modules\Core\Libraries\Db::row('SELECT * FROM mark_runs WHERE id = ? AND slam_id = ?', [(int) $runId, $slamId]));
        if ($run !== null) {
            $db->query('INSERT INTO tmp_marks SELECT mr.performance_id, pr.name, p.topic, mr.avg_user, mr.avg_judge, mr.avg_complete, mr.final_mark, mr.`rank`,
                mr.n_user, mr.n_judge, mr.n_complete, 0 FROM mark_results mr JOIN performances p ON p.id = mr.performance_id
                JOIN presenters pr ON pr.id = p.presenter_id WHERE mr.mark_run_id = ?', [$run['id']]);

            return;
        }
        foreach (service('scoring')->compute($slamId)['results'] as $r) {
            $db->table('tmp_marks')->insert([
                'performance_id' => $r['performance_id'], 'presenter_name' => $r['presenter_name'], 'topic' => $r['topic'],
                'avg_user' => $r['avg_user'], 'avg_judge' => $r['avg_judge'], 'avg_complete' => $r['avg_complete'],
                'final_mark' => $r['final_mark'], 'rank' => $r['rank'], 'n_user' => $r['n_user'], 'n_judge' => $r['n_judge'],
                'n_complete' => $r['n_complete'], 'provisional' => 1,
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
            'avg_user'       => ['sql' => 'm.avg_user', 'order' => true],
            'avg_judge'      => ['sql' => 'm.avg_judge', 'order' => true],
            'avg_complete'   => ['sql' => 'm.avg_complete', 'order' => true],
            'final_mark'     => ['sql' => 'm.final_mark', 'order' => true],
            'n_user'         => ['sql' => 'm.n_user', 'order' => true],
            'n_judge'        => ['sql' => 'm.n_judge', 'order' => true],
            'n_complete'     => ['sql' => 'm.n_complete', 'order' => true],
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
        foreach (['avg_user', 'avg_judge', 'avg_complete', 'final_mark'] as $k) {
            $row[$k] = $row[$k] === null ? null : round((float) $row[$k], 2);
        }

        return $row;
    }
}
