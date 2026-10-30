<?php

declare(strict_types=1);

namespace Modules\Scoring\Services;

use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;
use Modules\Schedule\Services\ScheduleService;

/**
 * Audience representatives: a panel drawn at random from the audience users who
 * reviewed every opened performance (the complete reviewers). The admin can
 * then add or remove people, but only complete reviewers can be on the panel.
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
            throw new DomainException('forbidden', 'Only an admin can change the representatives.', 403);
        }
        if (($reason = $this->lockedReason($slam)) !== null) {
            throw new DomainException('forbidden_transition', $reason, 409);
        }
    }

    /**
     * Replace the whole panel with $count people drawn at random from the
     * complete reviewers. The draw is a seeded shuffle of the pool sorted by
     * attendee id, so the logged seed reproduces it exactly.
     *
     * @return array{selected: list<int>, pool: int, seed: int}
     */
    public function draw(int $slamId, int $count, Actor $actor, ?int $seed = null): array
    {
        if ($count < 1 || $count > 1000) {
            throw new DomainException('validation', 'Choose between 1 and 1000 representatives.', 422, ['count' => 'Between 1 and 1000.']);
        }
        $seed ??= random_int(1, 2_000_000_000);

        return Db::tx(function ($db) use ($slamId, $count, $actor, $seed): array {
            $slam = Db::lockSlam($slamId);
            $this->guard($slam, $actor);
            $pool = service('scoring')->completeReviewerIds($slamId);
            sort($pool);
            $selected = array_slice(ScheduleService::seededShuffle($pool, $seed), 0, $count);
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
                'meta' => ['requested' => $count, 'pool' => count($pool), 'seed' => $seed],
            ]);

            return ['selected' => $selected, 'pool' => count($pool), 'seed' => $seed];
        });
    }

    public function add(int $slamId, int $attendeeId, Actor $actor): void
    {
        Db::tx(function ($db) use ($slamId, $attendeeId, $actor): void {
            $slam = Db::lockSlam($slamId);
            $this->guard($slam, $actor);
            if (! in_array($attendeeId, service('scoring')->completeReviewerIds($slamId), true)) {
                throw new DomainException('not_eligible', 'Only audience users who reviewed every performance can be representatives.', 422);
            }
            if (in_array($attendeeId, $this->ids($slamId), true)) {
                throw new DomainException('already_selected', 'This person is already a representative.', 409);
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
                throw new DomainException('not_found', 'This person is not a representative.', 404);
            }
            service('activityLog')->log($actor, 'representatives.removed', [
                'slam_id' => $slamId, 'target_type' => 'attendee', 'target_id' => $attendeeId, 'reason' => $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 255) : null,
            ]);
        });
    }
}
