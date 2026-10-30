<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('', ['namespace' => 'Modules\Scoring\Controllers', 'filter' => 'auth:admin'], static function ($routes): void {
    $routes->get('admin/slams/(:num)/marks', 'MarksController::marks/$1');
    $routes->get('admin/slams/(:num)/scores', 'MarksController::scores/$1');
    $routes->get('admin/slams/(:num)/leaderboard', 'MarksController::leaderboard/$1');
    $routes->get('admin/slams/(:num)/stats', 'MarksController::stats/$1');
    $routes->get('api/slams/(:num)/stats', 'MarksController::statsData/$1');
    $routes->get('admin/slams/(:num)/mark-config', 'MarksController::config/$1');
    $routes->post('admin/slams/(:num)/mark-config', 'MarksController::saveConfig/$1');
    $routes->post('admin/slams/(:num)/judging', 'MarksController::saveJudging/$1');
    $routes->get('admin/slams/(:num)/judge-marks', 'MarksController::judgeMarks/$1');
    $routes->post('admin/slams/(:num)/judge-marks', 'MarksController::saveJudgeMarks/$1');
    $routes->get('admin/slams/(:num)/representatives', 'MarksController::representatives/$1');
    $routes->post('api/slams/(:num)/representatives/draw', 'MarksController::drawRepresentatives/$1');
    $routes->post('api/slams/(:num)/representatives/(:num)/add', 'MarksController::addRepresentative/$1/$2');
    $routes->post('api/slams/(:num)/representatives/(:num)/remove', 'MarksController::removeRepresentative/$1/$2');
    $routes->get('admin/mark-runs/(:num)', 'MarksController::run/$1');
});
