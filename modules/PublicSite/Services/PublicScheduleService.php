<?php

declare(strict_types=1);

namespace Modules\PublicSite\Services;

use Modules\Core\Config\Slam as SlamConfig;
use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\Db;

/**
 * Public (no login) schedule data. Only an allow-list of fields leaves the
 * server: never review windows/deadlines, questions, scores, attendee data,
 * check-in counts or skipped/inactive performances.
 */
class PublicScheduleService
{
    public const SLOT_FIELDS = ['id', 'kind', 'break_type', 'label', 'presenter_name', 'designation', 'topic', 'abstract', 'profile', 'thumb',
        'planned_start_at', 'duration_minutes', 'status'];

    /** Published slams for /slams with a badge: upcoming | today | live | finished. */
    public function listPublished(): array
    {
        $rows  = Db::rows("SELECT * FROM slams WHERE schedule_is_public = 1 AND status <> 'draft' ORDER BY opens_at");
        $today = Clock::localToday();
        foreach ($rows as &$s) {
            $s['badge'] = $this->badge($s, $today);
        }

        return $rows;
    }

    public function badge(array $s, ?string $today = null): string
    {
        $today ??= Clock::localToday();
        if (in_array($s['status'], ['closed', 'marks_calculated', 'ended'], true)) {
            return 'finished';
        }
        if (in_array($s['status'], ['checkin_open', 'checkin_closed', 'reviewing', 'reviews_complete', 'revision', 'revision_closed'], true)) {
            return 'live';
        }

        return Clock::localValue($s['opens_at'], 'Y-m-d') === $today ? 'today' : 'upcoming';
    }

    /** Cached public JSON for one published slam (purged on any change). */
    public function document(array $slam): array
    {
        $ttl = config(SlamConfig::class)->publicCacheSeconds;

        return service('publicCache')->remember('public', (int) $slam['id'], $ttl, function () use ($slam): array {
            $live  = service('liveState')->shared((int) $slam['id']);
            $slots = [];
            $details = [];
            foreach (Db::rows('SELECT p.id, p.abstract, pr.profile FROM performances p JOIN presenters pr ON pr.id = p.presenter_id WHERE p.slam_id = ?', [$slam['id']]) as $d) {
                $details[(int) $d['id']] = $d;
            }
            $perfOf = [];
            foreach (Db::rows('SELECT id, performance_id FROM schedule_items WHERE slam_id = ?', [$slam['id']]) as $r) {
                $perfOf[(int) $r['id']] = $r['performance_id'] !== null ? (int) $r['performance_id'] : null;
            }
            foreach ($live['running_order'] as $row) {
                $pid = $perfOf[$row['id']] ?? null;
                $row['abstract'] = $pid !== null ? ($details[$pid]['abstract'] ?? null) : null;
                $row['profile']  = $pid !== null ? ($details[$pid]['profile'] ?? null) : null;
                // "Presenting now" means on stage; once the performance ends it is done, even if its review window runs on.
                $row['status']   = match (true) {
                    $row['kind'] === 'break' && $row['status'] === 'open' => 'break_now',
                    $row['kind'] === 'break' => $row['status'] === 'closed' ? 'done' : 'upcoming',
                    ! empty($row['performing']) => 'presenting',
                    in_array($row['status'], ['open', 'closed'], true) => 'done',
                    default => 'upcoming',
                };
                $slots[] = array_intersect_key($row, array_flip(self::SLOT_FIELDS));
            }
            $now  = null;
            $next = null;
            foreach ($slots as $s) {
                if (in_array($s['status'], ['presenting', 'break_now'], true)) {
                    $now = $s;
                }
                if ($next === null && $s['status'] === 'upcoming') {
                    $next = $s;
                }
            }
            $finished = in_array($slam['status'], ['closed', 'marks_calculated', 'ended'], true);
            $doc = [
                'slam' => [
                    'name' => $slam['name'], 'slug' => $slam['slug'], 'type' => $slam['type'],
                    'date' => Clock::iso($slam['opens_at']), 'doors_open' => Clock::iso($slam['checkin_opens_at']),
                    'venue_name' => $slam['venue_name'], 'map_url' => $slam['map_url'],
                ],
                'badge'        => $this->badge($slam),
                'phase'        => $slam['status'],
                'now'          => $now !== null ? array_intersect_key($now, array_flip(['id', 'kind', 'label', 'presenter_name', 'topic'])) : null,
                'next'         => $next !== null ? array_intersect_key($next, array_flip(['id', 'kind', 'label', 'presenter_name', 'topic', 'planned_start_at'])) : null,
                'late_minutes' => in_array($slam['status'], ['checkin_closed', 'reviewing'], true) ? (int) $live['late_minutes'] : 0,
                'finished'     => $finished,
                'paused'       => $slam['paused_at'] !== null,
                // The talk on stage and the break ads are public; review deadlines and review state are not.
                'talk'         => ! empty($live['talk']) ? array_intersect_key($live['talk'], array_flip(['title', 'presenter', 'designation', 'thumb', 'photo_alt', 'summary', 'started_at', 'on_stage'])) : null,
                'ads'          => $live['ads'] ?? [],
                'slots'        => $slots,
            ];
            $doc['etag'] = '"' . substr(md5(json_encode($doc)), 0, 16) . '"';

            return $doc;
        });
    }

    /**
     * What is happening now in every published slam that is live: the home page's "Happening now" block
     * (server-rendered, then refreshed from /api/public/live). Only allow-listed fields of the public document.
     *
     * @return list<array<string, mixed>>
     */
    public function live(): array
    {
        $out = [];
        foreach ($this->listPublished() as $s) {
            if ($s['badge'] !== 'live') {
                continue;
            }
            service('slamPhase')->tick((int) $s['id']);
            $d     = $this->document(service('slams')->find((int) $s['id']));
            $out[] = ['slug' => $d['slam']['slug'], 'name' => $d['slam']['name'], 'venue_name' => $d['slam']['venue_name']]
                + array_intersect_key($d, array_flip(['badge', 'phase', 'now', 'next', 'late_minutes', 'paused', 'finished', 'talk', 'ads']));
        }

        return $out;
    }

    /** iCalendar for one slam (Gates Open → Slam Close). */
    public function ics(array $slam, string $url): string
    {
        $start = Clock::parse($slam['checkin_opens_at'] ?? $slam['opens_at'])->format('Ymd\THis\Z');
        $end   = Clock::parse($slam['closes_at'])->format('Ymd\THis\Z');
        $esc   = static fn (string $s) => addcslashes(str_replace(["\r\n", "\n"], '\n', $s), ',;\\');
        $lines = [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Science Slam//Schedule//EN', 'BEGIN:VEVENT',
            'UID:slam-' . $slam['id'] . '@' . (parse_url($url, PHP_URL_HOST) ?: 'scienceslam'),
            'DTSTAMP:' . Clock::now()->format('Ymd\THis\Z'), "DTSTART:{$start}", "DTEND:{$end}",
            'SUMMARY:' . $esc($slam['name']),
            'LOCATION:' . $esc(trim(implode(', ', array_filter([$slam['venue_name'], $slam['address_line'], $slam['city']])))),
            'URL:' . $url, 'END:VEVENT', 'END:VCALENDAR',
        ];

        return implode("\r\n", $lines) . "\r\n";
    }
}
