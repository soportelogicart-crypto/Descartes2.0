<?php

declare(strict_types=1);

namespace Descartes\Api\Controllers;

use Descartes\Api\Http\ErrorResponse;
use Descartes\Api\Repositories\ProveedoresContactosRepository;
use Descartes\Api\Repositories\ProveedoresEstadisticaRepository;
use Descartes\Api\Services\MantenimientoService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;

final class ProveedorController
{
  private ProveedoresContactosRepository $contactosRepository;
  private ProveedoresEstadisticaRepository $estadisticaRepository;
  private MantenimientoService $mantenimientoService;

  public function __construct(
    ProveedoresContactosRepository $contactosRepository,
    ProveedoresEstadisticaRepository $estadisticaRepository,
    MantenimientoService $mantenimientoService
  ) {
    $this->contactosRepository = $contactosRepository;
    $this->estadisticaRepository = $estadisticaRepository;
    $this->mantenimientoService = $mantenimientoService;
  }

  public function siguienteCodigo(Request $request, Response $response): Response
  {
    $params = $request->getQueryParams();
    $empresa = trim((string) ($params['empresa'] ?? ''));
    try {
      $info = $this->mantenimientoService->siguienteCodigoProveedor($empresa);
      return $this->json($response, 200, $info);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function listContactos(Request $request, Response $response, array $args): Response
  {
    $codigo = trim((string) ($args['codigo'] ?? ''));
    if ($codigo === '') {
      return ErrorResponse::json($response, 400, 'Codigo de proveedor obligatorio', 'VALIDACION');
    }
    if (!$this->contactosRepository->proveedorExiste($codigo)) {
      return ErrorResponse::json($response, 404, 'Proveedor no encontrado', 'NO_ENCONTRADO');
    }

    $items = $this->contactosRepository->findByProveedor($codigo);
    return $this->json($response, 200, ['items' => $items]);
  }

  public function createContacto(Request $request, Response $response, array $args): Response
  {
    $codigo = trim((string) ($args['codigo'] ?? ''));
    if ($codigo === '') {
      return ErrorResponse::json($response, 400, 'Codigo de proveedor obligatorio', 'VALIDACION');
    }
    if (!$this->contactosRepository->proveedorExiste($codigo)) {
      return ErrorResponse::json($response, 404, 'Proveedor no encontrado', 'NO_ENCONTRADO');
    }

    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      $item = $this->contactosRepository->create($codigo, $body);
      return $this->json($response, 201, $item);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function updateContacto(Request $request, Response $response, array $args): Response
  {
    $codigo = trim((string) ($args['codigo'] ?? ''));
    $num = (string) ($args['num'] ?? 'C');
    $nroLin = (int) ($args['nroLin'] ?? 0);
    if ($codigo === '' || $nroLin <= 0) {
      return ErrorResponse::json($response, 400, 'Identificador de contacto incompleto', 'VALIDACION');
    }

    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      $item = $this->contactosRepository->update($codigo, $num, $nroLin, $body);
      if ($item === null) {
        return ErrorResponse::json($response, 404, 'Contacto no encontrado', 'NO_ENCONTRADO');
      }
      return $this->json($response, 200, $item);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function deleteContacto(Request $request, Response $response, array $args): Response
  {
    $codigo = trim((string) ($args['codigo'] ?? ''));
    $num = (string) ($args['num'] ?? 'C');
    $nroLin = (int) ($args['nroLin'] ?? 0);
    if ($codigo === '' || $nroLin <= 0) {
      return ErrorResponse::json($response, 400, 'Identificador de contacto incompleto', 'VALIDACION');
    }

    try {
      $ok = $this->contactosRepository->delete($codigo, $num, $nroLin);
      if (!$ok) {
        return ErrorResponse::json($response, 404, 'Contacto no encontrado', 'NO_ENCONTRADO');
      }
      return new SlimResponse(204);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function estadistica(Request $request, Response $response, array $args): Response
  {
    $codigo = trim((string) ($args['codigo'] ?? ''));
    if ($codigo === '') {
      return ErrorResponse::json($response, 400, 'Codigo de proveedor obligatorio', 'VALIDACION');
    }
    if (!$this->estadisticaRepository->proveedorExiste($codigo)) {
      return ErrorResponse::json($response, 404, 'Proveedor no encontrado', 'NO_ENCONTRADO');
    }

    $anio = (int) ($request->getQueryParams()['anio'] ?? date('Y'));
    if ($anio < 1990 || $anio > 2100) {
      return ErrorResponse::json($response, 400, 'Ejercicio no valido', 'VALIDACION');
    }

    try {
      return $this->json($response, 200, $this->estadisticaRepository->estadisticaAnual($codigo, $anio));
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
