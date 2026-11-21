<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('', ['namespace' => 'Modules\Ads\Controllers', 'filter' => 'auth:admin'], static function ($routes): void {
    $routes->get('admin/slams/(:num)/ads', 'AdsController::index/$1');
    $routes->post('admin/slams/(:num)/ads', 'AdsController::save/$1');
    $routes->post('admin/slams/(:num)/ads/(:num)/delete', 'AdsController::delete/$1/$2');
    $routes->post('admin/slams/(:num)/ads/pool', 'AdsController::choosePool/$1');
    // The ad pool (full admins: operators reach only their own slam's screens).
    $routes->get('admin/ads', 'AdsController::pool');
    $routes->post('admin/ads', 'AdsController::savePool');
    $routes->post('admin/ads/(:num)/delete', 'AdsController::deletePool/$1');
});
