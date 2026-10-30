<?php

declare(strict_types=1);

namespace Modules\Attendees\Services;

use Modules\Core\Libraries\Db;
use RuntimeException;

/**
 * Badge IDs: 2 letters + 5 digits, e.g. KR48213. Letters exclude look-alikes
 * (I, O, Q, S, Z); generated with random_int (CSPRNG); retried on collision.
 * Space ≈ 21² × 100,000 ≈ 44M, so login throttling is mandatory.
 */
class BadgeIdService
{
    public const LETTERS = 'ABCDEFGHJKLMNPRTUVWXY';

    public static function normalise(string $input): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $input));
    }

    public static function isValidFormat(string $id): bool
    {
        return (bool) preg_match('/^[' . self::LETTERS . ']{2}\d{5}$/', $id);
    }

    /**
     * Extract an ID from scanner input: either the plain ID or a check-in URL
     * ending in /in/{ID}.
     */
    public static function fromScan(string $raw): ?string
    {
        $raw = trim($raw);
        if (preg_match('#/in/([A-Za-z]{2}\d{5})/?(?:[?\#].*)?$#', $raw, $m)) {
            $raw = $m[1];
        }
        $id = self::normalise($raw);

        return self::isValidFormat($id) ? $id : null;
    }

    public function random(): string
    {
        $l = self::LETTERS;
        do {
            $digits = str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        } while (preg_match('/^(\d)\1{4}$/', $digits) || $digits === '12345'); // avoid 00000, 11111, ...

        return $l[random_int(0, strlen($l) - 1)] . $l[random_int(0, strlen($l) - 1)] . $digits;
    }

    /**
     * A fresh ID not used by any attendee nor in $reserved (IDs assigned earlier in
     * the same batch).
     *
     * @param array<string, true> $reserved
     */
    public function generate(array $reserved = []): string
    {
        for ($i = 0; $i < 50; $i++) {
            $id = $this->random();
            if (! isset($reserved[$id]) && ! $this->exists($id)) {
                return $id;
            }
        }
        throw new RuntimeException('Could not generate a unique badge ID.');
    }

    public function exists(string $id): bool
    {
        return Db::value('SELECT id FROM attendees WHERE badge_id = ?', [$id]) !== null;
    }
}
