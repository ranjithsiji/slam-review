<?php

declare(strict_types=1);

namespace Modules\Slams\DataTables;

use Modules\Core\DataTables\TableDefinition;

class SlamsTable extends TableDefinition
{
    public function roles(): array
    {
        return ['admin'];
    }

    public function from(): string
    {
        return 'slams s';
    }

    public function columns(): array
    {
        return [
            'id'                 => ['sql' => 's.id', 'order' => true],
            'name'               => ['sql' => 's.name', 'search' => true, 'order' => true],
            'type'               => ['sql' => 's.type', 'order' => true],
            'venue_name'         => ['sql' => 's.venue_name', 'search' => true, 'order' => true],
            'city'               => ['sql' => 's.city', 'search' => true, 'order' => true],
            'opens_at'           => ['sql' => 's.opens_at', 'order' => true],
            'status'             => ['sql' => 's.status', 'order' => true],
            'schedule_is_public' => ['sql' => 's.schedule_is_public', 'order' => true],
            'attendees'          => ['sql' => '(SELECT COUNT(*) FROM attendees a WHERE a.slam_id = s.id AND a.is_active = 1)'],
        ];
    }

    public function filters(): array
    {
        return ['status' => self::enumFilter('s.status', \Modules\Slams\Services\SlamPhaseService::ORDER), 'type' => self::enumFilter('s.type', ['regional', 'final'])];
    }

    public function defaultOrder(): array
    {
        return ['opens_at', 'asc'];
    }

    public function transform(array $row): array
    {
        return self::iso($row, 'opens_at');
    }
}
