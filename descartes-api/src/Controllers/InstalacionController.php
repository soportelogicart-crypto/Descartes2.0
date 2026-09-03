<?php

declare(strict_types=1);

namespace Descartes\Api\Controllers;

use Descartes\Api\Http\ErrorResponse;
use Descartes\Api\Services\Instalacion\InstalacionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class InstalacionController
{
  private InstalacionService $service;

  public function __construct(InstalacionService $service)
  {
    $this->service = $service;
  }

  public function estado(Request $request, Response $response): Response
  {
    return $this->json($response, 200, $this->service->obtenerEstado());
  }

  public function probar(Request $request, Response $response): Response
  {
    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      return $this->json($response, 200, $this->service->probarConexion($body));
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    }
  }

  public function configurar(Request $request, Response $response): Response
  {
    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      $data = $this->service->configurarYAplicar($body);
      return $this->json($response, 200, $data);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'MIGRACION');
    }
  }

  public function migrar(Request $request, Response $response): Response
  {
    try {
      $data = $this->service->aplicarMigracionesActivas();
      return $this->json($response, 200, $data);
    } catch (\RuntimeException $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'MIGRACION');
    }
  }

  /** @param array<string, mixed> $data */
  private function json(Response $response, int $status, array $data): Response
  {
    $response->getBody()->write((string) json_encode($data, JSON_UNESCAPED_UNICODE));
    return $response
      ->withHeader('Content-Type', 'application/json; charset=utf-8')
      ->withStatus($status);
  }
}
