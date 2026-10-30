<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('', ['namespace' => 'Modules\Reviews\Controllers'], static function ($routes): void {
    // Reviewer (checked-in user or judge).
    $routes->get('review', 'ReviewerController::page', ['filter' => 'reviewer']);
    $routes->get('review/thanks', 'ReviewerController::thanks');
    $routes->get('api/live/state', 'ReviewerController::state', ['filter' => ['reviewer', 'ratelimit:poll']]);
    $routes->post('api/review', 'ReviewerController::submit', ['filter' => ['reviewer', 'ratelimit:review']]);
    $routes->get('api/my-reviews', 'ReviewerController::mine', ['filter' => 'reviewer']);
    $routes->put('api/review/(:num)', 'ReviewerController::revise/$1', ['filter' => ['reviewer', 'ratelimit:review']]);

    // Admin: review questions.
    $routes->group('', ['filter' => 'auth:admin'], static function ($routes): void {
        $routes->get('admin/slams/(:num)/questions', 'QuestionsController::page/$1');
        $routes->get('admin/questions/templates', 'QuestionsController::page');
        $routes->post('api/questions', 'QuestionsController::create');
        $routes->put('api/questions/(:num)', 'QuestionsController::update/$1');
        $routes->delete('api/questions/(:num)', 'QuestionsController::delete/$1');
        $routes->post('api/questions/reorder', 'QuestionsController::reorder');
        $routes->post('api/questions/copy-from/(:num)', 'QuestionsController::copyFrom/$1');
    });
});
