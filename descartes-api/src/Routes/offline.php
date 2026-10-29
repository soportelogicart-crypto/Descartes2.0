<?php

declare(strict_types=1);

use Descartes\Api\Controllers\OfflineController;
use Descartes\Api\Middleware\AuthMiddleware;
use Descartes\Api\Middleware\PermissionMiddleware;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

/**
 * Modo sin conexión del TPV. /estado es público: el puesto lo usa como ping
 * aunque la sesión haya caducado (un 401 también dice que hay conexión).
 */
return function (App $app): void {
  $setPermiso = function (string $modulo, string $accion): callable {
    return function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($modulo, $accion) {
      return $handler->handle(
        $request
          ->withAttribute('permisoModulo', $modulo)
          ->withAttribute('permisoAccion', $accion)
      );
    };
  };

  $app->get('/api/offline/estado', [OfflineController::class, 'getEstado']);

  $app->group('/api/offline', function (RouteCollectorProxy $group) use ($setPermiso) {
    $group->get('/snapshot', [OfflineController::class, 'getSnapshot'])
      ->add($setPermiso('tpv', 'ver'));
    $group->get('/cambios', [OfflineController::class, 'getSnapshot'])
      ->add($setPermiso('tpv', 'ver'));
    $group->post('/ventas', [OfflineController::class, 'postVenta'])
      ->add($setPermiso('tpv', 'editar'));
  })
    ->add(PermissionMiddleware::class)
    ->add(AuthMiddleware::class);
};
