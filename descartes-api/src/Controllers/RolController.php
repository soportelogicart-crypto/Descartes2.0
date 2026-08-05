<?php

declare(strict_types=1);

namespace Descartes\Api\Controllers;

use Descartes\Api\Http\ErrorResponse;
use Descartes\Api\Services\RolService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;

final class RolController
{
  private RolService $rolService;

  public function __construct(RolService $rolService)
  {
    $this->rolService = $rolService;
  }

  public function getPermisos(Request $request, Response $response, array $args): Response
  {
    try {
      $data = $this->rolService->obtenerPermisos($args['codigo']);
      return $this->json($response, 200, $data);
    } catch (\RuntimeException $e) {
      return ErrorResponse::json($response, 404, $e->getMessage(), 'NO_ENCONTRADO');
    }
  }

  public function putPermisos(Request $request, Response $response, array $args): Response
  {
    $body = (array) json_decode((string) $request->getBody(), true);
    $permisos = isset($body[0]) ? $body : ($body['permisos'] ?? []);

    try {
      $data = $this->rolService->actualizarPermisos($args['codigo'], $permisos);
      return $this->json($response, 200, $data);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      return ErrorResponse::json($response, 404, $e->getMessage(), 'NO_ENCONTRADO');
    }
  }

  private function json(Response $response, int $status, array $payload): Response
  {
    $response = new SlimResponse($status);
    $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
    return $response->withHeader('Content-Type', 'application/json');
  }
}
