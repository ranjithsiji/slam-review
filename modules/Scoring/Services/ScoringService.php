<?php

declare(strict_types=1);

namespace Modules\Scoring\Services;

use Modules\ActivityLog\Services\ActivityLogService;
use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;

/**
 * Marks are always computed from the current answers:
 *   answers → per-review mark (weighted average of its answers, 1–10)
 *   → group averages A_u (users), A_j (judges), A_c (complete reviewers)
 *   → final = Σ w_g·A_g / Σ w_g over groups that actually have votes.
 * Full precision is kept; rounding happens only for display.
 */
class ScoringService
{
    /** @return array{w_user: float, w_judge: float, w_complete: float} */
    public function weights(int $slamId): array
    {
        $c = Db::row('SELECT * FROM score_config WHERE slam_id = ?', [$slamId]);

        return [
            'w_user'     => (float) ($c['w_user'] ?? 40),
            'w_judge'    => (float) ($c['w_judge'] ?? 40),
            'w_complete' => (float) ($c['w_complete_reviewer'] ?? 20),
        ];
    }

    public function updateWeights(int $slamId, array $in, Actor $actor): void
    {
        $slam = service('slams')->find($slamId);
        if ($slam['status'] === 'ended') {
            throw new DomainException('read_only', 'The event has ended; weights are locked.', 409);
        }
        $vals = [];
        foreach (['w_user', 'w_judge', 'w_complete_reviewer'] as $k) {
            $v = $in[$k] ?? null;
            if (! is_numeric($v) || (float) $v < 0 || (float) $v > 100) {
                throw new DomainException('validation', 'Each weight must be between 0 and 100.', 422, [$k => 'Invalid weight.']);
            }
            $vals[$k] = round((float) $v, 2);
        }
        if (abs(array_sum($vals) - 100) > 0.001) {
            throw new DomainException('validation', 'The three weights must add up to 100.', 422, ['w_user' => 'Weights must sum to 100.']);
        }
        $old = Db::row('SELECT * FROM score_config WHERE slam_id = ?', [$slamId]) ?? [];
        Db::tx(function ($db) use ($slamId, $vals, $actor, $old): void {
            $row = $vals + ['updated_by' => $actor->id, 'updated_at' => Clock::db()];
            if ($old === []) {
                $db->table('score_config')->insert($row + ['slam_id' => $slamId]);
            } else {
                $db->table('score_config')->where('slam_id', $slamId)->update($row);
            }
            [$o, $n] = ActivityLogService::diff($old, $vals);
            service('activityLog')->log($actor, 'score_config.updated', ['slam_id' => $slamId, 'target_type' => 'slam', 'target_id' => $slamId, 'old' => $o, 'new' => $n]);
        });
    }

    /** Schedule items that count for "complete reviewer": performances that were opened (not skipped). */
    public function openedItemIds(int $slamId): array
    {
        return array_map('intval', array_column(Db::rows(
            "SELECT si.id FROM schedule_items si WHERE si.slam_id = ? AND si.item_type = 'performance' AND si.status IN ('open','closed')
             AND EXISTS (SELECT 1 FROM review_windows rw WHERE rw.schedule_item_id = si.id)",
            [$slamId],
        ), 'id'));
    }

    /** Audience users who reviewed every opened performance. */
    public function completeReviewerIds(int $slamId): array
    {
        $items = $this->openedItemIds($slamId);
        if ($items === []) {
            return [];
        }
        $in = implode(',', $items);

        return array_map('intval', array_column(Db::rows(
            "SELECT r.attendee_id FROM reviews r JOIN attendees a ON a.id = r.attendee_id
             WHERE a.slam_id = ? AND a.user_type = 'user' AND r.schedule_item_id IN ({$in})
             GROUP BY r.attendee_id HAVING COUNT(DISTINCT r.schedule_item_id) = ?",
            [$slamId, count($items)],
        ), 'attendee_id'));
    }

