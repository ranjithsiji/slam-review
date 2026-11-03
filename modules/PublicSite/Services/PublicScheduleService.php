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
    public const SLOT_FIELDS = ['id', 'kind', 'break_type', 'label', 'presenter_name', 'designation', 'topic', 'abstract', 'profile', 'thumb', 'photo',
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
                'extras'       => $this->extras((int) $slam['id']),
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

    /**
     * The running order grouped for the public schedule. Sessions are derived, not stored: anything before the
     * first talk is the Opening, a tea or lunch break ends a session, and a trailing group with no talks is the Closing.
     *
     * Display-only extras go around that: before the first talk, and after the last one.
     *
     * @param list<array<string, mixed>> $slots  The public document's slots
     * @param list<array<string, mixed>> $extras The public document's extras
     *
     * @return list<array<string, mixed>> Sections of kind opening, session, closing, extra or break
     */
    public function programme(array $slots, array $extras = []): array
    {
        $before = array_values(array_filter($extras, static fn (array $e): bool => $e['placement'] === 'before'));
        $after  = array_values(array_filter($extras, static fn (array $e): bool => $e['placement'] === 'after'));
        $slots    = array_values($slots);
        $sections = [];
        $current  = null;
        $talkNo   = 0;
        $flush    = static function () use (&$sections, &$current): void {
            if ($current !== null) {
                $sections[] = $current;
                $current    = null;
            }
        };
        foreach ($slots as $i => $slot) {
            $start = strtotime((string) $slot['planned_start_at']);
            $next  = isset($slots[$i + 1]) ? strtotime((string) $slots[$i + 1]['planned_start_at'])
                : (isset($after[0]['starts_at']) ? strtotime($after[0]['starts_at'] . ' UTC') : null);
            $own   = $start + 60 * (int) $slot['duration_minutes'];
            // A slot runs up to the next one (its changeover included), unless there is a real gap in the day.
            $end  = $next !== null && $next >= $own && $next - $own <= 1800 ? $next : $own;
            $row  = $slot + ['start_at' => gmdate('Y-m-d H:i:s', $start), 'end_at' => gmdate('Y-m-d H:i:s', $end), 'slot_minutes' => (int) (($end - $start) / 60)];
            $isTalk = $slot['kind'] !== 'break';
            if (! $isTalk && in_array($slot['break_type'] ?? null, ['tea', 'lunch'], true)) {
                $flush();
                $sections[] = ['kind' => 'break', 'row' => $row];

                continue;
            }
            if ($isTalk) {
                $row['number'] = ++$talkNo;
            }
            if ($current === null) {
                $current = ['kind' => $talkNo === 0 ? 'opening' : 'session', 'rows' => []];
            } elseif ($isTalk && $current['kind'] === 'opening') {
                $flush();
                $current = ['kind' => 'session', 'rows' => []];
            }
            $current['rows'][] = $row;
        }
        $flush();

        return [...$this->extraSections($before), ...$this->nameSections($sections), ...$this->extraSections($after)];
    }

    /** @return list<array<string, mixed>> */
    private function extras(int $slamId): array
    {
        try {
            return Db::rows('SELECT placement, item_type, break_type, label, note, starts_at, ends_at FROM programme_extras WHERE slam_id = ? ORDER BY placement, position', [$slamId]);
        } catch (\Throwable) {
            return []; // Never let a missing table during a release break the live schedule.
        }
    }

    /** @param list<array<string, mixed>> $extras */
    private function extraSections(array $extras): array
    {
        $out     = [];
        $current = null;
        foreach ($extras as $e) {
            if ($e['item_type'] === 'heading') {
                if ($current !== null) {
                    $out[] = $current;
                }
                $current = ['kind' => 'extra', 'title' => $e['label'], 'note' => $e['note'], 'rows' => []];

                continue;
            }
            $row = [
                'kind' => $e['item_type'] === 'break' ? 'break' : 'extra', 'break_type' => $e['break_type'], 'label' => $e['label'], 'status' => 'upcoming',
                'start_at' => $e['starts_at'], 'end_at' => $e['ends_at'],
                'duration_minutes' => (int) round((strtotime((string) $e['ends_at']) - strtotime((string) $e['starts_at'])) / 60),
            ];
            if ($e['item_type'] === 'break') {
                if ($current !== null) {
                    $out[]   = $current;
                    $current = null;
                }
                $out[] = ['kind' => 'break', 'row' => $row];

                continue;
            }
            $current ??= ['kind' => 'extra', 'title' => '', 'note' => null, 'rows' => []];
            $current['rows'][] = $row;
        }
        if ($current !== null) {
            $out[] = $current;
        }
        $out = array_values(array_filter($out, static fn (array $sec): bool => $sec['kind'] !== 'extra' || $sec['rows'] !== []));
        foreach ($out as &$sec) {
            if ($sec['kind'] === 'extra') {
                $sec['start_at'] = $sec['rows'][0]['start_at'];
                $sec['end_at']   = $sec['rows'][array_key_last($sec['rows'])]['end_at'];
            }
        }
        unset($sec);

        return $out;
    }

    /** @param list<array<string, mixed>> $sections */
    private function nameSections(array $sections): array
    {
        $partOf = static fn (array $sec): string => (int) Clock::localValue($sec['rows'][0]['start_at'], 'G') < 12 ? 'Morning' : 'Afternoon';
        $total  = ['Morning' => 0, 'Afternoon' => 0];
        foreach ($sections as &$sec) {
            if ($sec['kind'] === 'session') {
                $sec['talks'] = array_values(array_filter($sec['rows'], static fn (array $r): bool => $r['kind'] !== 'break'));
                if ($sec['talks'] === []) {
                    $sec['kind'] = 'closing';
                } else {
                    $total[$partOf($sec)]++;
                }
            }
        }
        unset($sec);

        $number = 0;
        $seen   = ['Morning' => 0, 'Afternoon' => 0];
        foreach ($sections as &$sec) {
            if ($sec['kind'] === 'break') {
                continue;
            }
            $sec['start_at'] = $sec['rows'][0]['start_at'];
            $sec['end_at']   = $sec['rows'][array_key_last($sec['rows'])]['end_at'];
            $sec['note']     = null;
            if ($sec['kind'] === 'opening') {
                $sec['title'] = 'തുടക്കം (Opening)';
            } elseif ($sec['kind'] === 'closing') {
                $sec['title'] = 'സമാപന സമ്മേളനം & ഫലപ്രഖ്യാപനം (Valedictory Session)';
            } else {
                $part = $partOf($sec);
                $seen[$part]++;
                $number++;
                $sec['title'] = "സെഷൻ {$number} ({$part} Session" . ($total[$part] > 1 ? " {$seen[$part]}" : '') . ')';
                $sec['note']  = $this->sessionNote($sec['talks']);
            }
        }
        unset($sec);

        return $sections;
    }

    /** "10 minutes + 2 changeover = 12 minutes", shown only when every talk in the session has the same timing. */
    private function sessionNote(array $talks): ?string
    {
        $talk = array_unique(array_map(static fn (array $t): int => (int) $t['duration_minutes'], $talks));
        // The last talk may run into a gap in the day, so it does not count towards the session's rhythm.
        $slot = array_unique(array_map(static fn (array $t): int => (int) $t['slot_minutes'], count($talks) > 1 ? array_slice($talks, 0, -1) : $talks));
        if (count($talk) !== 1 || count($slot) !== 1) {
            return null;
        }
        $t = reset($talk);
        $s = reset($slot);

        return $s > $t ? "ഓരോ അവതരണത്തിനും: {$t} മിനിറ്റ് + " . ($s - $t) . " മുന്നൊരുക്കം = {$s} മിനിറ്റ്" : "ഓരോ അവതരണത്തിനും: {$t} മിനിറ്റ്";
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
