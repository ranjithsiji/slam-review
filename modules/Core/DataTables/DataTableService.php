<?php

declare(strict_types=1);

namespace Modules\Core\DataTables;

use Modules\Core\Config\DataTables as Registry;
use Modules\Core\Config\Slam as SlamConfig;
use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;

/**
 * Shared server-side processing for every DataTables endpoint.
 * Request: standard DataTables (draw, start, length, search.value, order[], columns[])
 * plus `filters`. Response: {draw, recordsTotal, recordsFiltered, data}.
 * Client strings never reach ORDER BY / WHERE: names map through the allow-list
 * and every value is a bound parameter.
 */
class DataTableService
{
    public const MAX_LENGTH = 100;

    public function definition(string $name, Actor $actor): TableDefinition
    {
        $class = config(Registry::class)->tables[$name] ?? null;
        if ($class === null) {
            throw new DomainException('unknown_table', 'Unknown table.', 404);
        }
        /** @var TableDefinition $def */
        $def = new $class();
        if (! in_array($actor->type, $def->roles(), true)) {
            throw new DomainException('forbidden', 'Not allowed.', 403);
        }

        return $def;
    }

    /**
     * @return array{0: string, 1: list<mixed>, 2: string, 3: list<mixed>} where+binds (scope only), where+binds (all)
     */
    private function where(TableDefinition $def, Actor $actor, array $req): array
    {
        [$scopeSql, $scopeBinds] = $def->scope($actor, (array) ($req['filters'] ?? []));
        $filters = $req['filters'] ?? [];
        if (! is_array($filters)) {
            throw new DomainException('invalid_filter', 'Invalid filters.', 422);
        }
        $allowed = $def->filters();
        foreach ($def->requiredFilters() as $f) {
            if (! isset($filters[$f]) || $filters[$f] === '' || $filters[$f] === []) {
                throw new DomainException('filter_required', "Filter '{$f}' is required.", 422);
            }
        }
        $fSql   = [];
        $fBinds = [];
        foreach ($filters as $k => $v) {
            if (! isset($allowed[$k])) {
                throw new DomainException('invalid_filter', 'Unknown filter.', 422);
            }
            if ($v === '' || $v === null || $v === []) {
                continue;
            }
            [$sql, $binds] = $allowed[$k]($v);
            $fSql[]  = $sql;
            $fBinds  = [...$fBinds, ...$binds];
        }

        $search = trim((string) ($req['search']['value'] ?? ''));
        $sSql   = [];
        $sBinds = [];
        if (mb_strlen($search) >= 2) {
            $search = mb_substr($search, 0, 100);
            $like   = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search) . '%';
            $parts  = [];
            foreach ($def->columns() as $col) {
                if (! empty($col['search'])) {
                    $parts[]  = $col['sql'] . ' LIKE ?';
                    $sBinds[] = $like;
                }
            }
            if ($parts !== []) {
                $sSql[] = '(' . implode(' OR ', $parts) . ')';
            }
        }

        $scopeWhere = $scopeSql === [] ? '' : ' WHERE ' . implode(' AND ', $scopeSql);
        $all        = [...$scopeSql, ...$fSql, ...$sSql];
        $allWhere   = $all === [] ? '' : ' WHERE ' . implode(' AND ', $all);

