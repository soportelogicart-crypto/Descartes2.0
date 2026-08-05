<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Facturacion;

use PDO;

/**
 * Diario de facturación (legacy DiarioFacturacion.rpt).
 * Consulta de facturas emitidas — reutiliza listado de Impresión sin cola de print.
 */
final class DiarioFacturacionService
{
  private ImpresionFacturasService $impresion;

  public function __construct(ImpresionFacturasService $impresion)
  {
    $this->impresion = $impresion;
  }

  /**
   * @param array<string, mixed> $query
   * @return array{items: list<array<string, mixed>>, totales: array{facturas: int, importe: float}}
   */
  public function listar(array $query): array
  {
    $q = $query;
    if (!isset($q['estadoImpresion']) && !isset($q['soloNoImpresas'])) {
      $q['estadoImpresion'] = 'todas';
    }
    if (!isset($q['tipoCobro'])) {
      $q['tipoCobro'] = 'todas';
    }
    return $this->impresion->listar($q);
  }

  /**
   * PDF sin marcar Impresa.
   *
   * @param array<string, mixed> $body
   */
  public function informePdf(array $body): string
  {
    $body['marcarImpresa'] = false;
    if (!isset($body['estadoImpresion']) && !isset($body['soloNoImpresas']) && empty($body['facturas'])) {
      $body['estadoImpresion'] = 'todas';
    }
    if (!isset($body['tipoCobro']) && empty($body['facturas'])) {
      $body['tipoCobro'] = 'todas';
    }
    return $this->impresion->informePdf($body);
  }
}
