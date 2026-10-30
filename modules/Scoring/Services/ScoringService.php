<?php

declare(strict_types=1);

namespace Modules\Scoring\Services;

use Modules\ActivityLog\Services\ActivityLogService;
use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;

/**
 * Marks are always computed from the current answers, in three groups:
 *   general reviewers (audience users who are not representatives),
 *   audience representatives (see RepresentativeService) and judges.
 *
 *   answers → per-review mark out of 10 = 10 · Σ(w_q · score_q) / Σ(w_q · max_q)
 *   → group averages A_general, A_rep, A_judge
 *   → final = Σ w_g·A_g / Σ w_g over the groups that actually have votes.
 * Full precision is kept; rounding happens only for display.
 */
class ScoringService
{
    /** Group key => [weight key, label]. Order is the display order. */
    public const GROUPS = [
        'general'        => ['w_general', 'Judges'],
        'representative' => ['w_representative', 'Audience representatives'],
        'judge'          => ['w_judge', 'Experts'],
    ];

    /** @return array{w_general: float, w_representative: float, w_judge: float} */
    public function weights(int $slamId): array
    {
        $c = Db::row('SELECT * FROM score_config WHERE slam_id = ?', [$slamId]);

        return [
            'w_general'        => (float) ($c['w_general'] ?? 40),
            'w_representative' => (float) ($c['w_representative'] ?? 20),
            'w_judge'          => (float) ($c['w_judge'] ?? 40),
        ];
    }

    public function updateWeights(int $slamId, array $in, Actor $actor): void
    {
        $slam = service('slams')->find($slamId);
        if ($slam['status'] === 'ended') {
            throw new DomainException('read_only', 'The event has ended; weights are locked.', 409);
        }
        $vals = [];
        foreach (['w_general', 'w_representative', 'w_judge'] as $k) {
            $v = $in[$k] ?? null;
            if (! is_numeric($v) || (float) $v < 0 || (float) $v > 100) {
                throw new DomainException('validation', 'Each weight must be between 0 and 100.', 422, [$k => 'Invalid weight.']);
            }
            $vals[$k] = round((float) $v, 2);
        }
        if (abs(array_sum($vals) - 100) > 0.001) {
            throw new DomainException('validation', 'The three weights must add up to 100.', 422, ['w_general' => 'Weights must sum to 100.']);
        }
        $old = Db::row('SELECT * FROM score_config WHERE slam_id = ?', [$slamId]) ?? [];
        Db::tx(function ($db) use ($slamId, $vals, $actor, $old): void {
            $this->saveConfig($db, $slamId, $vals, $actor, $old !== []);
            [$o, $n] = ActivityLogService::diff($old, $vals);
            service('activityLog')->log($actor, 'score_config.updated', ['slam_id' => $slamId, 'target_type' => 'slam', 'target_id' => $slamId, 'old' => $o, 'new' => $n]);
        });
    }

    /** Insert or update the slam's score_config row (defaults fill a new row). */
    public function saveConfig($db, int $slamId, array $vals, Actor $actor, ?bool $exists = null): void
    {
        $exists ??= Db::value('SELECT id FROM score_config WHERE slam_id = ?', [$slamId]) !== null;
        $row = $vals + ['updated_by' => $actor->isAdmin() ? $actor->id : null, 'updated_at' => Clock::db()];
        if ($exists) {
            $db->table('score_config')->where('slam_id', $slamId)->update($row);
        } else {
            $db->table('score_config')->insert($row + $this->weights($slamId) + ['slam_id' => $slamId]);
        }
    }

    public const JUDGE_TIMINGS = ['end_of_slam', 'live'];
    public const JUDGE_MODES   = ['detailed', 'consolidated'];
    /** Judging can be changed only before any performance has been reviewed. */
    public const JUDGING_EDITABLE_PHASES = ['draft', 'scheduled', 'open', 'checkin_open', 'checkin_closed'];

