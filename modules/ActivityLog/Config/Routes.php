<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('', ['namespace' => 'Modules\ActivityLog\Controllers'], static function ($routes): void {
    $routes->get('admin/activity-log', 'ActivityLogController::index', ['filter' => 'auth:admin']);
    $routes->get('volunteer/activity', 'ActivityLogController::volunteer', ['filter' => 'auth:volunteer']);
});
