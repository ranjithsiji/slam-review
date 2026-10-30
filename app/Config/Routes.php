<?php

use CodeIgniter\Router\RouteCollection;

/**
 * Application-level routes only. Each module registers its own routes in
 * modules/{Module}/Config/Routes.php (auto-discovered, see Config\Modules).
 *
 * @var RouteCollection $routes
 */
$routes->get('/', '\Modules\Core\Controllers\Home::index');
$routes->get('about', '\Modules\Core\Controllers\Home::about');
$routes->setAutoRoute(false);
