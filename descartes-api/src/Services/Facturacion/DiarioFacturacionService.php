<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Facturacion;

use Descartes\Api\Support\SimplePdf;

/**
 * Diario de facturación (legacy DiarioFacturacion.rpt).
 * Consulta de facturas emitidas — listado tabular (no reimpresión individual).
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
    return $this->impresion->listar($this->normalizarQuery($query));
  }

  /**
   * PDF del diario: listado tabular con los mismos filtros que la consulta.
   *
   * @param array<string, mixed> $body
   */
  public function informePdf(array $body): string
  {
    $q = $this->normalizarQuery($body);
    $data = $this->listar($q);
    $items = $data['items'];
    $totales = $data['totales'];

    $pdf = new SimplePdf();
    $pdf->title('Diario de facturacion');
    $pdf->text(
      'Tienda ' . $this->rangoTxt($q, 'empresaDesde', 'empresaHasta', 'empresa')
      . '  Fecha ' . $this->rangoTxt($q, 'fechaDesde', 'fechaHasta')
      . '  Cliente ' . $this->rangoTxt($q, 'clienteDesde', 'clienteHasta', 'cliente'),
      9
    );
    $tipo = strtoupper(trim((string) ($q['facturaTipo'] ?? '')));
    $estado = strtoupper(trim((string) ($q['estado'] ?? '')));
    $pdf->text(
      'Tipo ' . ($tipo !== '' ? $tipo : 'todos')
      . '  Estado ' . ($estado !== '' ? $estado : 'todos')
      . '  -  ' . (int) $totales['facturas'] . ' facturas'
      . '  -  Total ' . number_format((float) $totales['importe'], 2, '.', '') . ' EUR',
      9
    );
    if ((int) $totales['facturas'] >= 500) {
      $pdf->text('(Listado limitado a las primeras 500 facturas)', 8);
    }
    $pdf->spacer(8);

    if ($items === []) {
      $pdf->text('No hay facturas con esos filtros.', 10);
      return $pdf->build();
    }

    // Filas + subtotales por día (orden cronológico ya viene ASC).
    $rows = [];
    $fechaActual = null;
    $diaCount = 0;
    $diaImporte = 0.0;

    $flushDia = static function () use (&$rows, &$fechaActual, &$diaCount, &$diaImporte): void {
      if ($fechaActual === null || $diaCount === 0) {
        return;
      }
      $rows[] = [
        '',
        '',
        '',
        '',
        'Total dia ' . $fechaActual . ' (' . $diaCount . ')',
        number_format($diaImporte, 2, '.', ''),
        '',
        '',
        '',
      ];
      $diaCount = 0;
      $diaImporte = 0.0;
    };

    foreach ($items as $r) {
      $fecha = (string) ($r['fecha'] ?? '');
      if ($fechaActual !== null && $fecha !== $fechaActual) {
        $flushDia();
      }
      $fechaActual = $fecha;
      $imp = (float) ($r['importe'] ?? 0);
      $diaCount++;
      $diaImporte += $imp;

      $cobro = (($r['tipoCobro'] ?? '') === 'diferida') ? 'Dif.' : 'Con.';
      $rows[] = [
        $fecha,
        (string) ($r['empresa'] ?? ''),
        trim((string) ($r['facturaTipo'] ?? '')) . '-' . (string) ($r['factura'] ?? ''),
        (string) ($r['cliente'] ?? ''),
        substr((string) ($r['razonSocial'] ?? ''), 0, 28),
        number_format($imp, 2, '.', ''),
        $cobro,
        (string) ($r['fpago'] ?? ''),
        !empty($r['impresa']) ? 'Si' : '',
      ];
    }
    $flushDia();

    $pdf->table(
      ['Fecha', 'Tie', 'Factura', 'Cliente', 'Razon social', 'Importe', 'Cobro', 'F.P.', 'Imp'],
      $rows,
      [52, 28, 52, 48, 120, 50, 32, 32, 24]
    );

    $pdf->spacer(8);
    $pdf->text(
      'TOTAL - ' . (int) $totales['facturas'] . ' facturas - '
      . number_format((float) $totales['importe'], 2, '.', '') . ' EUR',
      11,
      true
    );

    return $pdf->build();
  }

  /**
   * @param array<string, mixed> $query
   * @return array<string, mixed>
   */
  private function normalizarQuery(array $query): array
  {
    if (!isset($query['estadoImpresion']) && !isset($query['soloNoImpresas'])) {
      $query['estadoImpresion'] = 'todas';
    }
    if (!isset($query['tipoCobro'])) {
      $query['tipoCobro'] = 'todas';
    }
    // Diario: orden cronológico (legacy).
    $query['ordenFecha'] = 'asc';
    return $query;
  }

  /**
   * @param array<string, mixed> $q
   */
  private function rangoTxt(array $q, string $desdeKey, string $hastaKey, string $exactKey = ''): string
  {
    $desde = trim((string) ($q[$desdeKey] ?? ''));
    $hasta = trim((string) ($q[$hastaKey] ?? ''));
    $exact = $exactKey !== '' ? trim((string) ($q[$exactKey] ?? '')) : '';
    if ($desde === '' && $hasta === '' && $exact !== '') {
      return $exact;
    }
    if ($desde === '' && $hasta === '') {
      return '-';
    }
    if ($desde !== '' && ($hasta === '' || $hasta === $desde)) {
      return $desde;
    }
    return ($desde !== '' ? $desde : '-') . '...' . ($hasta !== '' ? $hasta : '-');
  }
}
