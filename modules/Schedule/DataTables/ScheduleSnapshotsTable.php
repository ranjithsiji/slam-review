<?php

declare(strict_types=1);

namespace Modules\Schedule\DataTables;

use Modules\Core\DataTables\TableDefinition;

class ScheduleSnapshotsTable extends TableDefinition
{
    public function roles(): array
    {
        return ['admin'];
    }

    public function from(): string
    {
        return 'schedule_snapshots s LEFT JOIN admins a ON a.id = s.saved_by';
    }

    public function columns(): array
    {
        return [
            'version'  => ['sql' => 's.version', 'order' => true],
            'saved_at' => ['sql' => 's.saved_at', 'order' => true],
            'by'       => ['sql' => "COALESCE(a.name, 'system')"],
            'note'     => ['sql' => 's.note', 'search' => true],
            'slots'    => ['sql' => 'JSON_LENGTH(s.snapshot)'],
        ];
    }

    public function filters(): array
    {
        return ['slam_id' => self::intFilter('s.slam_id')];
    }

    public function requiredFilters(): array
    {
        return ['slam_id'];
    }

    public function defaultOrder(): array
    {
        return ['version', 'desc'];
    }

    public function transform(array $row): array
    {
        return self::iso($row, 'saved_at');
    }
}
