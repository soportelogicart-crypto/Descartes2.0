<?php

declare(strict_types=1);

use Descartes\Api\Config\EntityConfig;
use Descartes\Api\Controllers\ImportacionController;
use Descartes\Api\Middleware\AuthMiddleware;
use Descartes\Api\Middleware\PermissionMiddleware;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app): void {
  // Importar = dar de alta: mismo permiso "crear" que el modulo de cada entidad.
  $permisoCrear = function (ServerRequestInterface $request, RequestHandlerInterface $handler) {
    $route = $request->getAttribute('__route__');
    $entidad = $route?->getArgument('entidad');
    $config = $entidad ? EntityConfig::get((string) $entidad) : null;
    if ($config !== null) {
      $request = $request
        ->withAttribute('permisoModulo', $config['modulo'])
        ->withAttribute('permisoAccion', 'crear');
    }

    return $handler->handle($request);
  };

  $app->group('/api/importacion', function (RouteCollectorProxy $group) use ($permisoCrear) {
    // Definicion de hojas y columnas: no lleva datos, basta con estar autenticado.
    $group->get('/columnas', [ImportacionController::class, 'columnas']);
    $group->post('/{entidad}', [ImportacionController::class, 'importar'])->add($permisoCrear);
  })
    ->add(PermissionMiddleware::class)
    ->add(AuthMiddleware::class);
};
