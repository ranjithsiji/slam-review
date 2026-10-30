<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('', ['namespace' => 'Modules\Scoring\Controllers', 'filter' => 'auth:admin'], static function ($routes): void {
    $routes->get('admin/slams/(:num)/marks', 'MarksController::marks/$1');
    $routes->get('admin/slams/(:num)/stats', 'MarksController::stats/$1');
    $routes->get('api/slams/(:num)/stats', 'MarksController::statsData/$1');
    $routes->get('admin/slams/(:num)/mark-config', 'MarksController::config/$1');
    $routes->post('admin/slams/(:num)/mark-config', 'MarksController::saveConfig/$1');
    $routes->get('admin/slams/(:num)/complete-reviewers', 'MarksController::complete/$1');
    $routes->get('admin/mark-runs/(:num)', 'MarksController::run/$1');
});
