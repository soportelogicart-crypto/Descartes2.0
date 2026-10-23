<?php

declare(strict_types=1);

namespace Descartes\Api\Controllers;

use Descartes\Api\Http\ErrorResponse;
use Descartes\Api\Repositories\CampanasRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;

final class CampanaController
{
  private CampanasRepository $repository;

  public function __construct(CampanasRepository $repository)
  {
    $this->repository = $repository;
  }

  public function list(Request $request, Response $response): Response
  {
    $query = $request->getQueryParams();
    try {
      $data = $this->repository->list($query);
      return $this->json($response, 200, $data);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function get(Request $request, Response $response, array $args): Response
  {
    $empresa = trim((string) ($args['empresa'] ?? ''));
    $campana = (int) ($args['campana'] ?? 0);
    if ($empresa === '' || $campana < 1) {
      return ErrorResponse::json($response, 400, 'Empresa y campana obligatorios', 'VALIDACION');
    }
    $item = $this->repository->findOne($empresa, $campana);
    if ($item === null) {
      return ErrorResponse::json($response, 404, 'Campana no encontrada', 'NO_ENCONTRADO');
    }
    return $this->json($response, 200, $item);
  }

  public function create(Request $request, Response $response): Response
  {
    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      $item = $this->repository->create($body);
      return $this->json($response, 201, $item);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function update(Request $request, Response $response, array $args): Response
  {
    $empresa = trim((string) ($args['empresa'] ?? ''));
    $campana = (int) ($args['campana'] ?? 0);
    if ($empresa === '' || $campana < 1) {
      return ErrorResponse::json($response, 400, 'Empresa y campana obligatorios', 'VALIDACION');
    }
    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      $item = $this->repository->update($empresa, $campana, $body);
      if ($item === null) {
        return ErrorResponse::json($response, 404, 'Campana no encontrada', 'NO_ENCONTRADO');
      }
      return $this->json($response, 200, $item);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function delete(Request $request, Response $response, array $args): Response
  {
    $empresa = trim((string) ($args['empresa'] ?? ''));
    $campana = (int) ($args['campana'] ?? 0);
    if ($empresa === '' || $campana < 1) {
      return ErrorResponse::json($response, 400, 'Empresa y campana obligatorios', 'VALIDACION');
    }
    try {
      $ok = $this->repository->delete($empresa, $campana);
      if (!$ok) {
        return ErrorResponse::json($response, 404, 'Campana no encontrada', 'NO_ENCONTRADO');
      }
      return new SlimResponse(204);
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
