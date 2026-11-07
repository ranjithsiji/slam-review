<?php

declare(strict_types=1);

namespace Modules\Scoring\Services;

use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;

/**
 * The presenters a regional slam sends to the final: chosen by an admin (the marks are a guide, the choice is
 * theirs), shown and printed as names in alphabetical order, never with positions. Stored in app_settings as
 * "finalists:{slam id}" = performance ids, so no table is needed.
 */
class FinalistsService
{
    public const COUNT = 5;

    private static function key(int $slamId): string
    {
        return 'finalists:' . $slamId;
    }

    /** @return list<int> performance ids */
    public function ids(int $slamId): array
    {
        $v = (string) Db::value('SELECT value FROM app_settings WHERE `key` = ?', [self::key($slamId)]);

        return $v === '' ? [] : array_map('intval', explode(',', $v));
    }

    /**
     * Every active presenter of the slam with the current marks (if calculated) and whether they are chosen.
     *
     * @return list<array<string, mixed>>
     */
    public function candidates(int $slamId): array
    {
        $run    = service('markRuns')->currentRun($slamId);
        $marks  = $run === null ? [] : array_column(Db::rows('SELECT performance_id, final_mark, `rank` FROM mark_results WHERE mark_run_id = ?', [$run['id']]), null, 'performance_id');
        $chosen = array_flip($this->ids($slamId));
        $rows   = Db::rows(
            'SELECT p.id, pr.name, pr.designation_or_institution AS designation, p.topic FROM performances p JOIN presenters pr ON pr.id = p.presenter_id
              WHERE p.slam_id = ? AND p.is_active = 1 ORDER BY pr.name',
            [$slamId],
        );
        foreach ($rows as &$r) {
            $m = $marks[(int) $r['id']] ?? null;
            $r['id']         = (int) $r['id'];
            $r['final_mark'] = $m !== null && $m['final_mark'] !== null ? (float) $m['final_mark'] : null;
            $r['rank']       = $m !== null && $m['rank'] !== null ? (int) $m['rank'] : null;
            $r['chosen']     = isset($chosen[$r['id']]);
        }

        return $rows;
    }

    /** The chosen presenters, alphabetical. @return list<array<string, mixed>> */
    public function chosen(int $slamId): array
    {
        return array_values(array_filter($this->candidates($slamId), static fn ($r) => $r['chosen']));
    }

    /** @param list<int|string> $ids */
    public function save(int $slamId, array $ids, Actor $actor, ?string $note): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        // Normally 5. A different number (6 after a tie, say) is an exception and needs a reason.
        if ($ids === [] || count($ids) > 12) {
            throw new DomainException('validation', 'Choose the presenters going to the final (' . self::COUNT . ' normally).', 422);
        }
        if (count($ids) !== self::COUNT && ($note === null || trim($note) === '')) {
            throw new DomainException('validation', count($ids) . ' chosen instead of ' . self::COUNT . '. Write the reason in the note (for example: tie at 5th place) and save again.', 422);
        }
        $valid = array_map('intval', array_column(Db::rows('SELECT id FROM performances WHERE slam_id = ? AND is_active = 1', [$slamId]), 'id'));
        if (array_diff($ids, $valid) !== []) {
            throw new DomainException('validation', 'Only presenters of this slam can be chosen.', 422);
        }
        sort($ids);
        $old = $this->ids($slamId);
        Db::tx(function ($db) use ($slamId, $ids, $actor) {
            $db->query('INSERT INTO app_settings (`key`, value, updated_by, updated_at) VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE value = VALUES(value), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
                [self::key($slamId), implode(',', $ids), $actor->isAdmin() ? $actor->id : null, Clock::db()]);
        });
        service('activityLog')->log($actor, 'results.finalists_saved', [
            'slam_id' => $slamId, 'target_type' => 'slam', 'target_id' => $slamId,
            'old' => ['finalists' => $old], 'new' => ['finalists' => $ids],
            'reason' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null,
        ]);
    }
}
