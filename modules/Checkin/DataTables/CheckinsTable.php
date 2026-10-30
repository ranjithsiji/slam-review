<?php

declare(strict_types=1);

namespace Modules\Checkin\DataTables;

use Modules\Core\DataTables\TableDefinition;
use Modules\Core\Libraries\Actor;

/** Check-in monitor: one row per check-in (open and closed). */
class CheckinsTable extends TableDefinition
{
    public function roles(): array
    {
        return ['admin', 'volunteer'];
    }

    public function from(): string
    {
        return 'checkins c JOIN attendees a ON a.id = c.attendee_id';
    }

    public function columns(): array
    {
        return [
            'id'             => ['sql' => 'c.id', 'order' => true],
            'attendee_id'    => ['sql' => 'a.id'],
            'name'           => ['sql' => 'a.name', 'search' => true, 'order' => true],
            'badge_id'       => ['sql' => 'a.badge_id', 'search' => true, 'order' => true],
            'user_type'      => ['sql' => 'a.user_type', 'order' => true],
            'checked_in_at'  => ['sql' => 'c.checked_in_at', 'order' => true],
            'checked_out_at' => ['sql' => 'c.checked_out_at', 'order' => true],
            'checkout_type'  => ['sql' => 'c.checkout_type', 'order' => true],
            'by'             => ['sql' => 'c.checked_in_by_type'],
            'claimed'        => ['sql' => '(c.session_token_hash IS NOT NULL)'],
            'late'           => ['sql' => 'c.is_late_override'],
        ];
    }

    public function scope(Actor $actor, array $filters): array
    {
        return self::slamScope($actor, 'a.slam_id');
    }

    public function filters(): array
    {
        return [
            'slam_id' => self::intFilter('a.slam_id'),
            'open'    => static fn ($v) => [$v ? 'c.checked_out_at IS NULL' : 'c.checked_out_at IS NOT NULL', []],
        ];
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
        return self::iso($row, 'checked_in_at', 'checked_out_at');
    }
}
