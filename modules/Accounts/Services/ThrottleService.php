<?php

declare(strict_types=1);

namespace Modules\Accounts\Services;

use Modules\Core\Config\Slam as SlamConfig;
use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\Db;

/**
 * Login attempt limiting (anti-fraud), backed by login_attempts.
 * Limits apply per IP and per submitted ID/username; an IP is also capped on how
 * many different IDs it may try. Messages stay generic.
 */
class ThrottleService
{
    private SlamConfig $cfg;

    public function __construct(?SlamConfig $cfg = null)
    {
        $this->cfg = $cfg ?? config(SlamConfig::class);
    }

    /** "Am I registered?" attempts per IP, [how many, in how many minutes], on the day of a slam and on other days. */
    public const LOOKUP_EVENT_DAY = [10, 10];
    public const LOOKUP_OTHER_DAY = [3, 60];

    /** Seconds until this IP may use "Am I registered?" again, or 0. Every attempt counts, right or wrong. */
    public function lookupLockedFor(string $ip): int
    {
        [$max, $minutes] = service('slams')->isEventDay() ? self::LOOKUP_EVENT_DAY : self::LOOKUP_OTHER_DAY;
        $rows = Db::rows(
            "SELECT created_at FROM login_attempts WHERE ip = ? AND kind = 'lookup' AND created_at > ? ORDER BY created_at DESC LIMIT ?",
            [$ip, Clock::db(Clock::now()->modify("-{$minutes} minutes")), $max],
        );
        if (count($rows) < $max) {
            return 0;
        }

        return max(1, Clock::parse($rows[$max - 1]['created_at'])->getTimestamp() + $minutes * 60 - Clock::now()->getTimestamp());
    }

    public function recordLookup(string $ip): void
    {
        $this->record('lookup', $ip, '', false);
    }

    /** What a locked-out person is told: the same wording everywhere, with the minutes left. */
    public static function lockedMessage(int $seconds): string
    {
        $m = max(1, (int) ceil($seconds / 60));

        return 'Too many login attempts. Please try again after ' . $m . ($m === 1 ? ' minute.' : ' minutes.');
    }

    /** Value stored for a submitted ID: the ID itself if it matches the format, else a short hash. */
    public static function keyFor(string $kind, string $submitted): string
    {
        $s = strtoupper(trim($submitted));
        if ($kind !== 'reviewer') {
            return mb_substr(strtolower(trim($submitted)), 0, 60);
        }

        return \Modules\Attendees\Services\BadgeIdService::isValidFormat($s) ? $s : 'h:' . substr(hash('sha256', $s), 0, 16);
    }

    /**
     * Seconds until the caller may try again, or 0 if not locked out.
     */
    public function lockedFor(string $kind, string $ip, string $submitted): int
    {
        $key = self::keyFor($kind, $submitted);
        if ($kind === 'reviewer') {
            return max(
                $this->window('badge_id_or_username', $key, $kind, $this->cfg->idMaxFailures, $this->cfg->idWindowMinutes, $this->cfg->idLockoutMinutes),
                $this->window('ip', $ip, $kind, $this->cfg->ipMaxFailures, $this->cfg->ipWindowMinutes, $this->cfg->ipLockoutMinutes),
                $this->distinctIds($ip, $key),
            );
        }

        return max(
            $this->window('badge_id_or_username', $key, $kind, $this->cfg->staffMaxFailures, $this->cfg->staffWindowMinutes, $this->cfg->staffLockoutMinutes),
            $this->window('ip', $ip, $kind, $this->cfg->ipMaxFailures, $this->cfg->ipWindowMinutes, $this->cfg->ipLockoutMinutes),
        );
    }

    /**
     * Locked if the last $max failures all fall within $windowMin minutes; the
     * lockout lasts $lockMin minutes from the most recent failure.
     */
    private function window(string $col, string $value, string $kind, int $max, int $windowMin, int $lockMin): int
    {
        $since = Clock::db(Clock::now()->modify('-' . ($windowMin + $lockMin) . ' minutes'));
        $rows  = Db::rows(
            "SELECT created_at FROM login_attempts WHERE {$col} = ? AND kind = ? AND success = 0 AND created_at >= ? ORDER BY created_at DESC LIMIT ?",
            [$value, $kind, $since, $max],
        );
        if (count($rows) < $max) {
            return 0;
        }
        $newest = Clock::parse($rows[0]['created_at'])->getTimestamp();
        $oldest = Clock::parse($rows[$max - 1]['created_at'])->getTimestamp();
        if ($newest - $oldest > $windowMin * 60) {
            return 0;
        }

        return max(0, $newest + $lockMin * 60 - Clock::now()->getTimestamp());
    }

