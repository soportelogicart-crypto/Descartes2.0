<?php

declare(strict_types=1);

namespace Descartes\Api\Controllers;

use Descartes\Api\Http\ErrorResponse;
use Descartes\Api\Services\Facturacion\AlbaranesPendientesService;
use Descartes\Api\Services\Facturacion\AlbaranesPeriodicosService;
use Descartes\Api\Services\Facturacion\DiarioFacturacionService;
use Descartes\Api\Services\Facturacion\FacturaEmailService;
use Descartes\Api\Services\Facturacion\GeneracionFacturasManualService;
use Descartes\Api\Services\Facturacion\ImpresionFacturasService;
use Descartes\Api\Services\Facturacion\RetrocesoFacturaService;
use Descartes\Api\Services\Facturacion\TraspasoComercialService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

final class FacturacionController
{
  private GeneracionFacturasManualService $manual;
  private FacturaEmailService $facturaEmail;
  private ImpresionFacturasService $impresion;
  private TraspasoComercialService $traspaso;
  private AlbaranesPeriodicosService $periodicos;
  private DiarioFacturacionService $diario;
  private AlbaranesPendientesService $pendientes;
  private RetrocesoFacturaService $retroceso;
  private LoggerInterface $logger;

  public function __construct(
    GeneracionFacturasManualService $manual,
    FacturaEmailService $facturaEmail,
    ImpresionFacturasService $impresion,
    TraspasoComercialService $traspaso,
    AlbaranesPeriodicosService $periodicos,
    DiarioFacturacionService $diario,
    AlbaranesPendientesService $pendientes,
    RetrocesoFacturaService $retroceso,
    LoggerInterface $logger
  ) {
    $this->manual = $manual;
    $this->facturaEmail = $facturaEmail;
    $this->impresion = $impresion;
    $this->traspaso = $traspaso;
    $this->periodicos = $periodicos;
    $this->diario = $diario;
    $this->pendientes = $pendientes;
    $this->retroceso = $retroceso;
    $this->logger = $logger;
  }

  public function listManualPendientes(Request $request, Response $response): Response
  {
    try {
      return $this->json($response, 200, $this->manual->listarPendientes($request->getQueryParams()));
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function previewGeneracion(Request $request, Response $response): Response
  {
    try {
      return $this->json($response, 200, $this->manual->previewAutomatico($request->getQueryParams()));
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function generarAutomatico(Request $request, Response $response): Response
  {
    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      $result = $this->manual->generarAutomatico($body);
      // Las prefacturas no son documentos fiscales y no se envían. En las
      // facturas, cada fallo SMTP queda reflejado sin revertir la generación.
      if (($result['tipoFacturacion'] ?? 'facturas') === 'facturas') {
        $result['emails'] = $this->facturaEmail->enviarGeneradas($result['facturas'] ?? []);
      } else {
        $result['emails'] = [
          'candidatas' => 0,
          'enviadas' => 0,
          'omitidas' => 0,
          'errores' => 0,
          'detalles' => [],
        ];
      }
      $this->logger->info('Facturas generadas (automatico)', [
        'source' => 'api',
        'action' => 'facturacion.generar',
        'facturas' => $result['totales']['facturas'] ?? 0,
        'albaranes' => $result['totales']['albaranes'] ?? 0,
        'emailsEnviados' => $result['emails']['enviadas'] ?? 0,
        'emailsErrores' => $result['emails']['errores'] ?? 0,
      ]);
      return $this->json($response, 200, $result);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      return $this->runtimeError($response, $e);
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function generarManual(Request $request, Response $response): Response
  {
    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      $result = $this->manual->generar($body);
      $this->logger->info('Facturas generadas (manual)', [
        'source' => 'api',
        'action' => 'facturacion.manual.generar',
        'facturas' => $result['totales']['facturas'] ?? 0,
        'albaranes' => $result['totales']['albaranes'] ?? 0,
      ]);
      return $this->json($response, 200, $result);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      return $this->runtimeError($response, $e);
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function listImpresion(Request $request, Response $response): Response
  {
    try {
      return $this->json($response, 200, $this->impresion->listar($request->getQueryParams()));
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function pdfImpresion(Request $request, Response $response): Response
  {
    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      $pdf = $this->impresion->informePdf($body);
      $this->logger->info('Facturas PDF generado', [
        'source' => 'api',
        'action' => 'facturacion.impresion.pdf',
        'bytes' => strlen($pdf),
      ]);
      $response->getBody()->write($pdf);
      return $response
        ->withHeader('Content-Type', 'application/pdf')
        ->withHeader('Content-Disposition', 'attachment; filename="facturas.pdf"')
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

  public function marcarImpresion(Request $request, Response $response): Response
  {
    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      return $this->json($response, 200, $this->impresion->marcar($body));
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function traspasoManual(Request $request, Response $response): Response
  {
    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      $result = $this->traspaso->generar($body);
      $this->logger->info('Traspasos comerciales generados', [
        'source' => 'api',
        'action' => 'facturacion.manual.traspaso',
        'traspasos' => $result['totales']['traspasos'] ?? 0,
      ]);
      return $this->json($response, 200, $result);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      return $this->runtimeError($response, $e);
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function periodicosGenerar(Request $request, Response $response): Response
  {
    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      $result = $this->periodicos->generar($body);
      $this->logger->info('Albaranes periodicos generados', [
        'source' => 'api',
        'action' => 'facturacion.manual.periodicos',
        'generados' => $result['totales']['generados'] ?? 0,
      ]);
      return $this->json($response, 200, $result);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      return $this->runtimeError($response, $e);
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function listDiario(Request $request, Response $response): Response
  {
    try {
      return $this->json($response, 200, $this->diario->listar($request->getQueryParams()));
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function pdfDiario(Request $request, Response $response): Response
  {
    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      $pdf = $this->diario->informePdf($body);
      $response->getBody()->write($pdf);
      return $response
        ->withHeader('Content-Type', 'application/pdf')
        ->withHeader('Content-Disposition', 'attachment; filename="diario-facturacion.pdf"')
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

  public function listAlbaranesPendientes(Request $request, Response $response): Response
  {
    try {
      return $this->json($response, 200, $this->pendientes->listar($request->getQueryParams()));
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function pdfAlbaranesPendientes(Request $request, Response $response): Response
  {
    try {
      $pdf = $this->pendientes->informePdf($request->getQueryParams());
      $response->getBody()->write($pdf);
      return $response
        ->withHeader('Content-Type', 'application/pdf')
        ->withHeader('Content-Disposition', 'inline; filename="albaranes-pendientes.pdf"')
        ->withHeader('Content-Length', (string) strlen($pdf))
        ->withStatus(200);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function previewRetroceso(Request $request, Response $response): Response
  {
    try {
      return $this->json($response, 200, $this->retroceso->preview($request->getQueryParams()));
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      return $this->runtimeError($response, $e);
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function ejecutarRetroceso(Request $request, Response $response): Response
  {
    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      $result = $this->retroceso->ejecutar($body);
      $this->logger->info('Factura retrocedida', [
        'source' => 'api',
        'action' => 'facturacion.retroceso',
        'empresa' => $result['empresa'] ?? null,
        'factura' => $result['factura'] ?? null,
      ]);
      return $this->json($response, 200, $result);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      return $this->runtimeError($response, $e);
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

  private function json(Response $response, int $status, $data): Response
  {
    $response->getBody()->write((string) json_encode($data, JSON_UNESCAPED_UNICODE));
    return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
  }
}
