<?php

declare(strict_types=1);

namespace Descartes\Api\Controllers;

use Descartes\Api\Config\EntityConfig;
use Descartes\Api\Http\ErrorResponse;
use Descartes\Api\Services\DependencyException;
use Descartes\Api\Services\MantenimientoService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;

final class MantenimientoController
{
  private MantenimientoService $service;

  public function __construct(MantenimientoService $service)
  {
    $this->service = $service;
  }

  public function list(Request $request, Response $response, array $args): Response
  {
    $entidad = $args['entidad'];
    if (!EntityConfig::get($entidad)) {
      return ErrorResponse::json($response, 404, 'Entidad no encontrada', 'NO_ENCONTRADO');
    }

    $query = $request->getQueryParams();
    $payload = $this->service->list($entidad, $query);
    return $this->json($response, 200, $payload);
  }

  public function get(Request $request, Response $response, array $args): Response
  {
    $entidad = $args['entidad'];
    $codigo = $args['codigo'];
    if (!EntityConfig::get($entidad)) {
      return ErrorResponse::json($response, 404, 'Entidad no encontrada', 'NO_ENCONTRADO');
    }

    $item = $this->service->get($entidad, $codigo);
    if ($item === null) {
      return ErrorResponse::json($response, 404, 'Registro no encontrado', 'NO_ENCONTRADO');
    }

    return $this->json($response, 200, $item);
  }

  public function create(Request $request, Response $response, array $args): Response
  {
    $entidad = $args['entidad'];
    if (!EntityConfig::get($entidad)) {
      return ErrorResponse::json($response, 404, 'Entidad no encontrada', 'NO_ENCONTRADO');
    }

    $data = $this->requestData($request);
    try {
      $item = $this->service->create($entidad, $data);
      return $this->json($response, 201, $item);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\PDOException $e) {
      $mensaje = 'No se pudo crear el registro';
      if (($_ENV['APP_ENV'] ?? '') === 'local') {
        $mensaje .= ': ' . $e->getMessage();
      }
      return ErrorResponse::json($response, 409, $mensaje, 'CONFLICTO');
    }
  }

  public function update(Request $request, Response $response, array $args): Response
  {
    $entidad = $args['entidad'];
    $codigo = $args['codigo'];
    if (!EntityConfig::get($entidad)) {
      return ErrorResponse::json($response, 404, 'Entidad no encontrada', 'NO_ENCONTRADO');
    }

    $data = $this->requestData($request);
    try {
      $item = $this->service->update($entidad, $codigo, $data);
      if ($item === null) {
        return ErrorResponse::json($response, 404, 'Registro no encontrado', 'NO_ENCONTRADO');
      }
      return $this->json($response, 200, $item);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (DependencyException $e) {
      return ErrorResponse::json($response, 409, $e->getMessage(), 'DEPENDENCIA_ACTIVA', $e->getDependencias());
    }
  }

  public function delete(Request $request, Response $response, array $args): Response
  {
    $entidad = $args['entidad'];
    $codigo = $args['codigo'];
    if (!EntityConfig::get($entidad)) {
      return ErrorResponse::json($response, 404, 'Entidad no encontrada', 'NO_ENCONTRADO');
    }

    try {
      $this->service->softDelete($entidad, $codigo);
      return $response->withStatus(204);
    } catch (DependencyException $e) {
      return ErrorResponse::json($response, 409, $e->getMessage(), 'DEPENDENCIA_ACTIVA', $e->getDependencias());
    } catch (\PDOException $e) {
      $mensaje = 'No se pudo dar de baja el registro';
      if (($_ENV['APP_ENV'] ?? '') === 'local') {
        $mensaje .= ': ' . $e->getMessage();
      }
      return ErrorResponse::json($response, 409, $mensaje, 'CONFLICTO');
    } catch (\RuntimeException $e) {
      return ErrorResponse::json($response, 409, $e->getMessage(), 'CONFLICTO');
    }
  }

  private function requestData(Request $request): array
  {
    $parsed = $request->getParsedBody();
    if (is_array($parsed)) {
      return $parsed;
    }

    $decoded = json_decode((string) $request->getBody(), true);
    return is_array($decoded) ? $decoded : [];
  }

  private function json(Response $response, int $status, array $payload): Response
  {
    $response = new SlimResponse($status);
    $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
    return $response->withHeader('Content-Type', 'application/json');
  }
}
