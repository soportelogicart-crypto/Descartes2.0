<?php

declare(strict_types=1);

namespace Descartes\Api\Middleware;

use Descartes\Api\Http\ErrorResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class AuthMiddleware implements MiddlewareInterface
{
  public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
  {
    if (!isset($_SESSION['usuario'])) {
      return ErrorResponse::json(new Response(), 401, 'Sesion no iniciada', 'NO_AUTENTICADO');
    }

    return $handler->handle($request->withAttribute('usuario', $_SESSION['usuario']));
  }
}
