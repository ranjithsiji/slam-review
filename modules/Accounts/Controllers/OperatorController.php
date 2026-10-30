<?php

declare(strict_types=1);

namespace Modules\Accounts\Controllers;

use CodeIgniter\HTTP\RedirectResponse;
use Modules\Core\Controllers\BaseController;
use Modules\Core\Libraries\OperatorMode;

/** The slam operator's before-slam / slam-day switch. */
class OperatorController extends BaseController
{
    public function mode(): RedirectResponse
    {
        $slam = operator_slam();
        if ($slam === null) {
            return redirect()->to(site_url('admin'));
        }
        $mode = (string) $this->request->getPost('mode');
        $mode = isset(OperatorMode::LABELS[$mode]) ? $mode : OperatorMode::PREPARE;
        OperatorMode::set($mode);

        return redirect()->to(OperatorMode::homeUrl($slam, $mode));
    }
}
