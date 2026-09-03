<?php

declare(strict_types=1);

use Descartes\Api\Controllers\InstalacionController;
use Descartes\Api\Middleware\AuthMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

/**
 * Configuracion de instalacion (BD + migraciones automaticas).
 * Rutas publicas para el primer arranque; migrar manual requiere sesion.
 */
return function (App $app): void {
  $app->group('/api/instalacion', function (RouteCollectorProxy $group) {
    $group->get('/estado', [InstalacionController::class, 'estado']);
    $group->post('/probar', [InstalacionController::class, 'probar']);
    $group->post('/configurar', [InstalacionController::class, 'configurar']);
    $group->post('/migrar', [InstalacionController::class, 'migrar'])
      ->add(AuthMiddleware::class);
  });
};
