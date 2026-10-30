<?php

declare(strict_types=1);

namespace Modules\Core\Services;

use DateTimeZone;
use Modules\ActivityLog\Services\ActivityLogService;
use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;
use Throwable;

/**
 * Admin-editable application settings (app_settings). Defaults come from
 * Config\Slam (overridable in .env as slam.timezone / slam.timeFormat).
 *
 * timezone:    IANA zone used to enter and display every time. The database
 *              always stores UTC, so changing it never moves an event; it only
 *              changes the clock times people see and type.
 * time_format: '24h' (13:05) or '12h' (1:05 PM).
 */
class SettingsService
{
    public const TIME_FORMATS = ['24h', '12h'];
    private const CACHE_KEY   = 'app_settings';

    /** @var array<string, string>|null */
    private ?array $values = null;

    /** @return array{timezone: string, time_format: string} */
    public function all(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }
        $cfg    = config('Slam');
        $values = ['timezone' => $cfg->timezone, 'time_format' => $cfg->timeFormat];
        try {
            $stored = cache(self::CACHE_KEY);
            if (! is_array($stored)) {
                $stored = array_column(Db::rows('SELECT `key`, value FROM app_settings'), 'value', 'key');
                cache()->save(self::CACHE_KEY, $stored, 300);
            }
            $values = array_intersect_key($stored, $values) + $values;
        } catch (Throwable) {
            // Before migrations (or without a database) the configured defaults apply.
        }

        return $this->values = $values;
    }

    public function get(string $key): string
    {
        return $this->all()[$key] ?? throw new DomainException('not_found', 'Unknown setting.', 404);
    }

    /** @param array<string, mixed> $in */
    public function update(array $in, Actor $actor): void
    {
        if (! $actor->isAdmin()) {
            throw new DomainException('forbidden', 'Only an admin can change settings.', 403);
        }
        $new = [
            'timezone'    => trim((string) ($in['timezone'] ?? '')),
            'time_format' => (string) ($in['time_format'] ?? ''),
        ];
        $e = [];
        if (! in_array($new['timezone'], DateTimeZone::listIdentifiers(), true)) {
            $e['timezone'] = 'Choose a timezone from the list.';
        }
        if (! in_array($new['time_format'], self::TIME_FORMATS, true)) {
            $e['time_format'] = 'Choose 24-hour or 12-hour.';
        }
        if ($e !== []) {
            throw new DomainException('validation', 'Please correct the highlighted fields.', 422, $e);
        }
        $old = $this->all();
        [$o, $n] = ActivityLogService::diff($old, $new);
        if ($n === []) {
            return;
        }
        Db::tx(function ($db) use ($n, $o, $actor): void {
            foreach ($n as $k => $v) {
                $db->query('INSERT INTO app_settings (`key`, value, updated_by, updated_at) VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE value = VALUES(value), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)', [$k, $v, $actor->id, Clock::db()]);
            }
            service('activityLog')->log($actor, 'settings.updated', ['target_type' => 'settings', 'old' => $o, 'new' => $n]);
        });
        $this->forget();
        // Cached public pages and documents may hold formatted times.
        cache()->clean();
    }

    /** Drop cached values (after an update, or between tests). */
    public function forget(): void
    {
        $this->values = null;
        try {
            cache()->delete(self::CACHE_KEY);
        } catch (Throwable) {
        }
        Clock::resetDisplay();
    }
}
