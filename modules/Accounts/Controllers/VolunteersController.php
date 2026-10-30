<?php

declare(strict_types=1);

namespace Modules\Accounts\Controllers;

use CodeIgniter\HTTP\RedirectResponse;
use Modules\Core\Controllers\BaseController;
use Modules\Core\Libraries\Db;

class VolunteersController extends BaseController
{
    public function index(): string
    {
        return $this->render('Modules\Accounts\Views\volunteers', ['title' => 'Volunteers', 'slams' => service('slams')->all()]);
    }

    public function form(?int $id = null): string
    {
        $vol = $id !== null ? Db::row('SELECT id, slam_id, username, name, is_active FROM volunteers WHERE id = ?', [$id]) : null;
        if ($id !== null && $vol === null) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        return $this->render('Modules\Accounts\Views\volunteer_form', [
            'title' => $vol ? 'Edit volunteer' : 'New volunteer', 'vol' => $vol, 'slams' => service('slams')->all(),
            'preselect' => (int) $this->request->getGet('slam'),
        ]);
    }

    public function save(?int $id = null): RedirectResponse
    {
        return $this->formAction(
            fn () => site_url('admin/volunteers') . '?saved=' . service('auth')->saveVolunteer($id, $this->input(), $this->actor()),
            $id ? 'Volunteer updated.' : 'Volunteer created.',
        );
    }
}
