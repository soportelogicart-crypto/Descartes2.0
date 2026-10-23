<?php

declare(strict_types=1);

namespace Descartes\Api\Controllers;

use Descartes\Api\Http\ErrorResponse;
use Descartes\Api\Services\TipoDescuentoService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;

final class TipoDescuentoController
{
  private TipoDescuentoService $service;

  public function __construct(TipoDescuentoService $service)
  {
    $this->service = $service;
  }

  public function list(Request $request, Response $response): Response
  {
    try {
      return $this->json($response, 200, $this->service->list($request->getQueryParams()));
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, 'No se pudo cargar los tipos de descuento', 'ERROR');
    }
  }

  public function get(Request $request, Response $response): Response
  {
    try {
      $item = $this->service->get($request->getQueryParams());
      if ($item === null) {
        return ErrorResponse::json($response, 404, 'Tipo de descuento no encontrado', 'NO_ENCONTRADO');
      }

      return $this->json($response, 200, $item);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, 'No se pudo cargar el tipo de descuento', 'ERROR');
    }
  }

  public function porCodigo(Request $request, Response $response, array $args): Response
  {
    try {
      $item = $this->service->resumenPorCodigo((string) ($args['tipoDescuento'] ?? ''));
      if ($item === null) {
        return ErrorResponse::json($response, 404, 'Tipo de descuento no encontrado', 'NO_ENCONTRADO', [], [], false);
      }

      return $this->json($response, 200, $item);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, 'No se pudo cargar el tipo de descuento', 'ERROR');
    }
  }

  public function create(Request $request, Response $response): Response
  {
    $body = $request->getParsedBody();
    if (!is_array($body)) {
      return ErrorResponse::json($response, 400, 'Datos no válidos', 'VALIDACION');
    }
    try {
      return $this->json($response, 201, $this->service->create($body));
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, 'No se pudo crear el tipo de descuento', 'ERROR');
    }
  }

  public function update(Request $request, Response $response): Response
  {
    $body = $request->getParsedBody();
    if (!is_array($body)) {
      return ErrorResponse::json($response, 400, 'Datos no válidos', 'VALIDACION');
    }
    try {
      return $this->json($response, 200, $this->service->update($body));
    } catch (\InvalidArgumentException $e) {
      $status = $e->getMessage() === 'Tipo de descuento no encontrado' ? 404 : 400;
      $codigo = $status === 404 ? 'NO_ENCONTRADO' : 'VALIDACION';

      return ErrorResponse::json($response, $status, $e->getMessage(), $codigo);
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, 'No se pudo guardar el tipo de descuento', 'ERROR');
    }
  }

  public function delete(Request $request, Response $response): Response
  {
    try {
      if (!$this->service->delete($request->getQueryParams())) {
        return ErrorResponse::json($response, 404, 'Tipo de descuento no encontrado', 'NO_ENCONTRADO');
      }

      return new SlimResponse(204);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, 'No se pudo eliminar el tipo de descuento', 'ERROR');
    }
  }

  /**
   * @param array<string, mixed> $payload
   */
  private function json(Response $response, int $status, array $payload): Response
  {
    $response = new SlimResponse($status);
    $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));

    return $response->withHeader('Content-Type', 'application/json');
  }
}
