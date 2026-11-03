<?php

declare(strict_types=1);

namespace Modules\Scoring\Services;

use Modules\Attendees\Services\AttendeeService;
use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;
use Modules\Schedule\Services\ScheduleService;

/**
 * Audience representatives: a panel drawn at random from the audience users who
 * reviewed every opened performance (the complete reviewers), keeping the same
 * category mix as the judges who checked in. The admin can then add or remove
 * people, but only complete reviewers can be on the panel.
 * Representatives form their own scoring group; their reviews no longer count
 * in the general reviewers' average.
 */
class RepresentativeService
{
    /** The complete-reviewer set is fixed once every review window has closed. */
    public const EDITABLE_PHASES = ['reviews_complete', 'revision', 'revision_closed', 'closed', 'marks_calculated'];

    /** @return list<int> attendee ids */
    public function ids(int $slamId): array
    {
        return array_map('intval', array_column(Db::rows('SELECT attendee_id FROM audience_representatives WHERE slam_id = ? ORDER BY attendee_id', [$slamId]), 'attendee_id'));
    }

    public function count(int $slamId): int
    {
        return (int) (Db::value('SELECT representative_count FROM score_config WHERE slam_id = ?', [$slamId]) ?? 10);
    }

    /** Why the panel cannot be changed right now, or null when it can. */
    public function lockedReason(array $slam): ?string
    {
        return match (true) {
            $slam['status'] === 'ended' => 'The event has ended; the panel is final.',
            ! in_array($slam['status'], self::EDITABLE_PHASES, true) => 'The panel can be drawn after All Review Close, when the list of people who reviewed every performance is final.',
            default => null,
        };
    }

    private function guard(array $slam, Actor $actor): void
    {
        if (! $actor->isAdmin()) {
            throw new DomainException('forbidden', 'Only an admin can change the audience representatives.', 403);
        }
        if (($reason = $this->lockedReason($slam)) !== null) {
            throw new DomainException('forbidden_transition', $reason, 409);
        }
    }

    /**
     * Replace the whole panel with $count people drawn at random from the
     * complete reviewers, category by category (see allocate). Each category is
     * a seeded shuffle of its pool sorted by attendee id, so the logged seed
     * reproduces the draw exactly.
     *
     * @return array{selected: list<int>, pool: int, seed: int, categories: array<string, array{checked_in: int, pool: int, selected: int}>}
     */
    public function draw(int $slamId, int $count, Actor $actor, ?int $seed = null): array
    {
        if ($count < 1 || $count > 1000) {
            throw new DomainException('validation', 'Choose between 1 and 1000 audience representatives.', 422, ['count' => 'Between 1 and 1000.']);
        }
        $seed ??= random_int(1, 2_000_000_000);

        return Db::tx(function ($db) use ($slamId, $count, $actor, $seed): array {
            $slam = Db::lockSlam($slamId);
            $this->guard($slam, $actor);
            $pool    = service('scoring')->completeReviewerIds($slamId);
            $byGroup = $this->poolByCategory($slamId, $pool);
            $checked = $this->checkedInByCategory($slamId);
            $quotas  = self::allocate($checked, array_map('count', $byGroup), $count);

            $selected   = [];
            $categories = [];
            foreach ($byGroup as $cat => $ids) {
                $picked   = array_slice(ScheduleService::seededShuffle($ids, $seed), 0, $quotas[$cat]);
                $selected = [...$selected, ...$picked];
                $categories[$cat] = ['checked_in' => $checked[$cat] ?? 0, 'pool' => count($ids), 'selected' => count($picked)];
            }
            sort($selected);
            $old = $this->ids($slamId);

            $db->table('audience_representatives')->where('slam_id', $slamId)->delete();
            foreach ($selected as $id) {
                $db->table('audience_representatives')->insert([
                    'slam_id' => $slamId, 'attendee_id' => $id, 'source' => 'random', 'draw_seed' => $seed,
                    'added_by' => $actor->id, 'created_at' => Clock::db(),
                ]);
            }
            service('scoring')->saveConfig($db, $slamId, ['representative_count' => $count], $actor);
            service('activityLog')->log($actor, 'representatives.drawn', [
                'slam_id' => $slamId, 'target_type' => 'slam', 'target_id' => $slamId,
                'old' => ['representatives' => $old], 'new' => ['representatives' => $selected],
                'meta' => ['requested' => $count, 'pool' => count($pool), 'seed' => $seed, 'categories' => $categories],
            ]);

            return ['selected' => $selected, 'pool' => count($pool), 'seed' => $seed, 'categories' => $categories];
        });
    }

