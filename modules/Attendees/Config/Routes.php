<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('', ['namespace' => 'Modules\Attendees\Controllers', 'filter' => 'auth:admin'], static function ($routes): void {
    // Sidebar screens: reviewers, judges and representatives (the last lives in the Scoring module).
    $routes->get('admin/(reviewers|judges|representatives)', 'ParticipantsController::go/$1');
    $routes->get('admin/slams/(:num)/reviewers', 'ParticipantsController::reviewers/$1');
    $routes->get('admin/slams/(:num)/judges', 'ParticipantsController::judges/$1');
    $routes->get('admin/slams/(:num)/attendees', 'AttendeesController::index/$1');
    $routes->get('admin/slams/(:num)/attendees/new', 'AttendeesController::form/$1');
    $routes->post('admin/slams/(:num)/attendees', 'AttendeesController::create/$1');
    $routes->get('admin/slams/(:num)/attendees/import', 'AttendeesController::importForm/$1');
    $routes->get('admin/slams/(:num)/attendees/export.csv', 'AttendeesController::export/$1', ['filter' => 'nostore']);
    $routes->get('admin/attendees/(:num)/edit', 'AttendeesController::edit/$1');
    $routes->post('admin/attendees/(:num)', 'AttendeesController::update/$1');
    $routes->post('admin/attendees/(:num)/reissue-id', 'AttendeesController::reissue/$1');

    $routes->get('admin/attendees/import/template', 'AttendeesController::template');
    $routes->post('admin/attendees/import/preview', 'AttendeesController::preview', ['filter' => 'ratelimit:checkin']);
    $routes->get('admin/attendees/import/(:num)', 'AttendeesController::previewPage/$1');
    $routes->post('admin/attendees/import/commit/(:num)', 'AttendeesController::commit/$1');
    $routes->post('admin/attendees/import/cancel/(:num)', 'AttendeesController::cancel/$1');
    $routes->get('admin/attendees/import/(:num)/errors.csv', 'AttendeesController::errors/$1', ['filter' => 'nostore']);
    $routes->post('api/import-batches/(:num)/deactivate', 'AttendeesController::deactivateBatch/$1');

    $routes->get('admin/qr', 'QrController::index');
    $routes->get('admin/qr/(:num).svg', 'QrController::svg/$1', ['filter' => 'nostore']);
    $routes->get('admin/qr/(:num).png', 'QrController::png/$1', ['filter' => 'nostore']);
    $routes->post('admin/qr/batch', 'QrController::batch', ['filter' => 'nostore']);
    $routes->get('admin/qr/entrance.pdf', 'QrController::entrance', ['filter' => 'nostore']);
    $routes->get('admin/qr/public/(:num).svg', 'QrController::publicSvg/$1');
    $routes->get('api/admin/attendee-lookup', 'QrController::lookup');
});
