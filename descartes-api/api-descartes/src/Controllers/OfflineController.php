<?php

declare(strict_types=1);

namespace Descartes\Api\Controllers;

use Descartes\Api\Http\ErrorResponse;
use Descartes\Api\Services\Offline\OfflineEstadoService;
use Descartes\Api\Services\Offline\OfflineSnapshotService;
use Descartes\Api\Services\Offline\OfflineVentaService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Modo sin conexión del TPV: ping, foto de datos y alta de ventas pendientes. */
final class OfflineController
{
  private OfflineEstadoService $estado;
  private OfflineSnapshotService $snapshot;
  private OfflineVentaService $ventas;

  public function __construct(
    OfflineEstadoService $estado,
    OfflineSnapshotService $snapshot,
    OfflineVentaService $ventas
  ) {
    $this->estado = $estado;
    $this->snapshot = $snapshot;
    $this->ventas = $ventas;
  }

  public function getEstado(Request $request, Response $response): Response
  {
    try {
      return $this->json($response, 200, $this->estado->estado());
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  /** GET /snapshot (foto completa) y /cambios (solo bloques cuyo hash no coincide). */
  public function getSnapshot(Request $request, Response $response): Response
  {
    $q = $request->getQueryParams();
    $hashes = [];
    foreach (explode(',', (string) ($q['hashes'] ?? '')) as $par) {
      [$bloque, $hash] = array_pad(explode(':', $par, 2), 2, '');
      if ($bloque !== '' && $hash !== '') {
        $hashes[trim($bloque)] = trim($hash);
      }
    }
    try {
      $datos = $this->snapshot->snapshot(
        (string) ($q['empresa'] ?? ''),
        (string) ($q['puesto'] ?? ''),
        $hashes
      );
      return $this->json($response, 200, $datos);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function postVenta(Request $request, Response $response): Response
  {
    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      return $this->json($response, 200, $this->ventas->registrar($body));
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 422, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      $code = (int) $e->getCode();
      if ($code === 409) {
        return ErrorResponse::json($response, 409, $e->getMessage(), 'EN_PROCESO');
      }
      if ($code === 404) {
        return ErrorResponse::json($response, 422, $e->getMessage(), 'VALIDACION');
      }
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  /** @param array<string, mixed> $data */
  private function json(Response $response, int $status, array $data): Response
  {
    $response->getBody()->write((string) json_encode($data, JSON_UNESCAPED_UNICODE));
    return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
  }
}
