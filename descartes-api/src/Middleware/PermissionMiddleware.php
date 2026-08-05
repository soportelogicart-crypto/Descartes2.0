<?php

declare(strict_types=1);

namespace Descartes\Api\Middleware;

use Descartes\Api\Http\ErrorResponse;
use Descartes\Api\Services\PermissionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class PermissionMiddleware implements MiddlewareInterface
{
  private PermissionService $permissionService;

  public function __construct(PermissionService $permissionService)
  {
    $this->permissionService = $permissionService;
  }

  public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
  {
    $modulo = $request->getAttribute('permisoModulo');
    $accion = $request->getAttribute('permisoAccion');

    if ($modulo === null || $accion === null) {
      return $handler->handle($request);
    }

    $usuario = $request->getAttribute('usuario') ?? $_SESSION['usuario'] ?? null;
    if ($usuario === null) {
      return ErrorResponse::json(new Response(), 401, 'Sesion no iniciada', 'NO_AUTENTICADO');
    }

    if (!$this->permissionService->puede($usuario, (string) $modulo, (string) $accion)) {
      return ErrorResponse::json(
        new Response(),
        403,
        'No tiene permiso para esta accion',
        'PERMISO_INSUFICIENTE'
      );
    }

    return $handler->handle($request);
  }
}
