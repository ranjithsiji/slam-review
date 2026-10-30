<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('', ['namespace' => 'Modules\Checkin\Controllers'], static function ($routes): void {
    // Public check-in (no login). The ID in the URL is a credential: no-store, no-referrer.
    $routes->get('checkin', 'PublicCheckinController::typeId', ['filter' => 'nostore']);
    $routes->get('in/(:segment)', 'PublicCheckinController::landing/$1', ['filter' => 'nostore']);
    $routes->post('checkin', 'PublicCheckinController::submit', ['filter' => ['ratelimit:checkin', 'nostore']]);
    $routes->post('api/checkin', 'PublicCheckinController::submit', ['filter' => ['ratelimit:checkin', 'nostore']]);
    $routes->post('api/checkout', 'PublicCheckinController::checkout', ['filter' => 'reviewer']);

    // Volunteer panel (own slam only) — admins may use it too.
    $routes->get('volunteer', 'VolunteerController::panel', ['filter' => 'auth:volunteer,admin']);
    $routes->group('api/volunteer', ['filter' => ['auth:volunteer,admin', 'slamscope']], static function ($routes): void {
        $routes->get('state', 'VolunteerController::state');
        $routes->post('checkin', 'VolunteerController::checkin', ['filter' => 'ratelimit:search']);
    });
    $routes->post('api/force-checkout/(:num)', 'VolunteerController::forceCheckout/$1', ['filter' => 'auth:volunteer,admin']);
    $routes->get('api/attendees/search', 'VolunteerController::search', ['filter' => ['auth:volunteer,admin', 'ratelimit:search']]);
    $routes->post('api/checkin/late-override', 'VolunteerController::lateOverride', ['filter' => 'auth:admin']);
    $routes->get('admin/slams/(:num)/checkins', 'VolunteerController::monitor/$1', ['filter' => 'auth:admin']);
});
