<?php

declare(strict_types=1);

namespace Modules\Ads\Controllers;

use CodeIgniter\HTTP\RedirectResponse;
use Modules\Core\Controllers\BaseController;

/**
 * Break ads (text or image). Per slam (admin and that slam's operator): the slam's own ads and which pool ads it shows.
 * The ad pool itself (/admin/ads) is for full admins.
 */
class AdsController extends BaseController
{
    public function index(int $slamId): string
    {
        $slam = service('slams')->find($slamId);
        $edit = (int) $this->request->getGet('edit');

        return $this->render('Modules\Ads\Views\index', [
            'title' => 'Break ads — ' . $slam['name'], 'slam' => $slam, 'ads' => service('ads')->all($slamId),
            'pool' => service('ads')->pool(), 'chosen' => service('ads')->chosenIds($slamId),
            'editing' => $edit ? service('ads')->find($slamId, $edit) : null,
            'canManagePool' => ! $this->actor()->isOperator(),
        ]);
    }

    public function save(int $slamId): RedirectResponse
    {
        $id = (int) $this->request->getPost('id') ?: null;

        return $this->formAction(function () use ($slamId, $id) {
            service('ads')->save($slamId, $id, $this->input(), $this->uploaded(), $this->actor());

            return site_url("admin/slams/{$slamId}/ads");
        }, $id ? 'Ad updated.' : 'Ad added.');
    }

    public function delete(int $slamId, int $id): RedirectResponse
    {
        return $this->formAction(function () use ($slamId, $id) {
            service('ads')->delete($slamId, $id, $this->actor());

            return site_url("admin/slams/{$slamId}/ads");
        }, 'Ad deleted.');
    }

    /** The pool ads this slam shows (ticked boxes, in pool order). */
    public function choosePool(int $slamId): RedirectResponse
    {
        return $this->formAction(function () use ($slamId) {
            service('ads')->choosePool($slamId, (array) ($this->request->getPost('pool') ?? []), $this->actor());

            return site_url("admin/slams/{$slamId}/ads");
        }, 'Pool ads for this slam saved.');
    }

    public function pool(): string
    {
        $edit = (int) $this->request->getGet('edit');

        return $this->render('Modules\Ads\Views\pool', [
            'title' => 'Ad pool', 'ads' => service('ads')->pool(),
            'editing' => $edit ? service('ads')->find(null, $edit) : null,
        ]);
    }

    public function savePool(): RedirectResponse
    {
        $id = (int) $this->request->getPost('id') ?: null;

        return $this->formAction(function () use ($id) {
            service('ads')->save(null, $id, $this->input(), $this->uploaded(), $this->actor());

            return site_url('admin/ads');
        }, $id ? 'Pool ad updated.' : 'Pool ad added.');
    }

    public function deletePool(int $id): RedirectResponse
    {
        return $this->formAction(function () use ($id) {
            service('ads')->delete(null, $id, $this->actor());

            return site_url('admin/ads');
        }, 'Pool ad deleted.');
    }

    private function uploaded(): ?string
    {
        $file = $this->request->getFile('image');

        return $file !== null && $file->isValid() && $file->getSize() > 0 ? $file->getTempName() : null;
    }
}