    /**
     * How judges' marks reach the system for a slam.
     *   timing end_of_slam: an admin enters each judge's consolidated mark after the reviews close.
     *   timing live:        judges score on their phones; mode detailed (criteria) or consolidated (one mark).
     *
     * @return array{timing: string, mode: string, consolidated: bool}
     */
    public function judging(int $slamId): array
    {
        $c      = Db::row('SELECT judge_timing, judge_mode FROM score_config WHERE slam_id = ?', [$slamId]);
        $timing = $c['judge_timing'] ?? 'end_of_slam';
        $mode   = $c['judge_mode'] ?? 'detailed';

        return ['timing' => $timing, 'mode' => $mode, 'consolidated' => $timing === 'end_of_slam' || $mode === 'consolidated'];
    }

    /** Why judging cannot be changed for this slam, or null when it can. */
    public function judgingLockedReason(array $slam): ?string
    {
        if (! in_array($slam['status'], self::JUDGING_EDITABLE_PHASES, true)) {
            return 'Experts\' marking is fixed once reviewing starts.';
        }
        if (Db::value("SELECT r.id FROM reviews r JOIN attendees a ON a.id = r.attendee_id WHERE a.slam_id = ? AND a.user_type = 'judge' LIMIT 1", [$slam['id']]) !== null) {
            return 'Experts have already submitted marks.';
        }

        return null;
    }

    /**
     * Set judges' marking for one or more slams (bulk). Slams that can no longer
     * change are skipped and reported.
     *
     * @param list<int> $slamIds
     * @return array{updated: list<int>, skipped: array<int, string>}
     */
    public function updateJudging(array $slamIds, array $in, Actor $actor): array
    {
        if (! $actor->isAdmin()) {
            throw new DomainException('forbidden', 'Only an admin can change experts\' marking.', 403);
        }
        $timing = (string) ($in['judge_timing'] ?? '');
        $mode   = (string) ($in['judge_mode'] ?? 'detailed');
        if (! in_array($timing, self::JUDGE_TIMINGS, true) || ! in_array($mode, self::JUDGE_MODES, true)) {
            throw new DomainException('validation', 'Choose when experts mark and how.', 422, ['judge_timing' => 'Choose an option.']);
        }
        $out = ['updated' => [], 'skipped' => []];
        foreach (array_unique(array_map('intval', $slamIds)) as $slamId) {
            Db::tx(function ($db) use ($slamId, $timing, $mode, $actor, &$out): void {
                $slam = Db::lockSlam($slamId);
                if (($why = $this->judgingLockedReason($slam)) !== null) {
                    $out['skipped'][$slamId] = $why;

                    return;
                }
                $old = $this->judging($slamId);
                $this->saveConfig($db, $slamId, ['judge_timing' => $timing, 'judge_mode' => $mode], $actor);
                if ($old['timing'] !== $timing || $old['mode'] !== $mode) {
                    service('activityLog')->log($actor, 'score_config.judging_changed', [
                        'slam_id' => $slamId, 'target_type' => 'slam', 'target_id' => $slamId,
                        'old' => ['judge_timing' => $old['timing'], 'judge_mode' => $old['mode']], 'new' => ['judge_timing' => $timing, 'judge_mode' => $mode],
                    ]);
                }
                $out['updated'][] = $slamId;
            });
            service('publicCache')->purge($slamId);
        }

        return $out;
    }

    /** Schedule items that count for "complete judge": performances that were opened (not skipped). */
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
     * Per-review mark out of 10: 10 · Σ(w_q · score_q) / Σ(w_q · max_q).
     * With weight 1 everywhere this is the marks given scaled to 10: two audience
     * questions of 5 give score_1 + score_2; judge criteria of 3+3+3+1 add up.
     *
     * @param array<int, float|int> $scores question_id => score
     * @param array<int, array{weight: float, max_score: int}> $questions
     */
    public static function reviewMark(array $scores, array $questions): ?float
    {
        $num = 0.0;
        $den = 0.0;
        foreach ($scores as $qid => $score) {
            if (! isset($questions[$qid])) {
                continue;
            }
            $num += $questions[$qid]['weight'] * $score;
            $den += $questions[$qid]['weight'] * $questions[$qid]['max_score'];
        }

        return $den > 0 ? 10 * $num / $den : null;
    }

