<?php

declare(strict_types=1);

namespace Descartes\Api\Controllers;

use Descartes\Api\Http\ErrorResponse;
use Descartes\Api\Services\Facturacion\ImpresionFacturasService;
use Descartes\Api\Services\PermissionService;
use Descartes\Api\Services\Ventas\AbcVentasService;
use Descartes\Api\Services\Ventas\AnulacionConsultaService;
use Descartes\Api\Services\Ventas\ArqueoService;
use Descartes\Api\Services\Ventas\CobroPagoConsultaService;
use Descartes\Api\Services\Ventas\DesgloseArqueoVentasService;
use Descartes\Api\Services\Ventas\DispositivoPuestoService;
use Descartes\Api\Services\Ventas\PedidoClienteService;
use Descartes\Api\Services\Ventas\ValeService;
use Descartes\Api\Services\Ventas\VentaConsultaService;
use Descartes\Api\Services\Ventas\VentaEscrituraService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Response as SlimResponse;

final class VentasController
{
  private VentaConsultaService $ventas;
  private VentaEscrituraService $escritura;
  private ImpresionFacturasService $impresionFacturas;
  private ArqueoService $arqueos;
  private DesgloseArqueoVentasService $desgloseArqueo;
  private DispositivoPuestoService $dispositivos;
  private AnulacionConsultaService $anulaciones;
  private CobroPagoConsultaService $cobrosPagos;
  private ValeService $vales;
  private PedidoClienteService $pedidos;
  private AbcVentasService $abcVentas;
  private PermissionService $permissions;
  private LoggerInterface $logger;

  public function __construct(
    VentaConsultaService $ventas,
    VentaEscrituraService $escritura,
    ImpresionFacturasService $impresionFacturas,
    ArqueoService $arqueos,
    DesgloseArqueoVentasService $desgloseArqueo,
    DispositivoPuestoService $dispositivos,
    AnulacionConsultaService $anulaciones,
    CobroPagoConsultaService $cobrosPagos,
    ValeService $vales,
    PedidoClienteService $pedidos,
    AbcVentasService $abcVentas,
    PermissionService $permissions,
    LoggerInterface $logger
  ) {
    $this->ventas = $ventas;
    $this->escritura = $escritura;
    $this->impresionFacturas = $impresionFacturas;
    $this->arqueos = $arqueos;
    $this->desgloseArqueo = $desgloseArqueo;
    $this->dispositivos = $dispositivos;
    $this->anulaciones = $anulaciones;
    $this->cobrosPagos = $cobrosPagos;
    $this->vales = $vales;
    $this->pedidos = $pedidos;
    $this->abcVentas = $abcVentas;
    $this->permissions = $permissions;
    $this->logger = $logger;
  }

  public function listVentas(Request $request, Response $response): Response
  {
    return $this->json($response, 200, $this->ventas->listar($request->getQueryParams()));
  }

  public function getVenta(Request $request, Response $response, array $args): Response
  {
    $item = $this->ventas->obtener(
      (string) ($args['empresa'] ?? ''),
      (string) ($args['tipo'] ?? ''),
      (int) ($args['albaran'] ?? 0)
    );
    if ($item === null) {
      return ErrorResponse::json($response, 404, 'Venta no encontrada', 'NO_ENCONTRADO');
    }
    return $this->json($response, 200, $item);
  }

