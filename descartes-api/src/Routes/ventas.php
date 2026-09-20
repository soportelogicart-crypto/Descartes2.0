<?php

declare(strict_types=1);

use Descartes\Api\Controllers\CodigoPostalController;
use Descartes\Api\Controllers\VentasController;
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

  $permisoAbcSubmenu = function (ServerRequestInterface $request, RequestHandlerInterface $handler) {
    $q = $request->getQueryParams();
    $modulo = ListadosPermisosModulo::abc($q['dimension'] ?? null);

    return $handler->handle(
      $request->withAttribute('permisoModulo', $modulo)->withAttribute('permisoAccion', 'ver')
    );
  };

  $app->group('/api/ventas', function (RouteCollectorProxy $group) use ($setPermiso, $permisoAbcSubmenu) {
    $group->get('/codigos-postales/{codigo}', [CodigoPostalController::class, 'lookup'])
      ->add($setPermiso('ventas', 'ver'));
    $group->get('/albaranes', [VentasController::class, 'listVentas'])
      ->add($setPermiso('ventas', 'ver'));
    $group->get('/puestos/{puesto}', [VentasController::class, 'getPuestoVenta'])
      ->add($setPermiso('ventas', 'ver'));
    $group->post('/albaranes', [VentasController::class, 'createVenta'])
      ->add($setPermiso('ventas', 'crear'));
    $group->post('/albaranes/reservar', [VentasController::class, 'reservarAlbaran'])
      ->add($setPermiso('ventas', 'crear'));
    $group->get('/albaranes/{empresa}/{tipo}/{albaran}', [VentasController::class, 'getVenta'])
      ->add($setPermiso('ventas', 'ver'));
    $group->post('/albaranes/{empresa}/{tipo}/{albaran}/email', [VentasController::class, 'emailVenta'])
      ->add($setPermiso('ventas', 'ver'));
    $group->put('/albaranes/{empresa}/{tipo}/{albaran}', [VentasController::class, 'updateVenta'])
      ->add($setPermiso('ventas', 'editar'));
    $group->delete('/albaranes/{empresa}/{tipo}/{albaran}', [VentasController::class, 'deleteVenta'])
      ->add($setPermiso('ventas', 'eliminar'));
    $group->post('/albaranes/{empresa}/{tipo}/{albaran}/finalizar', [VentasController::class, 'finalizarVenta'])
      ->add($setPermiso('ventas', 'editar'));
    $group->post('/albaranes/{empresa}/{tipo}/{albaran}/abono', [VentasController::class, 'crearAbonoDesdeVenta'])
      ->add($setPermiso('ventas', 'crear'));
    $group->post('/albaranes/{empresa}/{tipo}/{albaran}/impreso', [VentasController::class, 'marcarVentaImpresa'])
      ->add($setPermiso('ventas', 'ver'));
    $group->get('/albaranes/{empresa}/{tipo}/{albaran}/pdf', [VentasController::class, 'pdfVenta'])
      ->add($setPermiso('ventas', 'ver'));

    $group->get('/arqueos', [VentasController::class, 'getArqueo'])
      ->add($setPermiso('ventas-arqueo', 'ver'));
    $group->get('/arqueos/desglose', [VentasController::class, 'getArqueoDesglose'])
      ->add($setPermiso('ventas-arqueo-desglose', 'ver'));
    $group->post('/arqueos/{empresa}/{puesto}/{sesion}/introducir', [VentasController::class, 'introducirArqueo'])
      ->add($setPermiso('ventas-arqueo', 'editar'));
    $group->post('/sesiones/{empresa}/{puesto}/{sesion}/cerrar', [VentasController::class, 'cerrarSesion'])
      ->add($setPermiso('ventas-arqueo', 'eliminar'));
    $group->post('/sesiones/{empresa}/{puesto}/{sesion}/entrada-caja', [VentasController::class, 'entradaCaja'])
      ->add($setPermiso('ventas-arqueo', 'crear'));
    $group->post('/sesiones/{empresa}/{puesto}/{sesion}/salida-caja', [VentasController::class, 'salidaCaja'])
      ->add($setPermiso('ventas-arqueo', 'crear'));
    $group->get('/arqueos/{empresa}/{puesto}/{sesion}/informe', [VentasController::class, 'informeArqueo'])
      ->add($setPermiso('ventas-arqueo', 'ver'));
    $group->get('/dispositivo/impresoras', [VentasController::class, 'listarImpresorasDispositivo'])
      ->add($setPermiso('puestos-trabajo', 'ver'));
    $group->post('/puestos/{puesto}/dispositivo/leer-cajon', [VentasController::class, 'leerCajonDispositivo'])
      ->add($setPermiso('ventas-arqueo', 'editar'));
    $group->post('/puestos/{puesto}/dispositivo/imprimir', [VentasController::class, 'imprimirDispositivo'])
      ->add($setPermiso('ventas-arqueo', 'ver'));

    $group->get('/anulaciones', [VentasController::class, 'listAnulaciones'])
      ->add($setPermiso('ventas-anulaciones', 'ver'));

    $group->get('/cobros-pagos', [VentasController::class, 'listCobrosPagos'])
      ->add($setPermiso('ventas-cobros-pagos', 'ver'));

    $group->get('/vales', [VentasController::class, 'listVales'])
      ->add($setPermiso('ventas-vales', 'ver'));
    $group->post('/vales', [VentasController::class, 'createVale'])
      ->add($setPermiso('ventas-vales', 'crear'));
    $group->post('/vales/{empresa}/{codigo}/liquidar', [VentasController::class, 'liquidarVale'])
      ->add($setPermiso('ventas-vales', 'editar'));

    $group->get('/pedidos-clientes', [VentasController::class, 'listPedidos'])
      ->add($setPermiso('ventas-pedidos', 'ver'));
    $group->post('/pedidos-clientes/reservar', [VentasController::class, 'reservarPedido'])
      ->add($setPermiso('ventas-pedidos', 'crear'));
    $group->post('/pedidos-clientes', [VentasController::class, 'createPedido'])
      ->add($setPermiso('ventas-pedidos', 'crear'));
    $group->get('/pedidos-clientes/{empresa}/{pedido}', [VentasController::class, 'getPedido'])
      ->add($setPermiso('ventas-pedidos', 'ver'));
    $group->put('/pedidos-clientes/{empresa}/{pedido}', [VentasController::class, 'updatePedido'])
      ->add($setPermiso('ventas-pedidos', 'editar'));
    $group->post('/pedidos-clientes/{empresa}/{pedido}/convertir-venta', [VentasController::class, 'convertirPedidoVenta'])
      ->add($setPermiso('ventas-pedidos', 'editar'));
    $group->post('/pedidos-clientes/{empresa}/{pedido}/impreso', [VentasController::class, 'marcarPedidoImpreso'])
      ->add($setPermiso('ventas-pedidos', 'editar'));

    $group->get('/abc', [VentasController::class, 'listAbcVentas'])
      ->add($permisoAbcSubmenu);
  })
    ->add(PermissionMiddleware::class)
    ->add(AuthMiddleware::class);
};
