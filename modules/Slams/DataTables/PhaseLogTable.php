<?php

declare(strict_types=1);

namespace Modules\Slams\DataTables;

use Modules\Core\DataTables\TableDefinition;

class PhaseLogTable extends TableDefinition
{
    public function roles(): array
    {
        return ['admin'];
    }

    public function from(): string
    {
        return 'slam_phase_log l LEFT JOIN admins a ON a.id = l.changed_by_id';
    }

    public function columns(): array
    {
        return [
            'id'          => ['sql' => 'l.id', 'order' => true],
            'changed_at'  => ['sql' => 'l.changed_at', 'order' => true],
            'from_status' => ['sql' => 'l.from_status'],
            'to_status'   => ['sql' => 'l.to_status'],
            'trigger'     => ['sql' => 'l.`trigger`', 'order' => true],
            'by'          => ['sql' => "COALESCE(a.name, l.changed_by_type)"],
            'note'        => ['sql' => 'l.note', 'search' => true],
        ];
    }

    public function filters(): array
    {
        return ['slam_id' => self::intFilter('l.slam_id')];
    }

    public function requiredFilters(): array
    {
        return ['slam_id'];
    }

    public function defaultOrder(): array
    {
        return ['id', 'desc'];
    }

    public function transform(array $row): array
    {
        return self::iso($row, 'changed_at');
    }
}
