<?php

declare(strict_types=1);

namespace Modules\Presenters\DataTables;

use Modules\Core\DataTables\TableDefinition;

class PerformancesTable extends TableDefinition
{
    public function roles(): array
    {
        return ['admin'];
    }

    public function from(): string
    {
        return 'performances p JOIN presenters pr ON pr.id = p.presenter_id LEFT JOIN schedule_items si ON si.performance_id = p.id';
    }

    public function columns(): array
    {
        return [
            'id'               => ['sql' => 'p.id', 'order' => true],
            'presenter_id'     => ['sql' => 'pr.id'],
            'thumb'            => ['sql' => 'pr.photo_thumb_path'],
            'presenter_name'   => ['sql' => 'pr.name', 'search' => true, 'order' => true],
            'topic'            => ['sql' => 'p.topic', 'search' => true, 'order' => true],
            'planned_start_at' => ['sql' => 'si.planned_start_at', 'order' => true],
            'slot_status'      => ['sql' => 'si.status', 'order' => true],
            'reviews'          => ['sql' => '(SELECT COUNT(*) FROM reviews r WHERE r.schedule_item_id = si.id)', 'order' => true],
            'is_active'        => ['sql' => 'p.is_active', 'order' => true],
        ];
    }

    public function filters(): array
    {
        return [
            'slam_id'   => self::intFilter('p.slam_id'),
            // 1 = in the schedule, 0 = still in the pool
            'scheduled' => static fn ($v) => [$v === '1' || $v === 1 || $v === true ? 'si.id IS NOT NULL' : 'si.id IS NULL', []],
        ];
    }

    public function requiredFilters(): array
    {
        return ['slam_id'];
    }

    public function defaultOrder(): array
    {
        return ['planned_start_at', 'asc'];
    }

    public function transform(array $row): array
    {
        return self::iso($row, 'planned_start_at');
    }
}
