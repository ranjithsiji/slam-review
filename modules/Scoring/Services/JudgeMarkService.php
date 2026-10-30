<?php

declare(strict_types=1);

namespace Modules\Scoring\Services;

use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;
use Modules\Reviews\Services\ReviewService;

/**
 * End-of-slam judging: an admin enters each judge's consolidated mark (0.1–10,
 * one decimal) per performance once the reviews have closed. The marks are
 * stored as ordinary reviews answering the slam's "Overall mark" question, with
 * reviews.entered_by set to the admin, so scoring treats them like any review.
 */
class JudgeMarkService
{
    /** From All Review Close until the event ends (recalculate marks after late entries). */
    public const ENTRY_PHASES = ['reviews_complete', 'revision', 'revision_closed', 'closed', 'marks_calculated'];

    /** Why marks cannot be entered right now, or null when they can. */
    public function lockedReason(array $slam): ?string
    {
        if (service('scoring')->judging((int) $slam['id'])['timing'] !== 'end_of_slam') {
            return 'Judges mark live on their phones for this slam (Mark configuration).';
        }

        return match (true) {
            $slam['status'] === 'ended' => 'The event has ended; judges\' marks are final.',
            ! in_array($slam['status'], self::ENTRY_PHASES, true) => 'Judges\' marks can be entered once every performance has been reviewed (All Review Close).',
            default => null,
        };
    }

    /**
     * Performances that ran (not skipped), the slam's judges and the marks entered so far.
     *
     * @return array{items: list<array<string,mixed>>, judges: list<array<string,mixed>>, marks: array<int, array<int, float>>}
     */
    public function grid(int $slamId): array
    {
        $ids   = service('scoring')->openedItemIds($slamId);
        $items = $ids === [] ? [] : Db::rows(
            'SELECT si.id, si.position, pr.name AS presenter_name, p.topic FROM schedule_items si
             JOIN performances p ON p.id = si.performance_id JOIN presenters pr ON pr.id = p.presenter_id
             WHERE si.id IN (' . implode(',', $ids) . ') ORDER BY si.position',
        );
        $judges = Db::rows("SELECT id, name, badge_id FROM attendees WHERE slam_id = ? AND user_type = 'judge' AND is_active = 1 ORDER BY name", [$slamId]);
        $total  = (int) service('questions')->totalQuestion($slamId)['id'];
        $marks  = [];
        foreach (Db::rows(
            "SELECT r.attendee_id, r.schedule_item_id, ans.score FROM reviews r JOIN attendees a ON a.id = r.attendee_id
             JOIN review_answers ans ON ans.review_id = r.id WHERE a.slam_id = ? AND a.user_type = 'judge' AND ans.question_id = ?",
            [$slamId, $total],
        ) as $r) {
            $marks[(int) $r['attendee_id']][(int) $r['schedule_item_id']] = (float) $r['score'];
        }

        return ['items' => $items, 'judges' => $judges, 'marks' => $marks];
    }

