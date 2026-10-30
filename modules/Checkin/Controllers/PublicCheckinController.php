<?php

declare(strict_types=1);

namespace Modules\Checkin\Controllers;

use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Modules\Attendees\Services\BadgeIdService;
use Modules\Core\Controllers\BaseController;
use Modules\Core\Libraries\DomainException;

/**
 * Attendee check-in. GET pages never check anyone in (link previews, QR apps and
 * prefetching are harmless); only the POST from the big button does.
 */
class PublicCheckinController extends BaseController
{
    public function typeId(): string|RedirectResponse
    {
        if (is_array(session('reviewer')) && service('checkins')->validateSession(session('reviewer')) !== null) {
            return redirect()->to(site_url('review'));
        }

        return view('Modules\Checkin\Views\checkin', ['badge' => null]);
    }

    /** /in/{ID}: same generic page for any ID; shows "Check in as …" with the ID prefilled. */
    public function landing(string $raw): string
    {
        $id = BadgeIdService::fromScan($raw) ?? '';

        return view('Modules\Checkin\Views\checkin', ['badge' => $id]);
    }

    public function submit(): RedirectResponse|ResponseInterface
    {
        $wantsJson = str_contains($this->request->getHeaderLine('Accept'), 'application/json');
        $raw       = (string) ($this->request->getPost('badge_id') ?? ($this->input()['badge_id'] ?? ''));
        $current   = session('reviewer');
        try {
            $r = service('checkins')->selfCheckin($raw, $this->request->getIPAddress(), is_array($current) ? $current : null);
        } catch (DomainException $e) {
            if ($wantsJson) {
                return $this->jsonError($e);
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
}
