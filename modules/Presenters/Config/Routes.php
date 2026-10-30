<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('', ['namespace' => 'Modules\Presenters\Controllers', 'filter' => 'auth:admin'], static function ($routes): void {
    $routes->get('admin/presenters', 'PresentersController::all');
    $routes->get('admin/presenters/(:num)/edit', 'PresentersController::editPresenter/$1');
    $routes->post('admin/presenters/(:num)', 'PresentersController::updatePresenter/$1');
    $routes->post('admin/presenters/(:num)/remove-photo', 'PresentersController::removePhoto/$1');
    $routes->get('admin/slams/(:num)/presenters', 'PresentersController::index/$1');
    $routes->get('admin/slams/(:num)/presenters/new', 'PresentersController::form/$1');
    $routes->post('admin/slams/(:num)/presenters', 'PresentersController::create/$1');
    $routes->get('admin/performances/(:num)/edit', 'PresentersController::editPerformance/$1');
    $routes->post('admin/performances/(:num)', 'PresentersController::updatePerformance/$1');

    $routes->get('api/slams/(:num)/presenter-pool', 'PresentersController::pool/$1');
    $routes->post('api/slams/(:num)/performances', 'PresentersController::apiCreate/$1');
    $routes->post('api/presenters/(:num)/photo', 'PresentersController::apiPhoto/$1');
});
