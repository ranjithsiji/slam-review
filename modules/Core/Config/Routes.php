<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('', ['namespace' => 'Modules\Core\Controllers'], static function ($routes): void {
    // Server-side DataTables (admin + volunteer; scope enforced per table definition).
    $routes->post('api/tables/(:segment)', 'TablesController::data/$1', ['filter' => 'auth:admin,volunteer']);
    $routes->get('admin/tables/(:segment)/export.csv', 'TablesController::export/$1', ['filter' => ['auth:admin', 'nostore']]);
});
