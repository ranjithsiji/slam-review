<?php

declare(strict_types=1);

namespace Modules\Reviews\Services;

use Modules\Core\Config\Slam as SlamConfig;
use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\Db;
use Modules\Slams\Services\ReviewWindowService;
use Modules\Slams\Services\SlamPhaseService;

/**
 * The live-state document phones poll. One shared document per slam (phase,
 * times, open window and deadline, current performance or break, next item,
 * running order, question sets) is cached and purged on every phase, schedule
 * or window change; the per-reviewer part is only a few flags.
 */
class LiveStateService
{
    public const SHARED_TTL = 2;

    /** @return array<string, mixed> */
    public function shared(int $slamId): array
    {
        return service('publicCache')->remember('live', $slamId, self::SHARED_TTL, fn () => $this->buildShared($slamId));
    }

    /** @return array<string, mixed> */
    public function buildShared(int $slamId): array
    {
        $slam   = service('slams')->find($slamId);
        $phase  = service('slamPhase');
        $proj   = $phase->projection($slam);
        $byId   = [];
        $order  = [];
        $presenters = $this->presenterDetails($slamId);
        foreach ($proj['items'] as $it) {
            if ($it['status'] === 'skipped' || ($it['item_type'] === 'performance' && (int) $it['perf_active'] === 0)) {
                continue;
            }
            $p    = $it['performance_id'] !== null ? ($presenters[(int) $it['performance_id']] ?? null) : null;
            $row  = [
                'id'                 => (int) $it['id'],
                'kind'               => $it['item_type'],
                'break_type'         => $it['break_type'],
                'label'              => $it['label'],
                'presenter_name'     => $p['name'] ?? null,
                'designation'        => $p['designation'] ?? null,
                'topic'              => $p['topic'] ?? null,
                'thumb'              => $p['thumb'] ?? null,
                'planned_start_at'   => Clock::iso($it['planned_start_at']),
                'projected_start_at' => Clock::iso($it['projected_start_at']),
                'duration_minutes'   => (int) $it['duration_minutes'],
                'status'             => $it['status'],
                // On stage right now (started, not yet ended); the review may open before, during or after.
                'performing'         => ! empty($it['performing_started_at']) && empty($it['performing_ended_at']) && $it['status'] !== 'skipped',
            ];
            $byId[(int) $it['id']] = $row;
            $order[] = $row;
        }

        $current = null;
        $window  = $phase->windows()->openWindow($slamId);
        if ($window !== null && ReviewWindowService::isRunning($window, $slam)) {
            $p       = $presenters[(int) $window['performance_id']] ?? [];
            $current = [
                'kind'             => 'performance',
                'schedule_item_id' => (int) $window['schedule_item_id'],
                'window_id'        => (int) $window['id'],
                'opened_at'        => Clock::iso($window['opened_at']),
                'closes_at'        => Clock::iso(ReviewWindowService::effectiveClose($window, $slam)),
            ] + $p;
        } elseif (($b = $phase->runningBreak($slamId)) !== null) {
            $current = ['kind' => 'break', 'schedule_item_id' => (int) $b['id'], 'break_type' => $b['break_type'], 'label' => $b['label'], 'ends_at' => Clock::iso($b['ends_at'])];
        }

        if ($current !== null && $current['kind'] === 'performance') {
            $current['performing'] = (bool) ($byId[$current['schedule_item_id']]['performing'] ?? false);
        }
        // Presenter on stage but the review has not opened yet.
        $presenting = null;
        foreach ($order as $row) {
            if ($row['performing'] && $row['status'] === 'pending' && $row['kind'] === 'performance') {
                $presenting = $row;
                break;
            }
        }

        // One "what is on right now" block for every screen: the talk on stage or in review, with photo, title, who, since when.
        $talk = null;
        $pick = ($current['kind'] ?? null) === 'performance' ? $current['schedule_item_id'] : ($presenting['id'] ?? null);
        if ($pick !== null) {
            $item = null;
            foreach ($proj['items'] as $it) {
                if ((int) $it['id'] === (int) $pick) {
                    $item = $it;
                }
            }
            $p = $item !== null && $item['performance_id'] !== null ? ($presenters[(int) $item['performance_id']] ?? []) : [];
            if ($item !== null && $p !== []) {
                $talk = [
                    'item_id' => (int) $item['id'], 'title' => $p['topic'], 'presenter' => $p['presenter_name'], 'designation' => $p['designation'],
                    'photo' => $p['photo'], 'thumb' => $p['thumb'], 'photo_alt' => $p['photo_alt'],
                    'summary' => self::shorten((string) ($p['abstract'] ?: $p['profile'] ?? ''), 220),
                    'started_at' => Clock::iso($item['performing_started_at'] ?? $item['window_opened_at'] ?? null),
                    'on_stage' => (bool) ($byId[(int) $item['id']]['performing'] ?? false),
                    'review_open' => $current !== null && ($current['kind'] ?? null) === 'performance',
                    'closes_at' => $current['closes_at'] ?? null,
                ];
            }
        }
        // Break ads: only while a break is running (so the shared document stays small the rest of the time).
        $ads = ($current['kind'] ?? null) === 'break' ? service('ads')->forBreak($slamId) : [];

        $next = null;
        foreach ($order as $row) {
            if ($row['status'] === 'pending') {
                $next = $row;
                break;
            }
        }

        $revision = null;
        if ($slam['status'] === 'revision' && ($rw = $phase->windows()->openRevisionWindow($slamId)) !== null) {
            $revision = ['closes_at' => Clock::iso(ReviewWindowService::effectiveClose($rw, $slam))];
        }

        $doc = [
            'slam' => [
                'id' => (int) $slam['id'], 'name' => $slam['name'], 'type' => $slam['type'], 'venue_name' => $slam['venue_name'],
                'address' => trim(implode(', ', array_filter([$slam['address_line'], $slam['city'], $slam['district']]))),
                'map_url' => $slam['map_url'], 'contact_name' => $slam['contact_name'], 'contact_phone' => $slam['contact_phone'],
                'contact_email' => $slam['contact_email'],
                'opens_at' => Clock::iso($slam['opens_at']), 'checkin_opens_at' => Clock::iso($slam['checkin_opens_at']),
                'checkin_closes_at' => Clock::iso($slam['checkin_closes_at']), 'closes_at' => Clock::iso($slam['closes_at']),
            ],
            'paused'        => $slam['paused_at'] !== null ? ['since' => Clock::iso($slam['paused_at']), 'reason' => $slam['pause_reason']] : null,
            'phase'         => $slam['status'],
            'phase_label'   => SlamPhaseService::LABELS[$slam['status']] ?? $slam['status'],
            'current'       => $current,
            'presenting'    => $presenting,
            'talk'          => $talk,
            'ads'           => $ads,
            'next'          => $next,
            'running_order' => $order,
            'late_minutes'  => $proj['late_minutes'],
            'revision'      => $revision,
            'judging'       => service('scoring')->judging($slamId),
            'questions'     => [
                'user'  => QuestionService::forClient(service('questions')->active($slamId, 'user')),
                'judge' => QuestionService::forClient(service('questions')->active($slamId, 'judge')),
            ],
            'poll_seconds'  => config(SlamConfig::class)->pollSeconds,
        ];
        $doc['version'] = substr(md5(json_encode($doc)), 0, 16);

        return $doc;
    }

