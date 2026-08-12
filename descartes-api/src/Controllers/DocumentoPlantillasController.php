<?php

declare(strict_types=1);

namespace Descartes\Api\Controllers;

use Descartes\Api\Http\ErrorResponse;
use Descartes\Api\Services\DocumentoPlantillasService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;

final class DocumentoPlantillasController
{
  private DocumentoPlantillasService $service;

  public function __construct(DocumentoPlantillasService $service)
  {
    $this->service = $service;
  }

  public function list(Request $request, Response $response): Response
  {
    $q = $request->getQueryParams();
    $empresa = isset($q['empresa']) ? trim((string) $q['empresa']) : null;
    $tipo = isset($q['tipo']) ? trim((string) $q['tipo']) : null;
    try {
      $items = $this->service->listar($empresa === '' ? null : $empresa, $tipo === '' ? null : $tipo);
      return $this->json($response, 200, ['items' => $items]);
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function get(Request $request, Response $response, array $args): Response
  {
    $id = (int) ($args['id'] ?? 0);
    try {
      $item = $this->service->obtener($id);
      if ($item === null) {
        return ErrorResponse::json($response, 404, 'Plantilla no encontrada', 'NO_ENCONTRADO');
      }
      return $this->json($response, 200, $item);
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function create(Request $request, Response $response): Response
  {
    $body = (array) ($request->getParsedBody() ?? []);
    if ($body === []) {
      $body = (array) json_decode((string) $request->getBody(), true);
    }
    try {
      $item = $this->service->crear($body);
      return $this->json($response, 201, $item);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function update(Request $request, Response $response, array $args): Response
  {
    $id = (int) ($args['id'] ?? 0);
    $body = (array) ($request->getParsedBody() ?? []);
    if ($body === []) {
      $body = (array) json_decode((string) $request->getBody(), true);
    }
    try {
      $item = $this->service->actualizar($id, $body);
      return $this->json($response, 200, $item);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      return ErrorResponse::json($response, 404, $e->getMessage(), 'NO_ENCONTRADO');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function delete(Request $request, Response $response, array $args): Response
  {
    $id = (int) ($args['id'] ?? 0);
    try {
      $this->service->eliminar($id);
      return $this->json($response, 200, ['ok' => true]);
    } catch (\RuntimeException $e) {
      return ErrorResponse::json($response, 404, $e->getMessage(), 'NO_ENCONTRADO');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function activar(Request $request, Response $response, array $args): Response
  {
    $id = (int) ($args['id'] ?? 0);
    try {
      $item = $this->service->activar($id);
      return $this->json($response, 200, $item);
    } catch (\RuntimeException $e) {
      return ErrorResponse::json($response, 404, $e->getMessage(), 'NO_ENCONTRADO');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function sembrar(Request $request, Response $response): Response
  {
    $body = (array) ($request->getParsedBody() ?? []);
    if ($body === []) {
      $body = (array) json_decode((string) $request->getBody(), true);
    }
    $empresa = trim((string) ($body['empresaCodigo'] ?? ''));
    $skeletons = $body['skeletons'] ?? [];
    if (!is_array($skeletons)) {
      return ErrorResponse::json($response, 400, 'skeletons debe ser un array', 'VALIDACION');
    }
    try {
      $items = $this->service->sembrar($empresa, $skeletons);
      return $this->json($response, 200, ['items' => $items]);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  private function json(Response $response, int $status, array $payload): Response
  {
    $response = new SlimResponse($status);
    $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
    return $response->withHeader('Content-Type', 'application/json');
  }
}
