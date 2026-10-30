<?php

declare(strict_types=1);

namespace Modules\Accounts\Controllers;

use CodeIgniter\HTTP\RedirectResponse;
use Modules\Core\Controllers\BaseController;
use Modules\Core\Libraries\DomainException;

class AuthController extends BaseController
{
    public function form(string $kind): string|RedirectResponse
    {
        $auth = session('auth');
        if (is_array($auth) && $auth['type'] === $kind) {
            return redirect()->to(site_url($kind === 'admin' ? 'admin' : 'volunteer'));
        }

        return view('Modules\Accounts\Views\login', ['kind' => $kind]);
    }

    public function login(string $kind): RedirectResponse
    {
        try {
            $auth = service('auth')->login($kind, (string) $this->request->getPost('username'), (string) $this->request->getPost('password'), $this->request->getIPAddress());
        } catch (DomainException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }
        $session = session();
        $session->regenerate(true);
        $session->remove('reviewer');
        $session->set('auth', $auth);

        return redirect()->to(site_url($kind === 'admin' ? 'admin' : 'volunteer'));
    }

    public function logout(): RedirectResponse
    {
        $auth = session('auth');
        if (is_array($auth)) {
            service('activityLog')->log($this->actor(), 'auth.logout', ['slam_id' => $auth['slam_id'] ?? null]);
        }
        session()->destroy();

        return redirect()->to(site_url(is_array($auth) && $auth['type'] === 'volunteer' ? 'volunteer/login' : 'admin/login'));
    }
}
