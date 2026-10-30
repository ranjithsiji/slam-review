<?php

declare(strict_types=1);

namespace Modules\Scoring\Controllers;

use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Modules\Core\Controllers\BaseController;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;

/** Marks written on paper, entered by hand or imported from a CSV. */
class PaperMarksController extends BaseController
{
    public function index(int $slamId): string
    {
        $slam = service('slams')->find($slamId);
        $items = [];
        foreach (service('scoring')->openedItemIds($slamId) as $itemId) {
            $r = Db::row('SELECT si.id, pr.name, p.topic, (SELECT COUNT(*) FROM reviews rv WHERE rv.schedule_item_id = si.id AND rv.entered_by IS NOT NULL) AS paper FROM schedule_items si JOIN performances p ON p.id = si.performance_id JOIN presenters pr ON pr.id = p.presenter_id WHERE si.id = ?', [$itemId]);
            if ($r !== null) {
                $items[] = $r;
            }
        }

        return $this->render('Modules\Scoring\Views\paper_marks', [
            'title' => 'Paper marks — ' . $slam['name'], 'slam' => $slam, 'items' => $items, 'questions' => service('questions')->active($slamId, 'user'),
            'judging' => service('scoring')->judging($slamId), 'open' => in_array($slam['status'], \Modules\Scoring\Services\PaperMarksService::PHASES, true),
            'summary' => session('paper_summary'), 'rowErrors' => session('paper_errors') ?? [],
        ]);
    }

    public function saveAudience(int $slamId): RedirectResponse
    {
        $in = $this->input();
        try {
            $rows = service('paperMarks')->parseLines((string) ($in['lines'] ?? ''));
            if ($rows === []) {
                throw new DomainException('validation', 'Type at least one line: badge ID and the marks.', 422);
            }
            $r = service('paperMarks')->saveAudience($slamId, (int) ($in['item_id'] ?? 0), $rows, $this->actor());
        } catch (DomainException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage())->with('paper_errors', $e->details['rows'] ?? []);
        }

        return redirect()->to(site_url("admin/slams/{$slamId}/paper-marks?item=" . (int) ($in['item_id'] ?? 0)))
            ->with('success', "Saved: {$r['created']} new, {$r['updated']} changed, {$r['unchanged']} unchanged.");
    }

    public function import(int $slamId): RedirectResponse
    {
        $file = $this->request->getFile('file');
        try {
            if ($file === null || ! $file->isValid()) {
                throw new DomainException('validation', 'Choose a CSV file.', 422);
            }
            $r = service('paperMarks')->importCsv($slamId, (string) $this->request->getPost('kind'), $file->getTempName(), $this->actor());
        } catch (DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage())->with('paper_errors', $e->details['rows'] ?? []);
        }

        return redirect()->to(site_url("admin/slams/{$slamId}/paper-marks"))->with('success', "Imported {$r['rows']} rows: {$r['created']} entered, {$r['updated']} changed.");
    }

    public function template(int $slamId, string $kind): ResponseInterface
    {
        return $this->download(service('paperMarks')->template($kind, $slamId), "paper-marks-{$kind}-template.csv", 'text/csv; charset=utf-8');
    }
}
