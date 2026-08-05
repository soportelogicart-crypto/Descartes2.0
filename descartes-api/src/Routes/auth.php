<?php

declare(strict_types=1);

use Descartes\Api\Controllers\AuthController;
use Descartes\Api\Middleware\AuthMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app): void {
  $app->group('/api/auth', function (RouteCollectorProxy $group) {
    $group->post('/login', [AuthController::class, 'login']);
    $group->post('/logout', [AuthController::class, 'logout'])->add(AuthMiddleware::class);
    $group->get('/me', [AuthController::class, 'me'])->add(AuthMiddleware::class);
  });

  // Errores del frontend (sin auth estricto: tambien fallos de login).
  $app->post('/api/logs', [\Descartes\Api\Controllers\LogController::class, 'create']);
};
