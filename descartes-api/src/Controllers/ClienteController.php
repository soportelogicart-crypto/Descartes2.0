<?php

declare(strict_types=1);

namespace Descartes\Api\Controllers;

use Descartes\Api\Http\ErrorResponse;
use Descartes\Api\Repositories\ClientesContactosRepository;
use Descartes\Api\Repositories\ClientesDireccionesRepository;
use Descartes\Api\Repositories\ClientesEstadisticaRepository;
use Descartes\Api\Services\ClienteCuentaContableService;
use Descartes\Api\Services\MantenimientoService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;

final class ClienteController
{
  private ClientesDireccionesRepository $direccionesRepository;
  private ClientesContactosRepository $contactosRepository;
  private ClientesEstadisticaRepository $estadisticaRepository;
  private MantenimientoService $mantenimientoService;
  private ClienteCuentaContableService $cuentaContableService;

  public function __construct(
    ClientesDireccionesRepository $direccionesRepository,
    ClientesContactosRepository $contactosRepository,
    ClientesEstadisticaRepository $estadisticaRepository,
    MantenimientoService $mantenimientoService,
    ClienteCuentaContableService $cuentaContableService
  ) {
    $this->direccionesRepository = $direccionesRepository;
    $this->contactosRepository = $contactosRepository;
    $this->estadisticaRepository = $estadisticaRepository;
    $this->mantenimientoService = $mantenimientoService;
    $this->cuentaContableService = $cuentaContableService;
  }

