<?php

declare(strict_types=1);

namespace Modules\Core\Libraries;

use CodeIgniter\Database\BaseConnection;
use Throwable;

final class Db
{
    /**
     * Run $fn in a transaction; commit on return, roll back and rethrow on any
     * exception. Nested calls join the outer transaction (CI4 depth counting), so
     * an exception anywhere rolls back the whole unit of work.
     *
     * @template T
     * @param callable(BaseConnection): T $fn
     * @return T
     */
    public static function tx(callable $fn): mixed
    {
        $db = db_connect();
        $db->transException(true);
        $db->transBegin();
        try {
            $result = $fn($db);
            $db->transCommit();

            return $result;
        } catch (Throwable $e) {
            $db->transRollback();
            throw $e;
        }
    }

    /**
     * Lock the slam row (SELECT ... FOR UPDATE). Must run inside tx(). The phase
     * machine, schedule writes and window operations all take this same lock, so a
     * timer tick and an admin click can never both apply.
     *
     * @return array<string, mixed>
     */
    public static function lockSlam(int $slamId): array
    {
        $row = db_connect()->query('SELECT * FROM slams WHERE id = ? FOR UPDATE', [$slamId])->getRowArray();
        if ($row === null) {
            throw new DomainException('not_found', 'Slam not found.', 404);
        }

        return $row;
    }

    /** @return array<string, mixed>|null */
    public static function row(string $sql, array $binds = []): ?array
    {
        return db_connect()->query($sql, $binds)->getRowArray();
    }

    /** @return list<array<string, mixed>> */
    public static function rows(string $sql, array $binds = []): array
    {
        return db_connect()->query($sql, $binds)->getResultArray();
    }

    public static function value(string $sql, array $binds = []): mixed
    {
        $row = db_connect()->query($sql, $binds)->getRowArray();

        return $row === null ? null : reset($row);
    }
}
