<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('', ['namespace' => 'Modules\Checkin\Controllers'], static function ($routes): void {
    // Public check-in (no login). The ID in the URL is a credential: no-store, no-referrer.
    $routes->get('checkin', 'PublicCheckinController::typeId', ['filter' => 'nostore']);
    $routes->get('in/(:segment)', 'PublicCheckinController::landing/$1', ['filter' => 'nostore']);
    // Step 1 shows the attendee's name and masked mobile; step 2 (confirm) checks them in.
    $routes->post('checkin', 'PublicCheckinController::verify', ['filter' => ['ratelimit:checkin', 'trap', 'nostore']]);
    $routes->get('checkin/confirm', 'PublicCheckinController::confirmPage', ['filter' => 'nostore']);
    $routes->post('checkin/confirm', 'PublicCheckinController::submit', ['filter' => ['ratelimit:checkin', 'trap', 'nostore']]);
    $routes->get('registered', 'PublicCheckinController::registered', ['filter' => 'nostore']);
    $routes->post('registered', 'PublicCheckinController::registered', ['filter' => ['ratelimit:lookup', 'trap', 'nostore']]);
    // "Get my badge": the badge and its QR code by unique ID (rate-limited, never cached).
    $routes->get('badge', 'PublicBadgeController::form', ['filter' => ['nostore']]);
    $routes->post('badge', 'PublicBadgeController::lookup', ['filter' => ['ratelimit:lookup', 'trap', 'nostore']]);
    $routes->get('badge/([A-Za-z]{2}[0-9]{5})', 'PublicBadgeController::show/$1', ['filter' => ['ratelimit:lookup', 'nostore']]);
    $routes->get('badge/([A-Za-z]{2}[0-9]{5})\.(png|svg|pdf)', 'PublicBadgeController::file/$1/$2', ['filter' => ['ratelimit:lookup', 'nostore']]);
    // After Gate Close: the phone waits here until a volunteer checks the person in.
    $routes->get('checkin/waiting', 'PublicCheckinController::waitingPage', ['filter' => 'nostore']);
    $routes->get('api/checkin/waiting', 'PublicCheckinController::waitingPoll', ['filter' => ['ratelimit:wait', 'nostore']]);
    $routes->get('checkin/not-me', 'PublicCheckinController::notMe', ['filter' => 'nostore']);
    $routes->post('api/checkin', 'PublicCheckinController::submit', ['filter' => ['ratelimit:checkin', 'nostore']]);
    $routes->post('api/checkout', 'PublicCheckinController::checkout', ['filter' => 'reviewer']);

    // Volunteer panel (own slam only) — admins may use it too.
    $routes->get('volunteer', 'VolunteerController::panel', ['filter' => 'auth:volunteer,admin']);
    $routes->group('api/volunteer', ['filter' => ['auth:volunteer,admin', 'slamscope']], static function ($routes): void {
        $routes->get('state', 'VolunteerController::state');
        $routes->post('checkin', 'VolunteerController::checkin', ['filter' => 'ratelimit:search']);
        $routes->post('idle-checkout', 'VolunteerController::idleCheckout');
        $routes->post('approve-request', 'VolunteerController::approveRequest');
    });
    $routes->post('api/force-checkout/(:num)', 'VolunteerController::forceCheckout/$1', ['filter' => 'auth:volunteer,admin']);
    $routes->get('api/attendees/search', 'VolunteerController::search', ['filter' => ['auth:volunteer,admin', 'ratelimit:search']]);
    $routes->post('api/checkin/late-override', 'VolunteerController::lateOverride', ['filter' => 'auth:volunteer,admin']);
    $routes->get('volunteer/not-reviewing', 'VolunteerController::notReviewing', ['filter' => 'auth:volunteer,admin']);
    $routes->get('admin/slams/(:num)/not-reviewing', 'VolunteerController::notReviewing/$1', ['filter' => 'auth:admin']);
    $routes->get('admin/slams/(:num)/checkins', 'VolunteerController::monitor/$1', ['filter' => 'auth:admin']);
});
