<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Facturacion;

use Descartes\Api\Services\Ventas\VentaConsultaService;
use Descartes\Api\Support\SimplePdf;

/**
 * Informe Albaranes pendientes de facturar (legacy InformeAlbaranes.rpt).
 * Solo consulta; reutiliza el criterio de pendientes del generador.
 */
final class AlbaranesPendientesService
{
  private GeneracionFacturasManualService $manual;
  private VentaConsultaService $ventas;

  public function __construct(GeneracionFacturasManualService $manual, VentaConsultaService $ventas)
  {
    $this->manual = $manual;
    $this->ventas = $ventas;
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
   * Ficha del albarán (cabecera + líneas) para la consulta desde el listado.
   *
   * @return array<string, mixed>|null
   */
  public function detalle(string $empresa, string $tipo, int $albaran): ?array
  {
    return $this->ventas->obtenerFicha(trim($empresa), trim($tipo), $albaran);
  }

  /** PDF del albarán en curso (aún sin factura): cabecera, líneas y desglose de IVA. */
  public function documentoPdf(string $empresa, string $tipo, int $albaran): string
  {
    $alb = $this->detalle($empresa, $tipo, $albaran);
    if ($alb === null) {
      throw new \RuntimeException('Albarán no encontrado', 404);
    }

    $pdf = new SimplePdf();
    $pdf->title('Albarán ' . trim((string) $alb['tipo']) . '/' . (int) $alb['albaran']);
    $pdf->text(
      'Tienda ' . trim((string) $alb['empresa'])
      . '   Fecha ' . (string) ($alb['fecha'] ?? '')
      . '   Puesto ' . trim((string) ($alb['puesto'] ?? ''))
      . '   Vendedor ' . trim((string) ($alb['vendedor'] ?? '')),
      9
    );
    $pdf->spacer(4);
    $pdf->text(
      'Cliente ' . trim((string) ($alb['cliente'] ?? ''))
      . ' - ' . trim((string) ($alb['razonSocial'] ?? '')),
      10,
      true
    );
    $nif = trim((string) ($alb['nif'] ?? ''));
    if ($nif !== '') {
      $pdf->text('NIF ' . $nif, 9);
    }
    foreach ($this->lineasDireccion($alb) as $linea) {
      $pdf->text($linea, 9);
    }
    $pdf->spacer(8);

    $rows = [];
    foreach ((array) ($alb['lineas'] ?? []) as $lin) {
      $rows[] = [
        (string) ($lin['articulo'] ?? ''),
        (string) ($lin['descripcion'] ?? ''),
        $this->num((float) ($lin['cantidad'] ?? 0)),
        $this->num((float) ($lin['precio'] ?? 0)),
        $this->num((float) ($lin['pjeDto'] ?? 0)),
        $this->num((float) ($lin['importe'] ?? 0)),
      ];
    }
    if ($rows === []) {
      $pdf->text('El albarán no tiene líneas.', 10);
    } else {
      $pdf->table(
        ['Articulo', 'Descripcion', 'Cantidad', 'Precio', '% Dto', 'Importe'],
        $rows,
        [70, 205, 55, 60, 45, 70]
      );
    }

    $pdf->spacer(10);
    foreach ((array) ($alb['importesIva'] ?? []) as $iva) {
      $pdf->text(
        'Base ' . $this->num((float) ($iva['base'] ?? 0))
        . '   IVA ' . $this->num((float) ($iva['pjeIva'] ?? 0)) . '%'
        . '   Cuota ' . $this->num((float) ($iva['iva'] ?? 0)),
        9
      );
    }
    $dto = (float) ($alb['descuento'] ?? 0);
    if (abs($dto) >= 0.005) {
      $pdf->text('Descuentos ' . $this->num($dto), 9);
    }
    $pdf->spacer(4);
    $pdf->text('TOTAL ' . $this->num((float) ($alb['importe'] ?? 0)) . ' EUR', 12, true);

    return $pdf->build();
  }

  /**
   * @param array<string, mixed> $alb
   * @return list<string>
   */
  private function lineasDireccion(array $alb): array
  {
    $out = [];
    $direccion = trim((string) ($alb['direccionEnvio'] ?? ''));
    if ($direccion !== '') {
      $out[] = $direccion;
    }
    $poblacion = trim(
      trim((string) ($alb['codigoPostalEnvio'] ?? '')) . ' ' . trim((string) ($alb['poblacionEnvio'] ?? ''))
    );
    $provincia = trim((string) ($alb['provinciaEnvio'] ?? ''));
    if ($provincia !== '') {
      $poblacion = trim($poblacion . ' (' . $provincia . ')');
    }
    if ($poblacion !== '') {
      $out[] = $poblacion;
    }
    return $out;
  }

  private function num(float $valor): string
  {
    return number_format($valor, 2, '.', '');
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