  public function siguienteCodigo(Request $request, Response $response): Response
  {
    $params = $request->getQueryParams();
    $empresa = trim((string) ($params['empresa'] ?? ''));
    try {
      $info = $this->mantenimientoService->siguienteCodigoCliente($empresa);
      return $this->json($response, 200, $info);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function checkNif(Request $request, Response $response): Response
  {
    $params = $request->getQueryParams();
    $nif = trim((string) ($params['nif'] ?? ''));
    $excluir = trim((string) ($params['excluir'] ?? ''));
    try {
      $encontrado = $this->mantenimientoService->buscarClienteActivoPorNif($nif, $excluir);
      return $this->json($response, 200, [
        'duplicado' => $encontrado !== null,
        'codigo' => $encontrado['codigo'] ?? null,
      ]);
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function cuentaContable(Request $request, Response $response, array $args): Response
  {
    $codigo = trim((string) ($args['codigo'] ?? ''));
    try {
      return $this->json($response, 200, $this->cuentaContableService->estado($codigo));
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function crearCuentaContable(Request $request, Response $response, array $args): Response
  {
    $codigo = trim((string) ($args['codigo'] ?? ''));
    try {
      return $this->json($response, 201, $this->cuentaContableService->crear($codigo));
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      if ((int) $e->getCode() === 409) {
        return ErrorResponse::json($response, 409, $e->getMessage(), 'CONFLICTO');
      }
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function listDirecciones(Request $request, Response $response, array $args): Response
  {
    $codigo = trim((string) ($args['codigo'] ?? ''));
    if ($codigo === '') {
      return ErrorResponse::json($response, 400, 'Codigo de cliente obligatorio', 'VALIDACION');
    }
    if (!$this->direccionesRepository->clienteExiste($codigo)) {
      return ErrorResponse::json($response, 404, 'Cliente no encontrado', 'NO_ENCONTRADO');
    }

    $items = $this->direccionesRepository->findByCliente($codigo);
    return $this->json($response, 200, ['items' => $items]);
  }

  public function createDireccion(Request $request, Response $response, array $args): Response
  {
    $codigo = trim((string) ($args['codigo'] ?? ''));
    if ($codigo === '') {
      return ErrorResponse::json($response, 400, 'Codigo de cliente obligatorio', 'VALIDACION');
    }
    if (!$this->direccionesRepository->clienteExiste($codigo)) {
      return ErrorResponse::json($response, 404, 'Cliente no encontrado', 'NO_ENCONTRADO');
    }

    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      $item = $this->direccionesRepository->create($codigo, $body);
      return $this->json($response, 201, $item);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function updateDireccion(Request $request, Response $response, array $args): Response
  {
    $codigo = trim((string) ($args['codigo'] ?? ''));
    $tipo = (string) ($args['tipo'] ?? '');
    $nroLin = (int) ($args['nroLin'] ?? 0);
    if ($codigo === '' || $nroLin <= 0) {
      return ErrorResponse::json($response, 400, 'Identificador de direccion incompleto', 'VALIDACION');
    }

    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      $item = $this->direccionesRepository->update($codigo, $tipo, $nroLin, $body);
      if ($item === null) {
        return ErrorResponse::json($response, 404, 'Direccion no encontrada', 'NO_ENCONTRADO');
      }
      return $this->json($response, 200, $item);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function deleteDireccion(Request $request, Response $response, array $args): Response
  {
    $codigo = trim((string) ($args['codigo'] ?? ''));
    $tipo = (string) ($args['tipo'] ?? '');
    $nroLin = (int) ($args['nroLin'] ?? 0);
    if ($codigo === '' || $nroLin <= 0) {
      return ErrorResponse::json($response, 400, 'Identificador de direccion incompleto', 'VALIDACION');
    }

    try {
      $ok = $this->direccionesRepository->delete($codigo, $tipo, $nroLin);
      if (!$ok) {
        return ErrorResponse::json($response, 404, 'Direccion no encontrada', 'NO_ENCONTRADO');
      }
      return new SlimResponse(204);
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
      return ErrorResponse::json($response, 400, 'Codigo de cliente obligatorio', 'VALIDACION');
    }
    if (!$this->contactosRepository->clienteExiste($codigo)) {
      return ErrorResponse::json($response, 404, 'Cliente no encontrado', 'NO_ENCONTRADO');
    }

    $items = $this->contactosRepository->findByCliente($codigo);
    return $this->json($response, 200, ['items' => $items]);
  }

  public function createContacto(Request $request, Response $response, array $args): Response
  {
    $codigo = trim((string) ($args['codigo'] ?? ''));
    if ($codigo === '') {
      return ErrorResponse::json($response, 400, 'Codigo de cliente obligatorio', 'VALIDACION');
    }
    if (!$this->contactosRepository->clienteExiste($codigo)) {
      return ErrorResponse::json($response, 404, 'Cliente no encontrado', 'NO_ENCONTRADO');
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
      return ErrorResponse::json($response, 400, 'Codigo de cliente obligatorio', 'VALIDACION');
    }
    if (!$this->estadisticaRepository->clienteExiste($codigo)) {
      return ErrorResponse::json($response, 404, 'Cliente no encontrado', 'NO_ENCONTRADO');
    }

    $anio = $this->anio($request->getQueryParams()['anio'] ?? null);
    if ($anio === null) {
      return ErrorResponse::json($response, 400, 'Ejercicio no valido', 'VALIDACION');
    }

    try {
      return $this->json($response, 200, $this->estadisticaRepository->estadisticaAnual($codigo, $anio));
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function putPrevision(Request $request, Response $response, array $args): Response
  {
    $codigo = trim((string) ($args['codigo'] ?? ''));
    $anio = $this->anio($args['anio'] ?? null);
    $mes = (int) ($args['mes'] ?? 0);
    if ($codigo === '' || $anio === null || $mes < 1 || $mes > 12) {
      return ErrorResponse::json($response, 400, 'Identificador de periodo incompleto', 'VALIDACION');
    }
    if (!$this->estadisticaRepository->clienteExiste($codigo)) {
      return ErrorResponse::json($response, 404, 'Cliente no encontrado', 'NO_ENCONTRADO');
    }

    $body = (array) json_decode((string) $request->getBody(), true);
    $prevision = $body['prevision'] ?? null;
    if (!is_numeric($prevision)) {
      return ErrorResponse::json($response, 400, 'La prevision debe ser numerica', 'VALIDACION');
    }

    try {
      $this->estadisticaRepository->guardarPrevision($codigo, $anio, $mes, (float) $prevision);
      return $this->json($response, 200, $this->estadisticaRepository->estadisticaAnual($codigo, $anio));
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function consumo(Request $request, Response $response, array $args): Response
  {
    $codigo = trim((string) ($args['codigo'] ?? ''));
    if ($codigo === '') {
      return ErrorResponse::json($response, 400, 'Codigo de cliente obligatorio', 'VALIDACION');
    }
    if (!$this->estadisticaRepository->clienteExiste($codigo)) {
      return ErrorResponse::json($response, 404, 'Cliente no encontrado', 'NO_ENCONTRADO');
    }

    $params = $request->getQueryParams();
    $anio = $this->anio($params['anio'] ?? null);
    if ($anio === null) {
      return ErrorResponse::json($response, 400, 'Ejercicio no valido', 'VALIDACION');
    }
    $agrupacion = trim((string) ($params['agrupacion'] ?? 'macrofamilia'));
    if (!in_array($agrupacion, ClientesEstadisticaRepository::agrupacionesValidas(), true)) {
      return ErrorResponse::json($response, 400, 'Agrupacion no valida', 'VALIDACION');
    }
    $medida = trim((string) ($params['medida'] ?? 'importe'));

    try {
      return $this->json($response, 200, $this->estadisticaRepository->consumo($codigo, $anio, $agrupacion, $medida));
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  private function anio(mixed $valor): ?int
  {
    $anio = (int) $valor;
    if ($anio < 1990 || $anio > 2100) {
      return null;
    }
    return $anio;
  }

  private function json(Response $response, int $status, array $payload): Response
  {
    $response = new SlimResponse($status);
    $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
    return $response->withHeader('Content-Type', 'application/json');
  }
}
