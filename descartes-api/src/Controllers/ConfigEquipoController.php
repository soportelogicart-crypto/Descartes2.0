<?php

declare(strict_types=1);

namespace Descartes\Api\Controllers;

use Descartes\Api\Http\ErrorResponse;
use Descartes\Api\Services\ConfigEquipoService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;

final class ConfigEquipoController
{
  private ConfigEquipoService $service;

  public function __construct(ConfigEquipoService $service)
  {
    $this->service = $service;
  }

  public function get(Request $request, Response $response, array $args): Response
  {
    $equipoId = (string) ($args['equipoId'] ?? '');

    try {
      $data = $this->service->obtener($equipoId);
      if ($data === null) {
        return ErrorResponse::json($response, 404, 'Configuracion de equipo no encontrada', 'NO_ENCONTRADO');
      }
      return $this->json($response, 200, $data);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    }
  }

  public function put(Request $request, Response $response, array $args): Response
  {
    $equipoId = (string) ($args['equipoId'] ?? '');
    $body = (array) json_decode((string) $request->getBody(), true);

    try {
      $data = $this->service->guardar($equipoId, $body);
      return $this->json($response, 200, $data);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    }
  }

  private function json(Response $response, int $status, array $payload): Response
  {
    $response = new SlimResponse($status);
    $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
    return $response->withHeader('Content-Type', 'application/json');
  }
}
