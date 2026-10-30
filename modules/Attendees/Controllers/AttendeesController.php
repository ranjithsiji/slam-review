<?php

declare(strict_types=1);

namespace Modules\Attendees\Controllers;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Modules\Attendees\Services\AttendeeService;
use Modules\Attendees\Services\ImportService;
use Modules\Core\Controllers\BaseController;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;

class AttendeesController extends BaseController
{
    public function index(int $slamId): string
    {
        $slam = service('slams')->find($slamId);

        return $this->render('Modules\Attendees\Views\index', [
            'title' => 'Attendees — ' . $slam['name'], 'slam' => $slam,
            'batches' => Db::rows("SELECT id, filename, created_at FROM import_batches WHERE slam_id = ? AND status = 'committed' ORDER BY id DESC", [$slamId]),
        ]);
    }

    /** Where to return after saving: the screen the organiser came from. */
    private function backUrl(int $slamId): string
    {
        $back = (string) ($this->request->getPost('back') ?? $this->request->getGet('back') ?? 'attendees');

        return site_url("admin/slams/{$slamId}/" . (in_array($back, ['reviewers', 'judges', 'attendees'], true) ? $back : 'attendees'));
    }

    public function form(int $slamId): string
    {
        $type = $this->request->getGet('type');

        return $this->render('Modules\Attendees\Views\form', [
            'title' => $type === 'judge' ? 'Add judge' : ($type === 'user' ? 'Add reviewer' : 'Add attendee'), 'slam' => service('slams')->find($slamId), 'a' => null,
            'defaultType' => $type === 'judge' ? 'judge' : 'user', 'back' => (string) ($this->request->getGet('back') ?? 'attendees'),
        ]);
    }

    public function create(int $slamId): RedirectResponse
    {
        return $this->formAction(function () use ($slamId) {
            service('attendees')->create($slamId, $this->input(), $this->actor());

            return $this->backUrl($slamId);
        }, 'Attendee registered and badge ID generated.');
    }

    public function edit(int $id): string
    {
        $a = Db::row('SELECT * FROM attendees WHERE id = ?', [$id]) ?? throw PageNotFoundException::forPageNotFound();

        return $this->render('Modules\Attendees\Views\form', [
            'title' => 'Edit attendee', 'slam' => service('slams')->find((int) $a['slam_id']), 'a' => $a,
            'status' => service('checkins')->status($id), 'qrUrl' => service('qr')->badgeUrl($a['badge_id']),
            'defaultType' => $a['user_type'], 'back' => (string) ($this->request->getGet('back') ?? 'attendees'),
        ]);
    }

    public function update(int $id): RedirectResponse
    {
        $slamId = (int) Db::value('SELECT slam_id FROM attendees WHERE id = ?', [$id]);

        return $this->formAction(function () use ($id, $slamId) {
            service('attendees')->update($id, $this->input(), $this->actor());

            return $this->backUrl($slamId);
        }, 'Attendee updated.');
    }

    public function reissue(int $id): RedirectResponse
    {
        return $this->formAction(function () use ($id) {
            service('attendees')->reissueId($id, (string) $this->request->getPost('reason'), $this->actor());

            return site_url("admin/attendees/{$id}/edit");
        }, 'A new badge ID was issued. The old ID no longer works; print a new badge.');
    }

    public function importForm(int $slamId): string
    {
        return $this->render('Modules\Attendees\Views\import', ['title' => 'Import attendees', 'slam' => service('slams')->find($slamId)]);
    }

    public function template(): ResponseInterface
    {
        return $this->download("\xEF\xBB\xBF" . ImportService::template(), 'attendees-template.csv', 'text/csv; charset=utf-8');
    }

    public function preview(): RedirectResponse
    {
        $slamId = (int) $this->request->getPost('slam_id');
        $file   = $this->request->getFile('file');

        return $this->formAction(function () use ($slamId, $file) {
            if ($file === null || ! $file->isValid()) {
                throw new DomainException('upload', 'Choose a CSV file to upload.');
            }
            $r = service('importer')->preview($slamId, $file->getTempName(), $file->getClientName(), (string) $this->request->getPost('mode'), $this->actor());

            return site_url('admin/attendees/import/' . $r['batch_id']);
        }, 'File checked. Review the preview, then confirm the import.');
    }

