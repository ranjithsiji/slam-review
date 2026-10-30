<?php

declare(strict_types=1);

namespace Modules\Presenters\DataTables;

use Modules\Core\DataTables\TableDefinition;

class PresentersTable extends TableDefinition
{
    public function roles(): array
    {
        return ['admin'];
    }

    public function from(): string
    {
        return 'presenters pr';
    }

    public function columns(): array
    {
        return [
            'id'          => ['sql' => 'pr.id', 'order' => true],
            'thumb'       => ['sql' => 'pr.photo_thumb_path'],
            'name'        => ['sql' => 'pr.name', 'search' => true, 'order' => true],
            'designation' => ['sql' => 'pr.designation_or_institution', 'search' => true, 'order' => true],
            'slams'       => ['sql' => "(SELECT GROUP_CONCAT(s.name ORDER BY s.opens_at SEPARATOR ', ') FROM performances p JOIN slams s ON s.id = p.slam_id WHERE p.presenter_id = pr.id)"],
            'is_active'   => ['sql' => 'pr.is_active', 'order' => true],
        ];
    }

    public function defaultOrder(): array
    {
        return ['name', 'asc'];
    }
}
