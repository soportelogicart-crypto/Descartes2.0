<?php

declare(strict_types=1);

namespace Descartes\Api\Controllers;

use Descartes\Api\Http\ErrorResponse;
use Descartes\Api\Services\Facturacion\AlbaranesPeriodicosConsultaService;
use Descartes\Api\Services\Facturacion\AlbaranesPeriodicosEscrituraService;
use Descartes\Api\Services\Facturacion\AlbaranesPeriodicosService;
use Descartes\Api\Services\Ventas\VentaConsultaService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;

final class AlbaranesPeriodicosController
{
  private AlbaranesPeriodicosConsultaService $consulta;
  private AlbaranesPeriodicosEscrituraService $escritura;
  private AlbaranesPeriodicosService $generacion;
  private VentaConsultaService $ventas;

  public function __construct(
    AlbaranesPeriodicosConsultaService $consulta,
    AlbaranesPeriodicosEscrituraService $escritura,
    AlbaranesPeriodicosService $generacion,
    VentaConsultaService $ventas
  ) {
    $this->consulta = $consulta;
    $this->escritura = $escritura;
    $this->generacion = $generacion;
    $this->ventas = $ventas;
  }

  public function list(Request $request, Response $response): Response
  {
    try {
      return $this->json($response, 200, $this->consulta->listar($request->getQueryParams()));
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return $this->error($response, $e);
    }
  }

  /** Documentos de venta candidatos a plantilla (presupuesto / albarán). */
  public function buscarPlantillas(Request $request, Response $response): Response
  {
    try {
      return $this->json($response, 200, $this->ventas->listar($request->getQueryParams()));
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return $this->error($response, $e);
    }
  }

  public function get(Request $request, Response $response, array $args): Response
  {
    [$empresa, $tipo, $albaran] = $this->parsePk($args);
    if ($empresa === null) {
      return ErrorResponse::json($response, 400, 'empresa, tipo y albaran obligatorios', 'VALIDACION');
    }

    $item = $this->consulta->obtener($empresa, $tipo, $albaran);
    if ($item === null) {
      return ErrorResponse::json($response, 404, 'Base periódica no encontrada', 'NO_ENCONTRADO');
    }

    return $this->json($response, 200, $item);
  }

  public function create(Request $request, Response $response): Response
  {
    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      return $this->json($response, 201, $this->escritura->crear($body));
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return $this->error($response, $e);
    }
  }

  public function update(Request $request, Response $response, array $args): Response
  {
    [$empresa, $tipo, $albaran] = $this->parsePk($args);
    if ($empresa === null) {
      return ErrorResponse::json($response, 400, 'empresa, tipo y albaran obligatorios', 'VALIDACION');
    }

    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      $item = $this->escritura->actualizar($empresa, $tipo, $albaran, $body);
      if ($item === null) {
        return ErrorResponse::json($response, 404, 'Base periódica no encontrada', 'NO_ENCONTRADO');
      }
      return $this->json($response, 200, $item);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return $this->error($response, $e);
    }
  }

  public function delete(Request $request, Response $response, array $args): Response
  {
    [$empresa, $tipo, $albaran] = $this->parsePk($args);
    if ($empresa === null) {
      return ErrorResponse::json($response, 400, 'empresa, tipo y albaran obligatorios', 'VALIDACION');
    }

    try {
      if (!$this->escritura->eliminar($empresa, $tipo, $albaran)) {
        return ErrorResponse::json($response, 404, 'Base periódica no encontrada', 'NO_ENCONTRADO');
      }
      return new SlimResponse(204);
    } catch (\Throwable $e) {
      return $this->error($response, $e);
    }
  }

  public function generar(Request $request, Response $response, array $args): Response
  {
    [$empresa, $tipo, $albaran] = $this->parsePk($args);
    if ($empresa === null) {
      return ErrorResponse::json($response, 400, 'empresa, tipo y albaran obligatorios', 'VALIDACION');
    }

    $body = (array) json_decode((string) $request->getBody(), true);
    $fechaReferencia = isset($body['fechaReferencia']) ? trim((string) $body['fechaReferencia']) : null;
    if ($fechaReferencia === '') {
      $fechaReferencia = null;
    }

    try {
      return $this->json(
        $response,
        200,
        $this->generacion->generarUno($empresa, $tipo, $albaran, $fechaReferencia)
      );
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return $this->error($response, $e);
    }
  }

  /** @return array{0: ?string, 1: string, 2: int} */
  private function parsePk(array $args): array
  {
    $empresa = trim((string) ($args['empresa'] ?? ''));
    $tipo = trim((string) ($args['tipo'] ?? ''));
    $albaran = (int) ($args['albaran'] ?? 0);
    if ($empresa === '' || $tipo === '' || $albaran < 1) {
      return [null, '', 0];
    }

    return [$empresa, $tipo, $albaran];
  }

  private function json(Response $response, int $status, array $payload): Response
  {
    $response = new SlimResponse($status);
    $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));

    return $response->withHeader('Content-Type', 'application/json');
  }

  private function error(Response $response, \Throwable $e): Response
  {
    $code = $e->getCode();
    if ($code === 404) {
      return ErrorResponse::json($response, 404, $e->getMessage(), 'NO_ENCONTRADO');
    }
    if ($code === 409) {
      return ErrorResponse::json($response, 409, $e->getMessage(), 'CONFLICTO');
    }

    return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
  }
}
