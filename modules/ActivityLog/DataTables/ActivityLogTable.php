<?php

declare(strict_types=1);

namespace Modules\ActivityLog\DataTables;

use Modules\Core\DataTables\TableDefinition;
use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\DomainException;

/**
 * Activity log viewer. Volunteers see only check-in/out entries of their slam.
 * recordsTotal is an approximate cached count; always filter by slam/time first.
 */
class ActivityLogTable extends TableDefinition
{
    public function roles(): array
    {
        return ['admin', 'volunteer'];
    }

    public function from(): string
    {
        return 'activity_log l';
    }

    public function columns(): array
    {
        return [
            'id'          => ['sql' => 'l.id', 'order' => true],
            'occurred_at' => ['sql' => 'l.occurred_at', 'order' => true],
            'slam_id'     => ['sql' => 'l.slam_id'],
            'actor_type'  => ['sql' => 'l.actor_type', 'order' => true],
            'actor_id'    => ['sql' => 'l.actor_id'],
            'actor_label' => ['sql' => 'l.actor_label', 'search' => true, 'order' => true],
            'action'      => ['sql' => 'l.action', 'order' => true],
            'target_type' => ['sql' => 'l.target_type'],
            'target_id'   => ['sql' => 'l.target_id'],
            'outcome'     => ['sql' => 'l.outcome', 'order' => true],
            'reason'      => ['sql' => 'l.reason', 'search' => true],
            'old_values'  => ['sql' => 'l.old_values'],
            'new_values'  => ['sql' => 'l.new_values'],
            'meta'        => ['sql' => 'l.meta'],
            'ip'          => ['sql' => 'INET6_NTOA(l.ip)'],
        ];
    }

    public function scope(Actor $actor, array $filters): array
    {
        if ($actor->isVolunteer()) {
            return [["l.slam_id = ?", "(l.action LIKE 'checkin.%' OR l.action LIKE 'checkout.%')"], [(int) $actor->slamId]];
        }

        return [[], []];
    }

    public function filters(): array
    {
        return [
            'slam_id'     => self::intFilter('l.slam_id'),
            'actor_type'  => self::enumFilter('l.actor_type', ['admin', 'volunteer', 'user', 'judge', 'system', 'anonymous']),
            'actor_id'    => self::intFilter('l.actor_id'),
            'outcome'     => self::enumFilter('l.outcome', ['success', 'failure', 'denied']),
            'target_type' => static fn ($v) => preg_match('/^[a-z_]{1,40}$/', (string) $v) ? ['l.target_type = ?', [$v]] : throw new DomainException('invalid_filter', 'Invalid filter value.', 422),
            'target_id'   => self::intFilter('l.target_id'),
            'action'      => static function ($v): array {
                $vals = is_array($v) ? $v : [$v];
                $parts = [];
                $binds = [];
                foreach ($vals as $a) {
                    if (! preg_match('/^[a-z_]+(\.[a-z_|*]+)*\*?$/', (string) $a)) {
                        throw new DomainException('invalid_filter', 'Invalid action filter.', 422);
                    }
                    $parts[] = 'l.action LIKE ?';
                    $binds[] = str_replace('*', '%', (string) $a);
                }

                return ['(' . implode(' OR ', $parts) . ')', $binds];
            },
            'ip'          => static fn ($v) => @inet_pton((string) $v) !== false ? ['l.ip = ?', [inet_pton((string) $v)]] : throw new DomainException('invalid_filter', 'Invalid IP.', 422),
            'from'        => self::dateFrom('l.occurred_at'),
            'to'          => self::dateTo('l.occurred_at'),
            'after_id'    => static fn ($v) => ['l.id > ?', [(int) $v]],
        ];
    }

    public function approximateTotal(): bool
    {
        return true;
    }

    public function defaultOrder(): array
    {
        return ['id', 'desc'];
    }

    public function transform(array $row): array
    {
        foreach (['old_values', 'new_values', 'meta'] as $k) {
            $row[$k] = $row[$k] !== null ? json_decode((string) $row[$k], true) : null;
        }
        $row['occurred_at'] = $row['occurred_at'] !== null ? str_replace(' ', 'T', substr((string) $row['occurred_at'], 0, 23)) . 'Z' : null;

        return $row;
    }
}
