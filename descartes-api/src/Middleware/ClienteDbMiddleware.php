<?php

declare(strict_types=1);

namespace Descartes\Api\Middleware;

use Descartes\Api\Config\Database;
use Descartes\Api\RequestContext;
use Descartes\Api\Services\Instalacion\MigrationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Selecciona la conexion PDO por cliente (Principio III).
 * Config: var/instalacion.json o .env. Opcional AUTO_MIGRATE=true al conectar.
 */
final class ClienteDbMiddleware implements MiddlewareInterface
{
  public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
  {
    $path = $request->getUri()->getPath();
    $isInstalacion = str_contains($path, '/api/instalacion');

    $clientId = $request->getHeaderLine('X-Cliente-Id');
    if ($clientId === '') {
      $clientId = $_ENV['DEFAULT_CLIENT_ID'] ?? 'default';
    }

    $clients = require dirname(__DIR__) . '/Config/clients.php';
    unset($clients);
    $config = Database::resolveConfig();

    try {
      $pdo = Database::createPdo($config);
      if ($this->autoMigrateEnabled()) {
        $this->aplicarMigracionesSilenciosas($pdo, $config);
      }
      RequestContext::setPdo($pdo);
    } catch (\Throwable $e) {
      if ($isInstalacion) {
        return $handler->handle(
          $request
            ->withAttribute('dbConnectionError', $e->getMessage())
            ->withAttribute('clienteId', $clientId)
        );
      }
      throw $e;
    }

    return $handler->handle(
      $request
        ->withAttribute('pdo', $pdo)
        ->withAttribute('clienteId', $clientId)
    );
  }

  private function autoMigrateEnabled(): bool
  {
    return filter_var($_ENV['AUTO_MIGRATE'] ?? getenv('AUTO_MIGRATE') ?: 'false', FILTER_VALIDATE_BOOL);
  }

  /** @param array{server: string, database: string, user: string, password: string, trust_cert: bool} $config */
  private function aplicarMigracionesSilenciosas(\PDO $pdo, array $config): void
  {
    static $done = false;
    if ($done) {
      return;
    }
    $done = true;
    try {
      $service = new MigrationService();
      $estado = $service->estado($config);
      if ($estado['conexionOk'] && $estado['pendientes'] !== []) {
        $service->aplicarPendientes($config);
      }
    } catch (\Throwable $e) {
      // No bloquear el arranque; el asistente de instalacion permite corregir.
    }
  }
}
