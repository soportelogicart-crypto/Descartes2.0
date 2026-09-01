<?php

declare(strict_types=1);

namespace Descartes\Api\Controllers;

use Descartes\Api\Http\ErrorResponse;
use Descartes\Api\Services\Tpv\TpvArticuloService;
use Descartes\Api\Services\Tpv\TpvClienteService;
use Descartes\Api\Services\Tpv\TpvContextoService;
use Descartes\Api\Services\Tpv\TpvTecladoService;
use Descartes\Api\Services\Ventas\VentaEmailService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** TPV venta táctil (006). */
final class TpvController
{
  private TpvContextoService $contexto;
  private TpvTecladoService $teclado;
  private TpvArticuloService $articulos;
  private TpvClienteService $clientes;
  private VentaEmailService $ventaEmail;

  public function __construct(
    TpvContextoService $contexto,
    TpvTecladoService $teclado,
    TpvArticuloService $articulos,
    TpvClienteService $clientes,
    VentaEmailService $ventaEmail
  ) {
    $this->contexto = $contexto;
    $this->teclado = $teclado;
    $this->articulos = $articulos;
    $this->clientes = $clientes;
    $this->ventaEmail = $ventaEmail;
  }

  public function ping(Request $request, Response $response): Response
  {
    return $this->json($response, 200, [
      'ok' => true,
      'modulo' => 'tpv',
      'message' => 'API TPV montada',
    ]);
  }

  public function emailVenta(Request $request, Response $response, array $args): Response
  {
    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      return $this->json($response, 200, $this->ventaEmail->enviar(
        (string) ($args['empresa'] ?? ''),
        (string) ($args['tipo'] ?? ''),
        (int) ($args['albaran'] ?? 0),
        (string) ($body['email'] ?? '')
      ));
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function getContexto(Request $request, Response $response): Response
  {
    $q = $request->getQueryParams();
    $empresa = trim((string) ($q['empresa'] ?? ''));
    $puesto = trim((string) ($q['puesto'] ?? ''));
    if ($empresa === '' || $puesto === '') {
      return ErrorResponse::json($response, 400, 'Query empresa y puesto son obligatorios', 'VALIDACION');
    }

    try {
      return $this->json($response, 200, $this->contexto->resolver($empresa, $puesto));
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function getNivelTeclado(Request $request, Response $response, array $args): Response
  {
    $general = trim((string) ($args['general'] ?? ''));
    $nivel = trim((string) ($args['nivel'] ?? ''));
    if ($general === '' || $nivel === '') {
      return ErrorResponse::json($response, 400, 'Teclado y nivel son obligatorios', 'VALIDACION');
    }

    try {
      $data = $this->teclado->obtenerNivel($general, $nivel);
      if ($data === null) {
        return ErrorResponse::json($response, 404, 'Teclado no encontrado', 'NO_ENCONTRADO');
      }
      return $this->json($response, 200, $data);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function getNivelesTeclado(Request $request, Response $response, array $args): Response
  {
    $general = trim((string) ($args['general'] ?? ''));
    try {
      return $this->json($response, 200, [
        'items' => $this->teclado->listarNiveles($general),
      ]);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function guardarBotonTeclado(
    Request $request,
    Response $response,
    array $args
  ): Response {
    $general = trim((string) ($args['general'] ?? ''));
    $nivel = trim((string) ($args['nivel'] ?? ''));
    $tecla = (int) ($args['tecla'] ?? -1);
    $body = (array) json_decode((string) $request->getBody(), true);

    try {
      if (($body['tipo'] ?? '') === 'articulo') {
        $articulo = trim((string) ($body['articulo'] ?? ''));
        if ($this->articulos->obtenerPrecio($articulo, 1) === null) {
          return ErrorResponse::json($response, 404, 'Artículo no encontrado', 'NO_ENCONTRADO');
        }
      }
      $nivelDestino = $this->teclado->guardarBoton($general, $nivel, $tecla, $body);
      return $this->json($response, 200, ['nivelDestino' => $nivelDestino]);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function borrarBotonTeclado(
    Request $request,
    Response $response,
    array $args
  ): Response {
    try {
      $this->teclado->borrarBoton(
        trim((string) ($args['general'] ?? '')),
        trim((string) ($args['nivel'] ?? '')),
        (int) ($args['tecla'] ?? -1)
      );
      return $response->withStatus(204);
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  /** Búsqueda de clientes desde la caja (código, NIF, nombre o teléfono). */
  public function buscarClientes(Request $request, Response $response): Response
  {
    $q = $request->getQueryParams();
    $query = trim((string) ($q['q'] ?? ''));
    $limite = isset($q['limite']) ? (int) $q['limite'] : 30;

    try {
      return $this->json($response, 200, ['items' => $this->clientes->buscar($query, $limite)]);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  /** Entrada manual o por pistola: resuelve código, Alternativo o EAN y devuelve precio. */
  public function resolverArticulo(Request $request, Response $response): Response
  {
    $q = $request->getQueryParams();
    $query = trim((string) ($q['q'] ?? ''));
    if ($query === '') {
      return ErrorResponse::json($response, 400, 'Parámetro q obligatorio', 'VALIDACION');
    }

    $tarifa = isset($q['tarifa']) ? (int) $q['tarifa'] : 1;

    try {
      $data = $this->articulos->resolver($query, $tarifa);
      if ($data === null) {
        return ErrorResponse::json(
          $response,
          404,
          'No existe ningún artículo con código o EAN "' . $query . '"',
          'NO_ENCONTRADO'
        );
      }
      return $this->json($response, 200, $data);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  /** Búsqueda por código o descripción para asignar teclas de venta rápida. */
  public function buscarArticulos(Request $request, Response $response): Response
  {
    $q = $request->getQueryParams();
    $query = trim((string) ($q['q'] ?? ''));
    $tarifa = isset($q['tarifa']) ? (int) $q['tarifa'] : 1;
    $limite = isset($q['limite']) ? (int) $q['limite'] : 30;
    $ambito = trim((string) ($q['ambito'] ?? 'todos'));

    try {
      return $this->json($response, 200, [
        'items' => $this->articulos->buscar($query, $tarifa, $limite, $ambito),
      ]);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function getArticuloPrecio(Request $request, Response $response, array $args): Response
  {
    $codigo = trim((string) ($args['codigo'] ?? ''));
    if ($codigo === '') {
      return ErrorResponse::json($response, 400, 'Código de artículo obligatorio', 'VALIDACION');
    }

    $q = $request->getQueryParams();
    $tarifa = isset($q['tarifa']) ? (int) $q['tarifa'] : 1;

    try {
      $data = $this->articulos->obtenerPrecio($codigo, $tarifa);
      if ($data === null) {
        return ErrorResponse::json($response, 404, 'Artículo no encontrado', 'NO_ENCONTRADO');
      }
      return $this->json($response, 200, $data);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  /**
   * @param array<string, mixed> $data
   */
  private function json(Response $response, int $status, array $data): Response
  {
    $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
    return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
  }
}
