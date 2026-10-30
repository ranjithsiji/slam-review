<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('', ['namespace' => 'Modules\Ads\Controllers', 'filter' => 'auth:admin'], static function ($routes): void {
    $routes->get('admin/slams/(:num)/ads', 'AdsController::index/$1');
    $routes->post('admin/slams/(:num)/ads', 'AdsController::save/$1');
    $routes->post('admin/slams/(:num)/ads/(:num)/delete', 'AdsController::delete/$1/$2');
});
