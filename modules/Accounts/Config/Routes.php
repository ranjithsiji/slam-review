<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('', ['namespace' => 'Modules\Accounts\Controllers'], static function ($routes): void {
    $routes->get('admin/login', 'AuthController::form/admin');
    $routes->post('admin/login', 'AuthController::login/admin', ['filter' => 'ratelimit:staff']);
    $routes->get('volunteer/login', 'AuthController::form/volunteer');
    $routes->post('volunteer/login', 'AuthController::login/volunteer', ['filter' => 'ratelimit:staff']);
    $routes->post('logout', 'AuthController::logout');

    $routes->group('admin/volunteers', ['filter' => 'auth:admin'], static function ($routes): void {
        $routes->get('/', 'VolunteersController::index');
        $routes->get('new', 'VolunteersController::form');
        $routes->post('create', 'VolunteersController::save');
        $routes->get('(:num)/edit', 'VolunteersController::form/$1');
        $routes->post('(:num)', 'VolunteersController::save/$1');
    });
});
