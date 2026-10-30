<?php

declare(strict_types=1);

namespace Modules\Core\DataTables;

use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\DomainException;

/**
 * One server-side table endpoint. Subclasses declare:
 *  - roles(): who may call it
 *  - from(): a constant FROM/JOIN clause (developer-written SQL, never client input)
 *  - columns(): allow-list name => [sql, search?, order?]; client column names/indexes
 *    only ever select from this list
 *  - scope(): mandatory WHERE for the actor (e.g. a volunteer's own slam)
 *  - filters(): allow-list of screen filters, each returning [sql, binds]
 */
abstract class TableDefinition
{
    /** @return list<string> */
    abstract public function roles(): array;

    abstract public function from(): string;

    /** @return array<string, array{sql: string, search?: bool, order?: bool}> */
    abstract public function columns(): array;

    /** @return array{0: list<string>, 1: list<mixed>} WHERE fragments + binds */
    public function scope(Actor $actor, array $filters): array
    {
        return [[], []];
    }

    /** @return array<string, callable(mixed): array{0: string, 1: list<mixed>}> */
    public function filters(): array
    {
        return [];
    }

    /** Filters that must be present (e.g. slam_id on big tables). */
    public function requiredFilters(): array
    {
        return [];
    }

    /** @return array{0: string, 1: 'asc'|'desc'} */
    public function defaultOrder(): array
    {
        return [array_key_first($this->columns()), 'asc'];
    }

    /**
     * Called once before any query (e.g. to build a per-connection temporary
     * table for computed data such as provisional marks).
     */
    public function prepare(Actor $actor, array $filters): void
    {
    }

    /** Use a cached approximate recordsTotal (huge tables). */
    public function approximateTotal(): bool
    {
        return false;
    }

    /** Post-process one row (dates → ISO, JSON decode, ...). */
    public function transform(array $row): array
    {
        return $row;
    }

    // --- helpers for subclasses -------------------------------------------

    protected static function intFilter(string $sql): callable
    {
        return static function (mixed $v) use ($sql): array {
            if (filter_var($v, FILTER_VALIDATE_INT) === false) {
                throw new DomainException('invalid_filter', 'Invalid filter value.', 422);
            }

            return [$sql . ' = ?', [(int) $v]];
        };
    }

    /** @param list<string> $allowed */
    protected static function enumFilter(string $sql, array $allowed): callable
    {
        return static function (mixed $v) use ($sql, $allowed): array {
            $vals = is_array($v) ? $v : [$v];
            foreach ($vals as $x) {
                if (! in_array($x, $allowed, true)) {
                    throw new DomainException('invalid_filter', 'Invalid filter value.', 422);
                }
            }
            if ($vals === []) {
                return ['1=1', []];
            }

            return [$sql . ' IN (' . implode(',', array_fill(0, count($vals), '?')) . ')', array_values($vals)];
        };
    }

    protected static function dateFrom(string $sql): callable
    {
        return static fn (mixed $v): array => [$sql . ' >= ?', [Clock::db(Clock::parse((string) $v))]];
    }

    protected static function dateTo(string $sql): callable
    {
        return static fn (mixed $v): array => [$sql . ' <= ?', [Clock::db(Clock::parse((string) $v))]];
    }

    protected static function iso(array $row, string ...$keys): array
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $row)) {
                $row[$k] = Clock::iso($row[$k]);
            }
        }

        return $row;
    }

    /** Volunteers: own slam only. Admins: any. */
    protected static function slamScope(Actor $actor, string $column): array
    {
        if ($actor->isVolunteer()) {
            return [[$column . ' = ?'], [(int) $actor->slamId]];
        }

        return [[], []];
    }
}