    /**
     * Full calculation for a slam.
     *
     * @return array{results: list<array<string,mixed>>, weights: array<string,float>, question_weights: array<int,array<string,mixed>>, counts: array<string,int>}
     */
    public function compute(int $slamId): array
    {
        $weights = $this->weights($slamId);
        $qrows   = Db::rows('SELECT id, audience, text, weight, position FROM review_questions WHERE slam_id = ? AND is_active = 1 ORDER BY audience, position', [$slamId]);
        $qw      = [];
        foreach ($qrows as $q) {
            $qw[(int) $q['id']] = ['audience' => $q['audience'], 'text' => $q['text'], 'weight' => (float) $q['weight']];
        }
        $complete = array_flip($this->completeReviewerIds($slamId));

        $answers = Db::rows(
            "SELECT r.id AS review_id, r.attendee_id, si.performance_id, a.user_type, ans.question_id, ans.score
             FROM reviews r JOIN schedule_items si ON si.id = r.schedule_item_id
             JOIN attendees a ON a.id = r.attendee_id JOIN review_answers ans ON ans.review_id = r.id
             WHERE si.slam_id = ?",
            [$slamId],
        );
        // Per review: weighted average over its answers to active questions.
        $reviews = [];
        foreach ($answers as $a) {
            $qid = (int) $a['question_id'];
            if (! isset($qw[$qid])) {
                continue;
            }
            $rid = (int) $a['review_id'];
            $reviews[$rid] ??= ['perf' => (int) $a['performance_id'], 'type' => $a['user_type'], 'attendee' => (int) $a['attendee_id'], 'num' => 0.0, 'den' => 0.0, 'scores' => []];
            $reviews[$rid]['num'] += $qw[$qid]['weight'] * (int) $a['score'];
            $reviews[$rid]['den'] += $qw[$qid]['weight'];
            $reviews[$rid]['scores'][$qid] = (int) $a['score'];
        }

        $perfs = Db::rows(
            "SELECT p.id, p.topic, pr.name AS presenter_name FROM performances p JOIN presenters pr ON pr.id = p.presenter_id
             JOIN schedule_items si ON si.performance_id = p.id
             WHERE p.slam_id = ? AND p.is_active = 1 AND si.status <> 'skipped' ORDER BY si.position",
            [$slamId],
        );
        $acc = [];
        foreach ($perfs as $p) {
            $acc[(int) $p['id']] = ['user' => [], 'judge' => [], 'complete' => [], 'q' => []];
        }
        foreach ($reviews as $r) {
            if (! isset($acc[$r['perf']]) || $r['den'] <= 0) {
                continue;
            }
            $mark = $r['num'] / $r['den'];
            $acc[$r['perf']][$r['type']][] = $mark;
            $groups = [$r['type']];
            if ($r['type'] === 'user' && isset($complete[$r['attendee']])) {
                $acc[$r['perf']]['complete'][] = $mark;
                $groups[] = 'complete';
            }
            foreach ($r['scores'] as $qid => $s) {
                foreach ($groups as $g) {
                    $acc[$r['perf']]['q'][$qid][$g][] = $s;
                }
            }
        }

        $avg     = static fn (array $xs): ?float => $xs === [] ? null : array_sum($xs) / count($xs);
        $results = [];
        foreach ($perfs as $p) {
            $a   = $acc[(int) $p['id']];
            $au  = $avg($a['user']);
            $aj  = $avg($a['judge']);
            $ac  = $avg($a['complete']);
            $results[] = [
                'performance_id' => (int) $p['id'], 'presenter_name' => $p['presenter_name'], 'topic' => $p['topic'],
                'avg_user' => $au, 'avg_judge' => $aj, 'avg_complete' => $ac,
                'final_mark' => self::finalMark($au, $aj, $ac, $weights),
                'n_user' => count($a['user']), 'n_judge' => count($a['judge']), 'n_complete' => count($a['complete']),
                'per_question' => array_map(static fn ($g) => array_map($avg, $g), $a['q']),
            ];
        }
        $results = self::rank($results);

        return [
            'results'          => $results,
            'weights'          => $weights,
            'question_weights' => $qw,
            'counts'           => [
                'reviews' => count($reviews), 'complete_reviewers' => count($complete),
                'opened_performances' => count($this->openedItemIds($slamId)),
                'users_reviewing'  => count(array_unique(array_column(array_filter($reviews, static fn ($r) => $r['type'] === 'user'), 'attendee'))),
                'judges_reviewing' => count(array_unique(array_column(array_filter($reviews, static fn ($r) => $r['type'] === 'judge'), 'attendee'))),
            ],
        ];
    }

    /**
     * Weighted final mark, re-normalised over the groups that have votes:
     * a group with no votes drops out and the remaining weights are scaled up.
     */
    public static function finalMark(?float $au, ?float $aj, ?float $ac, array $w): ?float
    {
        $num = 0.0;
        $den = 0.0;
        foreach ([[$au, $w['w_user']], [$aj, $w['w_judge']], [$ac, $w['w_complete']]] as [$a, $wt]) {
            if ($a !== null && $wt > 0) {
                $num += $wt * $a;
                $den += $wt;
            }
        }

        return $den > 0 ? $num / $den : null;
    }

    /**
     * Rank by final mark (desc). Equal final marks (at stored precision, 4 dp)
     * share a rank; within a tie order by A_j then A_u (desc). No mark → no rank.
     */
    public static function rank(array $results): array
    {
        usort($results, static function ($a, $b): int {
            if ($a['final_mark'] === null || $b['final_mark'] === null) {
                return ($a['final_mark'] === null) <=> ($b['final_mark'] === null);
            }

            return [round($b['final_mark'], 4), $b['avg_judge'] ?? -1, $b['avg_user'] ?? -1]
                <=> [round($a['final_mark'], 4), $a['avg_judge'] ?? -1, $a['avg_user'] ?? -1];
        });
        $rank = 0;
        $prev = null;
        foreach ($results as $i => &$r) {
            if ($r['final_mark'] === null) {
                $r['rank'] = null;
                continue;
            }
            $key = round($r['final_mark'], 4);
            if ($key !== $prev) {
                $rank = $i + 1;
                $prev = $key;
            }
            $r['rank'] = $rank;
        }
        unset($r);

        return $results;
    }
}
