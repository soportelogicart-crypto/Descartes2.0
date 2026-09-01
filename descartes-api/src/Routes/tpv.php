<?php

declare(strict_types=1);

use Descartes\Api\Controllers\TpvController;
use Descartes\Api\Controllers\VentasController;
use Descartes\Api\Middleware\AuthMiddleware;
use Descartes\Api\Middleware\PermissionMiddleware;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

/**
 * Rutas TPV / venta táctil (006-tpv-venta-tactil).
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

  $app->group('/api/tpv', function (RouteCollectorProxy $group) use ($setPermiso) {
    $group->get('/ping', [TpvController::class, 'ping'])
      ->add($setPermiso('tpv', 'ver'));
    $group->get('/contexto', [TpvController::class, 'getContexto'])
      ->add($setPermiso('tpv', 'ver'));
    $group->get('/teclados/{general}/niveles', [TpvController::class, 'getNivelesTeclado'])
      ->add($setPermiso('tpv', 'ver'));
    $group->get('/teclados/{general}/niveles/{nivel}', [TpvController::class, 'getNivelTeclado'])
      ->add($setPermiso('tpv', 'ver'));
    $group->put('/teclados/{general}/niveles/{nivel}/botones/{tecla}', [TpvController::class, 'guardarBotonTeclado'])
      ->add($setPermiso('tpv', 'editar'));
    $group->delete('/teclados/{general}/niveles/{nivel}/botones/{tecla}', [TpvController::class, 'borrarBotonTeclado'])
      ->add($setPermiso('tpv', 'editar'));
    $group->get('/articulos/resolver', [TpvController::class, 'resolverArticulo'])
      ->add($setPermiso('tpv', 'ver'));
    $group->get('/articulos', [TpvController::class, 'buscarArticulos'])
      ->add($setPermiso('tpv', 'ver'));
    $group->get('/clientes', [TpvController::class, 'buscarClientes'])
      ->add($setPermiso('tpv', 'ver'));
    $group->get('/articulos/{codigo}/precio', [TpvController::class, 'getArticuloPrecio'])
      ->add($setPermiso('tpv', 'ver'));
    $group->delete('/ventas/{empresa}/{tipo}/{albaran}', [VentasController::class, 'deleteVenta'])
      ->add($setPermiso('tpv', 'eliminar'));
    $group->post('/ventas/{empresa}/{tipo}/{albaran}/email', [TpvController::class, 'emailVenta'])
      ->add($setPermiso('tpv', 'ver'));
  })
    ->add(PermissionMiddleware::class)
    ->add(AuthMiddleware::class);
};
