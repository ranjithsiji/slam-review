<?php

declare(strict_types=1);

namespace Modules\Accounts\DataTables;

use Modules\Core\DataTables\TableDefinition;

class VolunteersTable extends TableDefinition
{
    public function roles(): array
    {
        return ['admin'];
    }

    public function from(): string
    {
        return 'volunteers v JOIN slams s ON s.id = v.slam_id';
    }

    public function columns(): array
    {
        return [
            'id'        => ['sql' => 'v.id', 'order' => true],
            'name'      => ['sql' => 'v.name', 'search' => true, 'order' => true],
            'username'  => ['sql' => 'v.username', 'search' => true, 'order' => true],
            'slam_name' => ['sql' => 's.name', 'search' => true, 'order' => true],
            'is_active' => ['sql' => 'v.is_active', 'order' => true],
        ];
    }

    public function filters(): array
    {
        return ['slam_id' => self::intFilter('v.slam_id')];
    }

    public function defaultOrder(): array
    {
        return ['name', 'asc'];
    }
}
