<?php

declare(strict_types=1);

namespace Modules\Accounts\Controllers;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use Modules\Core\Controllers\BaseController;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;

/** Administrator accounts: create, edit, deactivate, reset password. */
class AdminsController extends BaseController
{
    public function index(): string
    {
        return $this->render('Modules\Accounts\Views\admins', [
            'title' => 'Administrators', 'breadcrumbs' => [['Administrators', null]],
        ]);
    }

    public function form(?int $id = null): string
    {
        $row = $id !== null ? Db::row('SELECT id, username, name, role, slam_id, email, phone, is_active, last_login_at, password_changed_at FROM admins WHERE id = ?', [$id]) : null;
        if ($id !== null && $row === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->render('Modules\Accounts\Views\admin_form', [
            'title' => $row ? 'Edit administrator' : 'New administrator', 'row' => $row, 'slams' => service('slams')->all(), 'preselect' => (int) $this->request->getGet('slam'), 'asOperator' => $this->request->getGet('role') === 'operator', 'isSelf' => $row && (int) $row['id'] === $this->actor()->id,
            'breadcrumbs' => [['Administrators', site_url('admin/users')], [$row ? $row['name'] : 'New administrator', null]],
        ]);
    }

    public function save(?int $id = null): RedirectResponse
    {
        return $this->formAction(
            function () use ($id) {
                $saved = service('auth')->saveAdmin($id, $this->input(), $this->actor());

                return site_url($id ? "admin/users/{$saved}/edit" : 'admin/users');
            },
            $id ? 'Administrator updated.' : 'Administrator created.',
        );
    }

    /** Blank password = generate one; the result is shown once on the edit page. */
    public function reset(int $id): RedirectResponse
    {
        try {
            $new = service('auth')->resetPassword('admin', $id, (string) $this->request->getPost('password'), $this->actor());
        } catch (DomainException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage())->with('errors', $e->details);
        }
        $own = $id === $this->actor()->id;
        if ($own) {
            // Keep the person who just reset their own password signed in.
            $auth        = session('auth');
            $auth['pwd'] = (string) Db::value('SELECT password_changed_at FROM admins WHERE id = ?', [$id]);
            session()->set('auth', $auth);
        }

        return redirect()->to(site_url("admin/users/{$id}/edit"))->with('success', 'Password reset. Other sessions of this account were signed out.')->with('new_password', $new);
    }
}