        return [$scopeWhere, $scopeBinds, $allWhere, [...$scopeBinds, ...$fBinds, ...$sBinds]];
    }

    private function orderBy(TableDefinition $def, array $req): string
    {
        $cols    = $def->columns();
        $reqCols = $req['columns'] ?? [];
        if (! is_array($reqCols)) {
            throw new DomainException('invalid_column', 'Invalid columns.', 422);
        }
        foreach ($reqCols as $c) {
            $name = is_array($c) ? ($c['data'] ?? null) : null;
            if ($name !== null && $name !== '' && $name !== '_actions' && ! isset($cols[$name])) {
                throw new DomainException('invalid_column', 'Unknown column.', 422);
            }
        }
        $parts = [];
        foreach ((array) ($req['order'] ?? []) as $o) {
            $idx  = filter_var($o['column'] ?? null, FILTER_VALIDATE_INT);
            $name = (isset($o['name']) && $o['name'] !== '') ? $o['name'] : (($idx !== false && isset($reqCols[$idx]['data'])) ? $reqCols[$idx]['data'] : null);
            if ($name === null || ! isset($cols[$name]) || empty($cols[$name]['order'])) {
                throw new DomainException('invalid_order', 'This column cannot be sorted.', 422);
            }
            $dir     = strtolower((string) ($o['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
            $parts[] = $cols[$name]['sql'] . ' ' . $dir;
            if (count($parts) >= 3) {
                break;
            }
        }
        if ($parts === []) {
            [$n, $d] = $def->defaultOrder();
            $parts[] = $cols[$n]['sql'] . ' ' . strtoupper($d);
        }

        return ' ORDER BY ' . implode(', ', $parts);
    }

    private function select(TableDefinition $def): string
    {
        $sel = [];
        foreach ($def->columns() as $name => $col) {
            $sel[] = $col['sql'] . ' AS `' . $name . '`';
        }

        return 'SELECT ' . implode(', ', $sel) . ' FROM ' . $def->from();
    }

    /** @return array{draw: int, recordsTotal: int, recordsFiltered: int, data: list<array<string,mixed>>} */
    public function handle(string $name, array $req, Actor $actor): array
    {
        $def    = $this->definition($name, $actor);
        $draw   = (int) ($req['draw'] ?? 0);
        $start  = max(0, (int) ($req['start'] ?? 0));
        $length = (int) ($req['length'] ?? 25);
        $length = ($length <= 0 || $length > self::MAX_LENGTH) ? self::MAX_LENGTH : $length;
        $def->prepare($actor, (array) ($req['filters'] ?? []));

        [$scopeWhere, $scopeBinds, $allWhere, $allBinds] = $this->where($def, $actor, $req);
        $order = $this->orderBy($def, $req);

        $countFrom = 'SELECT COUNT(*) FROM ' . $def->from();
        if ($def->approximateTotal()) {
            $key   = 'dt_total_' . $name . '_' . md5($scopeWhere . json_encode($scopeBinds));
            $total = cache($key);
            if ($total === null) {
                $total = (int) Db::value($countFrom . $scopeWhere, $scopeBinds);
                cache()->save($key, $total, 30);
            }
        } else {
            $total = (int) Db::value($countFrom . $scopeWhere, $scopeBinds);
        }
        $filtered = $allWhere === $scopeWhere ? $total : (int) Db::value($countFrom . $allWhere, $allBinds);

        $rows = Db::rows($this->select($def) . $allWhere . $order . ' LIMIT ? OFFSET ?', [...$allBinds, $length, $start]);

        return [
            'draw'            => $draw,
            'recordsTotal'    => (int) $total,
            'recordsFiltered' => $filtered,
            'data'            => array_map([$def, 'transform'], $rows),
        ];
    }

    /** Filtered, sorted CSV export (admin only; capped; CSV-injection safe). */
    public function exportCsv(string $name, array $req, Actor $actor): string
    {
        if (! $actor->isAdmin()) {
            throw new DomainException('forbidden', 'Exports are for admins only.', 403);
        }
        $def = $this->definition($name, $actor);
        $def->prepare($actor, (array) ($req['filters'] ?? []));
        [, , $allWhere, $allBinds] = $this->where($def, $actor, $req);
        $order = $this->orderBy($def, $req);
        $cap   = config(SlamConfig::class)->exportMaxRows;
        $rows  = Db::rows($this->select($def) . $allWhere . $order . ' LIMIT ?', [...$allBinds, $cap]);

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, array_keys($def->columns()), ',', '"', '');
        foreach ($rows as $r) {
            $r = $def->transform($r);
            fputcsv($fh, array_map(static fn ($v) => \Modules\Attendees\Services\AttendeeService::csvSafe(is_scalar($v) || $v === null ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE)), $r), ',', '"', '');
        }
        rewind($fh);
        service('activityLog')->log($actor, $name === 'activity-log' ? 'activitylog.exported' : 'table.exported', ['meta' => ['table' => $name, 'rows' => count($rows), 'filters' => $req['filters'] ?? []]]);

        return (string) stream_get_contents($fh);
    }
}
