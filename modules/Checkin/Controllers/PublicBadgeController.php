<?php

declare(strict_types=1);

namespace Modules\Checkin\Controllers;

use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Modules\Attendees\Services\BadgeIdService;
use Modules\Core\Controllers\BaseController;
use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\Db;

/**
 * "Get my badge": anyone who knows their unique ID can see their badge and download the QR code
 * (PNG or SVG) or the printable badge (PDF), for people who lost the printed one. The ID is already
 * the credential for check-in, so this reveals nothing new; it is rate-limited like the other lookups,
 * never cached or logged with the ID, and an unknown ID gets the same neutral answer as a wrong one.
 */
class PublicBadgeController extends BaseController
{
    private const NOT_FOUND = 'We could not find that ID. Check it against your registration message or ask at the volunteer desk.';

    public function form(): string
    {
        return view('Modules\Checkin\Views\badge', ['badge' => null, 'error' => null, 'typed' => '']);
    }

    public function lookup(): string|RedirectResponse
    {
        $id = BadgeIdService::fromScan((string) $this->request->getPost('badge_id'));
        if ($id === null || $this->find($id) === null) {
            service('activityLog')->log(Actor::anonymous(), 'badge.lookup_failed', ['outcome' => 'failure', 'reason' => 'unknown_id']);

            return view('Modules\Checkin\Views\badge', ['badge' => null, 'error' => self::NOT_FOUND, 'typed' => strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $this->request->getPost('badge_id')), 0, 7))]);
        }

        return redirect()->to(site_url('badge/' . $id));
    }

    public function show(string $id): string
    {
        $a = $this->find(strtoupper($id));
        if ($a === null) {
            service('activityLog')->log(Actor::anonymous(), 'badge.lookup_failed', ['outcome' => 'failure', 'reason' => 'unknown_id']);
            $this->response->setStatusCode(404);

            return view('Modules\Checkin\Views\badge', ['badge' => null, 'error' => self::NOT_FOUND, 'typed' => '']);
        }
        $qr = service('qr');
        service('activityLog')->log(Actor::anonymous(), 'badge.self_viewed', ['slam_id' => (int) $a['slam_id'], 'target_type' => 'attendee', 'target_id' => (int) $a['id']]);

        return view('Modules\Checkin\Views\badge', ['badge' => $a, 'qrSvg' => $qr->svg($qr->badgeUrl($a['badge_id'])), 'error' => null, 'typed' => '']);
    }

    /** /badge/{ID}.png | .svg | .pdf */
    public function file(string $id, string $format): ResponseInterface
    {
        $a = $this->find(strtoupper($id));
        if ($a === null) {
            service('activityLog')->log(Actor::anonymous(), 'badge.lookup_failed', ['outcome' => 'failure', 'reason' => 'unknown_id']);

            return $this->response->setStatusCode(404)->setBody('Not found.');
        }
        $qr   = service('qr');
        $url  = $qr->badgeUrl($a['badge_id']);
        $name = 'badge-' . $a['badge_id'];
        [$body, $mime, $file] = match ($format) {
            'svg'   => [$qr->svg($url), 'image/svg+xml', "qr-{$a['badge_id']}.svg"],
            'pdf'   => [service('badgePdf')->badges(service('slams')->find((int) $a['slam_id']), [['id' => $a['id'], 'name' => $a['name'], 'badge_id' => $a['badge_id'], 'user_type' => $a['user_type']]], 'a6', false), 'application/pdf', $name . '.pdf'],
            default => [$qr->png($url, 20), 'image/png', "qr-{$a['badge_id']}.png"],
        };
        service('activityLog')->log(Actor::anonymous(), 'badge.self_downloaded', ['slam_id' => (int) $a['slam_id'], 'target_type' => 'attendee', 'target_id' => (int) $a['id'], 'meta' => ['format' => $format]]);

        return $this->download($body, $file, $mime);
    }

    private function find(string $id): ?array
    {
        if (! BadgeIdService::isValidFormat($id)) {
            return null;
        }

        return Db::row('SELECT a.id, a.slam_id, a.name, a.badge_id, a.user_type, s.name AS slam_name, s.opens_at, s.venue_name, s.city
                          FROM attendees a JOIN slams s ON s.id = a.slam_id WHERE a.badge_id = ? AND a.is_active = 1 AND s.status <> \'draft\'', [$id]);
    }
}
