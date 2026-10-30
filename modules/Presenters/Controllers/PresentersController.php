<?php

declare(strict_types=1);

namespace Modules\Presenters\Controllers;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Modules\Core\Controllers\BaseController;
use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\Db;

class PresentersController extends BaseController
{
    private function uploadedPhoto(string $field = 'photo'): ?string
    {
        $f = $this->request->getFile($field);
        if ($f === null || $f->getError() === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (! $f->isValid()) {
            throw new \Modules\Core\Libraries\DomainException('upload', 'The photo upload failed: ' . $f->getErrorString());
        }

        return $f->getTempName();
    }

    public function all(): string
    {
        return $this->render('Modules\Presenters\Views\all', ['title' => 'Presenters']);
    }

    public function index(int $slamId): string
    {
        $slam = service('slams')->find($slamId);

        return $this->render('Modules\Presenters\Views\index', ['title' => 'Presenters — ' . $slam['name'], 'slam' => $slam]);
    }

    public function form(int $slamId): string
    {
        $slam  = service('slams')->find($slamId);
        $items = service('schedule')->items($slamId);
        $next  = service('schedule')->nextStart($slam, service('schedule')->normalise(service('schedule')->toDraft($items)));

        return $this->render('Modules\Presenters\Views\form', [
            'title' => 'Add presenter', 'slam' => $slam, 'nextStart' => $next,
            'pool' => service('presenters')->pool($slamId),
        ]);
    }

    public function create(int $slamId): RedirectResponse
    {
        $in = $this->input();
        if (! empty($in['planned_start_at'])) {
            $in['planned_start_at'] = Clock::fromIstLocal((string) $in['planned_start_at']);
        }

        return $this->formAction(function () use ($slamId, $in) {
            service('presenters')->addToSlam($slamId, $in, $this->actor(), $this->uploadedPhoto());

            return site_url("admin/slams/{$slamId}/presenters");
        }, 'Presenter added to the schedule.');
    }

    public function editPresenter(int $id): string
    {
        $p = Db::row('SELECT * FROM presenters WHERE id = ?', [$id]) ?? throw PageNotFoundException::forPageNotFound();

        return $this->render('Modules\Presenters\Views\presenter_form', [
            'title' => 'Edit presenter', 'p' => $p, 'back' => (string) $this->request->getGet('back'),
            'performances' => Db::rows('SELECT p.*, s.name AS slam_name FROM performances p JOIN slams s ON s.id = p.slam_id WHERE p.presenter_id = ?', [$id]),
        ]);
    }

    public function updatePresenter(int $id): RedirectResponse
    {
        return $this->formAction(function () use ($id) {
            service('presenters')->updatePresenter($id, $this->input(), $this->actor(), $this->uploadedPhoto());

            return site_url("admin/presenters/{$id}/edit");
        }, 'Presenter updated.');
    }

    public function removePhoto(int $id): RedirectResponse
    {
        return $this->formAction(function () use ($id) {
            service('presenters')->removePhoto($id, $this->actor());

            return site_url("admin/presenters/{$id}/edit");
        }, 'Photo removed.');
    }

    public function editPerformance(int $id): string
    {
        $perf = Db::row('SELECT p.*, pr.name AS presenter_name FROM performances p JOIN presenters pr ON pr.id = p.presenter_id WHERE p.id = ?', [$id])
            ?? throw PageNotFoundException::forPageNotFound();

        return $this->render('Modules\Presenters\Views\performance_form', [
            'title' => 'Edit performance', 'perf' => $perf, 'slam' => service('slams')->find((int) $perf['slam_id']),
            'reviews' => (int) Db::value('SELECT COUNT(*) FROM reviews r JOIN schedule_items si ON si.id = r.schedule_item_id WHERE si.performance_id = ?', [$id]),
        ]);
    }

    public function updatePerformance(int $id): RedirectResponse
    {
        $slamId = (int) Db::value('SELECT slam_id FROM performances WHERE id = ?', [$id]);

        return $this->formAction(function () use ($id, $slamId) {
            service('presenters')->updatePerformance($id, $this->input(), $this->actor());

            return site_url("admin/slams/{$slamId}/presenters");
        }, 'Performance updated.');
    }

    public function pool(int $slamId): ResponseInterface
    {
        return $this->api(fn () => ['data' => array_map(static fn ($r) => $r + ['thumb' => $r['photo_thumb_path'] ? '/' . $r['photo_thumb_path'] : null], service('presenters')->pool($slamId, (string) $this->request->getGet('q')))]);
    }

    /** Schedule maker "Add presenter": creates presenter + performance (the slot goes into the draft). */
    public function apiCreate(int $slamId): ResponseInterface
    {
        $in = $this->request->getPost() ?: $this->input();

        return $this->api(function () use ($slamId, $in) {
            $r = service('presenters')->addToSlam($slamId, $in, $this->actor(), $this->uploadedPhoto(), false);
            $p = Db::row('SELECT pr.name, pr.photo_thumb_path, p.topic FROM performances p JOIN presenters pr ON pr.id = p.presenter_id WHERE p.id = ?', [$r['performance_id']]);

            return $r + ['presenter_name' => $p['name'], 'topic' => $p['topic'], 'thumb' => $p['photo_thumb_path'] ? '/' . $p['photo_thumb_path'] : null];
        });
    }

    public function apiPhoto(int $id): ResponseInterface
    {
        return $this->api(function () use ($id) {
            $p = Db::row('SELECT * FROM presenters WHERE id = ?', [$id]) ?? throw new \Modules\Core\Libraries\DomainException('not_found', 'Presenter not found.', 404);
            service('presenters')->updatePresenter($id, $p, $this->actor(), $this->uploadedPhoto());
            $p = Db::row('SELECT photo_path, photo_thumb_path FROM presenters WHERE id = ?', [$id]);

            return ['photo' => '/' . $p['photo_path'], 'thumb' => '/' . $p['photo_thumb_path']];
        });
    }
}
