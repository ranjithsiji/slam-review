<?php

declare(strict_types=1);

namespace Modules\Scoring\DataTables;

use Modules\Core\DataTables\TableDefinition;
use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\DomainException;

/** Per-question average points by group, per performance (provisional, from current answers). */
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
            question VARCHAR(500), max_score INT, avg_general DOUBLE NULL, avg_representative DOUBLE NULL, avg_judge DOUBLE NULL) ENGINE=MEMORY DEFAULT CHARSET=utf8mb4');
        $calc = service('scoring')->compute($slamId);
        foreach ($calc['results'] as $r) {
            foreach ($r['per_question'] as $qid => $groups) {
                $q = $calc['question_weights'][$qid] ?? null;
                if ($q === null) {
                    continue;
                }
                $db->table('tmp_mark_stats')->insert([
                    'presenter_name' => $r['presenter_name'], 'topic' => $r['topic'], 'audience' => $q['audience'], 'question' => $q['text'], 'max_score' => $q['max_score'],
                    'avg_general' => $groups['general'] ?? null, 'avg_representative' => $groups['representative'] ?? null, 'avg_judge' => $groups['judge'] ?? null,
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
            'max_score'          => ['sql' => 't.max_score'],
            'avg_general'        => ['sql' => 't.avg_general', 'order' => true],
            'avg_representative' => ['sql' => 't.avg_representative', 'order' => true],
            'avg_judge'          => ['sql' => 't.avg_judge', 'order' => true],
        ];
    }

    public function filters(): array
    {
        return ['slam_id' => static fn ($v) => ['1=1', []], 'audience' => self::enumFilter('t.audience', ['user', 'judge'])];
    }

    public function transform(array $row): array
    {
        foreach (['avg_general', 'avg_representative', 'avg_judge'] as $k) {
            $row[$k] = $row[$k] === null ? null : round((float) $row[$k], 2);
        }

        return $row;
    }
}
