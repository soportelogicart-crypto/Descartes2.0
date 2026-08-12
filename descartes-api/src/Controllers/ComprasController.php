<?php

declare(strict_types=1);

namespace Descartes\Api\Controllers;

use Descartes\Api\Http\ErrorResponse;
use Descartes\Api\Services\Compras\AlbaranCompraConsultaService;
use Descartes\Api\Services\Compras\AlbaranCompraEscrituraService;
use Descartes\Api\Services\Compras\FacturaCompraConsultaService;
use Descartes\Api\Services\Compras\PedidoProveedorConsultaService;
use Descartes\Api\Services\Compras\PedidoProveedorEscrituraService;
use Descartes\Api\Services\Compras\PedidoProveedorRecepcionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Módulo Compras (004).
 * Errores homogéneos: ErrorResponse `{ error, codigo }` → UI `extractApiError`.
 */
final class ComprasController
{
  private AlbaranCompraConsultaService $albaranes;
  private AlbaranCompraEscrituraService $escritura;
  private PedidoProveedorConsultaService $pedidos;
  private PedidoProveedorEscrituraService $pedidosEscritura;
  private PedidoProveedorRecepcionService $recepcion;
  private FacturaCompraConsultaService $facturas;

  public function __construct(
    AlbaranCompraConsultaService $albaranes,
    AlbaranCompraEscrituraService $escritura,
    PedidoProveedorConsultaService $pedidos,
    PedidoProveedorEscrituraService $pedidosEscritura,
    PedidoProveedorRecepcionService $recepcion,
    FacturaCompraConsultaService $facturas
  ) {
    $this->albaranes = $albaranes;
    $this->escritura = $escritura;
    $this->pedidos = $pedidos;
    $this->pedidosEscritura = $pedidosEscritura;
    $this->recepcion = $recepcion;
    $this->facturas = $facturas;
  }

  public function ping(Request $request, Response $response): Response
  {
    return $this->json($response, 200, [
      'ok' => true,
      'modulo' => 'compras',
      'message' => 'API Compras montada',
    ]);
  }

  public function listAlbaranes(Request $request, Response $response): Response
  {
    try {
      return $this->json($response, 200, $this->albaranes->listar($request->getQueryParams()));
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function getAlbaran(Request $request, Response $response, array $args): Response
  {
    $empresa = trim((string) ($args['empresa'] ?? ''));
    $albaran = (int) ($args['albaran'] ?? 0);
    if ($empresa === '' || $albaran <= 0) {
      return ErrorResponse::json($response, 400, 'Empresa y albarán son obligatorios', 'VALIDACION');
    }

    try {
      $item = $this->albaranes->obtener($empresa, $albaran);
      if ($item === null) {
        return ErrorResponse::json($response, 404, 'Albarán de compra no encontrado', 'NO_ENCONTRADO');
      }
      return $this->json($response, 200, $item);
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function createAlbaran(Request $request, Response $response): Response
  {
    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      $item = $this->escritura->crear($body);
      return $this->json($response, 201, $item);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      return $this->runtimeError($response, $e);
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function updateAlbaran(Request $request, Response $response, array $args): Response
  {
    $empresa = trim((string) ($args['empresa'] ?? ''));
    $albaran = (int) ($args['albaran'] ?? 0);
    if ($empresa === '' || $albaran <= 0) {
      return ErrorResponse::json($response, 400, 'Empresa y albarán son obligatorios', 'VALIDACION');
    }

    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      $item = $this->escritura->actualizar($empresa, $albaran, $body);
      return $this->json($response, 200, $item);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      return $this->runtimeError($response, $e);
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function deleteAlbaran(Request $request, Response $response, array $args): Response
  {
    $empresa = trim((string) ($args['empresa'] ?? ''));
    $albaran = (int) ($args['albaran'] ?? 0);
    if ($empresa === '' || $albaran <= 0) {
      return ErrorResponse::json($response, 400, 'Empresa y albarán son obligatorios', 'VALIDACION');
    }

    try {
      $this->escritura->eliminar($empresa, $albaran);
      return $response->withStatus(204);
    } catch (\RuntimeException $e) {
      return $this->runtimeError($response, $e);
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function actualizarStockAlbaran(Request $request, Response $response, array $args): Response
  {
    $empresa = trim((string) ($args['empresa'] ?? ''));
    $albaran = (int) ($args['albaran'] ?? 0);
    if ($empresa === '' || $albaran <= 0) {
      return ErrorResponse::json($response, 400, 'Empresa y albarán son obligatorios', 'VALIDACION');
    }

    try {
      $item = $this->escritura->actualizarStock($empresa, $albaran);
      return $this->json($response, 200, $item);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      return $this->runtimeError($response, $e);
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function listPedidos(Request $request, Response $response): Response
  {
    try {
      return $this->json($response, 200, $this->pedidos->listar($request->getQueryParams()));
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function getPedido(Request $request, Response $response, array $args): Response
  {
    $empresa = trim((string) ($args['empresa'] ?? ''));
    $pedido = (int) ($args['pedido'] ?? 0);
    if ($empresa === '' || $pedido <= 0) {
      return ErrorResponse::json($response, 400, 'Empresa y pedido son obligatorios', 'VALIDACION');
    }

    try {
      $item = $this->pedidos->obtener($empresa, $pedido);
      if ($item === null) {
        return ErrorResponse::json($response, 404, 'Pedido a proveedor no encontrado', 'NO_ENCONTRADO');
      }
      return $this->json($response, 200, $item);
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function createPedido(Request $request, Response $response): Response
  {
    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      $item = $this->pedidosEscritura->crear($body);
      return $this->json($response, 201, $item);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      return $this->runtimeError($response, $e);
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function updatePedido(Request $request, Response $response, array $args): Response
  {
    $empresa = trim((string) ($args['empresa'] ?? ''));
    $pedido = (int) ($args['pedido'] ?? 0);
    if ($empresa === '' || $pedido <= 0) {
      return ErrorResponse::json($response, 400, 'Empresa y pedido son obligatorios', 'VALIDACION');
    }

    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      $item = $this->pedidosEscritura->actualizar($empresa, $pedido, $body);
      return $this->json($response, 200, $item);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      return $this->runtimeError($response, $e);
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function recibirPedido(Request $request, Response $response, array $args): Response
  {
    $empresa = trim((string) ($args['empresa'] ?? ''));
    $pedido = (int) ($args['pedido'] ?? 0);
    if ($empresa === '' || $pedido <= 0) {
      return ErrorResponse::json($response, 400, 'Empresa y pedido son obligatorios', 'VALIDACION');
    }

    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      $result = $this->recepcion->recibir($empresa, $pedido, $body);
      return $this->json($response, 201, $result);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      return $this->runtimeError($response, $e);
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function listFacturas(Request $request, Response $response): Response
  {
    try {
      return $this->json($response, 200, $this->facturas->listar($request->getQueryParams()));
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function getFactura(Request $request, Response $response, array $args): Response
  {
    $factura = (int) ($args['factura'] ?? 0);
    if ($factura <= 0) {
      return ErrorResponse::json($response, 400, 'Factura obligatoria', 'VALIDACION');
    }

    try {
      $item = $this->facturas->obtener($factura);
      if ($item === null) {
        return ErrorResponse::json($response, 404, 'Factura de proveedor no encontrada', 'NO_ENCONTRADO');
      }
      return $this->json($response, 200, $item);
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
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
