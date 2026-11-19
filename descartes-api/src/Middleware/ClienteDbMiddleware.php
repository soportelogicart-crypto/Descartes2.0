<?php

declare(strict_types=1);

namespace Descartes\Api\Middleware;

use Descartes\Api\Config\CatalogoInstalaciones;
use Descartes\Api\Config\Database;
use Descartes\Api\Http\ErrorResponse;
use Descartes\Api\RequestContext;
use Descartes\Api\Services\Instalacion\MigrationService;
use Descartes\Api\Services\Offline\OfflineEstadoService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Elige la base de la petición.
 * Sin X-Cliente-Id se usa la instalación por defecto (var/instalacion.json o .env).
 * Con identificador, hace falta X-Cliente-Clave y se abre solo esa instalación.
 */
final class ClienteDbMiddleware implements MiddlewareInterface
{
  public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
  {
    $path = $request->getUri()->getPath();
    $isInstalacion = str_contains($path, '/api/instalacion');

    $pedido = strtolower(trim($request->getHeaderLine('X-Cliente-Id')));
    $clave = $request->getHeaderLine('X-Cliente-Clave');
    $explicita = $pedido !== '' && $pedido !== CatalogoInstalaciones::DEFECTO;
    $clientId = $explicita ? $pedido : CatalogoInstalaciones::DEFECTO;

    // Alta de una instalación nueva: no depende de la base ni de la sesión de este PC.
    $esAlta = $request->getMethod() === 'POST' && preg_match('#/api/instalacion/clientes$#', $path) === 1;
    if ($esAlta) {
      $explicita = false;
      $clientId = CatalogoInstalaciones::DEFECTO;
    }

    $sesionCliente = isset($_SESSION['usuario'])
      ? (string) ($_SESSION['clienteId'] ?? CatalogoInstalaciones::DEFECTO)
      : null;
    if (!$esAlta && $sesionCliente !== null && $sesionCliente !== $clientId) {
      return ErrorResponse::json(
        new Response(),
        401,
        'La sesión es de otra instalación. Vuelva a entrar.',
        'SESION_OTRA_INSTALACION',
        [],
        [],
        false
      );
    }

    if ($explicita) {
      $config = CatalogoInstalaciones::autenticar($pedido, $clave);
      if ($config === null) {
        return ErrorResponse::json(
          new Response(),
          401,
          'Instalación desconocida o clave incorrecta',
          'INSTALACION_NO_AUTORIZADA',
          [],
          [],
          false
        );
      }
    } else {
      $config = Database::resolveConfig();
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
      $_SESSION['clienteId'] = $clientId;
    }

    $request = $request
      ->withAttribute('clienteId', $clientId)
      ->withAttribute('instalacionSql', $explicita ? $config : null);

    try {
      $pdo = Database::createPdo($config);
      if ($this->autoMigrateEnabled()) {
        $this->aplicarMigracionesSilenciosas($pdo, $config);
      }
      RequestContext::setPdo($pdo);
    } catch (\Throwable $e) {
      // El ejecutable toma 502/503/504 como "no hay internet". La base no se ha
      // abierto, pero el PHP sí ha contestado: 500 con JSON no corta la conexión.
      if (str_contains($path, '/api/offline/estado')) {
        return $this->pingSinBase();
      }
      if ($isInstalacion && !$explicita) {
        return $handler->handle(
          $request
            ->withAttribute('dbConnectionError', $e->getMessage())
            ->withAttribute('clienteId', $clientId)
        );
      }
      return ErrorResponse::json(
        new Response(),
        500,
        'No se pudo abrir la base de la instalación ' . $clientId . ': ' . $e->getMessage(),
        'INSTALACION_SIN_CONEXION',
        [],
        ['clienteId' => $clientId],
        true
      );
    }

    return $handler->handle(
      $request
        ->withAttribute('pdo', $pdo)
        ->withAttribute('clienteId', $clientId)
    );
  }

  /** El ping del ejecutable solo comprueba que el PHP vive. No abre la base. */
  private function pingSinBase(): ResponseInterface
  {
    $response = new Response();
    $response->getBody()->write((string) json_encode([
      'ok' => true,
      'hora' => date('c'),
      'versionDatos' => OfflineEstadoService::VERSION_DATOS,
      'versionCatalogo' => '0',
    ], JSON_UNESCAPED_UNICODE));

    return $response->withHeader('Content-Type', 'application/json');
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
