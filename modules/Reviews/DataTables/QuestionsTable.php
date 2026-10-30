<?php

declare(strict_types=1);

namespace Modules\Reviews\DataTables;

use Modules\Core\DataTables\TableDefinition;

class QuestionsTable extends TableDefinition
{
    public function roles(): array
    {
        return ['admin'];
    }

    public function from(): string
    {
        return 'review_questions q';
    }

    public function columns(): array
    {
        return [
            'id'        => ['sql' => 'q.id'],
            'position'  => ['sql' => 'q.position', 'order' => true],
            'text'      => ['sql' => 'q.text', 'search' => true, 'order' => true],
            'text_ml'   => ['sql' => 'q.text_ml', 'search' => true],
            'help_text' => ['sql' => 'q.help_text'],
            'weight'    => ['sql' => 'q.weight', 'order' => true],
            'is_active' => ['sql' => 'q.is_active', 'order' => true],
            'locked'    => ['sql' => '(q.locked_at IS NOT NULL)'],
            'answers'   => ['sql' => '(SELECT COUNT(*) FROM review_answers ra WHERE ra.question_id = q.id)'],
        ];
    }

    public function filters(): array
    {
        return [
            'slam_id'  => static fn ($v) => $v === 'template' ? ['q.slam_id IS NULL', []] : (filter_var($v, FILTER_VALIDATE_INT) !== false ? ['q.slam_id = ?', [(int) $v]] : throw new \Modules\Core\Libraries\DomainException('invalid_filter', 'Invalid filter value.', 422)),
            'audience' => self::enumFilter('q.audience', ['user', 'judge']),
        ];
    }

    public function requiredFilters(): array
    {
        return ['slam_id', 'audience'];
    }

    public function defaultOrder(): array
    {
        return ['position', 'asc'];
    }
}
