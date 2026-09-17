<?php

declare(strict_types=1);

use Descartes\Api\Controllers\EtiquetasController;
use Descartes\Api\Middleware\AuthMiddleware;
use Descartes\Api\Middleware\PermissionMiddleware;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

/**
 * Rutas del módulo Etiquetas (005-etiquetas-gestion).
 * Permisos módulo `etiquetas`:
 *   ver      → listar, ping, preview
 *   crear    → alta cola, desde albarán compra
 *   editar   → actualizar línea, confirmar impresión
 *   eliminar → quitar línea
 * Handlers cola CRUD → T010/T011. Imprimir / albarán / preview → T020+.
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

  $app->group('/api/etiquetas', function (RouteCollectorProxy $group) use ($setPermiso) {
    $group->get('/ping', [EtiquetasController::class, 'ping'])
      ->add($setPermiso('etiquetas', 'ver'));

    // Rutas estáticas antes de {articulo}/{nroLin}
    $group->post('/imprimir', [EtiquetasController::class, 'confirmarImpresion'])
      ->add($setPermiso('etiquetas', 'editar'));
    $group->post('/eliminar-lote', [EtiquetasController::class, 'eliminarLote'])
      ->add($setPermiso('etiquetas', 'eliminar'));
    $group->post('/desde-albaran-compra', [EtiquetasController::class, 'desdeAlbaranCompra'])
      ->add($setPermiso('etiquetas', 'crear'));
    $group->get('/preview-datos', [EtiquetasController::class, 'previewDatos'])
      ->add($setPermiso('etiquetas', 'ver'));

    // Cola
    $group->get('', [EtiquetasController::class, 'listar'])
      ->add($setPermiso('etiquetas', 'ver'));
    $group->post('', [EtiquetasController::class, 'crear'])
      ->add($setPermiso('etiquetas', 'crear'));
    $group->put('/{articulo}/{nroLin}', [EtiquetasController::class, 'actualizar'])
      ->add($setPermiso('etiquetas', 'editar'));
    $group->delete('/{articulo}/{nroLin}', [EtiquetasController::class, 'eliminar'])
      ->add($setPermiso('etiquetas', 'eliminar'));
  })
    ->add(PermissionMiddleware::class)
    ->add(AuthMiddleware::class);
};
