<?php

declare(strict_types=1);

namespace Modules\Slams\Controllers;

use CodeIgniter\HTTP\RedirectResponse;
use Modules\Core\Controllers\BaseController;
use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\Db;

class SlamsController extends BaseController
{
    public function dashboard(): string
    {
        $slams = service('slams')->all();
        foreach ($slams as &$s) {
            $s['counts'] = Db::row(
                'SELECT (SELECT COUNT(*) FROM attendees WHERE slam_id = ? AND is_active = 1) AS attendees,
                        (SELECT COUNT(*) FROM checkins c JOIN attendees a ON a.id = c.attendee_id WHERE a.slam_id = ? AND c.checked_out_at IS NULL) AS checked_in,
                        (SELECT COUNT(*) FROM performances WHERE slam_id = ? AND is_active = 1) AS performances',
                [$s['id'], $s['id'], $s['id']],
            );
        }
        unset($s);

        return $this->render('Modules\Slams\Views\dashboard', ['title' => 'Dashboard', 'slams' => $slams]);
    }

    public function index(): string
    {
        return $this->render('Modules\Slams\Views\index', ['title' => 'Slams']);
    }

    public function show(int $id): string
    {
        service('slamPhase')->tick($id, true);
        $slam = service('slams')->find($id);

        return $this->render('Modules\Slams\Views\show', [
            'title' => $slam['name'], 'slam' => $slam, 'readiness' => service('slams')->readiness($id),
            'counts' => Db::row(
                "SELECT (SELECT COUNT(*) FROM attendees WHERE slam_id = ? AND is_active = 1 AND user_type = 'user') AS users,
                        (SELECT COUNT(*) FROM attendees WHERE slam_id = ? AND is_active = 1 AND user_type = 'judge') AS judges,
                        (SELECT COUNT(*) FROM checkins c JOIN attendees a ON a.id = c.attendee_id WHERE a.slam_id = ? AND c.checked_out_at IS NULL) AS checked_in,
                        (SELECT COUNT(*) FROM performances WHERE slam_id = ? AND is_active = 1) AS performances,
                        (SELECT COUNT(*) FROM reviews r JOIN schedule_items si ON si.id = r.schedule_item_id WHERE si.slam_id = ?) AS reviews",
                [$id, $id, $id, $id, $id],
            ),
            'publicUrl' => site_url('slams/' . $slam['slug']),
            // Presenters still in the pool (no schedule slot yet).
            'unscheduled' => (int) Db::value("SELECT COUNT(*) FROM performances p WHERE p.slam_id = ? AND p.is_active = 1 AND NOT EXISTS (SELECT 1 FROM schedule_items si WHERE si.performance_id = p.id AND si.status <> 'skipped')", [$id]),
        ]);
    }

    public function form(?int $id = null): string
    {
        $slam = $id !== null ? service('slams')->find($id) : null;

        return $this->render('Modules\Slams\Views\form', ['title' => $slam ? 'Edit slam' : 'New slam', 'slam' => $slam]);
    }

    public function save(?int $id = null): RedirectResponse
    {
        $in = $this->input();
        // The form takes times in the display timezone; convert to UTC.
        foreach (\Modules\Slams\Services\SlamService::TIME_FIELDS as $f) {
            if (array_key_exists($f, $in)) {
                $in[$f] = Clock::fromLocalInput((string) $in[$f]);
            }
        }

        return $this->formAction(function () use ($id, $in) {
            if ($id === null) {
                $id = service('slams')->create($in, $this->actor());
            } else {
                service('slams')->update($id, $in, $this->actor());
            }

            return site_url('admin/slams/' . $id);
        }, $id ? 'Slam updated.' : 'Slam created. Next: add presenters, questions and attendees.');
    }

    public function publish(int $id): RedirectResponse
    {
        $on = (bool) $this->request->getPost('public');

        return $this->formAction(function () use ($id, $on) {
            service('slams')->setPublished($id, $on, $this->actor());

            return site_url('admin/slams/' . $id);
        }, $on ? 'The public schedule is now published.' : 'The public schedule is now hidden.');
    }
}
