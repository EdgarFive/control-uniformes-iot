<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->setAutoRoute(false);
$routes->get('/', static fn () => redirect()->to(site_url('admin')));

service('auth')->routes($routes, ['only' => ['login', 'auth-actions']]);
$routes->post('logout', '\CodeIgniter\Shield\Controllers\LoginController::logoutAction', ['as' => 'logout', 'filter' => 'csrf']);

$routes->group('admin', ['filter' => ['panel', 'csrf']], static function (RouteCollection $routes) {
    $routes->get('/', 'Admin\PanelController::index');
    $routes->get('detecciones', 'Admin\PanelController::historial');
    $routes->get('detecciones/(:num)', 'Admin\PanelController::detalle/$1');
    $routes->get('detecciones/(:num)/foto', 'Admin\PanelController::foto/$1');
    $routes->get('dispositivos', 'Admin\PanelController::dispositivos');
    $routes->get('uniformes', 'Admin\PanelController::uniformes');
    $routes->post('uniformes/(:num)', 'Admin\PanelController::guardarUniforme/$1');
});

$routes->get('api/health', 'Api\DeteccionesController::health');

$routes->post('api/detecciones', 'Api\DeteccionesController::recibir');
