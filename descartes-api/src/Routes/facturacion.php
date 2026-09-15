<?php

declare(strict_types=1);

use Descartes\Api\Controllers\FacturacionController;
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

  $app->group('/api/facturacion', function (RouteCollectorProxy $group) use ($setPermiso) {
    $group->get('/manual/pendientes', [FacturacionController::class, 'listManualPendientes'])
      ->add($setPermiso('facturacion-manual', 'ver'));
    $group->post('/manual/generar', [FacturacionController::class, 'generarManual'])
      ->add($setPermiso('facturacion-manual', 'crear'));
    $group->post('/manual/email', [FacturacionController::class, 'emailManual'])
      ->add($setPermiso('facturacion-manual', 'crear'));
    $group->get(
      '/manual/documento/{empresa}/{facturaTipo}/{factura}',
      [FacturacionController::class, 'documentoImpresion']
    )
      ->add($setPermiso('facturacion-manual', 'ver'));
    $group->post('/manual/traspaso', [FacturacionController::class, 'traspasoManual'])
      ->add($setPermiso('facturacion-manual', 'crear'));
    $group->post('/manual/periodicos/generar', [FacturacionController::class, 'periodicosGenerar'])
      ->add($setPermiso('facturacion-manual', 'crear'));

    $group->get('/contabilidad/pendientes', [FacturacionController::class, 'listTraspasoContable'])
      ->add($setPermiso('facturacion-contabilidad', 'ver'));
    $group->post('/contabilidad/traspasar', [FacturacionController::class, 'ejecutarTraspasoContable'])
      ->add($setPermiso('facturacion-contabilidad', 'crear'));

    $group->get('/generar/preview', [FacturacionController::class, 'previewGeneracion'])
      ->add($setPermiso('facturacion-generacion', 'ver'));
    $group->post('/generar', [FacturacionController::class, 'generarAutomatico'])
      ->add($setPermiso('facturacion-generacion', 'crear'));

    $group->get('/impresion', [FacturacionController::class, 'listImpresion'])
      ->add($setPermiso('facturacion-impresion', 'ver'));
    $group->post('/impresion/pdf', [FacturacionController::class, 'pdfImpresion'])
      ->add($setPermiso('facturacion-impresion', 'ver'));
    $group->post('/impresion/marcar', [FacturacionController::class, 'marcarImpresion'])
      ->add($setPermiso('facturacion-impresion', 'crear'));
    $group->get(
      '/impresion/documento/{empresa}/{facturaTipo}/{factura}',
      [FacturacionController::class, 'documentoImpresion']
    )
      ->add($setPermiso('facturacion-impresion', 'ver'));
    $group->get('/recibos', [FacturacionController::class, 'listRecibosImpresion'])
      ->add($setPermiso('facturacion-impresion', 'ver'));
    $group->post('/recibos/pdf', [FacturacionController::class, 'pdfRecibosImpresion'])
      ->add($setPermiso('facturacion-impresion', 'ver'));

    $group->get('/diario', [FacturacionController::class, 'listDiario'])
      ->add($setPermiso('facturacion-diario', 'ver'));
    $group->post('/diario/pdf', [FacturacionController::class, 'pdfDiario'])
      ->add($setPermiso('facturacion-diario', 'ver'));

    $group->get('/albaranes-pendientes', [FacturacionController::class, 'listAlbaranesPendientes'])
      ->add($setPermiso('facturacion-albaranes-pendientes', 'ver'));
    $group->get('/albaranes-pendientes/pdf', [FacturacionController::class, 'pdfAlbaranesPendientes'])
      ->add($setPermiso('facturacion-albaranes-pendientes', 'ver'));
    $group->get('/albaranes-pendientes/{empresa}/{tipo}/{albaran}', [FacturacionController::class, 'getAlbaranPendiente'])
      ->add($setPermiso('facturacion-albaranes-pendientes', 'ver'));
    $group->get(
      '/albaranes-pendientes/{empresa}/{tipo}/{albaran}/pdf',
      [FacturacionController::class, 'pdfAlbaranPendiente']
    )
      ->add($setPermiso('facturacion-albaranes-pendientes', 'ver'));

    $group->get('/retroceso/preview', [FacturacionController::class, 'previewRetroceso'])
      ->add($setPermiso('facturacion-retroceso', 'ver'));
    $group->post('/retroceso', [FacturacionController::class, 'ejecutarRetroceso'])
      ->add($setPermiso('facturacion-retroceso', 'crear'));
  })
    ->add(PermissionMiddleware::class)
    ->add(AuthMiddleware::class);
};
