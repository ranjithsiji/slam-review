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
            'in_pool'          => ['sql' => '(si.id IS NULL)'],
            // A slot can leave the schedule while it has not run and nobody reviewed it (ScheduleService::assertRemovable).
            'can_unschedule'   => ['sql' => "(si.id IS NOT NULL AND si.status IN ('pending','skipped') AND NOT EXISTS (SELECT 1 FROM reviews r2 WHERE r2.schedule_item_id = si.id) AND NOT EXISTS (SELECT 1 FROM review_windows w2 WHERE w2.schedule_item_id = si.id))"],
            'can_delete'       => ['sql' => "((si.id IS NULL OR (si.status IN ('pending','skipped') AND NOT EXISTS (SELECT 1 FROM reviews r3 WHERE r3.schedule_item_id = si.id) AND NOT EXISTS (SELECT 1 FROM review_windows w3 WHERE w3.schedule_item_id = si.id))) AND NOT EXISTS (SELECT 1 FROM mark_results m3 WHERE m3.performance_id = p.id))"],
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
