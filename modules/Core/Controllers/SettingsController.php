<?php

declare(strict_types=1);

namespace Modules\Core\Controllers;

use CodeIgniter\HTTP\RedirectResponse;
use DateTimeZone;
use Modules\Core\Libraries\Clock;

/** Admin → Settings: display timezone, 12/24-hour clock, and the server clock. */
class SettingsController extends BaseController
{
    public function index(): string
    {
        $zones = [];
        foreach (DateTimeZone::listIdentifiers() as $z) {
            $zones[str_contains($z, '/') ? strstr($z, '/', true) : 'Other'][] = $z;
        }

        return $this->render('Modules\Core\Views\settings', [
            'title' => 'Settings', 'settings' => service('appSettings')->all(), 'zones' => $zones,
            'serverNow' => Clock::iso(Clock::db()), 'phpZone' => date_default_timezone_get(),
        ]);
    }

    public function save(): RedirectResponse
    {
        return $this->formAction(function () {
            service('appSettings')->update($this->input(), $this->actor());

            return site_url('admin/settings');
        }, 'Settings saved. All times are now entered and shown in the new timezone.');
    }
}
