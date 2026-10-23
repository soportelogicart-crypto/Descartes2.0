<?php

declare(strict_types=1);

use Descartes\Api\Controllers\ListadosController;
use Descartes\Api\Middleware\AuthMiddleware;
use Descartes\Api\Middleware\PermissionMiddleware;
use Descartes\Api\Services\Listados\ListadosPermisosModulo;
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

  $permisoStockSubmenu = function (ServerRequestInterface $request, RequestHandlerInterface $handler) {
    $q = $request->getQueryParams();
    $modulo = ListadosPermisosModulo::stock($q['agruparPor'] ?? null);

    return $handler->handle(
      $request->withAttribute('permisoModulo', $modulo)->withAttribute('permisoAccion', 'ver')
    );
  };

  $app->group('/api/listados', function (RouteCollectorProxy $group) use ($setPermiso, $permisoStockSubmenu) {
    $group->get('/stock', [ListadosController::class, 'listStock'])
      ->add($permisoStockSubmenu);
    $group->get('/stock-minimos', [ListadosController::class, 'listStockMinimos'])
      ->add($setPermiso('listados-stock-minimos', 'ver'));
    $group->get('/informe-iva', [ListadosController::class, 'listInformeIva'])
      ->add($setPermiso('listados-informe-iva', 'ver'));
    $group->post('/informe-iva/pdf', [ListadosController::class, 'pdfInformeIva'])
      ->add($setPermiso('listados-informe-iva', 'ver'));
    $group->get('/informe-tickets', [ListadosController::class, 'listInformeTickets'])
      ->add($setPermiso('listados-informe-tickets', 'ver'));
    $group->get('/extracto-clientes', [ListadosController::class, 'listExtractoClientes'])
      ->add($setPermiso('listados-extracto-clientes', 'ver'));
  })
    ->add(PermissionMiddleware::class)
    ->add(AuthMiddleware::class);
};
