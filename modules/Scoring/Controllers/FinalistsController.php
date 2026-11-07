<?php

declare(strict_types=1);

namespace Modules\Scoring\Controllers;

use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Modules\Core\Controllers\BaseController;
use Modules\Scoring\Services\FinalistsService;

/** Finalists: choose the presenters going to the final; names only, alphabetical, no positions. */
class FinalistsController extends BaseController
{
    private function svc(): FinalistsService
    {
        return new FinalistsService();
    }

    public function index(int $slamId): string
    {
        $slam = service('slams')->find($slamId);

        return $this->render('Modules\Scoring\Views\finalists', [
            'title' => 'Finalists — ' . $slam['name'], 'slam' => $slam, 'rows' => $this->svc()->candidates($slamId),
            'count' => FinalistsService::COUNT, 'hasMarks' => service('markRuns')->currentRun($slamId) !== null,
        ]);
    }

    public function save(int $slamId): RedirectResponse
    {
        return $this->formAction(function () use ($slamId) {
            $this->svc()->save($slamId, (array) ($this->request->getPost('finalists') ?? []), $this->actor(), (string) $this->request->getPost('note'));

            return site_url("admin/slams/{$slamId}/finalists");
        }, 'Finalists saved.');
    }

    /** One poster of the finalists: photo and name each, the Science Slam logo in the corner. Drawn in the browser. */
    public function poster(int $slamId): string
    {
        $slam = service('slams')->find($slamId);
        $people = array_map(static fn ($r) => [
            'name' => $r['name'], 'designation' => (string) $r['designation'],
            'photo' => $r['photo_path'] !== null ? base_url($r['photo_path']) : null,
        ], $this->svc()->chosen($slamId));

        return $this->render('Modules\Scoring\Views\finalists_poster', ['title' => 'Finalists poster — ' . $slam['name'], 'slam' => $slam, 'people' => $people]);
    }

    public function sheet(int $slamId): ResponseInterface
    {
        $slam = service('slams')->find($slamId);
        $rows = $this->svc()->chosen($slamId);
        if ($rows === []) {
            throw new \CodeIgniter\Exceptions\PageNotFoundException('No finalists chosen yet.');
        }
        service('activityLog')->log($this->actor(), 'results.finalists_printed', ['slam_id' => $slamId]);
        $top = array_map(static fn ($r) => ['presenter_name' => $r['name'], 'designation' => $r['designation'], 'topic' => $r['topic'], 'final_mark' => 0.0], $rows);

        return $this->response->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'inline; filename="finalists-' . $slam['slug'] . '.pdf"')->setHeader('Cache-Control', 'no-store')
            ->setBody(service('resultsPdf')->finalistsSheet($slam, $top));
    }
}
