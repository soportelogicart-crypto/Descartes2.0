<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Facturacion;

use Descartes\Api\Support\SimplePdf;

/**
 * Informe Albaranes pendientes de facturar (legacy InformeAlbaranes.rpt).
 * Solo consulta; reutiliza el criterio de pendientes del generador.
 */
final class AlbaranesPendientesService
{
  private GeneracionFacturasManualService $manual;

  public function __construct(GeneracionFacturasManualService $manual)
  {
    $this->manual = $manual;
  }

  /**
   * @param array<string, mixed> $query
   * @return array{items: list<array<string, mixed>>, totales: array{albaranes: int, importe: float}}
   */
  public function listar(array $query): array
  {
    return $this->manual->listarPendientes($this->normalizarQuery($query));
  }

  /**
   * PDF del listado (mismos filtros que listar).
   *
   * @param array<string, mixed> $query
   */
  public function informePdf(array $query): string
  {
    $data = $this->listar($query);
    $items = $data['items'];
    $totales = $data['totales'];
    $q = $this->normalizarQuery($query);

    $pdf = new SimplePdf();
    $pdf->title('Albaranes pendientes de facturar');
    $pdf->text(
      'Tienda ' . $this->rangoTxt($q, 'empresaDesde', 'empresaHasta', 'empresa')
      . '  Fecha ' . $this->rangoTxt($q, 'fechaDesde', 'fechaHasta')
      . '  Cliente ' . $this->rangoTxt($q, 'clienteDesde', 'clienteHasta', 'cliente'),
      9
    );
    $seleccion = trim((string) ($q['seleccion'] ?? 'todos'));
    $pdf->text(
      'Prefactura: ' . ($seleccion !== '' ? $seleccion : 'todos')
      . '  -  ' . (int) $totales['albaranes'] . ' albaranes'
      . '  -  Total ' . number_format((float) $totales['importe'], 2, '.', '') . ' EUR',
      9
    );
    $pdf->spacer(8);

    if ($items === []) {
      $pdf->text('No hay albaranes pendientes con esos filtros.', 10);
      return $pdf->build();
    }

    $rows = [];
    foreach ($items as $r) {
      $rows[] = [
        (string) ($r['fecha'] ?? ''),
        (string) ($r['empresa'] ?? ''),
        (string) ($r['albaran'] ?? ''),
        (string) ($r['cliente'] ?? ''),
        (string) ($r['razonSocial'] ?? ''),
        number_format((float) ($r['importe'] ?? 0), 2, '.', ''),
        !empty($r['prefactura']) ? 'Si' : '',
      ];
    }

    $pdf->table(
      ['Fecha', 'Tie', 'Alb', 'Cliente', 'Razon social', 'Importe', 'Pref'],
      $rows,
      [55, 35, 45, 55, 200, 60, 35]
    );

    $pdf->spacer(8);
    $pdf->text(
      'TOTAL - ' . (int) $totales['albaranes'] . ' albaranes - '
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
    $empresa = trim((string) ($query['empresa'] ?? ''));
    $empresaDesde = trim((string) ($query['empresaDesde'] ?? ''));
    $empresaHasta = trim((string) ($query['empresaHasta'] ?? ''));
    if ($empresa === '' && $empresaDesde === '' && $empresaHasta === '') {
      throw new \InvalidArgumentException('Indique tienda (empresa)');
    }
    // Compat: un solo código → filtro exacto (sin romper rango Desde/Hasta).
    if (
      $empresa === ''
      && $empresaDesde !== ''
      && ($empresaHasta === '' || $empresaHasta === $empresaDesde)
    ) {
      $query['empresa'] = $empresaDesde;
    }
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
