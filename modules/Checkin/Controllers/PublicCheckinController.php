<?php

declare(strict_types=1);

namespace Modules\Checkin\Controllers;

use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Modules\Attendees\Services\BadgeIdService;
use Modules\Core\Controllers\BaseController;
use Modules\Core\Libraries\DomainException;

/**
 * Attendee self check-in, in two steps:
 *   1. POST /checkin (badge ID from the QR landing page or typed): throttled like
 *      a login; shows the attendee's name and masked mobile to verify.
 *   2. POST /checkin/confirm: checks them in.
 * GET pages never reveal anyone's details or check anyone in (link previews, QR
 * apps and prefetching are harmless).
 */
class PublicCheckinController extends BaseController
{
    public function typeId(): string|RedirectResponse
    {
        if (is_array(session('reviewer')) && service('checkins')->validateSession(session('reviewer')) !== null) {
            return redirect()->to(site_url('review'));
        }

        return view('Modules\Checkin\Views\checkin', ['badge' => null, 'pending' => null]);
    }

    /** /in/{ID}: same generic page for any ID; shows "Check in as …" with the ID prefilled. */
    public function landing(string $raw): string
    {
        $id = BadgeIdService::fromScan($raw) ?? '';

        return view('Modules\Checkin\Views\checkin', ['badge' => $id, 'pending' => null]);
    }

    /** Pending verification lives in the session for a few minutes only. */
    private const PENDING_SECONDS = 300;

    /** Step 1: look the badge up and show who it belongs to. */
    public function verify(): RedirectResponse
    {
        $raw = (string) ($this->request->getPost('badge_id') ?? '');
        try {
            $who = service('checkins')->selfLookup($raw, $this->request->getIPAddress());
        } catch (DomainException $e) {
            return redirect()->to(site_url('checkin'))->withInput()->with('error', $e->getMessage());
        }
        session()->set('checkin_pending', $who + ['at' => time()]);

        return redirect()->to(site_url('checkin/confirm'));
    }

    /** The "Is this you?" card (PRG target of step 1). */
    public function confirmPage(): string|RedirectResponse
    {
        $p = session('checkin_pending');
        if (! is_array($p) || time() - (int) $p['at'] > self::PENDING_SECONDS) {
            session()->remove('checkin_pending');

            return redirect()->to(site_url('checkin'));
        }

        return view('Modules\Checkin\Views\checkin', ['badge' => null, 'pending' => $p]);
    }

    /** "Am I registered?": GET shows the form, POST the result (never cached). */
    public function registered(): string
    {
        $data = ['result' => null, 'mobile' => '', 'error' => null];
        if ($this->request->getMethod() === 'POST') {
            $data['mobile'] = (string) $this->request->getPost('mobile');
            try {
                $data['result'] = service('checkins')->registrationLookup($data['mobile']);
            } catch (DomainException $e) {
                $data['error'] = $e->getMessage();
            }
        }

        return view('Modules\Checkin\Views\registered', $data);
    }

    public function notMe(): RedirectResponse
    {
        session()->remove('checkin_pending');

        return redirect()->to(site_url('checkin'))->with('info', 'Please type the ID printed on your own badge, or visit the volunteer desk.');
    }

