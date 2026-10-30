<?php

declare(strict_types=1);

namespace Modules\Scoring\DataTables;

use Modules\Core\DataTables\TableDefinition;
use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\DomainException;

/** Per-question averages by group, per performance (provisional, from current answers). */
class MarkStatsTable extends TableDefinition
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
        $db = db_connect();
        $db->query('DROP TEMPORARY TABLE IF EXISTS tmp_mark_stats');
        $db->query('CREATE TEMPORARY TABLE tmp_mark_stats (presenter_name VARCHAR(120), topic VARCHAR(200), audience VARCHAR(10),
            question VARCHAR(500), avg_user DOUBLE NULL, avg_judge DOUBLE NULL, avg_complete DOUBLE NULL) ENGINE=MEMORY DEFAULT CHARSET=utf8mb4');
        $calc = service('scoring')->compute($slamId);
        foreach ($calc['results'] as $r) {
            foreach ($r['per_question'] as $qid => $groups) {
                $q = $calc['question_weights'][$qid] ?? null;
                if ($q === null) {
                    continue;
                }
                $db->table('tmp_mark_stats')->insert([
                    'presenter_name' => $r['presenter_name'], 'topic' => $r['topic'], 'audience' => $q['audience'], 'question' => $q['text'],
                    'avg_user' => $groups['user'] ?? null, 'avg_judge' => $groups['judge'] ?? null, 'avg_complete' => $groups['complete'] ?? null,
                ]);
            }
        }
    }

    public function from(): string
    {
        return 'tmp_mark_stats t';
    }

    public function columns(): array
    {
        return [
            'presenter_name' => ['sql' => 't.presenter_name', 'search' => true, 'order' => true],
            'topic'          => ['sql' => 't.topic', 'search' => true],
            'audience'       => ['sql' => 't.audience', 'order' => true],
            'question'       => ['sql' => 't.question', 'search' => true, 'order' => true],
            'avg_user'       => ['sql' => 't.avg_user', 'order' => true],
            'avg_judge'      => ['sql' => 't.avg_judge', 'order' => true],
            'avg_complete'   => ['sql' => 't.avg_complete', 'order' => true],
        ];
    }

    public function filters(): array
    {
        return ['slam_id' => static fn ($v) => ['1=1', []], 'audience' => self::enumFilter('t.audience', ['user', 'judge'])];
    }

    public function transform(array $row): array
    {
        foreach (['avg_user', 'avg_judge', 'avg_complete'] as $k) {
            $row[$k] = $row[$k] === null ? null : round((float) $row[$k], 2);
        }

        return $row;
    }
}
