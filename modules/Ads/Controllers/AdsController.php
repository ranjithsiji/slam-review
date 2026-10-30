<?php

declare(strict_types=1);

namespace Modules\Ads\Controllers;

use CodeIgniter\HTTP\RedirectResponse;
use Modules\Core\Controllers\BaseController;
use Modules\Core\Libraries\Db;

/** Break ads (text or image), per slam: admin and that slam's operator. */
class AdsController extends BaseController
{
    public function index(int $slamId): string
    {
        $slam = service('slams')->find($slamId);
        $edit = (int) $this->request->getGet('edit');

        return $this->render('Modules\Ads\Views\index', [
            'title' => 'Break ads — ' . $slam['name'], 'slam' => $slam, 'ads' => service('ads')->all($slamId),
            'editing' => $edit ? Db::row('SELECT * FROM ads WHERE id = ? AND slam_id = ?', [$edit, $slamId]) : null,
        ]);
    }

    public function save(int $slamId): RedirectResponse
    {
        $id   = (int) $this->request->getPost('id') ?: null;
        $file = $this->request->getFile('image');
        $tmp  = $file !== null && $file->isValid() && $file->getSize() > 0 ? $file->getTempName() : null;

        return $this->formAction(function () use ($slamId, $id, $tmp) {
            service('ads')->save($slamId, $id, $this->input(), $tmp, $this->actor());

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
}
