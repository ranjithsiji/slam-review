<?php

declare(strict_types=1);

namespace Modules\Accounts\Controllers;

use CodeIgniter\HTTP\RedirectResponse;
use Modules\Core\Controllers\BaseController;
use Modules\Core\Libraries\DomainException;

/** Admins see which networks are locked out of check-in (a shared venue Wi-Fi can be) and unlock them. */
class LockoutsController extends BaseController
{
    public function index(): string
    {
        return $this->render('Modules\Accounts\Views\lockouts', [
            'title' => 'Lockouts', 'networks' => service('throttle')->networks(), 'myIp' => $this->request->getIPAddress(),
            'cfg' => config(\Modules\Core\Config\Slam::class),
        ]);
    }

    public function unlock(): RedirectResponse
    {
        return $this->formAction(function () {
            $ip = trim((string) $this->request->getPost('ip'));
            if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                throw new DomainException('validation', 'That is not a network address.');
            }
            service('throttle')->unlockNetwork($ip, $this->actor(), (string) $this->request->getPost('reason'));

            return site_url('admin/lockouts');
        }, 'Network unlocked. People on it can check in again.');
    }
}
