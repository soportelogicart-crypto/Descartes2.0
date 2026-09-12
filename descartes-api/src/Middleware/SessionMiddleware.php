<?php

declare(strict_types=1);

namespace Descartes\Api\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SessionMiddleware implements MiddlewareInterface
{
  public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
  {
    $sessionName = $_ENV['SESSION_NAME'] ?? 'DESCARTES_SESSION';
    if (session_status() !== PHP_SESSION_ACTIVE) {
      session_name($sessionName);
      session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
      ]);
      session_start();
    }

    // Liberar el bloqueo del fichero de sesión salvo login/logout.
    // Si no, un GET largo (listado de clientes) deja Guardar/POST esperando
    // y en pantalla parece que el botón no hace nada.
    $path = $request->getUri()->getPath();
    if (!preg_match('#/api/auth/(login|logout)/?$#', $path)) {
      session_write_close();
    }

    return $handler->handle($request);
  }
}