    /** First $max characters of a text, cut at a word, with an ellipsis. */
    public static function shorten(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max);
        $sp  = mb_strrpos($cut, ' ');

        return rtrim($sp !== false && $sp > $max * 0.6 ? mb_substr($cut, 0, $sp) : $cut, " ,.;:") . '…';
    }

    /** @return array<int, array<string, mixed>> performance_id => presenter/topic details */
    private function presenterDetails(int $slamId): array
    {
        $out = [];
        foreach (Db::rows(
            'SELECT p.id, p.topic, p.abstract, pr.name, pr.designation_or_institution, pr.profile, pr.photo_path, pr.photo_thumb_path, pr.photo_alt
             FROM performances p JOIN presenters pr ON pr.id = p.presenter_id WHERE p.slam_id = ?',
            [$slamId],
        ) as $r) {
            $out[(int) $r['id']] = [
                'presenter_name' => $r['name'], 'name' => $r['name'], 'designation' => $r['designation_or_institution'], 'topic' => $r['topic'],
                'abstract' => $r['abstract'], 'profile' => $r['profile'],
                'photo' => $r['photo_path'] !== null ? '/' . $r['photo_path'] : null,
                'thumb' => $r['photo_thumb_path'] !== null ? '/' . $r['photo_thumb_path'] : null,
                'photo_alt' => $r['photo_alt'] ?: $r['name'],
            ];
        }

        return $out;
    }

    /**
     * Reviewer view: shared doc with only this reviewer's question set, plus flags.
     *
     * @param array{id:int, slam_id:int, badge_id:string, name:string, user_type:string} $attendee
     * @return array{body: array<string, mixed>, etag: string}
     */
    public function forReviewer(array $attendee): array
    {
        $shared = $this->shared((int) $attendee['slam_id']);
        $cur    = $shared['current'];
        $itemId = ($cur['kind'] ?? null) === 'performance' ? (int) $cur['schedule_item_id'] : null;
        $mine   = null;
        if ($itemId !== null) {
            $r = Db::row('SELECT id FROM reviews WHERE attendee_id = ? AND schedule_item_id = ?', [$attendee['id'], $itemId]);
            if ($r !== null) {
                $mine = ['review_id' => (int) $r['id'], 'answers' => array_map(
                    static fn ($a) => ['question_id' => (int) $a['question_id'], 'score' => (float) $a['score']],
                    Db::rows('SELECT question_id, score FROM review_answers WHERE review_id = ?', [$r['id']]),
                )];
            }
        }
        $reviewed = (int) Db::value('SELECT COUNT(*) FROM reviews WHERE attendee_id = ?', [$attendee['id']]);

        $body              = $shared;
        $body['questions'] = $shared['questions'][$attendee['user_type']] ?? [];
        // Judges whose marks are entered by the organisers get no review form.
        $body['judge_offline'] = $attendee['user_type'] === 'judge' && $shared['judging']['timing'] === 'end_of_slam';
        if ($body['judge_offline']) {
            $body['questions'] = [];
        }
        $body['me']        = [
            'name' => $attendee['name'], 'badge_id' => $attendee['badge_id'], 'user_type' => $attendee['user_type'],
            'checked_in' => true, 'reviewed_count' => $reviewed,
        ];
        $body['already_reviewed'] = $mine !== null;
        $body['my_review']        = $mine;
        $etag = '"' . $shared['version'] . '-' . md5(json_encode([$body['me'], $mine])) . '"';
        $body['server_now'] = Clock::iso(Clock::db());

        return ['body' => $body, 'etag' => $etag];
    }

    /**
     * "What is happening now" for staff screens (admin live bar, volunteer desk): one line, an optional deadline,
     * paused and behind/ahead information.
     *
     * @return array<string, mixed>
     */
    public function nowSummary(int $slamId): array
    {
        $slam   = service('slams')->find($slamId);
        $status = $slam['status'];
        $live   = $this->shared($slamId);
        $cur    = $live['current'] ?? null;
        $inside = (int) Db::value('SELECT COUNT(*) FROM checkins c JOIN attendees a ON a.id = c.attendee_id WHERE a.slam_id = ? AND c.checked_out_at IS NULL', [$slamId]);
        [$detail, $until] = match (true) {
            $status === 'reviewing' && ($cur['kind'] ?? null) === 'performance' => [(! empty($cur['performing']) ? 'On stage, review open: ' : 'Performance over, review open: ') . ($cur['presenter_name'] ?? 'performance'), $cur['closes_at'] ?? null],
            $status === 'reviewing' && ! empty($live['presenting']) => ['On stage: ' . ($live['presenting']['presenter_name'] ?? 'performance') . ' (review not open yet)', null],
            $status === 'reviewing' && ($cur['kind'] ?? null) === 'break' => [($cur['label'] ?? 'Break') . ' in progress', $cur['ends_at'] ?? null],
            $status === 'reviewing' => ['Between performances' . (($n = $live['next']['presenter_name'] ?? null) ? ': next is ' . $n : ''), null],
            $status === 'revision' => ['Reviewers are editing their scores', $live['revision']['closes_at'] ?? null],
            $status === 'checkin_open' => ["Gates are open: {$inside} checked in", $slam['checkin_closes_at'] ? Clock::iso($slam['checkin_closes_at']) : null],
            $status === 'checkin_closed' => ["Gates closed, {$inside} inside" . (($n = $live['next']['presenter_name'] ?? null) ? ". First up: {$n}" : ''), null],
            $status === 'open' => ['Slam is open; waiting to open the gates', null],
            $status === 'reviews_complete' => ['All performances reviewed', null],
            $status === 'revision_closed' => ['Scores locked', null],
            in_array($status, ['draft', 'scheduled'], true) => ['Not started yet', null],
            in_array($status, ['closed', 'marks_calculated', 'ended'], true) => ['The slam has ended', null],
            default => ['Marks are calculated next', null],
        };
        $phase = service('slamPhase');
        $drift = in_array($status, ['checkin_closed', 'reviewing'], true) ? $phase->drift($slam, $phase->projection($slam)['items']) : null;

        return [
            'status' => $status, 'label' => \Modules\Slams\Services\SlamPhaseService::LABELS[$status] ?? $status,
            'detail' => $detail, 'until' => $until, 'paused_since' => $slam['paused_at'] !== null ? Clock::iso($slam['paused_at']) : null,
            'drift' => $drift === null ? null : ['minutes' => $drift['minutes'], 'basis' => $drift['basis'], 'original' => $drift['original']],
            'next' => $live['next']['presenter_name'] ?? null, 'talk' => $live['talk'] ?? null,
        ];
    }
}