    /**
     * How many representatives each category gets: its share of the people who
     * checked in, by the largest-remainder method so the seats add up. Every
     * category with someone in the pool gets at least one seat when there are
     * enough seats, and no category gets more than its pool; spare seats go to
     * the category furthest below its share.
     *
     * @param array<string, int> $checkedIn people checked in, per category
     * @param array<string, int> $pool      people eligible, per category
     * @return array<string, int> seats per category of $pool
     */
    public static function allocate(array $checkedIn, array $pool, int $count): array
    {
        ksort($pool);
        $seats = min($count, array_sum($pool));
        $weights = array_map(static fn (string $c): int => $checkedIn[$c] ?? 0, array_combine(array_keys($pool), array_keys($pool)));
        if (array_sum($weights) === 0) {
            $weights = $pool;
        }
        $total = max(1, array_sum($weights));
        $exact = array_map(static fn (int $w): float => $seats * $w / $total, $weights);
        $quota = array_map(static fn (float $e): int => (int) floor($e + 1e-9), $exact);

        $remainders = [];
        foreach ($exact as $c => $e) {
            $remainders[$c] = $e - $quota[$c];
        }
        arsort($remainders);
        foreach (array_slice(array_keys($remainders), 0, $seats - array_sum($quota)) as $c) {
            $quota[$c]++;
        }

        $mostBelow = static function (array $quota, callable $canTake) use ($exact): ?string {
            $best = null;
            foreach ($quota as $c => $q) {
                if ($canTake($c) && ($best === null || $exact[$c] - $q > $exact[$best] - $quota[$best])) {
                    $best = $c;
                }
            }

            return $best;
        };
        $mostAbove = static function (array $quota, callable $canGive) use ($exact): ?string {
            $best = null;
            foreach ($quota as $c => $q) {
                if ($canGive($c) && ($best === null || $q - $exact[$c] > $quota[$best] - $exact[$best])) {
                    $best = $c;
                }
            }

            return $best;
        };

        // Nobody beyond the pool: pass the extra seats on.
        foreach (array_keys($quota) as $c) {
            while ($quota[$c] > $pool[$c]) {
                $to = $mostBelow($quota, static fn ($x) => $quota[$x] < $pool[$x]);
                $quota[$c]--;
                if ($to !== null) {
                    $quota[$to]++;
                }
            }
        }
        // Every category present, if there are enough seats.
        $present = array_keys(array_filter($pool));
        if ($seats >= count($present)) {
            foreach ($present as $c) {
                if ($quota[$c] === 0) {
                    $from = $mostAbove($quota, static fn ($x) => $quota[$x] > 1);
                    if ($from !== null) {
                        $quota[$from]--;
                        $quota[$c]++;
                    }
                }
            }
        }

        return $quota;
    }

    /**
     * Pool ids grouped by category, each sorted by id. '' = no category.
     *
     * @param list<int> $pool
     * @return array<string, list<int>>
     */
    private function poolByCategory(int $slamId, array $pool): array
    {
        $cats = [];
        foreach (Db::rows('SELECT id, category FROM attendees WHERE slam_id = ?', [$slamId]) as $r) {
            $cats[(int) $r['id']] = (string) $r['category'];
        }
        sort($pool);
        $groups = [];
        foreach ($pool as $id) {
            $groups[$cats[$id] ?? ''][] = $id;
        }
        ksort($groups);

        return $groups;
    }

    /** @return array<string, int> judges who checked in (now or earlier), per category; '' = no category */
    private function checkedInByCategory(int $slamId): array
    {
        $out = [];
        foreach (Db::rows(
            "SELECT a.category, COUNT(DISTINCT a.id) n FROM attendees a JOIN checkins c ON c.attendee_id = a.id
             WHERE a.slam_id = ? AND a.user_type = 'user' GROUP BY a.category",
            [$slamId],
        ) as $r) {
            $out[(string) $r['category']] = (int) $r['n'];
        }

        return $out;
    }

    /**
     * Category mix for the representatives screen.
     *
     * @return list<array{label: string, checked_in: int, percent: float, pool: int, selected: int}>
     */
    public function categoryMix(int $slamId): array
    {
        $checked  = $this->checkedInByCategory($slamId);
        $pool     = array_map('count', $this->poolByCategory($slamId, service('scoring')->completeReviewerIds($slamId)));
        $selected = array_map('count', $this->poolByCategory($slamId, $this->ids($slamId)));
        $total    = max(1, array_sum($checked));
        $rows     = [];
        foreach ([...array_keys(AttendeeService::CATEGORIES), ''] as $cat) {
            if ($cat !== '' || isset($checked['']) || isset($pool[''])) {
                $rows[] = [
                    'label' => AttendeeService::categoryLabel($cat === '' ? null : $cat), 'checked_in' => $checked[$cat] ?? 0,
                    'percent' => round(100 * ($checked[$cat] ?? 0) / $total, 1), 'pool' => $pool[$cat] ?? 0, 'selected' => $selected[$cat] ?? 0,
                ];
            }
        }

        return $rows;
    }

    public function add(int $slamId, int $attendeeId, Actor $actor): void
    {
        Db::tx(function ($db) use ($slamId, $attendeeId, $actor): void {
            $slam = Db::lockSlam($slamId);
            $this->guard($slam, $actor);
            if (! in_array($attendeeId, service('scoring')->completeReviewerIds($slamId), true)) {
                throw new DomainException('not_eligible', 'Only judges who reviewed every performance can be audience representatives.', 422);
            }
            if (in_array($attendeeId, $this->ids($slamId), true)) {
                throw new DomainException('already_selected', 'This person is already an audience representative.', 409);
            }
            $db->table('audience_representatives')->insert([
                'slam_id' => $slamId, 'attendee_id' => $attendeeId, 'source' => 'manual', 'added_by' => $actor->id, 'created_at' => Clock::db(),
            ]);
            service('activityLog')->log($actor, 'representatives.added', ['slam_id' => $slamId, 'target_type' => 'attendee', 'target_id' => $attendeeId]);
        });
    }

    public function remove(int $slamId, int $attendeeId, Actor $actor, ?string $reason): void
    {
        Db::tx(function ($db) use ($slamId, $attendeeId, $actor, $reason): void {
            $slam = Db::lockSlam($slamId);
            $this->guard($slam, $actor);
            $db->table('audience_representatives')->where('slam_id', $slamId)->where('attendee_id', $attendeeId)->delete();
            if ($db->affectedRows() === 0) {
                throw new DomainException('not_found', 'This person is not an audience representative.', 404);
            }
            service('activityLog')->log($actor, 'representatives.removed', [
                'slam_id' => $slamId, 'target_type' => 'attendee', 'target_id' => $attendeeId, 'reason' => $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 255) : null,
            ]);
        });
    }
}
