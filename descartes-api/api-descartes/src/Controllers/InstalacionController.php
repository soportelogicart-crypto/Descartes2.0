<?php

declare(strict_types=1);

namespace Descartes\Api\Controllers;

use Descartes\Api\Config\CatalogoInstalaciones;
use Descartes\Api\Http\ErrorResponse;
use Descartes\Api\Services\Instalacion\InstalacionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class InstalacionController
{
  private InstalacionService $service;

  public function __construct(InstalacionService $service)
  {
    $this->service = $service;
  }

  public function estado(Request $request, Response $response): Response
  {
    $config = $this->configExplicita($request);
    $data = $this->service->obtenerEstado($config);
    $data['instalacionId'] = (string) ($request->getAttribute('clienteId') ?? CatalogoInstalaciones::DEFECTO);
    return $this->json($response, 200, $data);
  }

  public function probar(Request $request, Response $response): Response
  {
    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      $resultado = $this->configExplicita($request) !== null
        ? $this->service->probarSinGuardar($body)
        : $this->service->probarConexion($body);
      return $this->json($response, 200, $resultado);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    }
  }

  public function configurar(Request $request, Response $response): Response
  {
    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      if ($this->configExplicita($request) !== null) {
        $id = (string) ($request->getAttribute('clienteId') ?? '');
        $data = $this->service->configurarEnCatalogo($id, $body);
      } else {
        $data = $this->service->configurarYAplicar($body);
      }
      $data['instalacionId'] = (string) ($request->getAttribute('clienteId') ?? CatalogoInstalaciones::DEFECTO);
      return $this->json($response, 200, $data);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'MIGRACION');
    }
  }

  public function listarClientes(Request $request, Response $response): Response
  {
    return $this->json($response, 200, ['instalaciones' => CatalogoInstalaciones::listar()]);
  }

  public function crearCliente(Request $request, Response $response): Response
  {
    $body = (array) json_decode((string) $request->getBody(), true);
    $id = (string) ($body['id'] ?? '');
    try {
      return $this->json($response, 201, CatalogoInstalaciones::crear($id, $body));
    } catch (\InvalidArgumentException $e) {
      $codigo = str_contains($e->getMessage(), 'Ya existe') ? 'INSTALACION_DUPLICADA' : 'VALIDACION';
      $status = $codigo === 'INSTALACION_DUPLICADA' ? 409 : 400;
      return ErrorResponse::json($response, $status, $e->getMessage(), $codigo);
    } catch (\RuntimeException $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'INSTALACION');
    }
  }

  public function regenerarClaveCliente(Request $request, Response $response, array $args): Response
  {
    try {
      return $this->json(
        $response,
        200,
        CatalogoInstalaciones::regenerarClave((string) ($args['id'] ?? ''))
      );
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'INSTALACION');
    }
  }

  public function migrar(Request $request, Response $response): Response
  {
    try {
      $data = $this->service->aplicarMigracionesActivas($this->configExplicita($request));
      $data['instalacionId'] = (string) ($request->getAttribute('clienteId') ?? CatalogoInstalaciones::DEFECTO);
      return $this->json($response, 200, $data);
    } catch (\RuntimeException $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'MIGRACION');
    }
  }

  /** @return array<string, mixed>|null */
  private function configExplicita(Request $request): ?array
  {
    $config = $request->getAttribute('instalacionSql');
    return is_array($config) ? $config : null;
  }

  /** @param array<string, mixed> $data */
  private function json(Response $response, int $status, array $data): Response
  {
    $response->getBody()->write((string) json_encode($data, JSON_UNESCAPED_UNICODE));
    return $response
      ->withHeader('Content-Type', 'application/json; charset=utf-8')
      ->withStatus($status);
  }
}
