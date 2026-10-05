<?php

declare(strict_types=1);

namespace Descartes\Api\Controllers;

use Descartes\Api\Http\ErrorResponse;
use Descartes\Api\Services\Facturacion\ImpresionFacturasService;
use Descartes\Api\Services\PermissionService;
use Descartes\Api\Services\Ventas\AbcVentasService;
use Descartes\Api\Services\Ventas\AnulacionConsultaService;
use Descartes\Api\Services\Ventas\AutorizacionTarjetaService;
use Descartes\Api\Services\Ventas\ArqueoService;
use Descartes\Api\Services\Ventas\CobroPagoConsultaService;
use Descartes\Api\Services\Ventas\DesgloseArqueoVentasService;
use Descartes\Api\Services\Ventas\DispositivoPuestoService;
use Descartes\Api\Services\Ventas\FidelizacionService;
use Descartes\Api\Services\Ventas\FidelizacionValesSemestreService;
use Descartes\Api\Services\TipoDescuentoService;
use Descartes\Api\Services\Ventas\PedidoClienteService;
use Descartes\Api\Services\Ventas\SituacionVentasService;
use Descartes\Api\Services\Ventas\ValeService;
use Descartes\Api\Services\Ventas\VentaConsultaService;
use Descartes\Api\Services\Ventas\VentaEmailService;
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
  private FidelizacionService $fidelizacion;
  private FidelizacionValesSemestreService $fidelizacionVales;
  private PedidoClienteService $pedidos;
  private AbcVentasService $abcVentas;
  private VentaEmailService $ventaEmail;
  private AutorizacionTarjetaService $autorizacionesTarjeta;
  private PermissionService $permissions;
  private TipoDescuentoService $tiposDescuento;
  private SituacionVentasService $situacionVentas;
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
    FidelizacionService $fidelizacion,
    FidelizacionValesSemestreService $fidelizacionVales,
    PedidoClienteService $pedidos,
    AbcVentasService $abcVentas,
    VentaEmailService $ventaEmail,
    AutorizacionTarjetaService $autorizacionesTarjeta,
    PermissionService $permissions,
    TipoDescuentoService $tiposDescuento,
    SituacionVentasService $situacionVentas,
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
    $this->fidelizacion = $fidelizacion;
    $this->fidelizacionVales = $fidelizacionVales;
    $this->pedidos = $pedidos;
    $this->abcVentas = $abcVentas;
    $this->ventaEmail = $ventaEmail;
    $this->autorizacionesTarjeta = $autorizacionesTarjeta;
    $this->permissions = $permissions;
    $this->tiposDescuento = $tiposDescuento;
    $this->situacionVentas = $situacionVentas;
    $this->logger = $logger;
  }

  public function descuentoOferta(Request $request, Response $response): Response
  {
    $query = $request->getQueryParams();
    $articulos = $query['articulos'] ?? $query['articulo'] ?? '';
    if (!is_array($articulos)) {
      $articulos = explode(',', (string) $articulos);
    }
    try {
      $items = $this->tiposDescuento->porcentajesLinea(
        (string) ($query['cliente'] ?? ''),
        (string) ($query['empresa'] ?? ''),
        (string) ($query['fecha'] ?? ''),
        $articulos
      );

      return $this->json($response, 200, ['items' => $items]);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, 'No se pudo calcular el descuento de la oferta', 'ERROR');
    }
  }

  public function listVentas(Request $request, Response $response): Response
  {
    return $this->json($response, 200, $this->ventas->listar($request->getQueryParams()));
  }

  public function getPuestoVenta(Request $request, Response $response, array $args): Response
  {
    $puesto = trim((string) ($args['puesto'] ?? ''));
    if ($puesto === '') {
      return ErrorResponse::json($response, 400, 'Puesto obligatorio', 'VALIDACION');
    }

    return $this->json($response, 200, $this->escritura->datosPuesto($puesto));
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

  public function emailVenta(Request $request, Response $response, array $args): Response
  {
    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      $resultado = $this->ventaEmail->enviar(
        (string) ($args['empresa'] ?? ''),
        (string) ($args['tipo'] ?? ''),
        (int) ($args['albaran'] ?? 0),
        (string) ($body['email'] ?? ''),
        isset($body['pdf']) ? (string) $body['pdf'] : null
      );
      $this->audit('ventas.email', 'Documento de venta enviado por email', [
        'empresa' => $args['empresa'] ?? null,
        'tipo' => $args['tipo'] ?? null,
        'albaran' => $args['albaran'] ?? null,
        'destinatario' => $resultado['destinatario'],
      ]);
      return $this->json($response, 200, $resultado);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
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

  public function getSituacionVentas(Request $request, Response $response): Response
  {
    try {
      return $this->json($response, 200, $this->situacionVentas->consultar($request->getQueryParams()));
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR_INTERNO');
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

  public function listarImpresorasDispositivo(Request $request, Response $response): Response
  {
    try {
      $item = $this->dispositivos->listarImpresoras();
      return $this->json($response, 200, $item);
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

  public function getAutorizacionTarjetaAlbaran(Request $request, Response $response, array $args): Response
  {
    try {
      $empresa = trim((string) ($args['empresa'] ?? ''));
      $albaran = (int) ($args['albaran'] ?? 0);
      $item = $this->autorizacionesTarjeta->buscarPorAlbaran($empresa, $albaran);
      return $this->json($response, 200, ['item' => $item]);
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function buscarAutorizacionTarjeta(Request $request, Response $response, array $args): Response
  {
    try {
      $q = $request->getQueryParams();
      $empresa = trim((string) ($args['empresa'] ?? ''));
      $aut = trim((string) ($q['aut'] ?? $q['autorizacion'] ?? ''));
      $clr = trim((string) ($q['clr'] ?? ''));
      $importeRaw = $q['importe'] ?? null;
      $importe = $importeRaw !== null && $importeRaw !== '' ? (float) $importeRaw : null;
      $albaranOrigen = isset($q['albaran']) ? (int) $q['albaran'] : 0;
      $item = $this->autorizacionesTarjeta->resolverParaDevolucion(
        $empresa,
        $aut,
        $clr,
        $importe,
        $albaranOrigen > 0 ? $albaranOrigen : null
      );
      return $this->json($response, 200, ['item' => $item]);
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function registrarAutorizacionTarjeta(Request $request, Response $response, array $args): Response
  {
    $body = (array) ($request->getParsedBody() ?? []);
    if ($body === []) {
      $body = (array) json_decode((string) $request->getBody(), true);
    }
    try {
      $empresa = trim((string) ($args['empresa'] ?? ''));
      $puesto = trim((string) ($body['puesto'] ?? ''));
      $sesion = (int) ($body['sesion'] ?? 0);
      $albaran = (int) ($args['albaran'] ?? 0);
      $this->autorizacionesTarjeta->registrar($empresa, $puesto, $sesion, $albaran, $body);
      return $this->json($response, 200, ['ok' => true]);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function cobrarDatafonoDispositivo(Request $request, Response $response, array $args): Response
  {
    return $this->operacionDatafono($request, $response, $args, 'cobrarDatafono');
  }

  public function cancelarDatafonoDispositivo(Request $request, Response $response, array $args): Response
  {
    return $this->operacionDatafono($request, $response, $args, 'cancelarDatafono');
  }

  public function estadoDatafonoDispositivo(Request $request, Response $response, array $args): Response
  {
    return $this->operacionDatafono($request, $response, $args, 'estadoDatafono');
  }

  private function operacionDatafono(
    Request $request,
    Response $response,
    array $args,
    string $metodo
  ): Response {
    $body = (array) ($request->getParsedBody() ?? []);
    if ($body === []) {
      $body = (array) json_decode((string) $request->getBody(), true);
    }
    try {
      $item = $this->dispositivos->{$metodo}(trim((string) ($args['puesto'] ?? '')), $body);
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

  public function valeFidelizacionDisponible(Request $request, Response $response): Response
  {
    $q = $request->getQueryParams();
    try {
      $item = $this->vales->fidelizacionDisponible(
        trim((string) ($q['empresa'] ?? '')),
        trim((string) ($q['cliente'] ?? ''))
      );
      return $this->json($response, 200, $item);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
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

  public function semestreFidelizacion(Request $request, Response $response): Response
  {
    $sem = $this->fidelizacionVales->semestreAnterior();
    return $this->json($response, 200, $sem);
  }

  public function configuracionFidelizacion(Request $request, Response $response): Response
  {
    try {
      $q = $request->getQueryParams();
      return $this->json(
        $response,
        200,
        $this->fidelizacionVales->configuracion(trim((string) ($q['empresa'] ?? '')))
      );
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      return $this->runtimeError($response, $e);
    }
  }

  public function guardarSemestreFidelizacion(Request $request, Response $response): Response
  {
    $body = (array) ($request->getParsedBody() ?? []);
    try {
      return $this->json(
        $response,
        200,
        $this->fidelizacionVales->guardarConfiguracion(
          trim((string) ($body['codigo'] ?? '')),
          $body
        )
      );
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      return $this->runtimeError($response, $e);
    }
  }

  public function guardarPuntosFidelizacion(Request $request, Response $response): Response
  {
    $body = (array) ($request->getParsedBody() ?? []);
    try {
      return $this->json(
        $response,
        200,
        $this->fidelizacion->guardarConfiguracionPuntos(
          trim((string) ($body['codigo'] ?? '')),
          $body
        )
      );
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      return $this->runtimeError($response, $e);
    }
  }

  public function arbolExclusionFidelizacion(Request $request, Response $response): Response
  {
    $q = $request->getQueryParams();
    try {
      return $this->json(
        $response,
        200,
        $this->fidelizacion->arbolExclusion(
          trim((string) ($q['nivel'] ?? '')),
          trim((string) ($q['codigo'] ?? ''))
        )
      );
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, 'No se pudo cargar el árbol de artículos', 'ERROR');
    }
  }

  public function puntosCanjeDisponible(Request $request, Response $response): Response
  {
    $q = $request->getQueryParams();
    try {
      return $this->json(
        $response,
        200,
        $this->fidelizacion->canjeDisponible(
          trim((string) ($q['empresa'] ?? '')),
          trim((string) ($q['cliente'] ?? '')),
          (float) ($q['importe'] ?? 0)
        )
      );
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, 'No se pudieron consultar los puntos', 'ERROR');
    }
  }

  public function marcarTiendaSinPuntos(Request $request, Response $response): Response
  {
    $body = (array) ($request->getParsedBody() ?? []);
    try {
      return $this->json(
        $response,
        200,
        $this->fidelizacionVales->marcarSinPuntos(
          trim((string) ($body['empresa'] ?? '')),
          filter_var($body['sinPuntos'] ?? false, FILTER_VALIDATE_BOOL)
        )
      );
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      return $this->runtimeError($response, $e);
    }
  }

  public function seleccionarModeloFidelizacion(Request $request, Response $response): Response
  {
    $body = (array) ($request->getParsedBody() ?? []);
    try {
      return $this->json(
        $response,
        200,
        $this->fidelizacionVales->seleccionarModelo(
          trim((string) ($body['empresa'] ?? '')),
          trim((string) ($body['codigo'] ?? ''))
        )
      );
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      return $this->runtimeError($response, $e);
    }
  }

  public function estadoAutomaticoFidelizacion(Request $request, Response $response): Response
  {
    try {
      $q = $request->getQueryParams();
      return $this->json(
        $response,
        200,
        $this->fidelizacionVales->estadoAutomatico(trim((string) ($q['empresa'] ?? '')))
      );
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function generarAutomaticamenteFidelizacion(Request $request, Response $response): Response
  {
    $body = (array) ($request->getParsedBody() ?? []);
    try {
      $item = $this->fidelizacionVales->generarAutomaticamente(
        trim((string) ($body['empresa'] ?? '')),
        trim((string) ($body['puesto'] ?? ''))
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

  public function generarValesFidelizacion(Request $request, Response $response): Response
  {
    $body = (array) ($request->getParsedBody() ?? []);
    if ($body === []) {
      $body = (array) json_decode((string) $request->getBody(), true);
    }
    try {
      $item = $this->fidelizacionVales->ejecutar($body);
      return $this->json($response, !empty($item['simulado']) ? 200 : 201, $item);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      return $this->runtimeError($response, $e);
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
