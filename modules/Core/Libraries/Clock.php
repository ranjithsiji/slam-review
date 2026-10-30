<?php

declare(strict_types=1);

namespace Modules\Core\Libraries;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Server clock. Every deadline check goes through here (never the client clock,
 * never MySQL NOW()), so tests can freeze time with Clock::set().
 * All values are UTC; display conversion to Asia/Kolkata happens in views.
 */
final class Clock
{
    public const DISPLAY_TZ = 'Asia/Kolkata';

    private static ?DateTimeImmutable $frozen = null;

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

    /** Convert an IST date + time entered by an admin into a UTC DB value. */
    public static function fromIst(string $date, string $time): string
    {
        $dt = new DateTimeImmutable($date . ' ' . $time, new DateTimeZone(self::DISPLAY_TZ));

        return self::db($dt);
    }

    /** Convert a datetime-local value (IST) into a UTC DB value. */
    public static function fromIstLocal(?string $local): ?string
    {
        if ($local === null || trim($local) === '') {
            return null;
        }

        return self::db(new DateTimeImmutable(str_replace('T', ' ', $local), new DateTimeZone(self::DISPLAY_TZ)));
    }

    /** Format a UTC DB value in IST. */
    public static function ist(?string $dbValue, string $format = 'd M Y, H:i'): string
    {
        if ($dbValue === null || $dbValue === '') {
            return '';
        }

        return self::parse($dbValue)->setTimezone(new DateTimeZone(self::DISPLAY_TZ))->format($format);
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
