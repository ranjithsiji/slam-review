<?php

declare(strict_types=1);

namespace Modules\Attendees\DataTables;

use Modules\Core\DataTables\TableDefinition;

class ImportRowsTable extends TableDefinition
{
    public function roles(): array
    {
        return ['admin'];
    }

    public function from(): string
    {
        return 'import_batch_rows r';
    }

    public function columns(): array
    {
        return [
            'line_no'   => ['sql' => 'r.line_no', 'order' => true],
            'status'    => ['sql' => 'r.status', 'order' => true],
            'name'      => ['sql' => 'r.name', 'search' => true, 'order' => true],
            'mobile'    => ['sql' => 'r.mobile', 'search' => true],
            'email'     => ['sql' => 'r.email', 'search' => true],
            'user_type' => ['sql' => 'r.user_type'],
            'badge_id'  => ['sql' => 'r.badge_id'],
            'error'     => ['sql' => 'r.error', 'search' => true],
        ];
    }

    public function filters(): array
    {
        return ['batch_id' => self::intFilter('r.batch_id'), 'status' => self::enumFilter('r.status', ['new', 'duplicate', 'invalid'])];
    }

    public function requiredFilters(): array
    {
        return ['batch_id'];
    }
}
