<?php

declare(strict_types=1);

namespace Modules\Attendees\DataTables;

use Modules\Core\DataTables\TableDefinition;

class ImportBatchesTable extends TableDefinition
{
    public function roles(): array
    {
        return ['admin'];
    }

    public function from(): string
    {
        return 'import_batches b LEFT JOIN admins a ON a.id = b.uploaded_by';
    }

    public function columns(): array
    {
        return [
            'id'           => ['sql' => 'b.id', 'order' => true],
            'created_at'   => ['sql' => 'b.created_at', 'order' => true],
            'filename'     => ['sql' => 'b.filename', 'search' => true, 'order' => true],
            'mode'         => ['sql' => 'b.mode'],
            'status'       => ['sql' => 'b.status', 'order' => true],
            'rows_total'   => ['sql' => 'b.rows_total', 'order' => true],
            'rows_created' => ['sql' => 'b.rows_created'],
            'rows_updated' => ['sql' => 'b.rows_updated'],
            'rows_skipped' => ['sql' => 'b.rows_skipped'],
            'rows_failed'  => ['sql' => 'b.rows_failed'],
            'uploaded_by'  => ['sql' => 'a.name'],
        ];
    }

    public function filters(): array
    {
        return ['slam_id' => self::intFilter('b.slam_id')];
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
        return self::iso($row, 'created_at');
    }
}
