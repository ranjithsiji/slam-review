<?php

declare(strict_types=1);

namespace Modules\Accounts\Controllers;

use CodeIgniter\HTTP\RedirectResponse;
use Modules\Core\Controllers\BaseController;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;

/** The signed-in administrator's own profile and password. */
class AccountController extends BaseController
{
    public function show(): string
    {
        $row = Db::row('SELECT id, username, name, email, phone, last_login_at FROM admins WHERE id = ?', [$this->actor()->id]);

        return $this->render('Modules\Accounts\Views\account', ['title' => 'My account', 'row' => $row, 'breadcrumbs' => [['My account', null]]]);
    }

    public function profile(): RedirectResponse
    {
        return $this->formAction(function () {
            service('auth')->updateProfile((int) $this->actor()->id, $this->input(), $this->actor());

            return site_url('admin/account');
        }, 'Your details were saved.');
    }

    public function password(): RedirectResponse
    {
        try {
            $in    = $this->input();
            $stamp = service('auth')->changeOwnPassword((int) $this->actor()->id, (string) ($in['current'] ?? ''), (string) ($in['password'] ?? ''), (string) ($in['confirm'] ?? ''), $this->actor());
        } catch (DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage())->with('errors', $e->details);
        }
        $auth        = session('auth');
        $auth['pwd'] = $stamp;
        session()->set('auth', $auth);

        return redirect()->to(site_url('admin/account'))->with('success', 'Password changed. Other devices were signed out.');
    }
}