  public function createVenta(Request $request, Response $response): Response
  {
    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      $item = $this->escritura->crear($body);
      $this->audit('ventas.crear', 'Venta creada', [
        'empresa' => $item['empresa'] ?? ($body['empresa'] ?? null),
        'tipo' => $item['tipo'] ?? ($body['tipo'] ?? null),
        'albaran' => $item['albaran'] ?? null,
      ]);
      return $this->json($response, 201, $item);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function reservarAlbaran(Request $request, Response $response): Response
  {
    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      $item = $this->escritura->reservarAlbaran(
        (string) ($body['empresa'] ?? ''),
        isset($body['puesto']) ? (string) $body['puesto'] : null
      );
      return $this->json($response, 200, $item);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      return $this->runtimeError($response, $e);
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function updateVenta(Request $request, Response $response, array $args): Response
  {
    $body = (array) json_decode((string) $request->getBody(), true);
    $empresa = (string) ($args['empresa'] ?? '');
    $tipo = (string) ($args['tipo'] ?? '');
    $albaran = (int) ($args['albaran'] ?? 0);
    try {
      $item = $this->escritura->actualizar($empresa, $tipo, $albaran, $body);
      $this->audit('ventas.guardar', 'Venta guardada', [
        'empresa' => $empresa,
        'tipo' => $tipo,
        'albaran' => $albaran,
      ]);
      return $this->json($response, 200, $item);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      return $this->runtimeError($response, $e);
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function deleteVenta(Request $request, Response $response, array $args): Response
  {
    $empresa = (string) ($args['empresa'] ?? '');
    $tipo = (string) ($args['tipo'] ?? '');
    $albaran = (int) ($args['albaran'] ?? 0);
    try {
      $this->escritura->eliminar($empresa, $tipo, $albaran);
      $this->audit('ventas.borrar', 'Venta borrada', [
        'empresa' => $empresa,
        'tipo' => $tipo,
        'albaran' => $albaran,
      ]);
      return $response->withStatus(204);
    } catch (\RuntimeException $e) {
      return $this->runtimeError($response, $e);
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function finalizarVenta(Request $request, Response $response, array $args): Response
  {
    $body = (array) json_decode((string) $request->getBody(), true);
    $empresa = (string) ($args['empresa'] ?? '');
    $tipo = (string) ($args['tipo'] ?? '');
    $albaran = (int) ($args['albaran'] ?? 0);
    try {
      $item = $this->escritura->finalizar($empresa, $tipo, $albaran, $body);
      $this->audit('ventas.finalizar', 'Venta finalizada', [
        'empresa' => $empresa,
        'tipo' => $tipo,
        'albaran' => $albaran,
        'tipoFinal' => $item['tipo'] ?? null,
      ]);
      return $this->json($response, 200, $item);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      return $this->runtimeError($response, $e);
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function crearAbonoDesdeVenta(Request $request, Response $response, array $args): Response
  {
    $body = (array) json_decode((string) $request->getBody(), true);
    $empresa = (string) ($args['empresa'] ?? '');
    $tipo = (string) ($args['tipo'] ?? '');
    $albaran = (int) ($args['albaran'] ?? 0);
    try {
      $item = $this->escritura->crearAbonoDesdeAlbaran($empresa, $tipo, $albaran, $body);
      $this->audit('ventas.abono', 'Albarán de abono creado', [
        'empresa' => $empresa,
        'tipoOrigen' => $tipo,
        'albaranOrigen' => $albaran,
        'albaranAbono' => $item['albaran'] ?? null,
      ]);
      return $this->json($response, 201, $item);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      return $this->runtimeError($response, $e);
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function marcarVentaImpresa(Request $request, Response $response, array $args): Response
  {
    try {
      $item = $this->escritura->marcarImpreso(
        (string) ($args['empresa'] ?? ''),
        (string) ($args['tipo'] ?? ''),
        (int) ($args['albaran'] ?? 0)
      );
      return $this->json($response, 200, $item);
    } catch (\RuntimeException $e) {
      return $this->runtimeError($response, $e);
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  /**
   * PDF de factura/abono tipificado (FacturaTipo F o A con número).
   * Tickets (T) y albaranes abiertos/cerrados sin factura: no aplica (otro canal).
   */
  public function pdfVenta(Request $request, Response $response, array $args): Response
  {
    try {
      $empresa = (string) ($args['empresa'] ?? '');
      $tipo = (string) ($args['tipo'] ?? '');
      $albaran = (int) ($args['albaran'] ?? 0);
      $ficha = $this->ventas->obtener($empresa, $tipo, $albaran);
      if ($ficha === null) {
        return ErrorResponse::json($response, 404, 'Venta no encontrada', 'NO_ENCONTRADO');
      }

      $facturaTipo = strtoupper(trim((string) ($ficha['facturaTipo'] ?? '')));
      $factura = (int) ($ficha['factura'] ?? 0);
      if (!in_array($facturaTipo, ['F', 'A'], true) || $factura <= 0) {
        return ErrorResponse::json(
          $response,
          400,
          'Solo facturas y abonos tipificados tienen PDF. Tickets y albaranes usan otro canal.',
          'VALIDACION'
        );
      }

      $q = $request->getQueryParams();
      $marcarFactura = !array_key_exists('marcarImpresa', $q) || !empty($q['marcarImpresa']);
      // Previsualización por defecto no marca Impresa en Facturas.
      if (isset($q['marcarImpresa']) && ($q['marcarImpresa'] === '0' || $q['marcarImpresa'] === 'false')) {
        $marcarFactura = false;
      } elseif (!isset($q['marcarImpresa'])) {
        $marcarFactura = false;
      }

      $pdf = $this->impresionFacturas->informePdf([
        'facturas' => [
          [
            'empresa' => (string) ($ficha['empresa'] ?? $empresa),
            'facturaTipo' => $facturaTipo,
            'factura' => $factura,
          ],
        ],
        'marcarImpresa' => $marcarFactura,
      ]);

      $filename = sprintf('factura_%s_%s_%d.pdf', $empresa, $facturaTipo, $factura);
      $response->getBody()->write($pdf);
      return $response
        ->withHeader('Content-Type', 'application/pdf')
        ->withHeader('Content-Disposition', 'inline; filename="' . $filename . '"')
        ->withHeader('Content-Length', (string) strlen($pdf))
        ->withStatus(200);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      return $this->runtimeError($response, $e);
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function getArqueo(Request $request, Response $response): Response
  {
    $q = $request->getQueryParams();
    $empresa = trim((string) ($q['empresa'] ?? ''));
    $puesto = trim((string) ($q['puesto'] ?? ''));
    $sesion = (int) ($q['sesion'] ?? 0);
    if ($empresa === '' || $puesto === '' || $sesion <= 0) {
      return ErrorResponse::json($response, 400, 'empresa, puesto y sesion son obligatorios', 'VALIDACION');
    }
    return $this->json($response, 200, $this->arqueos->obtener($empresa, $puesto, $sesion));
  }

  public function introducirArqueo(Request $request, Response $response, array $args): Response
  {
    $empresa = trim((string) ($args['empresa'] ?? ''));
    $puesto = trim((string) ($args['puesto'] ?? ''));
    $sesion = (int) ($args['sesion'] ?? 0);
    $body = (array) ($request->getParsedBody() ?? []);
    if ($body === []) {
      $body = (array) json_decode((string) $request->getBody(), true);
    }

    $usuario = $request->getAttribute('usuario') ?? ($_SESSION['usuario'] ?? null);
    if (!is_array($usuario)) {
      return ErrorResponse::json($response, 401, 'Sesion no iniciada', 'NO_AUTENTICADO');
    }

    $forzar = !empty($body['forzarRepeticion']);
    if ($forzar && !$this->permissions->puede($usuario, 'ventas-arqueo', 'crear')) {
      return ErrorResponse::json(
        $response,
        403,
        'No tiene permiso para repetir el arqueo',
        'SIN_PERMISO'
      );
    }

    try {
      $item = $this->arqueos->introducir(
        $empresa,
        $puesto,
        $sesion,
        $body,
        (string) ($usuario['codigo'] ?? '')
      );
      $this->audit('ventas.arqueo.introducir', 'Arqueo introducido', [
        'empresa' => $empresa,
        'puesto' => $puesto,
        'sesion' => $sesion,
        'forzarRepeticion' => $forzar,
      ]);
      return $this->json($response, 200, $item);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      return $this->runtimeError($response, $e);
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function cerrarSesion(Request $request, Response $response, array $args): Response
  {
    $usuario = $request->getAttribute('usuario') ?? ($_SESSION['usuario'] ?? null);
    if (!is_array($usuario)) {
      return ErrorResponse::json($response, 401, 'Sesion no iniciada', 'NO_AUTENTICADO');
    }
    $body = (array) ($request->getParsedBody() ?? []);
    if ($body === []) {
      $body = (array) json_decode((string) $request->getBody(), true);
    }
    try {
      $item = $this->arqueos->cerrarSesion(
        trim((string) ($args['empresa'] ?? '')),
        trim((string) ($args['puesto'] ?? '')),
        (int) ($args['sesion'] ?? 0),
        $body,
        (string) ($usuario['codigo'] ?? '')
      );
      $this->audit('ventas.arqueo.cerrar', 'Sesion de caja cerrada', [
        'empresa' => $args['empresa'] ?? null,
        'puesto' => $args['puesto'] ?? null,
        'sesion' => $args['sesion'] ?? null,
      ]);
      return $this->json($response, 200, $item);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      return $this->runtimeError($response, $e);
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function entradaCaja(Request $request, Response $response, array $args): Response
  {
    return $this->movimientoCaja($request, $response, $args, 'entrada');
  }

  public function salidaCaja(Request $request, Response $response, array $args): Response
  {
    return $this->movimientoCaja($request, $response, $args, 'salida');
  }

  private function movimientoCaja(
    Request $request,
    Response $response,
    array $args,
    string $tipo
  ): Response {
    $body = (array) ($request->getParsedBody() ?? []);
    if ($body === []) {
      $body = (array) json_decode((string) $request->getBody(), true);
    }
    try {
      $item = $this->arqueos->movimientoCaja(
        trim((string) ($args['empresa'] ?? '')),
        trim((string) ($args['puesto'] ?? '')),
        (int) ($args['sesion'] ?? 0),
        $tipo,
        $body
      );
      return $this->json($response, 200, $item);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      return $this->runtimeError($response, $e);
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function informeArqueo(Request $request, Response $response, array $args): Response
  {
    $empresa = trim((string) ($args['empresa'] ?? ''));
    $puesto = trim((string) ($args['puesto'] ?? ''));
    $sesion = (int) ($args['sesion'] ?? 0);
    $formato = strtolower(trim((string) ($request->getQueryParams()['formato'] ?? 'pdf')));
    try {
      if ($formato === 'html') {
        $html = $this->arqueos->informeHtml($empresa, $puesto, $sesion);
        $response->getBody()->write($html);
        return $response
          ->withHeader('Content-Type', 'text/html; charset=utf-8')
          ->withStatus(200);
      }

      $pdf = $this->arqueos->informePdf($empresa, $puesto, $sesion);
      $filename = sprintf('arqueo_%s_%s_%d.pdf', $empresa, $puesto, $sesion);
      $response->getBody()->write($pdf);
      return $response
        ->withHeader('Content-Type', 'application/pdf')
        ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
        ->withHeader('Content-Length', (string) strlen($pdf))
        ->withStatus(200);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      return $this->runtimeError($response, $e);
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function leerCajonDispositivo(Request $request, Response $response, array $args): Response
  {
    $body = (array) ($request->getParsedBody() ?? []);
    if ($body === []) {
      $body = (array) json_decode((string) $request->getBody(), true);
    }
    try {
      $item = $this->dispositivos->leerCajon(trim((string) ($args['puesto'] ?? '')), $body);
      return $this->json($response, 200, $item);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function imprimirDispositivo(Request $request, Response $response, array $args): Response
  {
    $body = (array) ($request->getParsedBody() ?? []);
    if ($body === []) {
      $body = (array) json_decode((string) $request->getBody(), true);
    }
    try {
      $item = $this->dispositivos->imprimir(trim((string) ($args['puesto'] ?? '')), $body);
      return $this->json($response, 200, $item);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function getArqueoDesglose(Request $request, Response $response): Response
  {
    $q = $request->getQueryParams();
    $salida = strtolower(trim((string) ($q['salida'] ?? 'json')));
    try {
      if ($salida === 'pdf') {
        $pdf = $this->desgloseArqueo->informePdf($q);
        $response->getBody()->write($pdf);
        return $response
          ->withHeader('Content-Type', 'application/pdf')
          ->withHeader('Content-Disposition', 'attachment; filename="desglose_arqueo.pdf"')
          ->withHeader('Content-Length', (string) strlen($pdf))
          ->withStatus(200);
      }
      if ($salida === 'termica') {
        $texto = $this->desgloseArqueo->textoTermico($q);
        return $this->json($response, 200, ['texto' => $texto]);
      }
      return $this->json($response, 200, $this->desgloseArqueo->consultar($q));
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function listAnulaciones(Request $request, Response $response): Response
  {
    return $this->json($response, 200, $this->anulaciones->listar($request->getQueryParams()));
  }

  public function listCobrosPagos(Request $request, Response $response): Response
  {
    return $this->json($response, 200, $this->cobrosPagos->listar($request->getQueryParams()));
  }

  public function listVales(Request $request, Response $response): Response
  {
    return $this->json($response, 200, $this->vales->listar($request->getQueryParams()));
  }

  public function createVale(Request $request, Response $response): Response
  {
    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      $item = $this->vales->emitir($body);
      return $this->json($response, 201, $item);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function liquidarVale(Request $request, Response $response, array $args): Response
  {
    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      $item = $this->vales->liquidar(
        (string) ($args['empresa'] ?? ''),
        (int) ($args['codigo'] ?? 0),
        $body
      );
      return $this->json($response, 200, $item);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      $code = (int) $e->getCode();
      if ($code === 404) {
        return ErrorResponse::json($response, 404, $e->getMessage(), 'NO_ENCONTRADO');
      }
      if ($code === 409) {
        return ErrorResponse::json($response, 409, $e->getMessage(), 'CONFLICTO');
      }
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function listPedidos(Request $request, Response $response): Response
  {
    return $this->json($response, 200, $this->pedidos->listar($request->getQueryParams()));
  }

  public function getPedido(Request $request, Response $response, array $args): Response
  {
    $item = $this->pedidos->obtener(
      (string) ($args['empresa'] ?? ''),
      (int) ($args['pedido'] ?? 0)
    );
    if ($item === null) {
      return ErrorResponse::json($response, 404, 'Pedido no encontrado', 'NO_ENCONTRADO');
    }
    return $this->json($response, 200, $item);
  }

  public function createPedido(Request $request, Response $response): Response
  {
    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      $item = $this->pedidos->crear($body);
      return $this->json($response, 201, $item);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function reservarPedido(Request $request, Response $response): Response
  {
    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      $item = $this->pedidos->reservarPedido(
        (string) ($body['empresa'] ?? ''),
        isset($body['puesto']) ? (string) $body['puesto'] : null
      );
      return $this->json($response, 200, $item);
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
    $body = (array) ($request->getParsedBody() ?? []);
    if ($body === []) {
      $body = (array) json_decode((string) $request->getBody(), true);
    }
    try {
      $item = $this->pedidos->actualizar(
        trim((string) ($args['empresa'] ?? '')),
        (int) ($args['pedido'] ?? 0),
        $body
      );
      return $this->json($response, 200, $item);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      return $this->runtimeError($response, $e);
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function convertirPedidoVenta(Request $request, Response $response, array $args): Response
  {
    $body = (array) ($request->getParsedBody() ?? []);
    if ($body === []) {
      $body = (array) json_decode((string) $request->getBody(), true);
    }
    try {
      $item = $this->pedidos->convertirAVenta(
        trim((string) ($args['empresa'] ?? '')),
        (int) ($args['pedido'] ?? 0),
        $body
      );
      return $this->json($response, 200, $item);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      return $this->runtimeError($response, $e);
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function marcarPedidoImpreso(Request $request, Response $response, array $args): Response
  {
    try {
      $item = $this->pedidos->marcarImpreso(
        (string) ($args['empresa'] ?? ''),
        (int) ($args['pedido'] ?? 0)
      );
      return $this->json($response, 200, $item);
    } catch (\RuntimeException $e) {
      if ((int) $e->getCode() === 404) {
        return ErrorResponse::json($response, 404, $e->getMessage(), 'NO_ENCONTRADO');
      }
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function listAbcVentas(Request $request, Response $response): Response
  {
    try {
      return $this->json($response, 200, $this->abcVentas->generar($request->getQueryParams()));
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
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

  /** @param array<string, mixed> $context */
  private function audit(string $action, string $message, array $context = []): void
  {
    $this->logger->info($message, array_merge([
      'source' => 'api',
      'action' => $action,
    ], $context));
  }

  private function json(Response $response, int $status, $data): Response
  {
    $response->getBody()->write((string) json_encode($data, JSON_UNESCAPED_UNICODE));
    return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
  }
}
