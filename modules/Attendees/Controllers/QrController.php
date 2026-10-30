<?php

declare(strict_types=1);

namespace Modules\Attendees\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use Modules\Attendees\Services\BadgePdfService;
use Modules\Core\Controllers\BaseController;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;

/** QR code generator, badge PDFs and the entrance poster. Files are streamed, never stored. */
class QrController extends BaseController
{
    public function index(): string
    {
        $slams  = service('slams')->all();
        $slamId = (int) ($this->request->getGet('slam') ?: ($slams[0]['id'] ?? 0));

        return $this->render('Modules\Attendees\Views\qr', [
            'title' => 'QR codes & badges', 'slams' => $slams, 'slamId' => $slamId,
            'batches' => $slamId ? Db::rows("SELECT id, filename, created_at FROM import_batches WHERE slam_id = ? AND status = 'committed' ORDER BY id DESC", [$slamId]) : [],
            'presets' => BadgePdfService::PRESETS, 'typeUrl' => service('qr')->typeIdUrl(),
            'publicUrl' => $slamId ? site_url('slams/' . (service('slams')->find($slamId)['slug'])) : null,
        ]);
    }

    private function attendee(int $id): array
    {
        return Db::row('SELECT * FROM attendees WHERE id = ?', [$id]) ?? throw new DomainException('not_found', 'Attendee not found.', 404);
    }

    public function svg(int $id): ResponseInterface
    {
        $a = $this->attendee($id);
        service('activityLog')->log($this->actor(), 'qr.downloaded', ['slam_id' => (int) $a['slam_id'], 'target_type' => 'attendee', 'target_id' => $id, 'meta' => ['format' => 'svg']]);
        service('attendees')->markPrinted([$id]);
        $svg = service('qr')->svg(service('qr')->badgeUrl($a['badge_id']));
        if ($this->request->getGet('inline')) {
            return $this->response->setHeader('Content-Type', 'image/svg+xml')->setHeader('Cache-Control', 'no-store')->setBody($svg);
        }

        return $this->download($svg, 'qr-' . $a['badge_id'] . '.svg', 'image/svg+xml');
    }

    public function png(int $id): ResponseInterface
    {
        $a = $this->attendee($id);
        service('activityLog')->log($this->actor(), 'qr.downloaded', ['slam_id' => (int) $a['slam_id'], 'target_type' => 'attendee', 'target_id' => $id, 'meta' => ['format' => 'png']]);
        service('attendees')->markPrinted([$id]);

        return $this->download(service('qr')->png(service('qr')->badgeUrl($a['badge_id']), 20), 'qr-' . $a['badge_id'] . '.png', 'image/png');
    }

    public function batch(): ResponseInterface
    {
        $slamId = (int) $this->request->getPost('slam_id');
        $slam   = service('slams')->find($slamId);
        $where  = ['slam_id = ?', 'is_active = 1'];
        $binds  = [$slamId];
        $filter = (string) $this->request->getPost('filter');
        switch ($filter) {
            case 'user':
            case 'judge':
                $where[] = 'user_type = ?';
                $binds[] = $filter;
                break;
            case 'not_printed':
                $where[] = 'badge_print_count = 0';
                break;
            case 'batch':
                $where[] = 'import_batch_id = ?';
                $binds[] = (int) $this->request->getPost('batch_id');
                break;
            case 'selected':
                $ids = array_values(array_filter(array_map('intval', explode(',', (string) $this->request->getPost('ids')))));
                if ($ids === []) {
                    return redirect()->back()->with('error', 'Pick at least one attendee.');
                }
                $where[] = 'id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
                $binds   = [...$binds, ...$ids];
                break;
        }
        $rows = Db::rows('SELECT id, name, badge_id, user_type FROM attendees WHERE ' . implode(' AND ', $where) . ' ORDER BY user_type DESC, name', $binds);
        if ($rows === []) {
            return redirect()->back()->with('error', 'No attendees match this selection.');
        }
        $pdf = service('badgePdf')->badges($slam, $rows, (string) $this->request->getPost('preset'), (bool) $this->request->getPost('cut_marks'));
        service('attendees')->markPrinted(array_map(static fn ($r) => (int) $r['id'], $rows));
        service('activityLog')->log($this->actor(), 'badge.exported', ['slam_id' => $slamId, 'meta' => ['format' => 'pdf', 'count' => count($rows), 'filter' => $filter]]);

        return $this->download($pdf, 'badges-' . $slam['slug'] . '.pdf', 'application/pdf');
    }

    public function entrance(): ResponseInterface
    {
        $slamId = (int) $this->request->getGet('slam');
        $slam   = $slamId ? service('slams')->find($slamId) : null;
        $public = $slam !== null && (int) $slam['schedule_is_public'] === 1 ? site_url('slams/' . $slam['slug']) : null;
        $pdf    = service('badgePdf')->entrancePoster($slam, (string) $this->request->getGet('format'), $public);
        service('activityLog')->log($this->actor(), 'qr.generated', ['slam_id' => $slamId ?: null, 'meta' => ['what' => 'entrance_poster']]);

        return $this->download($pdf, 'checkin-poster.pdf', 'application/pdf');
    }

    public function publicSvg(int $slamId): ResponseInterface
    {
        $slam = service('slams')->find($slamId);

        return $this->response->setHeader('Content-Type', 'image/svg+xml')->setBody(service('qr')->svg(site_url('slams/' . $slam['slug'])));
    }

    /** Single-attendee search for the QR screen (name, mobile or ID). */
    public function lookup(): ResponseInterface
    {
        $slamId = (int) $this->request->getGet('slam');
        $q      = trim((string) $this->request->getGet('q'));

        return $this->api(function () use ($slamId, $q) {
            if (mb_strlen($q) < 2) {
                return ['data' => []];
            }
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            $rows = Db::rows(
                'SELECT id, name, badge_id, user_type, badge_print_count FROM attendees WHERE slam_id = ? AND (name LIKE ? OR mobile LIKE ? OR badge_id = ?) ORDER BY name LIMIT 20',
                [$slamId, $like, $like, strtoupper($q)],
            );

            return ['data' => array_map(fn ($r) => $r + ['url' => service('qr')->badgeUrl($r['badge_id'])], $rows)];
        });
    }
}
