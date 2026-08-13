<?php

declare(strict_types=1);

namespace Descartes\Api\Controllers;

use Descartes\Api\Http\ErrorResponse;
use Descartes\Api\Services\Etiquetas\EtiquetaColaService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Módulo Etiquetas (005).
 * Cola CRUD (T011). Imprimir / albarán / preview → T020+.
 */
final class EtiquetasController
{
  private EtiquetaColaService $cola;

  public function __construct(EtiquetaColaService $cola)
  {
    $this->cola = $cola;
  }

  public function ping(Request $request, Response $response): Response
  {
    return $this->json($response, 200, [
      'ok' => true,
      'modulo' => 'etiquetas',
      'message' => 'API Etiquetas montada',
    ]);
  }

  public function listar(Request $request, Response $response): Response
  {
    try {
      return $this->json($response, 200, $this->cola->listar($request->getQueryParams()));
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function crear(Request $request, Response $response): Response
  {
    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      $item = $this->cola->crear($body);
      return $this->json($response, 201, $item);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      return $this->runtimeError($response, $e);
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function actualizar(Request $request, Response $response, array $args): Response
  {
    $articulo = trim((string) ($args['articulo'] ?? ''));
    $nroLin = (int) ($args['nroLin'] ?? 0);
    if ($articulo === '' || $nroLin <= 0) {
      return ErrorResponse::json($response, 400, 'Artículo y nroLin son obligatorios', 'VALIDACION');
    }

    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      $item = $this->cola->actualizar($articulo, $nroLin, $body);
      return $this->json($response, 200, $item);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      return $this->runtimeError($response, $e);
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function eliminar(Request $request, Response $response, array $args): Response
  {
    $articulo = trim((string) ($args['articulo'] ?? ''));
    $nroLin = (int) ($args['nroLin'] ?? 0);
    if ($articulo === '' || $nroLin <= 0) {
      return ErrorResponse::json($response, 400, 'Artículo y nroLin son obligatorios', 'VALIDACION');
    }

    try {
      $this->cola->eliminar($articulo, $nroLin);
      return $response->withStatus(204);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      return $this->runtimeError($response, $e);
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function confirmarImpresion(Request $request, Response $response): Response
  {
    $body = (array) json_decode((string) $request->getBody(), true);
    $lineas = $body['lineas'] ?? null;
    if (!is_array($lineas) || $lineas === []) {
      return ErrorResponse::json(
        $response,
        400,
        'lineas es obligatorio (array no vacío de { articulo, nroLin })',
        'VALIDACION'
      );
    }

    try {
      $result = $this->cola->confirmarImpresion($lineas);
      return $this->json($response, 200, $result);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function desdeAlbaranCompra(Request $request, Response $response): Response
  {
    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      $result = $this->cola->desdeAlbaranCompra($body);
      return $this->json($response, 201, $result);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      return $this->runtimeError($response, $e);
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function previewDatos(Request $request, Response $response): Response
  {
    return ErrorResponse::json($response, 501, 'Preview datos no implementado (T020)', 'NO_IMPLEMENTADO');
  }

  private function runtimeError(Response $response, \RuntimeException $e): Response
  {
    $code = (int) $e->getCode();
    if ($code === 404) {
      return ErrorResponse::json($response, 404, $e->getMessage(), 'NO_ENCONTRADO');
    }
    if ($code === 409) {
      return ErrorResponse::json($response, 409, $e->getMessage(), 'CONFLICTO');
    }
    return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
  }

  /** @param mixed $data */
  private function json(Response $response, int $status, $data): Response
  {
    $response->getBody()->write((string) json_encode($data, JSON_UNESCAPED_UNICODE));
    return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
  }
}