    /** An IP that tried too many different IDs is locked until the window passes. */
    private function distinctIds(string $ip, string $key): int
    {
        $since = Clock::db(Clock::now()->modify('-' . $this->cfg->ipDistinctWindowMinutes . ' minutes'));
        $rows  = Db::rows(
            "SELECT badge_id_or_username, MIN(created_at) AS first_at FROM login_attempts
             WHERE ip = ? AND kind = 'reviewer' AND success = 0 AND created_at >= ? GROUP BY badge_id_or_username ORDER BY first_at",
            [$ip, $since],
        );
        $ids = array_column($rows, 'badge_id_or_username');
        if (count($ids) < $this->cfg->ipMaxDistinctIds || in_array($key, $ids, true)) {
            return count($ids) > $this->cfg->ipMaxDistinctIds ? $this->cfg->ipDistinctWindowMinutes * 60 : 0;
        }
        $first = Clock::parse($rows[0]['first_at'])->getTimestamp();

        return max(1, $first + $this->cfg->ipDistinctWindowMinutes * 60 - Clock::now()->getTimestamp());
    }

    /**
     * Networks (IP addresses) with failed badge check-ins, or "Am I registered?" checks, in the last hour, and
     * whether each is locked out now. For the admin Lockouts screen.
     *
     * @return list<array{ip: string, failures: int, distinct_ids: int, lookups: int, last_at: string, locked_seconds: int}>
     */
    public function networks(): array
    {
        $since = Clock::db(Clock::now()->modify('-' . max($this->cfg->ipDistinctWindowMinutes, $this->cfg->ipWindowMinutes + $this->cfg->ipLockoutMinutes, self::LOOKUP_OTHER_DAY[1]) . ' minutes'));
        $rows  = Db::rows(
            "SELECT ip, SUM(kind = 'reviewer' AND success = 0) AS failures, COUNT(DISTINCT IF(kind = 'reviewer' AND success = 0, badge_id_or_username, NULL)) AS distinct_ids,
                    SUM(kind = 'lookup') AS lookups, MAX(created_at) AS last_at
             FROM login_attempts WHERE created_at >= ? AND ((kind = 'reviewer' AND success = 0) OR kind = 'lookup') GROUP BY ip ORDER BY last_at DESC",
            [$since],
        );
        foreach ($rows as &$r) {
            $r['failures'] = (int) $r['failures']; $r['distinct_ids'] = (int) $r['distinct_ids']; $r['lookups'] = (int) $r['lookups'];
            // A key no-one uses: only the network-wide limits count, not one badge's.
            $r['locked_seconds'] = max(
                $this->window('ip', $r['ip'], 'reviewer', $this->cfg->ipMaxFailures, $this->cfg->ipWindowMinutes, $this->cfg->ipLockoutMinutes),
                $this->distinctIds($r['ip'], 'h:none'),
                $this->lookupLockedFor($r['ip']),
            );
        }

        return $rows;
    }

    /** Unlock a network: its failed check-ins and "Am I registered?" attempts are cleared. Logged. @return int rows cleared */
    public function unlockNetwork(string $ip, Actor $actor, ?string $reason = null): int
    {
        $db = db_connect();
        $db->table('login_attempts')->where('ip', $ip)->groupStart()
            ->groupStart()->where('kind', 'reviewer')->where('success', 0)->groupEnd()->orWhere('kind', 'lookup')
            ->groupEnd()->delete();
        $n = $db->affectedRows();
        service('activityLog')->log($actor, 'auth.lockout_cleared', [
            'target_type' => 'network', 'reason' => $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 255) : null,
            'meta' => ['ip' => $ip, 'attempts_cleared' => $n],
        ]);

        return $n;
    }

    public function record(string $kind, string $ip, string $submitted, bool $success): void
    {
        db_connect()->table('login_attempts')->insert([
            'ip' => mb_substr($ip, 0, 45), 'kind' => $kind, 'badge_id_or_username' => self::keyFor($kind, $submitted),
            'success' => $success ? 1 : 0, 'created_at' => Clock::db(),
        ]);
    }

    /** Record a failure and log auth.lockout if this failure triggered one. */
    public function fail(string $kind, string $ip, string $submitted, Actor $actor, string $reason, ?int $slamId = null): void
    {
        $this->record($kind, $ip, $submitted, false);
        $key = self::keyFor($kind, $submitted);
        service('activityLog')->log($actor, 'auth.login.failure', [
            'slam_id' => $slamId, 'outcome' => 'failure', 'reason' => $reason, 'meta' => ['kind' => $kind, 'submitted' => $key],
        ]);
        if ($this->lockedFor($kind, $ip, $submitted) > 0) {
            service('activityLog')->log($actor, 'auth.lockout', ['slam_id' => $slamId, 'outcome' => 'denied', 'reason' => 'locked_out', 'meta' => ['kind' => $kind, 'submitted' => $key]]);
        }
    }
}
