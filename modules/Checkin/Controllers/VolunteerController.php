<?php

declare(strict_types=1);

namespace Modules\Checkin\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use Modules\Attendees\Services\BadgeIdService;
use Modules\Core\Controllers\BaseController;
use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;
use Modules\Slams\Services\SlamPhaseService;

class VolunteerController extends BaseController
{
    private function slamId(): int
    {
        $auth = session('auth');
        if ($auth['type'] === 'volunteer' || ($auth['role'] ?? null) === 'operator') {
            return (int) $auth['slam_id'];
        }
        $id = (int) ($this->request->getGet('slam') ?: $this->request->getPost('slam_id') ?: ($this->input()['slam_id'] ?? 0));
        if ($id === 0) {
            $id = (int) Db::value("SELECT id FROM slams WHERE status NOT IN ('draft','ended') ORDER BY opens_at LIMIT 1");
        }

        return $id;
    }

    public function panel(): string
    {
        $slamId = $this->slamId();
        $slam   = service('slams')->find($slamId);

        return $this->render('Modules\Checkin\Views\volunteer', ['title' => 'Volunteer desk', 'slam' => $slam], session('auth')['type'] === 'volunteer' ? 'volunteer' : 'admin');
    }

    public function state(): ResponseInterface
    {
        return $this->api(function () {
            session()->close();
            $slamId = $this->slamId();
            service('slamPhase')->tick($slamId);
            if ((session('auth')['type'] ?? '') === 'volunteer') {
                service('engagement')->volunteerHeartbeat((int) session('auth')['id']);
            }
            $slam = service('slams')->find($slamId);

            return [
                'server_now' => Clock::iso(Clock::db()),
                'slam' => [
                    'id' => (int) $slam['id'], 'name' => $slam['name'], 'venue_name' => $slam['venue_name'],
                    'status' => $slam['status'], 'status_label' => SlamPhaseService::LABELS[$slam['status']],
                    'checkin_opens_at' => Clock::iso($slam['checkin_opens_at']), 'checkin_closes_at' => Clock::iso($slam['checkin_closes_at']),
                    'read_only' => in_array($slam['status'], ['closed', 'marks_calculated', 'ended'], true),
                ],
                'now' => service('liveState')->nowSummary($slamId),
                'waiting' => service('checkins')->waitingList($slamId),
                'counts' => array_map('intval', Db::row(
                    "SELECT (SELECT COUNT(*) FROM attendees WHERE slam_id = ? AND is_active = 1) AS registered,
                            (SELECT COUNT(*) FROM checkins c JOIN attendees a ON a.id = c.attendee_id WHERE a.slam_id = ? AND c.checked_out_at IS NULL) AS checked_in",
                    [$slamId, $slamId],
                )),
            ];
        });
    }

    /** Late check-in (after Gate Close) by scan (URL or ID), typed ID, or attendee id from search. */
    public function checkin(): ResponseInterface
    {
        $in = $this->input();

        return $this->api(function () use ($in) {
            $slamId = $this->slamId();
            if (! empty($in['attendee_id'])) {
                $att = Db::row('SELECT * FROM attendees WHERE id = ?', [(int) $in['attendee_id']]);
            } else {
                $badge = BadgeIdService::fromScan((string) ($in['code'] ?? ''));
                $att   = $badge !== null ? Db::row('SELECT * FROM attendees WHERE badge_id = ?', [$badge]) : null;
            }
            if ($att === null || (int) $att['slam_id'] !== $slamId) {
                service('activityLog')->log($this->actor(), 'checkin.rejected', ['slam_id' => $slamId, 'outcome' => 'failure', 'reason' => 'not_registered']);
                throw new DomainException('not_registered', 'Not registered for this slam. Please direct the person to the organisers.', 404);
            }
            $r = service('checkins')->lateCheckin((int) $att['id'], isset($in['reason']) ? (string) $in['reason'] : null, $this->actor());

            return ['ok' => true, 'attendee' => ['id' => (int) $att['id'], 'name' => $att['name'], 'badge_id' => $att['badge_id'], 'user_type' => $att['user_type']], 'checkin_id' => $r['checkin_id'], 'phone' => $r['phone']];
        });
    }

