<?php

declare(strict_types=1);

namespace Modules\ActivityLog\Services;

use CodeIgniter\Database\BaseConnection;
use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\Clock;
use Throwable;

/**
 * Single writer for the append-only activity_log table. There is deliberately no
 * update or delete method anywhere in the application.
 *
 * Call log() inside the same transaction as the action it describes (a rolled-back
 * action then leaves no success entry). Log failures/denials outside the transaction.
 * For state-changing review.*, window.*, phase.* and checkout.forced entries a write
 * failure throws so the surrounding transaction rolls back (integrity first); for
 * other actions it is swallowed and reported to the PHP log.
 */
class ActivityLogService
{
    private const STRICT_PREFIXES = ['review.', 'window.', 'phase.', 'slam.phase_changed', 'checkout.forced', 'revision.', 'scores.'];

    private const SECRET_KEYS = ['password', 'password_hash', 'csrf', 'token', 'session', 'session_token_hash'];

    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= db_connect();
    }

    /**
     * @param array{
     *   slam_id?: int|null, target_type?: string|null, target_id?: int|null,
     *   outcome?: string, reason?: string|null, old?: array<string,mixed>|null,
     *   new?: array<string,mixed>|null, meta?: array<string,mixed>|null
     * } $data
     */
    public function log(Actor $actor, string $action, array $data = []): void
    {
        $row = [
            'occurred_at' => Clock::dbMillis(),
            'slam_id'     => $data['slam_id'] ?? $actor->slamId,
            'actor_type'  => $actor->type,
            'actor_id'    => $actor->id,
            'actor_label' => $actor->label !== null ? mb_substr($actor->label, 0, 150) : null,
            'action'      => $action,
            'target_type' => $data['target_type'] ?? null,
            'target_id'   => $data['target_id'] ?? null,
            'outcome'     => $data['outcome'] ?? 'success',
            'reason'      => isset($data['reason']) ? mb_substr((string) $data['reason'], 0, 255) : null,
            'old_values'  => $this->json($data['old'] ?? null),
            'new_values'  => $this->json($data['new'] ?? null),
            'meta'        => $this->json($data['meta'] ?? null),
            'ip'          => $this->ip(),
            'user_agent'  => $this->userAgent(),
            'session_ref' => $this->sessionRef(),
        ];

        try {
            $this->db->table('activity_log')->insert($row);
        } catch (Throwable $e) {
            if ($this->isStrict($action) && ($data['outcome'] ?? 'success') === 'success') {
                throw $e;
            }
            log_message('error', 'activity_log write failed for {action}: {msg}', ['action' => $action, 'msg' => $e->getMessage()]);
        }
    }

    /** Only the fields that differ between $old and $new (both filtered of secrets). */
    public static function diff(array $old, array $new): array
    {
        $o = [];
        $n = [];
        foreach ($new as $k => $v) {
            if (! array_key_exists($k, $old) || (string) $old[$k] !== (string) $v) {
                $o[$k] = $old[$k] ?? null;
                $n[$k] = $v;
            }
        }

        return [$o, $n];
    }

    private function isStrict(string $action): bool
    {
        foreach (self::STRICT_PREFIXES as $p) {
            if (str_starts_with($action, $p)) {
                return true;
            }
        }

        return false;
    }

    private function json(?array $value): ?string
    {
        if ($value === null || $value === []) {
            return null;
        }
        foreach (self::SECRET_KEYS as $k) {
            unset($value[$k]);
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function ip(): ?string
    {
        if (is_cli()) {
            return null;
        }
        $ip = service('request')->getIPAddress();
        $packed = @inet_pton($ip);

        return $packed === false ? null : $packed;
    }

    private function userAgent(): ?string
    {
        if (is_cli()) {
            return null;
        }
        $ua = (string) service('request')->getUserAgent();

        return $ua === '' ? null : mb_substr($ua, 0, 255);
    }

    /** Short keyed hash of the session id: correlates actions without storing the id. */
    private function sessionRef(): ?string
    {
        if (is_cli() || session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        return substr(hash_hmac('sha256', session_id(), (string) config('Encryption')->key), 0, 16);
    }
}