    public function previewPage(int $batchId): string
    {
        $b = Db::row('SELECT * FROM import_batches WHERE id = ?', [$batchId]) ?? throw PageNotFoundException::forPageNotFound();

        return $this->render('Modules\Attendees\Views\import_preview', [
            'title' => 'Import preview', 'batch' => $b, 'slam' => service('slams')->find((int) $b['slam_id']),
            'counts' => array_column(Db::rows('SELECT status, COUNT(*) n FROM import_batch_rows WHERE batch_id = ? GROUP BY status', [$batchId]), 'n', 'status'),
            'duplicateOf' => Db::value("SELECT id FROM import_batches WHERE slam_id = ? AND file_sha256 = ? AND status = 'committed' AND id <> ? LIMIT 1", [$b['slam_id'], $b['file_sha256'], $batchId]),
            'checkedInWarn' => $b['mode'] === 'update_existing' ? (int) Db::value(
                "SELECT COUNT(*) FROM import_batch_rows r JOIN attendees a ON a.slam_id = ? AND a.mobile = r.mobile JOIN checkins c ON c.attendee_id = a.id AND c.checked_out_at IS NULL WHERE r.batch_id = ? AND r.status = 'duplicate'",
                [$b['slam_id'], $batchId],
            ) : 0,
        ]);
    }

    public function commit(int $batchId): RedirectResponse
    {
        $slamId = (int) Db::value('SELECT slam_id FROM import_batches WHERE id = ?', [$batchId]);
        try {
            $r = service('importer')->commit($batchId, $this->actor(), (bool) $this->request->getPost('confirm_reimport'));
        } catch (DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url("admin/slams/{$slamId}/attendees"))->with('success', sprintf(
            'Import done: %d created, %d updated, %d skipped, %d invalid rows not imported.',
            $r['created'], $r['updated'], $r['skipped'], $r['failed'],
        ));
    }

    public function cancel(int $batchId): RedirectResponse
    {
        $slamId = (int) Db::value('SELECT slam_id FROM import_batches WHERE id = ?', [$batchId]);
        service('importer')->cancel($batchId, $this->actor());

        return redirect()->to(site_url("admin/slams/{$slamId}/attendees/import"))->with('success', 'Import cancelled. Nothing was saved.');
    }

    public function errors(int $batchId): ResponseInterface
    {
        return $this->download(service('importer')->errorsCsv($batchId), "import-{$batchId}-errors.csv", 'text/csv; charset=utf-8');
    }

    public function deactivateBatch(int $batchId): ResponseInterface
    {
        return $this->api(fn () => ['ok' => true, 'deactivated' => service('attendees')->deactivateBatch($batchId, $this->actor())]);
    }

    /** Badge printing export: name, mobile, type, badge ID, check-in URL. */
    public function export(int $slamId): ResponseInterface
    {
        $slam = service('slams')->find($slamId);
        $qr   = service('qr');
        $where = 'slam_id = ? AND is_active = 1';
        $binds = [$slamId];
        if ($b = (int) $this->request->getGet('batch')) {
            $where  .= ' AND import_batch_id = ?';
            $binds[] = $b;
        }
        $rows = Db::rows("SELECT id, name, mobile, email, user_type, badge_id FROM attendees WHERE {$where} ORDER BY name", $binds);
        $fh   = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['name', 'mobile', 'email', 'type', 'badge_id', 'checkin_url'], ',', '"', '');
        foreach ($rows as $r) {
            fputcsv($fh, array_map([AttendeeService::class, 'csvSafe'], [$r['name'], $r['mobile'], (string) $r['email'], $r['user_type'], $r['badge_id'], $qr->badgeUrl($r['badge_id'])]), ',', '"', '');
        }
        rewind($fh);
        service('attendees')->markPrinted(array_map(static fn ($r) => (int) $r['id'], $rows));
        service('activityLog')->log($this->actor(), 'badge.exported', ['slam_id' => $slamId, 'meta' => ['format' => 'csv', 'count' => count($rows)]]);

        return $this->download((string) stream_get_contents($fh), 'badges-' . $slam['slug'] . '.csv', 'text/csv; charset=utf-8');
    }
}
