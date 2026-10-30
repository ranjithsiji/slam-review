<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('', ['namespace' => 'Modules\Schedule\Controllers'], static function ($routes): void {
    $routes->group('', ['filter' => 'auth:admin'], static function ($routes): void {
        $routes->get('admin/slams/(:num)/schedule', 'ScheduleController::page/$1');
        $routes->get('admin/slams/(:num)/schedule/export.pdf', 'ScheduleController::exportPdf/$1');
        $routes->get('admin/slams/(:num)/schedule/export.csv', 'ScheduleController::exportCsv/$1');

        $routes->get('api/slams/(:num)/schedule', 'ScheduleController::get/$1');
        $routes->put('api/slams/(:num)/schedule', 'ScheduleController::save/$1');
        $routes->post('api/slams/(:num)/schedule/items', 'ScheduleController::addItem/$1');
        $routes->post('api/slams/(:num)/schedule/validate', 'ScheduleController::validateDraft/$1');
        $routes->post('api/slams/(:num)/schedule/reflow', 'ScheduleController::reflowDraft/$1');
        $routes->post('api/slams/(:num)/schedule/generate', 'ScheduleController::generate/$1');
        $routes->post('api/slams/(:num)/schedule/reorder', 'ScheduleController::reorder/$1');
        $routes->post('api/slams/(:num)/schedule/reflow-remaining', 'ScheduleController::reflowRemaining/$1');
        $routes->get('api/slams/(:num)/schedule/snapshots', 'ScheduleController::snapshots/$1');
        $routes->post('api/slams/(:num)/schedule/snapshots/(:num)/restore', 'ScheduleController::restore/$1/$2');
        $routes->post('api/slams/(:num)/schedule/copy-structure/(:num)', 'ScheduleController::copyStructure/$1/$2');
        $routes->put('api/schedule/items/(:num)', 'ScheduleController::updateItem/$1');
        $routes->delete('api/schedule/items/(:num)', 'ScheduleController::deleteItem/$1');
        $routes->post('api/schedule/items/(:num)/extend-break', 'ScheduleController::extendBreak/$1');
        $routes->post('api/schedule/items/(:num)/end-break', 'ScheduleController::endBreak/$1');
    });
    // Volunteers: read-only run-sheet for their own slam.
    $routes->get('volunteer/run-sheet', 'ScheduleController::runSheet', ['filter' => 'auth:volunteer,admin']);
});
