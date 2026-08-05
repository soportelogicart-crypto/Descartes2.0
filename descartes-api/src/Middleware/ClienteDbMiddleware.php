<?php

declare(strict_types=1);

namespace Descartes\Api\Middleware;

use Descartes\Api\Config\Database;
use Descartes\Api\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Selecciona la conexion PDO por cliente (Principio III).
 * v1: siempre resuelve a larasa del .env; interfaz lista para multi-cliente.
 */
final class ClienteDbMiddleware implements MiddlewareInterface
{
  public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
  {
    $clientId = $request->getHeaderLine('X-Cliente-Id');
    if ($clientId === '') {
      $clientId = $_ENV['DEFAULT_CLIENT_ID'] ?? 'default';
    }

    $clients = require dirname(__DIR__) . '/Config/clients.php';
    $config = $clients[$clientId] ?? [
      'server' => $_ENV['DB_SERVER'] ?? 'localhost',
      'database' => $_ENV['DB_NAME'] ?? 'larasa',
      'user' => $_ENV['DB_USER'] ?? '',
      'password' => $_ENV['DB_PASSWORD'] ?? '',
      'trust_cert' => $_ENV['DB_TRUST_CERT'] ?? 'true',
    ];

    $pdo = Database::createPdo($config);
    RequestContext::setPdo($pdo);

    return $handler->handle(
      $request
        ->withAttribute('pdo', $pdo)
        ->withAttribute('clienteId', $clientId)
    );
  }
}
