<?php

declare(strict_types=1);

namespace Modules\Scoring\DataTables;

use Modules\Core\DataTables\TableDefinition;

class MarkRunsTable extends TableDefinition
{
    public function roles(): array
    {
        return ['admin'];
    }

    public function from(): string
    {
        return 'mark_runs r LEFT JOIN admins a ON a.id = r.calculated_by';
    }

    public function columns(): array
    {
        return [
            'id'            => ['sql' => 'r.id', 'order' => true],
            'calculated_at' => ['sql' => 'r.calculated_at', 'order' => true],
            'by'            => ['sql' => "COALESCE(a.name, 'system')"],
            'is_current'    => ['sql' => 'r.is_current'],
            'is_final'      => ['sql' => 'r.is_final'],
            'weights'       => ['sql' => 'r.weights_snapshot'],
            'counts'        => ['sql' => 'r.counts_snapshot'],
            'note'          => ['sql' => 'r.note', 'search' => true],
        ];
    }

    public function filters(): array
    {
        return ['slam_id' => self::intFilter('r.slam_id')];
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
        $row['weights'] = json_decode((string) $row['weights'], true);
        $row['counts']  = json_decode((string) $row['counts'], true);

        return self::iso($row, 'calculated_at');
    }
}