    /**
     * Full calculation for a slam.
     *
     * @return array{results: list<array<string,mixed>>, weights: array<string,float>, question_weights: array<int,array<string,mixed>>, representatives: list<int>, distribution: array<int, array<string,int>>, counts: array<string,int>}
     */
    public function compute(int $slamId): array
    {
        $weights = $this->weights($slamId);
        $qrows   = Db::rows('SELECT id, audience, kind, text, weight, max_score, position FROM review_questions WHERE slam_id = ? AND is_active = 1 ORDER BY audience, kind, position', [$slamId]);
        $qw      = [];
        foreach ($qrows as $q) {
            $qw[(int) $q['id']] = ['audience' => $q['audience'], 'kind' => $q['kind'], 'text' => $q['text'], 'weight' => (float) $q['weight'], 'max_score' => (int) $q['max_score']];
        }
        $repIds = service('representatives')->ids($slamId);
        $reps   = array_flip($repIds);

        $answers = Db::rows(
            "SELECT r.id AS review_id, r.attendee_id, si.performance_id, a.user_type, ans.question_id, ans.score
             FROM reviews r JOIN schedule_items si ON si.id = r.schedule_item_id
             JOIN attendees a ON a.id = r.attendee_id JOIN review_answers ans ON ans.review_id = r.id
             WHERE si.slam_id = ?",
            [$slamId],
        );
        $reviews = [];
        foreach ($answers as $a) {
            $rid   = (int) $a['review_id'];
            $group = $a['user_type'] === 'judge' ? 'judge' : (isset($reps[(int) $a['attendee_id']]) ? 'representative' : 'general');
            $reviews[$rid] ??= ['perf' => (int) $a['performance_id'], 'group' => $group, 'attendee' => (int) $a['attendee_id'], 'scores' => []];
            $reviews[$rid]['scores'][(int) $a['question_id']] = (float) $a['score'];
        }

        $perfs = Db::rows(
            "SELECT p.id, p.topic, pr.name AS presenter_name FROM performances p JOIN presenters pr ON pr.id = p.presenter_id
             JOIN schedule_items si ON si.performance_id = p.id
             WHERE p.slam_id = ? AND p.is_active = 1 AND si.status <> 'skipped' ORDER BY si.position",
            [$slamId],
        );
        $acc = [];
        foreach ($perfs as $p) {
            $acc[(int) $p['id']] = ['general' => [], 'representative' => [], 'judge' => [], 'q' => []];
        }
        // Review marks out of 10, rounded to whole marks, per group (statistics chart).
        $dist = array_fill(1, 10, ['general' => 0, 'representative' => 0, 'judge' => 0]);
        foreach ($reviews as $r) {
            $mark = self::reviewMark($r['scores'], $qw);
            if (! isset($acc[$r['perf']]) || $mark === null) {
                continue;
            }
            $acc[$r['perf']][$r['group']][] = $mark;
            $dist[max(1, min(10, (int) round($mark)))][$r['group']]++;
            foreach ($r['scores'] as $qid => $s) {
                if (isset($qw[$qid])) {
                    $acc[$r['perf']]['q'][$qid][$r['group']][] = $s;
                }
            }
        }

        $avg     = static fn (array $xs): ?float => $xs === [] ? null : array_sum($xs) / count($xs);
        $results = [];
        foreach ($perfs as $p) {
            $a    = $acc[(int) $p['id']];
            $avgs = ['general' => $avg($a['general']), 'representative' => $avg($a['representative']), 'judge' => $avg($a['judge'])];
            $results[] = [
                'performance_id' => (int) $p['id'], 'presenter_name' => $p['presenter_name'], 'topic' => $p['topic'],
                'avg_general' => $avgs['general'], 'avg_representative' => $avgs['representative'], 'avg_judge' => $avgs['judge'],
                'final_mark' => self::breakdown($avgs, $weights)['final'],
                'n_general' => count($a['general']), 'n_representative' => count($a['representative']), 'n_judge' => count($a['judge']),
                'per_question' => array_map(static fn ($g) => array_map($avg, $g), $a['q']),
            ];
        }
        $results = self::rank($results);
        $byGroup = static fn (string $g) => count(array_unique(array_column(array_filter($reviews, static fn ($r) => $r['group'] === $g), 'attendee')));

        return [
            'results'          => $results,
            'weights'          => $weights,
            'question_weights' => $qw,
            'representatives'  => $repIds,
            'distribution'     => $dist,
            'counts'           => [
                'reviews' => count($reviews), 'complete_reviewers' => count($this->completeReviewerIds($slamId)),
                'representatives' => count($repIds), 'opened_performances' => count($this->openedItemIds($slamId)),
                'general_reviewing' => $byGroup('general'), 'representatives_reviewing' => $byGroup('representative'), 'judges_reviewing' => $byGroup('judge'),
            ],
        ];
    }

