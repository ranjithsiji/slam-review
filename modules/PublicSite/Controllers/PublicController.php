<?php

declare(strict_types=1);

namespace Modules\PublicSite\Controllers;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;
use Modules\Core\Controllers\BaseController;
use Modules\PublicSite\Services\PublicScheduleService;

/** Public schedule pages (no login). Unpublished slams are 404. */
class PublicController extends BaseController
{
    private PublicScheduleService $svc;

    public function __construct()
    {
        $this->svc = new PublicScheduleService();
    }

    private function published(string $slug): array
    {
        $slam = service('slams')->findBySlug($slug);
        if ($slam === null || (int) $slam['schedule_is_public'] !== 1 || $slam['status'] === 'draft') {
            throw PageNotFoundException::forPageNotFound();
        }

        return $slam;
    }

    public function index(): string
    {
        return view('Modules\PublicSite\Views\index', ['slams' => $this->svc->listPublished()]);
    }

    public function show(string $slug): string
    {
        $slam = $this->published($slug);
        service('slamPhase')->tick((int) $slam['id']);
        $slam = service('slams')->find((int) $slam['id']);

        return view('Modules\PublicSite\Views\show', ['slam' => $slam, 'doc' => $this->svc->document($slam), 'url' => site_url('slams/' . $slug)]);
    }

    /** Live block for the home page: every published slam that is running, with what is on now. */
    public function liveJson(): ResponseInterface
    {
        $doc  = ['slams' => $this->svc->live()];
        $etag = '"' . substr(md5(json_encode($doc)), 0, 16) . '"';
        $this->response->setHeader('ETag', $etag)->setHeader('Cache-Control', 'public, max-age=5');
        if (trim($this->request->getHeaderLine('If-None-Match')) === $etag) {
            return $this->response->setStatusCode(304);
        }

        return $this->json($doc);
    }

    public function scheduleJson(string $slug): ResponseInterface
    {
        $slam = $this->published($slug);
        service('slamPhase')->tick((int) $slam['id']);
        $doc  = $this->svc->document(service('slams')->find((int) $slam['id']));
        $this->response->setHeader('ETag', $doc['etag'])->setHeader('Cache-Control', 'public, max-age=5');
        if (trim($this->request->getHeaderLine('If-None-Match')) === $doc['etag']) {
            return $this->response->setStatusCode(304);
        }
        unset($doc['etag']);

        return $this->json($doc);
    }

    public function ics(string $slug): ResponseInterface
    {
        $slam = $this->published($slug);

        return $this->response->setHeader('Content-Type', 'text/calendar; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $slam['slug'] . '.ics"')
            ->setBody($this->svc->ics($slam, site_url('slams/' . $slug)));
    }
}
