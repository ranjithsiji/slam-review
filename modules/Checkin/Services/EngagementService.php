<?php

declare(strict_types=1);

namespace Modules\Checkin\Services;

use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;

/**
 * Live turnout and "not reviewing" detection, for deciding on the spot whether to extend a review window
 * and for the desk to check out people who sit in the hall without reviewing.
 *
 * Who counts: audience users who are checked in right now (judges too, when judges mark live on their phones).
 * A person is "not reviewing" when, among the finished review windows that opened after they checked in,
 * they skipped the most recent N in a row (default 2). Windows before they arrived never count against them,
 * and a window still open is not counted (they may still vote).
 */
class EngagementService
{
    public const DEFAULT_MIN_MISSED = 2;

    private function eligibleTypes(int $slamId): array
    {
        return service('scoring')->judging($slamId)['timing'] === 'live' ? ['user', 'judge'] : ['user'];
    }

    private const VOLUNTEER_TTL = 90; // seconds: the desk polls every few seconds while it is open

    /** Called by the volunteer desk's poll: remembers that this volunteer is on screen right now. */
    public function volunteerHeartbeat(int $volunteerId): void
    {
        cache()->save('vol_seen_' . $volunteerId, time(), self::VOLUNTEER_TTL);
    }

    /**
     * Who is live right now. Reviewers and judges: checked in (inside), and how many of them have their phone
     * linked. Volunteers: desk open in the last minute and a half.
     *
     * @return array{users: array, judges: array, volunteers: array}
     */
    public function presence(int $slamId): array
    {
        $rows = Db::rows(
            'SELECT a.user_type, COUNT(DISTINCT a.id) AS inside, COUNT(DISTINCT CASE WHEN c.session_token_hash IS NOT NULL THEN a.id END) AS phone
               FROM attendees a JOIN checkins c ON c.attendee_id = a.id AND c.checked_out_at IS NULL WHERE a.slam_id = ? GROUP BY a.user_type',
            [$slamId],
        );
        $in    = array_column($rows, null, 'user_type');
        $total = array_column(Db::rows('SELECT user_type, COUNT(*) AS n FROM attendees WHERE slam_id = ? AND is_active = 1 GROUP BY user_type', [$slamId]), 'n', 'user_type');
        $group = static fn (string $t) => ['inside' => (int) ($in[$t]['inside'] ?? 0), 'phone' => (int) ($in[$t]['phone'] ?? 0), 'total' => (int) ($total[$t] ?? 0)];

        $names = [];
        $vols  = Db::rows('SELECT id, name FROM volunteers WHERE slam_id = ? AND is_active = 1 ORDER BY name', [$slamId]);
        foreach ($vols as $v) {
            if (cache()->get('vol_seen_' . $v['id']) !== null) {
                $names[] = $v['name'];
            }
        }

        return ['users' => $group('user'), 'judges' => $group('judge'), 'volunteers' => ['online' => count($names), 'total' => count($vols), 'names' => $names]];
    }

    /**
     * Turnout for the performance now in review, or for the one that closed last.
     *
     * @return array<string, mixed>|null
     */
    public function turnout(int $slamId): ?array
    {
        $item = Db::row(
            "SELECT si.id, si.status, pr.name AS presenter_name FROM schedule_items si
               JOIN performances p ON p.id = si.performance_id JOIN presenters pr ON pr.id = p.presenter_id
              LEFT JOIN review_windows w ON w.schedule_item_id = si.id
              WHERE si.slam_id = ? AND si.item_type = 'performance' AND si.status IN ('open','closed')
              GROUP BY si.id, si.status, pr.name
              ORDER BY (si.status = 'open') DESC, MAX(w.opened_at) DESC, si.id DESC LIMIT 1",
            [$slamId],
        );
        if ($item === null) {
            return null;
        }
        $types = $this->eligibleTypes($slamId);
        $in    = implode(',', array_fill(0, count($types), '?'));
        $row   = Db::row(
            "SELECT COUNT(DISTINCT a.id) AS checked_in,
                    COUNT(DISTINCT CASE WHEN r.id IS NOT NULL THEN a.id END) AS voted
               FROM attendees a
               JOIN checkins c ON c.attendee_id = a.id AND c.checked_out_at IS NULL
               LEFT JOIN reviews r ON r.attendee_id = a.id AND r.schedule_item_id = ?
              WHERE a.slam_id = ? AND a.user_type IN ({$in})",
            [$item['id'], $slamId, ...$types],
        );
        $inNow = (int) $row['checked_in'];
        $voted = (int) $row['voted'];

        return [
            'item_id' => (int) $item['id'], 'presenter_name' => $item['presenter_name'], 'open' => $item['status'] === 'open',
            'checked_in' => $inNow, 'voted' => $voted, 'not_voted' => max(0, $inNow - $voted),
            'percent' => $inNow > 0 ? (int) round($voted * 100 / $inNow) : 0,
            'voted_total' => (int) Db::value('SELECT COUNT(*) FROM reviews WHERE schedule_item_id = ?', [$item['id']]),
        ];
    }