    /**
     * How the final mark is built, group by group. A group with no votes (or a
     * zero weight) drops out and the remaining weights are re-normalised, so
     * each group's effective share is w_g / Σ w over the groups that count.
     *
     * @param array{general: ?float, representative: ?float, judge: ?float} $avgs
     * @return array{groups: array<string, array{label: string, avg: ?float, weight: float, share: ?float, contribution: ?float}>, final: ?float, weight_used: float}
     */
    public static function breakdown(array $avgs, array $weights): array
    {
        $used = 0.0;
        foreach (self::GROUPS as $g => [$wk]) {
            if (($avgs[$g] ?? null) !== null && (float) $weights[$wk] > 0) {
                $used += (float) $weights[$wk];
            }
        }
        $groups = [];
        $final  = null;
        foreach (self::GROUPS as $g => [$wk, $label]) {
            $a      = $avgs[$g] ?? null;
            $w      = (float) $weights[$wk];
            $counts = $a !== null && $w > 0 && $used > 0;
            $share  = $counts ? $w / $used : null;
            $groups[$g] = ['label' => $label, 'avg' => $a, 'weight' => $w, 'share' => $share, 'contribution' => $counts ? $a * $share : null];
            if ($counts) {
                $final = ($final ?? 0.0) + $a * $share;
            }
        }

        return ['groups' => $groups, 'final' => $final, 'weight_used' => $used];
    }

    /** Breakdown for a stored or computed result row. */
    public static function breakdownFor(array $row, array $weights): array
    {
        $f = static fn ($v) => $v === null ? null : (float) $v;

        return self::breakdown(['general' => $f($row['avg_general']), 'representative' => $f($row['avg_representative']), 'judge' => $f($row['avg_judge'])], $weights);
    }

    /**
     * Rank by final mark (desc). Equal final marks (at stored precision, 4 dp)
     * share a rank; within a tie order by judges, then representatives, then
     * general reviewers (desc). No mark → no rank.
     */
    public static function rank(array $results): array
    {
        $key = static fn ($r) => [round($r['final_mark'], 4), $r['avg_judge'] ?? -1, $r['avg_representative'] ?? -1, $r['avg_general'] ?? -1];
        usort($results, static function ($a, $b) use ($key): int {
            if ($a['final_mark'] === null || $b['final_mark'] === null) {
                return ($a['final_mark'] === null) <=> ($b['final_mark'] === null);
            }

            return $key($b) <=> $key($a);
        });
        $rank = 0;
        $prev = null;
        foreach ($results as $i => &$r) {
            if ($r['final_mark'] === null) {
                $r['rank'] = null;
                continue;
            }
            $k = round($r['final_mark'], 4);
            if ($k !== $prev) {
                $rank = $i + 1;
                $prev = $k;
            }
            $r['rank'] = $rank;
        }
        unset($r);

        return $results;
    }
}