    /**
     * Save one judge's marks. A blank value removes a mark entered earlier.
     *
     * @param array<int|string, mixed> $marks schedule_item_id => mark ('' to clear)
     * @return array{saved: int, removed: int}
     */
    public function save(int $slamId, int $judgeId, array $marks, Actor $actor): array
    {
        if (! $actor->isAdmin()) {
            throw new DomainException('forbidden', 'Only an admin can enter judges\' marks.', 403);
        }

        $result = Db::tx(function ($db) use ($slamId, $judgeId, $marks, $actor): array {
            $slam = Db::lockSlam($slamId);
            if (($why = $this->lockedReason($slam)) !== null) {
                throw new DomainException('forbidden_transition', $why, 409);
            }
            $judge = Db::row("SELECT * FROM attendees WHERE id = ? AND slam_id = ? AND user_type = 'judge'", [$judgeId, $slamId])
                ?? throw new DomainException('not_found', 'Judge not found in this slam.', 404);
            $items  = array_flip(service('scoring')->openedItemIds($slamId));
            $total  = (int) service('questions')->totalQuestion($slamId)['id'];
            $errors = [];
            $clean  = [];
            foreach ($marks as $itemId => $raw) {
                $itemId = (int) $itemId;
                if (! isset($items[$itemId])) {
                    throw new DomainException('validation', 'A performance in the form did not run in this slam.', 422);
                }
                $raw = trim((string) $raw);
                if ($raw === '') {
                    $clean[$itemId] = null;
                    continue;
                }
                $v = ReviewService::parseScore(str_replace(',', '.', $raw), 'judge', 10);
                if ($v === null) {
                    $errors["marks.{$itemId}"] = 'From 0.1 to 10, one decimal at most.';
                    continue;
                }
                $clean[$itemId] = (float) $v;
            }
            if ($errors !== []) {
                throw new DomainException('validation', 'Each mark must be from 0.1 to 10 with at most one decimal place.', 422, $errors);
            }

            $now     = Clock::db();
            $changes = [];
            $saved   = 0;
            $removed = 0;
            foreach ($clean as $itemId => $value) {
                $review = Db::row('SELECT * FROM reviews WHERE attendee_id = ? AND schedule_item_id = ? FOR UPDATE', [$judgeId, $itemId]);
                $old    = $review === null ? null : (float) Db::value('SELECT score FROM review_answers WHERE review_id = ? AND question_id = ?', [$review['id'], $total]);
                if ($value === null) {
                    if ($review !== null) {
                        $db->table('review_revisions')->where('review_id', $review['id'])->delete();
                        $db->table('review_answers')->where('review_id', $review['id'])->delete();
                        $db->table('reviews')->where('id', $review['id'])->delete();
                        $changes[$itemId] = ['old' => $old, 'new' => null];
                        $removed++;
                    }
                    continue;
                }
                if ($review === null) {
                    $windowId = (int) Db::value('SELECT id FROM review_windows WHERE schedule_item_id = ? ORDER BY id DESC LIMIT 1', [$itemId]);
                    $db->table('reviews')->insert([
                        'attendee_id' => $judgeId, 'schedule_item_id' => $itemId, 'review_window_id' => $windowId,
                        'submitted_at' => $now, 'entered_by' => $actor->id,
                    ]);
                    $db->table('review_answers')->insert(['review_id' => (int) $db->insertID(), 'question_id' => $total, 'score' => $value]);
                } elseif ($old === null || abs($old - $value) > 1e-9) {
                    $db->table('review_answers')->where('review_id', $review['id'])->where('question_id', $total)->update(['score' => $value]);
                    $db->table('review_revisions')->insert([
                        'review_id' => $review['id'], 'question_id' => $total, 'old_score' => $old, 'new_score' => $value, 'revision_window_id' => null, 'changed_at' => $now,
                    ]);
                    $db->table('reviews')->where('id', $review['id'])->update(['edited_at' => $now, 'edit_count' => (int) $review['edit_count'] + 1, 'entered_by' => $actor->id]);
                } else {
                    continue;
                }
                $changes[$itemId] = ['old' => $old, 'new' => $value];
                $saved++;
            }
            if ($changes !== []) {
                service('questions')->lockAudience($slamId, 'judge', $actor);
                service('activityLog')->log($actor, 'judge_marks.entered', [
                    'slam_id' => $slamId, 'target_type' => 'attendee', 'target_id' => $judgeId,
                    'meta' => ['judge' => $judge['name'], 'changes' => $changes],
                ]);
            }

            return ['saved' => $saved, 'removed' => $removed];
        });
        // Marks are calculated by the software from the weights: after Slam Close every change of the entered
        // marks recalculates, so Marks, Final scores and the Leaderboard never lag behind.
        if ($result['saved'] + $result['removed'] > 0 && in_array(service('slams')->find($slamId)['status'], ['closed', 'marks_calculated'], true)) {
            try {
                service('markRuns')->calculate($slamId, $actor, "Automatic: judges' marks entered");
            } catch (\Throwable) {
                // the marks are saved; the Marks screen offers a manual recalculation
            }
        }

        return $result;
    }

    /** @return array{entered: int, expected: int} */
    public function progress(int $slamId): array
    {
        $g = $this->grid($slamId);

        return ['entered' => array_sum(array_map('count', $g['marks'])), 'expected' => count($g['items']) * count($g['judges'])];
    }
}
