<?php

declare(strict_types=1);

use Descartes\Api\Controllers\EncargoController;
use Descartes\Api\Middleware\AuthMiddleware;
use Descartes\Api\Middleware\PermissionMiddleware;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app): void {
  $setPermiso = function (string $accion): callable {
    return function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($accion) {
      return $handler->handle(
        $request
          ->withAttribute('permisoModulo', 'ventas')
          ->withAttribute('permisoAccion', $accion)
      );
    };
  };

  $app->group('/api/encargos', function (RouteCollectorProxy $group) use ($setPermiso) {
    $group->get('', [EncargoController::class, 'list'])->add($setPermiso('ver'));
    $group->get('/clientes', [EncargoController::class, 'clientes'])->add($setPermiso('ver'));
    $group->get('/albaran', [EncargoController::class, 'albaran'])->add($setPermiso('ver'));
    $group->get('/qr', [EncargoController::class, 'qr'])->add($setPermiso('ver'));
    $group->get('/tiendas', [EncargoController::class, 'tiendas'])->add($setPermiso('ver'));
    $group->get('/tiendas/{codigo}/logo', [EncargoController::class, 'logoTienda'])->add($setPermiso('ver'));
    $group->get('/siguiente', [EncargoController::class, 'siguiente'])->add($setPermiso('ver'));
    $group->post('', [EncargoController::class, 'create'])->add($setPermiso('crear'));
    $group->post('/recibir', [EncargoController::class, 'recibir'])->add($setPermiso('editar'));
    $group->post('/expedir-dia', [EncargoController::class, 'expedirDia'])->add($setPermiso('editar'));
    $group->get('/{numero}', [EncargoController::class, 'get'])->add($setPermiso('ver'));
    $group->post('/{numero}/realizar', [EncargoController::class, 'realizar'])->add($setPermiso('editar'));
    $group->post('/{numero}/expedir', [EncargoController::class, 'expedir'])->add($setPermiso('editar'));
    $group->post('/{numero}/devolver', [EncargoController::class, 'devolver'])->add($setPermiso('editar'));
    $group->post('/{numero}/entregar', [EncargoController::class, 'entregar'])->add($setPermiso('editar'));
    $group->post('/{numero}/modalidad', [EncargoController::class, 'modalidad'])->add($setPermiso('editar'));
    $group->post('/{numero}/etiqueta', [EncargoController::class, 'etiqueta'])->add($setPermiso('ver'));
    $group->post('/{numero}/etiqueta-transporte', [EncargoController::class, 'etiquetaTransporte'])->add($setPermiso('ver'));
  })
    ->add(PermissionMiddleware::class)
    ->add(AuthMiddleware::class);
};