    /**
     * Step 2 (form, from the confirm card) or one-step JSON API (POST /api/checkin).
     */
    public function submit(): RedirectResponse|ResponseInterface
    {
        $wantsJson = str_contains($this->request->getHeaderLine('Accept'), 'application/json');
        $pending   = session('checkin_pending');
        if (! $wantsJson && (! is_array($pending) || time() - (int) $pending['at'] > self::PENDING_SECONDS)) {
            return redirect()->to(site_url('checkin'))->with('error', 'That took too long. Please scan your badge again.');
        }
        $raw = $wantsJson ? (string) ($this->input()['badge_id'] ?? '') : (string) $pending['badge_id'];
        session()->remove('checkin_pending');
        $current   = session('reviewer');
        try {
            $r = service('checkins')->selfCheckin($raw, $this->request->getIPAddress(), is_array($current) ? $current : null);
        } catch (DomainException $e) {
            if ($wantsJson) {
                return $this->jsonError($e);
            }
            if ($e->reason === 'gate_closed') {
                return $this->askTheDesk($raw); // after Gate Close: wait for the volunteer
            }

            return redirect()->to(site_url('checkin'))->withInput()->with('error', $e->getMessage());
        }
        $a       = $r['attendee'];
        $session = session();
        if (! $r['resumed']) {
            $session->regenerate(true);
        }
        $session->remove('auth');
        $session->set('reviewer', [
            'attendee_id' => (int) $a['id'], 'slam_id' => (int) $a['slam_id'], 'badge_id' => $a['badge_id'], 'name' => $a['name'],
            'user_type' => $a['user_type'], 'checkin_id' => $r['checkin_id'], 'token' => $r['token'],
        ]);
        if ($wantsJson) {
            return $this->json(['ok' => true, 'redirect' => site_url('review')]);
        }

        return redirect()->to(site_url('review'))->with('welcome', $r['resumed'] ? null : 'You are checked in for ' . $r['slam']['name'] . '.');
    }

    /** The attendee checks themself out (leaving early). */
    public function checkout(): ResponseInterface
    {
        $sess = session('reviewer');

        return $this->api(function () use ($sess) {
            service('checkins')->checkout((int) $sess['attendee_id'], 'manual', $this->actor());
            service('activityLog')->log($this->actor(), 'auth.logout', ['slam_id' => (int) $sess['slam_id']]);
            session()->destroy();

            return ['ok' => true, 'redirect' => site_url('checkin')];
        });
    }

    /** After Gate Close: this phone asks the desk to check the person in and waits on its own screen. */
    private function askTheDesk(string $rawId): RedirectResponse
    {
        try {
            $r = service('checkins')->requestLate($rawId, (int) (session('late_request')['id'] ?? 0) ?: null);
        } catch (DomainException $e) {
            return redirect()->to(site_url('checkin'))->with('error', $e->getMessage());
        }
        session()->set('late_request', [
            'id' => $r['id'], 'token' => $r['token'], 'code' => $r['code'], 'name' => $r['attendee']['name'],
            'mobile' => \Modules\Checkin\Services\CheckinService::maskMobile($r['attendee']['mobile']), 'slam_name' => $r['slam']['name'],
        ]);

        return redirect()->to(site_url('checkin/waiting'));
    }

    /** The intermediate screen: "a volunteer will check you in". */
    public function waitingPage(): string|RedirectResponse
    {
        $lr = session('late_request');
        if (! is_array($lr)) {
            return redirect()->to(site_url('checkin'));
        }

        return view('Modules\Checkin\Views\waiting', ['lr' => $lr]);
    }

    /** Polled by the waiting screen: when the volunteer has checked this person in, this phone gets the review screen. */
    public function waitingPoll(): ResponseInterface
    {
        $lr = session('late_request');
        if (! is_array($lr)) {
            return $this->json(['status' => 'none']);
        }
        $st = service('checkins')->waitingStatus((int) $lr['id'], (string) $lr['token']);
        if ($st['status'] !== 'approved') {
            return $this->json(['status' => $st['status']]);
        }
        $a       = $st['attendee'];
        $session = session();
        $session->regenerate(true);
        $session->remove(['auth', 'late_request', 'checkin_pending']);
        $session->set('reviewer', [
            'attendee_id' => (int) $a['id'], 'slam_id' => (int) $a['slam_id'], 'badge_id' => $a['badge_id'], 'name' => $a['name'],
            'user_type' => $a['user_type'], 'checkin_id' => $st['checkin_id'], 'token' => (string) $lr['token'],
        ]);

        return $this->json(['status' => 'approved', 'redirect' => site_url('review')]);
    }
}
