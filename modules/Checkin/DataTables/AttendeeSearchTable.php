<?php

declare(strict_types=1);

namespace Modules\Checkin\DataTables;

use Modules\Core\DataTables\TableDefinition;
use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\DomainException;

/**
 * Volunteer/admin attendee lookup by name, mobile or email (3+ characters).
 * Returns registration + current check-in state; never creates anything.
 */
class AttendeeSearchTable extends TableDefinition
{
    public function roles(): array
    {
        return ['admin', 'volunteer'];
    }

    public function from(): string
    {
        return 'attendees a LEFT JOIN checkins c ON c.attendee_id = a.id AND c.checked_out_at IS NULL';
    }

    public function columns(): array
    {
        return [
            'id'         => ['sql' => 'a.id'],
            'name'       => ['sql' => 'a.name', 'order' => true],
            'badge_id'   => ['sql' => 'a.badge_id', 'order' => true],
            'user_type'  => ['sql' => 'a.user_type', 'order' => true],
            'mobile'     => ['sql' => 'a.mobile'],
            'email'      => ['sql' => 'a.email'],
            'is_active'  => ['sql' => 'a.is_active'],
            'checked_in' => ['sql' => '(c.id IS NOT NULL)', 'order' => true],
            'last_state' => ['sql' => "(SELECT COALESCE(c2.checkout_type, 'in') FROM checkins c2 WHERE c2.attendee_id = a.id ORDER BY c2.id DESC LIMIT 1)"],
        ];
    }

    public function scope(Actor $actor, array $filters): array
    {
        [$sql, $binds] = self::slamScope($actor, 'a.slam_id');
        $q = trim((string) ($filters['q'] ?? ''));
        if (mb_strlen($q) < 3) {
            throw new DomainException('query_too_short', 'Type at least 3 characters.', 422);
        }

        return [$sql, $binds];
    }

    public function filters(): array
    {
        return [
            'slam_id' => self::intFilter('a.slam_id'),
            'q'       => static function ($v): array {
                $q      = mb_substr(trim((string) $v), 0, 100);
                $digits = preg_replace('/\D+/', '', $q);
                $like   = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';
                $parts  = ['a.name LIKE ?', 'a.email LIKE ?', 'a.badge_id = ?'];
                $binds  = [$like, $like, strtoupper(str_replace(' ', '', $q))];
                if (strlen((string) $digits) >= 4) {
                    $parts[] = 'a.mobile LIKE ?';
                    $binds[] = '%' . substr((string) $digits, -10) . '%';
                }

                return ['(' . implode(' OR ', $parts) . ')', $binds];
            },
        ];
    }

    public function requiredFilters(): array
    {
        return ['slam_id', 'q'];
    }

    public function defaultOrder(): array
    {
        return ['name', 'asc'];
    }

    public function transform(array $row): array
    {
        // Mask mobile for display (volunteers confirm the last digits with the person).
        $row['mobile'] = $row['mobile'] !== null ? str_repeat('•', 6) . substr($row['mobile'], -4) : null;

        return $row;
    }
}
