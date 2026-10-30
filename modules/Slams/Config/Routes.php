<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('', ['namespace' => 'Modules\Slams\Controllers'], static function ($routes): void {
    $routes->group('admin', ['filter' => 'auth:admin'], static function ($routes): void {
        $routes->get('/', 'SlamsController::dashboard');
        $routes->get('slams', 'SlamsController::index');
        $routes->get('slams/new', 'SlamsController::form');
        $routes->post('slams/create', 'SlamsController::save');
        $routes->get('slams/(:num)', 'SlamsController::show/$1');
        $routes->get('slams/(:num)/edit', 'SlamsController::form/$1');
        $routes->post('slams/(:num)', 'SlamsController::save/$1');
        $routes->post('slams/(:num)/publish', 'SlamsController::publish/$1');
        $routes->get('slams/(:num)/control', 'ControlController::page/$1');
        $routes->get('slams/(:num)/status', 'ControlController::statusPage/$1');
    });

    // Live top bar on every organiser page: what is going on in each running slam.
    $routes->get('api/admin/live', 'ControlController::liveBar', ['filter' => 'auth:admin']);

    // Phase control API (admin only; every action goes through SlamPhaseService).
    $routes->group('api/slams/(:num)', ['filter' => 'auth:admin'], static function ($routes): void {
        $routes->get('control-state', 'ControlController::state/$1');
        $routes->put('times', 'ControlController::times/$1');
        $routes->post('ready', 'ControlController::action/$1/ready');
        $routes->post('open', 'ControlController::action/$1/open');
        $routes->post('checkin-gate/(:segment)', 'ControlController::gate/$1/$2');
        $routes->post('window/(:segment)', 'ControlController::window/$1/$2');
        $routes->post('items/(:num)/skip', 'ControlController::skip/$1/$2');
        $routes->post('items/(:num)/perform/(start|end)', 'ControlController::perform/$1/$2/$3');
        $routes->post('end-reviewing', 'ControlController::action/$1/end-reviewing');
        $routes->post('revision/(:segment)', 'ControlController::revision/$1/$2');
        $routes->post('close', 'ControlController::action/$1/close');
        $routes->post('marks/calculate', 'ControlController::action/$1/calculate');
        $routes->post('end', 'ControlController::action/$1/end');
        $routes->post('hold', 'ControlController::action/$1/hold');
        $routes->post('resume', 'ControlController::action/$1/resume');
        $routes->post('mode', 'ControlController::action/$1/mode');
        $routes->post('advance', 'ControlController::action/$1/advance');
    });
});
