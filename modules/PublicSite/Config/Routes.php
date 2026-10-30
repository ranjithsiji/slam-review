<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('', ['namespace' => 'Modules\PublicSite\Controllers', 'filter' => 'ratelimit:public'], static function ($routes): void {
    $routes->get('slams', 'PublicController::index');
    $routes->get('slams/(:segment)/calendar.ics', 'PublicController::ics/$1');
    $routes->get('slams/(:segment)', 'PublicController::show/$1');
    $routes->get('api/public/live', 'PublicController::liveJson');
    $routes->get('api/public/slams/(:segment)/schedule', 'PublicController::scheduleJson/$1');
});
