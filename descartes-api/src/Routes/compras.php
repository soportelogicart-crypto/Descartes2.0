<?php

declare(strict_types=1);

use Descartes\Api\Controllers\ComprasController;
use Descartes\Api\Middleware\AuthMiddleware;
use Descartes\Api\Middleware\PermissionMiddleware;
use Descartes\Api\Services\Listados\ListadosPermisosModulo;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

/**
 * Rutas del módulo Compras (004-compras-gestion).
 * Permisos: ver (GET), crear (POST alta), editar (PUT / stock / recepción), eliminar (DELETE).
 * Handlers: albaranes + pedidos + recepción + GET facturas (T035).
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

  $permisoAbcComprasSubmenu = function (ServerRequestInterface $request, RequestHandlerInterface $handler) {
    $q = $request->getQueryParams();
    $modulo = ListadosPermisosModulo::abcCompras($q['dimension'] ?? null);

    return $handler->handle(
      $request->withAttribute('permisoModulo', $modulo)->withAttribute('permisoAccion', 'ver')
    );
  };

  $app->group('/api/compras', function (RouteCollectorProxy $group) use ($setPermiso, $permisoAbcComprasSubmenu) {
    $group->get('/ping', [ComprasController::class, 'ping'])
      ->add($setPermiso('compras', 'ver'));

    $group->get('/abc', [ComprasController::class, 'listAbcCompras'])
      ->add($permisoAbcComprasSubmenu);

    // Albaranes
    $group->get('/albaranes', [ComprasController::class, 'listAlbaranes'])
      ->add($setPermiso('compras', 'ver'));
    $group->post('/albaranes/reservar', [ComprasController::class, 'reservarAlbaran'])
      ->add($setPermiso('compras', 'crear'));
    $group->post('/albaranes', [ComprasController::class, 'createAlbaran'])
      ->add($setPermiso('compras', 'crear'));
    $group->get('/albaranes/{empresa}/{albaran}', [ComprasController::class, 'getAlbaran'])
      ->add($setPermiso('compras', 'ver'));
    $group->put('/albaranes/{empresa}/{albaran}', [ComprasController::class, 'updateAlbaran'])
      ->add($setPermiso('compras', 'editar'));
    $group->delete('/albaranes/{empresa}/{albaran}', [ComprasController::class, 'deleteAlbaran'])
      ->add($setPermiso('compras', 'eliminar'));
    $group->post('/albaranes/{empresa}/{albaran}/actualizar-stock', [ComprasController::class, 'actualizarStockAlbaran'])
      ->add($setPermiso('compras', 'editar'));
    $group->post('/albaranes/{empresa}/{albaran}/recuperar', [ComprasController::class, 'recuperarAlbaran'])
      ->add($setPermiso('compras', 'editar'));
    $group->post('/albaranes/{empresa}/{albaran}/convertir-venta', [ComprasController::class, 'convertirVentaAlbaran'])
      ->add($setPermiso('compras', 'editar'));
    $group->post('/albaranes/{empresa}/{albaran}/abono', [ComprasController::class, 'crearAbonoAlbaran'])
      ->add($setPermiso('compras', 'crear'));

    // Pedidos a proveedor
    $group->get('/pedidos', [ComprasController::class, 'listPedidos'])
      ->add($setPermiso('compras', 'ver'));
    $group->post('/pedidos/reservar', [ComprasController::class, 'reservarPedido'])
      ->add($setPermiso('compras', 'crear'));
    $group->post('/pedidos', [ComprasController::class, 'createPedido'])
      ->add($setPermiso('compras', 'crear'));
    $group->get('/pedidos/{empresa}/{pedido}', [ComprasController::class, 'getPedido'])
      ->add($setPermiso('compras', 'ver'));
    $group->put('/pedidos/{empresa}/{pedido}', [ComprasController::class, 'updatePedido'])
      ->add($setPermiso('compras', 'editar'));
    $group->post('/pedidos/{empresa}/{pedido}/recibir', [ComprasController::class, 'recibirPedido'])
      ->add($setPermiso('compras', 'editar'));

    // Facturas proveedor (solo lectura v1)
    $group->get('/facturas', [ComprasController::class, 'listFacturas'])
      ->add($setPermiso('compras', 'ver'));
    $group->get('/facturas/{factura}', [ComprasController::class, 'getFactura'])
      ->add($setPermiso('compras', 'ver'));
  })
    ->add(PermissionMiddleware::class)
    ->add(AuthMiddleware::class);
};
