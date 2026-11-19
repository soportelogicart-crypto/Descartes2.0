<?php

declare(strict_types=1);

namespace Descartes\Api\Controllers;

use Descartes\Api\Http\ErrorResponse;
use Descartes\Api\Services\CarpetaCompartidaService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;

final class CarpetaCompartidaController
{
  private CarpetaCompartidaService $service;

  public function __construct(CarpetaCompartidaService $service)
  {
    $this->service = $service;
  }

  public function get(Request $request, Response $response): Response
  {
    try {
      return $this->json($response, 200, $this->service->obtener());
    } catch (\RuntimeException $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR_INTERNO');
    }
  }

  public function put(Request $request, Response $response): Response
  {
    $body = (array) json_decode((string) $request->getBody(), true);

    try {
      return $this->json($response, 200, $this->service->guardar($body));
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR_INTERNO');
    }
  }

  private function json(Response $response, int $status, array $payload): Response
  {
    $response = new SlimResponse($status);
    $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
    return $response->withHeader('Content-Type', 'application/json');
  }
}
