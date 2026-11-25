<?php

declare(strict_types=1);

namespace Descartes\Api\Controllers;

use Descartes\Api\Http\ErrorResponse;
use Descartes\Api\Services\Importacion\ImportacionFilaValidator;
use Descartes\Api\Services\Importacion\ImportacionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;

final class ImportacionController
{
  private ImportacionService $service;

  public function __construct(ImportacionService $service)
  {
    $this->service = $service;
  }

  public function columnas(Request $request, Response $response): Response
  {
    return $this->json(200, $this->service->columnas());
  }

  public function importar(Request $request, Response $response, array $args): Response
  {
    $entidad = (string) ($args['entidad'] ?? '');
    if (ImportacionFilaValidator::hoja($entidad) === null) {
      return ErrorResponse::json($response, 404, 'Entidad no importable', 'NO_ENCONTRADO');
    }

    $body = $this->requestData($request);
    $filas = $body['filas'] ?? null;
    if (!is_array($filas)) {
      return ErrorResponse::json($response, 400, 'Falta la lista de filas', 'VALIDACION');
    }
    $contexto = is_array($body['contexto'] ?? null) ? $body['contexto'] : [];

    try {
      $resultado = $this->service->importar(
        $entidad,
        array_values($filas),
        (bool) ($body['simulacro'] ?? false),
        $contexto,
        trim((string) ($body['empresaCodigo'] ?? ''))
      );
      return $this->json(200, $resultado);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    }
  }

  /** @return array<string, mixed> */
  private function requestData(Request $request): array
  {
    $parsed = $request->getParsedBody();
    if (is_array($parsed)) {
      return $parsed;
    }

    $decoded = json_decode((string) $request->getBody(), true);
    return is_array($decoded) ? $decoded : [];
  }

  /** @param array<string, mixed> $payload */
  private function json(int $status, array $payload): Response
  {
    $response = new SlimResponse($status);
    $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
    return $response->withHeader('Content-Type', 'application/json');
  }
}
