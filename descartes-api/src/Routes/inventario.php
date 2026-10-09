<?php

declare(strict_types=1);

use Descartes\Api\Controllers\InventarioController;
use Descartes\Api\Middleware\AuthMiddleware;
use Descartes\Api\Middleware\PermissionMiddleware;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

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

  $app->group('/api/inventario', function (RouteCollectorProxy $group) use ($setPermiso) {
    $group->get('/recuento', [InventarioController::class, 'estado'])
      ->add($setPermiso('inventario', 'ver'));
    $group->post('/recuento/congelar', [InventarioController::class, 'congelar'])
      ->add($setPermiso('inventario', 'crear'));
    $group->post('/recuento/linea', [InventarioController::class, 'anotar'])
      ->add($setPermiso('inventario', 'crear'));
    $group->post('/recuento/descartar', [InventarioController::class, 'descartar'])
      ->add($setPermiso('inventario', 'editar'));
    $group->post('/recuento/actualizar', [InventarioController::class, 'actualizar'])
      ->add($setPermiso('inventario', 'editar'));
  })
    ->add(PermissionMiddleware::class)
    ->add(AuthMiddleware::class);
};
