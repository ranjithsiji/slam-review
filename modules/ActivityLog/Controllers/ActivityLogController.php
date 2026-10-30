<?php

declare(strict_types=1);

namespace Modules\ActivityLog\Controllers;

use Modules\Core\Controllers\BaseController;

/** Activity log viewer (DataTable with filters, detail drawer, live tail, CSV export). */
class ActivityLogController extends BaseController
{
    public function index(): string
    {
        $g = $this->request->getGet();

        return $this->render('Modules\ActivityLog\Views\index', [
            'title' => 'Activity log', 'slams' => service('slams')->all(),
            'preset' => array_intersect_key($g, array_flip(['slam_id', 'actor_type', 'actor_id', 'target_type', 'target_id', 'action', 'outcome', 'ip'])),
        ]);
    }

    public function volunteer(): string
    {
        $slam = service('slams')->find((int) session('auth')['slam_id']);

        return $this->render('Modules\ActivityLog\Views\volunteer', ['title' => 'Check-in activity', 'slam' => $slam], 'volunteer');
    }
}
