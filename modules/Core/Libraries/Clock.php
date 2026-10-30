<?php

declare(strict_types=1);

namespace Modules\Core\Libraries;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Server clock. Every deadline check goes through here (never the client clock,
 * never MySQL NOW()), so tests can freeze time with Clock::set().
 * All values are UTC. Entering and displaying times uses the display timezone
 * and time format from the admin settings (default Asia/Kolkata, 24-hour).
 */
final class Clock
{
    private static ?DateTimeImmutable $frozen = null;

    /** @var array{timezone: string, time_format: string}|null */
    private static ?array $display = null;

    /** Display timezone (IANA name), e.g. Asia/Kolkata. */
    public static function tz(): string
    {
        return self::display()['timezone'];
    }

    public static function is12h(): bool
    {
        return self::display()['time_format'] === '12h';
    }

    /** Short label for the display timezone at a given moment, e.g. IST, CET or UTC+04:00. */
    public static function tzLabel(?string $dbValue = null): string
    {
        $t = ($dbValue === null ? self::now() : self::parse($dbValue))->setTimezone(new DateTimeZone(self::tz()));
        $abbr = $t->format('T');

        return preg_match('/^[A-Z]{2,5}$/', $abbr) ? $abbr : 'UTC' . $t->format('P');
    }

    /** Use these display settings (tests), or reload them from SettingsService on next use (null). */
    public static function useDisplay(?string $timezone = null, string $timeFormat = '24h'): void
    {
        self::$display = $timezone === null ? null : ['timezone' => $timezone, 'time_format' => $timeFormat];
    }

    public static function resetDisplay(): void
    {
        self::$display = null;
    }

    /** @return array{timezone: string, time_format: string} */
    private static function display(): array
    {
        return self::$display ??= service('appSettings')->all();
    }

    public static function now(): DateTimeImmutable
    {
        return self::$frozen ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public static function set(DateTimeInterface|string|null $time): void
    {
        if ($time === null) {
            self::$frozen = null;

            return;
        }
        self::$frozen = is_string($time) ? self::parse($time) : DateTimeImmutable::createFromInterface($time)->setTimezone(new DateTimeZone('UTC'));
    }

    public static function advance(int $seconds): void
    {
        self::$frozen = self::now()->modify(sprintf('%+d seconds', $seconds));
    }

    /** Parse a DB value (UTC 'Y-m-d H:i:s') or any ISO string into UTC. */
    public static function parse(string $value): DateTimeImmutable
    {
        // A string without an offset is taken as UTC; one with an offset is converted.
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
    }

    public static function parseOrNull(?string $value): ?DateTimeImmutable
    {
        return ($value === null || $value === '') ? null : self::parse($value);
    }

    /** Format for DATETIME columns (UTC). */
    public static function db(?DateTimeInterface $time = null): string
    {
        return DateTimeImmutable::createFromInterface($time ?? self::now())->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** DATETIME(3) for the activity log. */
    public static function dbMillis(): string
    {
        return self::now()->format('Y-m-d H:i:s.v');
    }

    /** ISO 8601 UTC with Z, as sent to clients. */
    public static function iso(?string $dbValue): ?string
    {
        return $dbValue === null || $dbValue === '' ? null : self::parse($dbValue)->format('Y-m-d\TH:i:s\Z');
    }

    /** Convert a date + time entered in the display timezone into a UTC DB value. */
    public static function fromLocal(string $date, string $time): string
    {
        return self::db(new DateTimeImmutable($date . ' ' . $time, new DateTimeZone(self::tz())));
    }

    /** Convert a datetime-local value (display timezone) into a UTC DB value. */
    public static function fromLocalInput(?string $local): ?string
    {
        if ($local === null || trim($local) === '') {
            return null;
        }

        return self::db(new DateTimeImmutable(str_replace('T', ' ', $local), new DateTimeZone(self::tz())));
    }

    /**
     * Format a UTC DB value in the display timezone for people to read. With the
     * 12-hour setting, 'H:i' in the format becomes 'g:i A' (1:05 PM).
     */
    public static function local(?string $dbValue, string $format = 'd M Y, H:i'): string
    {
        return self::localValue($dbValue, self::is12h() ? strtr($format, ['H:i:s' => 'g:i:s A', 'H:i' => 'g:i A']) : $format);
    }

    /** Format a UTC DB value in the display timezone exactly as given (form values, date keys). */
    public static function localValue(?string $dbValue, string $format): string
    {
        if ($dbValue === null || $dbValue === '') {
            return '';
        }

        return self::parse($dbValue)->setTimezone(new DateTimeZone(self::tz()))->format($format);
    }

    /** Today's date (Y-m-d) in the display timezone. */
    public static function localToday(): string
    {
        return self::now()->setTimezone(new DateTimeZone(self::tz()))->format('Y-m-d');
    }

    public static function addMinutes(string $dbValue, int $minutes): string
    {
        return self::db(self::parse($dbValue)->modify(sprintf('%+d minutes', $minutes)));
    }

    /** Whole seconds from a to b (b - a). */
    public static function diffSeconds(string $a, string $b): int
    {
        return self::parse($b)->getTimestamp() - self::parse($a)->getTimestamp();
    }
}
