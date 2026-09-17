<?php

declare(strict_types=1);

use Descartes\Api\Controllers\ListadosController;
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

  $app->group('/api/listados', function (RouteCollectorProxy $group) use ($setPermiso) {
    $group->get('/stock', [ListadosController::class, 'listStock'])
      ->add($setPermiso('listados', 'ver'));
    $group->get('/stock-minimos', [ListadosController::class, 'listStockMinimos'])
      ->add($setPermiso('listados', 'ver'));
    $group->get('/informe-iva', [ListadosController::class, 'listInformeIva'])
      ->add($setPermiso('listados', 'ver'));
    $group->get('/informe-tickets', [ListadosController::class, 'listInformeTickets'])
      ->add($setPermiso('listados', 'ver'));
    $group->get('/extracto-clientes', [ListadosController::class, 'listExtractoClientes'])
      ->add($setPermiso('listados', 'ver'));
  })
    ->add(PermissionMiddleware::class)
    ->add(AuthMiddleware::class);
};
