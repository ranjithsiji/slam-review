<?php

declare(strict_types=1);

namespace Modules\Accounts\DataTables;

use Modules\Core\DataTables\TableDefinition;

class AdminsTable extends TableDefinition
{
    public function roles(): array
    {
        return ['admin'];
    }

    public function from(): string
    {
        return 'admins a LEFT JOIN slams s ON s.id = a.slam_id';
    }

    public function columns(): array
    {
        return [
            'id'            => ['sql' => 'a.id', 'order' => true],
            'name'          => ['sql' => 'a.name', 'search' => true, 'order' => true],
            'username'      => ['sql' => 'a.username', 'search' => true, 'order' => true],
            'role_label'    => ['sql' => 'a.role'],
            'slam_name'     => ['sql' => 's.name', 'search' => true],
            'email'         => ['sql' => 'a.email', 'search' => true, 'order' => true],
            'is_active'     => ['sql' => 'a.is_active', 'order' => true],
            'last_login_at' => ['sql' => 'a.last_login_at', 'order' => true],
        ];
    }

    public function transform(array $row): array
    {
        $row['role_label'] = $row['role_label'] === 'operator' ? 'Slam operator' : 'Administrator';

        return $row;
    }

    public function defaultOrder(): array
    {
        return ['name', 'asc'];
    }
}