    public function forceCheckout(int $attendeeId): ResponseInterface
    {
        $in = $this->input();

        return $this->api(function () use ($attendeeId, $in) {
            service('checkins')->checkout($attendeeId, 'forced', $this->actor(), (string) ($in['reason'] ?? ''));

            return ['ok' => true];
        });
    }

    public function lateOverride(): ResponseInterface
    {
        $in = $this->input();

        return $this->api(fn () => service('checkins')->lateCheckin((int) ($in['attendee_id'] ?? 0), (string) ($in['reason'] ?? ''), $this->actor()) + ['ok' => true]);
    }

    /** Simple JSON search (name, mobile, email, ID); results show registered + checked-in state. */
    public function search(): ResponseInterface
    {
        return $this->api(function () {
            session()->close();
            $slamId = $this->slamId();
            $res    = service('dataTables')->handle('attendee-search', [
                'draw' => 1, 'start' => 0, 'length' => 20, 'filters' => ['slam_id' => $slamId, 'q' => (string) $this->request->getGet('q')],
            ], $this->actor());
            service('activityLog')->log($this->actor(), 'attendee.lookup', ['slam_id' => $slamId, 'meta' => [
                'query_type' => preg_match('/^\d[\d\s+-]*$/', (string) $this->request->getGet('q')) ? 'mobile' : (str_contains((string) $this->request->getGet('q'), '@') ? 'email' : 'name'),
                'results' => $res['recordsFiltered'],
            ]]);

            return ['data' => $res['data'], 'total' => $res['recordsFiltered']];
        });
    }

    public function monitor(int $slamId): string
    {
        $slam = service('slams')->find($slamId);

        return $this->render('Modules\Checkin\Views\monitor', ['title' => 'Check-in monitor — ' . $slam['name'], 'slam' => $slam]);
    }

    /** Checked-in reviewers who skipped the last N finished reviews in a row, with force check-out (admin/operator and volunteer). */
    public function notReviewing(?int $slamId = null): string
    {
        $slamId ??= $this->slamId();
        $slam    = service('slams')->find($slamId);
        $auth    = session('auth');
        $isVol   = ($auth['type'] ?? '') === 'volunteer';

        return $this->render('Modules\Checkin\Views\not_reviewing', [
            'title' => 'Not reviewing — ' . $slam['name'], 'slam' => $slam, 'min' => $this->minMissed(), 'isVolunteer' => $isVol,
            'readOnly' => in_array($slam['status'], ['closed', 'marks_calculated', 'ended'], true),
        ], $isVol ? 'volunteer' : 'admin');
    }

    private function minMissed(): int
    {
        $m = (int) ($this->request->getGet('min') ?: \Modules\Checkin\Services\EngagementService::DEFAULT_MIN_MISSED);

        return max(1, min(50, $m));
    }

    /** Force check-out everyone still "not reviewing" (or just the chosen ids). The list is recomputed on the server. */
    public function idleCheckout(): ResponseInterface
    {
        $in = $this->input();

        return $this->api(function () use ($in) {
            $slamId = $this->slamId();
            $only   = isset($in['ids']) && is_array($in['ids']) ? array_map('intval', $in['ids']) : null;
            $min    = max(1, min(50, (int) ($in['min'] ?? \Modules\Checkin\Services\EngagementService::DEFAULT_MIN_MISSED)));

            return service('engagement')->checkoutIdle($slamId, $min, $only, (string) ($in['reason'] ?? ''), $this->actor()) + ['ok' => true];
        });
    }

    /** The desk picks a waiting phone from the list: the person is checked in and exactly that phone gets the review screen. */
    public function approveRequest(): ResponseInterface
    {
        $in = $this->input();

        return $this->api(function () use ($in) {
            $slamId = $this->slamId();
            $id     = (int) ($in['request_id'] ?? 0);
            if ((int) Db::value('SELECT slam_id FROM late_checkin_requests WHERE id = ?', [$id]) !== $slamId) {
                throw new DomainException('not_found', 'That request is gone.', 404);
            }
            $r = service('checkins')->approveRequest($id, $this->actor());

            return ['ok' => true, 'attendee' => ['id' => (int) $r['attendee']['id'], 'name' => $r['attendee']['name'], 'badge_id' => $r['attendee']['badge_id'], 'user_type' => $r['attendee']['user_type']], 'code' => $r['code']];
        });
    }
}