    /**
     * Checked-in reviewers who have skipped at least $min recent finished reviews in a row.
     *
     * @return list<array<string, mixed>> attendee_id, name, badge_id, mobile, user_type, checked_in_at, eligible, reviewed, missed, last_review_at
     */
    public function idle(int $slamId, int $min = self::DEFAULT_MIN_MISSED): array
    {
        $min     = max(1, $min);
        $windows = Db::rows(
            "SELECT si.id, MIN(w.opened_at) AS opened_at FROM schedule_items si JOIN review_windows w ON w.schedule_item_id = si.id
              WHERE si.slam_id = ? AND si.item_type = 'performance' AND si.status = 'closed' GROUP BY si.id ORDER BY opened_at, si.id",
            [$slamId],
        );
        if ($windows === []) {
            return [];
        }
        $types = $this->eligibleTypes($slamId);
        $in    = implode(',', array_fill(0, count($types), '?'));
        $people = Db::rows(
            "SELECT a.id, a.name, a.badge_id, a.mobile, a.user_type, c.checked_in_at FROM attendees a
               JOIN checkins c ON c.attendee_id = a.id AND c.checked_out_at IS NULL
              WHERE a.slam_id = ? AND a.user_type IN ({$in})",
            [$slamId, ...$types],
        );
        $voted = [];
        foreach (Db::rows('SELECT r.attendee_id, r.schedule_item_id, r.submitted_at FROM reviews r JOIN schedule_items si ON si.id = r.schedule_item_id WHERE si.slam_id = ?', [$slamId]) as $r) {
            $voted[(int) $r['attendee_id']][(int) $r['schedule_item_id']] = $r['submitted_at'];
        }

        $out = [];
        foreach ($people as $p) {
            $mine     = $voted[(int) $p['id']] ?? [];
            $eligible = 0;
            $reviewed = 0;
            $streak   = 0;
            foreach ($windows as $w) {
                if ($w['opened_at'] < $p['checked_in_at']) {
                    continue; // they were not in the hall yet
                }
                $eligible++;
                if (isset($mine[(int) $w['id']])) {
                    $reviewed++;
                    $streak = 0;
                } else {
                    $streak++;
                }
            }
            if ($streak >= $min) {
                $out[] = [
                    'attendee_id' => (int) $p['id'], 'name' => $p['name'], 'badge_id' => $p['badge_id'], 'mobile' => $p['mobile'], 'user_type' => $p['user_type'],
                    'checked_in_at' => $p['checked_in_at'], 'eligible' => $eligible, 'reviewed' => $reviewed, 'missed' => $streak,
                    'last_review_at' => $mine === [] ? null : max($mine),
                ];
            }
        }
        usort($out, static fn ($a, $b) => [$b['missed'], $a['name']] <=> [$a['missed'], $b['name']]);

        return $out;
    }

    /** Cheap count for polling screens (cached a few seconds). */
    public function idleCount(int $slamId, int $min = self::DEFAULT_MIN_MISSED): int
    {
        return (int) service('publicCache')->remember('idle', $slamId, 5, fn () => ['n' => count($this->idle($slamId, $min))])['n'];
    }

    /**
     * Force check-out of people who are still "not reviewing": the list is recomputed here, so a stale page can
     * never check out someone who has voted since. $only narrows it to the people chosen on screen.
     *
     * @param list<int>|null $only
     * @return array{checked_out: int, skipped: int}
     */
    public function checkoutIdle(int $slamId, int $min, ?array $only, string $reason, Actor $actor): array
    {
        if (trim($reason) === '') {
            throw new DomainException('reason_required', 'A reason is required for a forced check-out.');
        }
        $done = 0;
        $list = $this->idle($slamId, $min);
        foreach ($list as $p) {
            if ($only !== null && ! in_array($p['attendee_id'], $only, true)) {
                continue;
            }
            service('checkins')->checkout($p['attendee_id'], 'forced', $actor, $reason . " ({$p['missed']} in a row not reviewed)");
            $done++;
        }

        return ['checked_out' => $done, 'skipped' => ($only === null ? 0 : max(0, count($only) - $done))];
    }
}
