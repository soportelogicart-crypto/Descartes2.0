<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Listados;

use Descartes\Api\Support\SimplePdf;
use InvalidArgumentException;
use PDO;

/**
 * Informe de IVA legacy: una fila por factura/ticket en [Facturas] (bases/cuotas en cabecera).
 * Opcional modo tickets TPV vía albaranes agrupados por número de documento.
 */
final class InformeIvaListadoService
{
  private const LIMITE_FILAS = 10000;

  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  /** @param array<string, mixed> $query */
  public function generar(array $query): array
  {
    $q = $this->normalizarQuery($query);
    if ($q['tipoDocumento'] === 'tickets') {
      return $this->generarDesdeAlbaranesAgrupados($q, 'T');
    }

    return $this->generarDesdeFacturas($q);
  }

  /**
   * PDF tabular (legacy Crystal) con los mismos filtros que la consulta.
   *
   * @param array<string, mixed> $query
   */
  public function informePdf(array $query): string
  {
    $q = $this->normalizarQuery($query);
    $data = $q['tipoDocumento'] === 'tickets'
      ? $this->generarDesdeAlbaranesAgrupados($q, 'T')
      : $this->generarDesdeFacturas($q);

    $items = $data['items'];
    $tot = $data['totales'];
    $resumen = $data['resumenPorIva'];
    $formato = $q['formato'];

    if ($formato === 'cliente') {
      usort($items, static function (array $a, array $b): int {
        $c = strcasecmp((string) ($a['cliente'] ?? ''), (string) ($b['cliente'] ?? ''));
        if ($c !== 0) {
          return $c;
        }

        return strcmp((string) ($a['fecha'] ?? ''), (string) ($b['fecha'] ?? ''));
      });
    }

    $pdf = new SimplePdf();
    $pdf->title('Informe de IVA');
    $pdf->text(
      'Periodo ' . $q['fechaDesde'] . ' - ' . $q['fechaHasta']
      . '  Divisa EU  Estado ' . $this->etiquetaEstado($q['estado'])
      . '  Formato ' . $formato,
      9
    );
    $pdf->text(
      (int) $tot['tickets'] . ' documentos  Base '
      . number_format((float) $tot['baseImponible'], 2, '.', '')
      . '  IVA ' . number_format((float) $tot['cuotaIva'], 2, '.', '')
      . '  Total ' . number_format((float) $tot['importeTotal'], 2, '.', '') . ' EUR',
      9
    );
    if (!empty($data['truncado'])) {
      $pdf->text('(Listado limitado a ' . self::LIMITE_FILAS . ' filas)', 8);
    }
    $pdf->spacer(6);

    if ($items === []) {
      $pdf->text('No hay documentos con esos filtros.', 10);

      return $pdf->build();
    }

    $headers = ['Fecha', 'Doc.', 'Cliente'];
    if ($formato === 'extendido' || $formato === 'extendido_ctb') {
      $headers[] = 'Tienda';
    }
    foreach ($resumen as $r) {
      $l = $this->fmtPje((float) $r['pjeIva']);
      $headers[] = 'B.' . $l;
      $headers[] = 'IVA ' . $l;
    }
    $headers[] = 'Total';
    if ($formato === 'extendido_ctb') {
      $headers[] = 'Ctb';
      $headers[] = 'LROD';
    }

    $rows = [];
    $limitePdf = 500;
    foreach (array_slice($items, 0, $limitePdf) as $row) {
      $doc = trim((string) ($row['facturaTipo'] ?? 'F')) . '-' . (string) ($row['numeroTicket'] ?? '');
      $line = [
        (string) ($row['fecha'] ?? ''),
        $doc,
        (string) (($row['razonSocial'] ?? '') !== '' ? $row['razonSocial'] : ($row['cliente'] ?? '')),
      ];
      if ($formato === 'extendido' || $formato === 'extendido_ctb') {
        $line[] = (string) ($row['empresa'] ?? '');
      }
      foreach ($resumen as $r) {
        $d = $this->buscarDesglose($row['desgloseIva'] ?? [], (float) $r['pjeIva']);
        $line[] = $d ? number_format($d['baseImponible'], 2, '.', '') : '';
        $line[] = $d ? number_format($d['cuotaIva'], 2, '.', '') : '';
      }
      $line[] = number_format((float) ($row['importeTotal'] ?? 0), 2, '.', '');
      if ($formato === 'extendido_ctb') {
        $line[] = !empty($row['trasCtb']) ? 'S' : '';
        $line[] = !empty($row['trasModem']) ? 'S' : '';
      }
      $rows[] = $line;
    }

    $n = count($headers);
    $w = array_fill(0, $n, 515.0 / max(1, $n));
    $aligns = array_fill(0, $n, 'r');
    $aligns[0] = '';
    $aligns[1] = '';
    $aligns[2] = '';

    $pdf->table($headers, $rows, $w, $aligns);

    if (count($items) > $limitePdf) {
      $pdf->spacer(4);
      $pdf->text('(PDF limitado a las primeras ' . $limitePdf . ' filas)', 8);
    }

    return $pdf->build();
  }

  /**
   * @param array<string, mixed> $q
   * @return array<string, mixed>
   */
  private function generarDesdeFacturas(array $q): array
  {
    $where = [
      'f.[Fecha] >= CONVERT(datetime, :fechaDesde, 120)',
      'f.[Fecha] <= CONVERT(datetime, :fechaHasta, 120)',
    ];
    $params = [
      'fechaDesde' => $q['fechaDesde'] . ' 00:00:00',
      'fechaHasta' => $q['fechaHasta'] . ' 23:59:59',
    ];

    $estado = (string) $q['estado'];
    if ($estado === 'menos' || $estado === 'mas') {
      ListadosFiltrosSql::filtroEstadoInformeIva($where, $estado, 'f');
      if ($estado === 'mas') {
        $where[] = "RTRIM(f.[FacturaTipo]) = 'F'";
      }
    } else {
      $where[] = "RTRIM(f.[FacturaTipo]) = 'F'";
      ListadosFiltrosSql::filtroEstadoInformeIva($where, $estado, 'f');
    }

    if ($q['soloDivisaEu']) {
      ListadosFiltrosSql::filtroDivisaEuEmpresa($where, 'f.[Empresa]');
    }

    $this->aplicarRangosFactura($where, $params, $q, 'f');
    $this->aplicarFiltrosCabeceraVentas($where, $params, $q, 'f');

    $baseSum = implode(' + ', array_map(static fn (int $i) => "ISNULL(f.[ImporteBase{$i}], 0)", range(1, 6)));
    $ivaSum = implode(' + ', array_map(static fn (int $i) => "ISNULL(f.[ImporteIva{$i}], 0)", range(1, 6)));

    $sql = 'SELECT TOP ' . (self::LIMITE_FILAS + 1) . "
              RTRIM(f.[Empresa]) AS empresa,
              RTRIM(f.[FacturaTipo]) AS facturaTipo,
              f.[Factura] AS numeroTicket,
              CONVERT(varchar(10), f.[Fecha], 23) AS fecha,
              RTRIM(ISNULL(f.[Cliente], '')) AS cliente,
              RTRIM(ISNULL(cl.[RazonSocial], '')) AS razonSocial,
              ISNULL(f.[Importe], 0) AS importeTotal,
              ISNULL(f.[TrasCtb], 0) AS trasCtb,
              ISNULL(f.[TrasModem], 0) AS trasModem,
              ({$baseSum}) AS baseImponible,
              ({$ivaSum}) AS cuotaIva,
              f.[ImporteBase1], f.[ImporteBase2], f.[ImporteBase3], f.[ImporteBase4], f.[ImporteBase5], f.[ImporteBase6],
              f.[ImporteIva1], f.[ImporteIva2], f.[ImporteIva3], f.[ImporteIva4], f.[ImporteIva5], f.[ImporteIva6],
              f.[PjeIva1], f.[PjeIva2], f.[PjeIva3], f.[PjeIva4], f.[PjeIva5], f.[PjeIva6]
            FROM [Facturas] f
            LEFT JOIN [Clientes] cl ON cl.[Codigo] = f.[Cliente]
            WHERE " . implode(' AND ', $where) . '
            ORDER BY f.[Fecha], f.[Factura]';

    return $this->ejecutarListado($sql, $params, $q);
  }

  /**
   * @param array<string, mixed> $q
   * @return array<string, mixed>
   */
  private function generarDesdeAlbaranesAgrupados(array $q, string $facturaTipo): array
  {
    $where = [
      'c.[Fecha] >= CONVERT(datetime, :fechaDesde, 120)',
      'c.[Fecha] <= CONVERT(datetime, :fechaHasta, 120)',
      'ISNULL(c.[Anulado], 0) = 0',
      "RTRIM(ISNULL(c.[Estado], '')) <> 'B'",
      "RTRIM(c.[FacturaTipo]) = :facturaTipoCab",
      'ISNULL(c.[Factura], 0) > 0',
    ];
    $params = [
      'fechaDesde' => $q['fechaDesde'] . ' 00:00:00',
      'fechaHasta' => $q['fechaHasta'] . ' 23:59:59',
      'facturaTipoCab' => $facturaTipo,
    ];
    ListadosFiltrosSql::filtroRangoTexto($where, $params, 'c.[Empresa]', $q, 'empresaDesde', 'empresaHasta', 'empresa', 'iva_tienda');
    ListadosFiltrosSql::filtroRangoTexto($where, $params, 'c.[Empresa]', $q, 'origenDesde', 'origenHasta', null, 'iva_origen');
    ListadosFiltrosSql::filtroRangoTexto($where, $params, 'c.[Cliente]', $q, 'clienteDesde', 'clienteHasta');
    ListadosFiltrosSql::filtroRangoTexto($where, $params, 'c.[Puesto]', $q, 'puestoDesde', 'puestoHasta');
    ListadosFiltrosSql::filtroRangoEntero($where, $params, 'c.[Sesion]', $q, 'sesionDesde', 'sesionHasta');
    if ($q['facturaDesde'] > 0) {
      $where[] = 'c.[Factura] >= :facturaDesde';
      $params['facturaDesde'] = $q['facturaDesde'];
    }
    if ($q['facturaHasta'] > 0) {
      $where[] = 'c.[Factura] <= :facturaHasta';
      $params['facturaHasta'] = $q['facturaHasta'];
    }
    if ($q['soloDivisaEu']) {
      ListadosFiltrosSql::filtroDivisaEuEmpresa($where, 'c.[Empresa]');
    }

    $baseSum = implode(' + ', array_map(static fn (int $i) => "ISNULL(c.[ImporteBase{$i}], 0)", range(1, 6)));
    $ivaSum = implode(' + ', array_map(static fn (int $i) => "ISNULL(c.[ImporteIva{$i}], 0)", range(1, 6)));

    $sql = 'SELECT TOP ' . (self::LIMITE_FILAS + 1) . "
              RTRIM(c.[Empresa]) AS empresa,
              RTRIM(c.[FacturaTipo]) AS facturaTipo,
              c.[Factura] AS numeroTicket,
              CONVERT(varchar(10), MIN(c.[Fecha]), 23) AS fecha,
              MAX(RTRIM(ISNULL(c.[Cliente], ''))) AS cliente,
              MAX(RTRIM(ISNULL(c.[RazonSocial], ''))) AS razonSocial,
              SUM(ISNULL(c.[Importe], 0)) AS importeTotal,
              SUM({$baseSum}) AS baseImponible,
              SUM({$ivaSum}) AS cuotaIva,
              SUM(ISNULL(c.[ImporteBase1], 0)) AS ImporteBase1,
              SUM(ISNULL(c.[ImporteBase2], 0)) AS ImporteBase2,
              SUM(ISNULL(c.[ImporteBase3], 0)) AS ImporteBase3,
              SUM(ISNULL(c.[ImporteBase4], 0)) AS ImporteBase4,
              SUM(ISNULL(c.[ImporteBase5], 0)) AS ImporteBase5,
              SUM(ISNULL(c.[ImporteBase6], 0)) AS ImporteBase6,
              SUM(ISNULL(c.[ImporteIva1], 0)) AS ImporteIva1,
              SUM(ISNULL(c.[ImporteIva2], 0)) AS ImporteIva2,
              SUM(ISNULL(c.[ImporteIva3], 0)) AS ImporteIva3,
              SUM(ISNULL(c.[ImporteIva4], 0)) AS ImporteIva4,
              SUM(ISNULL(c.[ImporteIva5], 0)) AS ImporteIva5,
              SUM(ISNULL(c.[ImporteIva6], 0)) AS ImporteIva6,
              MAX(ISNULL(c.[PjeIva1], 0)) AS PjeIva1,
              MAX(ISNULL(c.[PjeIva2], 0)) AS PjeIva2,
              MAX(ISNULL(c.[PjeIva3], 0)) AS PjeIva3,
              MAX(ISNULL(c.[PjeIva4], 0)) AS PjeIva4,
              MAX(ISNULL(c.[PjeIva5], 0)) AS PjeIva5,
              MAX(ISNULL(c.[PjeIva6], 0)) AS PjeIva6
            FROM [AlbaranesVentasCab] c
            WHERE " . implode(' AND ', $where) . '
            GROUP BY RTRIM(c.[Empresa]), RTRIM(c.[FacturaTipo]), c.[Factura]
            ORDER BY MIN(c.[Fecha]), c.[Factura]';

    return $this->ejecutarListado($sql, $params, $q);
  }

  /**
   * @param array<string, mixed> $params
   * @param array<string, mixed> $q
   * @return array<string, mixed>
   */
  private function ejecutarListado(string $sql, array $params, array $q): array
  {
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);

    $items = [];
    $totBase = 0.0;
    $totCuota = 0.0;
    $totImporte = 0.0;
    /** @var array<string, array{pjeIva: float, baseImponible: float, cuotaIva: float}> $resumenBuckets */
    $resumenBuckets = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $base = round((float) ($row['baseImponible'] ?? 0), 2);
      $cuota = round((float) ($row['cuotaIva'] ?? 0), 2);
      $imp = round((float) ($row['importeTotal'] ?? 0), 2);
      $totBase += $base;
      $totCuota += $cuota;
      $totImporte += $imp;

      $desglose = $this->desgloseDesdeFilas($row);
      $this->acumularDesgloseEnResumen($desglose, $resumenBuckets);

      $items[] = [
        'empresa' => (string) ($row['empresa'] ?? ''),
        'albaran' => 0,
        'fecha' => (string) ($row['fecha'] ?? ''),
        'numeroTicket' => $row['numeroTicket'] !== null ? (int) $row['numeroTicket'] : null,
        'facturaTipo' => (string) ($row['facturaTipo'] ?? ''),
        'cliente' => (string) ($row['cliente'] ?? ''),
        'razonSocial' => (string) ($row['razonSocial'] ?? ''),
        'trasCtb' => !empty($row['trasCtb']),
        'trasModem' => !empty($row['trasModem']),
        'desgloseIva' => $desglose,
        'baseImponible' => $base,
        'cuotaIva' => $cuota,
        'importeTotal' => $imp,
      ];
    }

    $truncado = count($items) > self::LIMITE_FILAS;
    if ($truncado) {
      $items = array_slice($items, 0, self::LIMITE_FILAS);
    }

    $resumenPorIva = [];
    foreach ($resumenBuckets as $b) {
      $resumenPorIva[] = [
        'pjeIva' => $b['pjeIva'],
        'baseImponible' => round($b['baseImponible'], 2),
        'cuotaIva' => round($b['cuotaIva'], 2),
      ];
    }
    usort($resumenPorIva, static fn (array $a, array $b) => $a['pjeIva'] <=> $b['pjeIva']);

    $formato = (string) ($q['formato'] ?? 'normal');
    if ($formato === 'cliente') {
      usort($items, static function (array $a, array $b): int {
        $c = strcasecmp((string) ($a['cliente'] ?? ''), (string) ($b['cliente'] ?? ''));
        if ($c !== 0) {
          return $c;
        }

        return strcmp((string) ($a['fecha'] ?? ''), (string) ($b['fecha'] ?? ''));
      });
    }

    return [
      'fechaDesde' => $q['fechaDesde'],
      'fechaHasta' => $q['fechaHasta'],
      'empresa' => (string) ($q['empresa'] ?? ''),
      'tipoDocumento' => $q['tipoDocumento'],
      'estado' => $q['estado'],
      'formato' => $formato,
      'divisa' => 'EU',
      'facturaDesde' => $q['facturaDesde'] > 0 ? $q['facturaDesde'] : null,
      'facturaHasta' => $q['facturaHasta'] > 0 ? $q['facturaHasta'] : null,
      'soloNumerados' => true,
      'items' => $items,
      'resumenPorIva' => $resumenPorIva,
      'totales' => [
        'tickets' => count($items),
        'baseImponible' => round($totBase, 2),
        'cuotaIva' => round($totCuota, 2),
        'importeTotal' => round($totImporte, 2),
      ],
      'truncado' => $truncado,
      'limite' => self::LIMITE_FILAS,
    ];
  }

  /**
   * @param array<string, mixed> $row
   * @return list<array{pjeIva: float, baseImponible: float, cuotaIva: float}>
   */
  private function desgloseDesdeFilas(array $row): array
  {
    /** @var array<string, array{pjeIva: float, baseImponible: float, cuotaIva: float}> $slots */
    $slots = [];
    for ($i = 1; $i <= 6; $i++) {
      $base = (float) ($row["ImporteBase{$i}"] ?? 0);
      $iva = (float) ($row["ImporteIva{$i}"] ?? 0);
      $pje = round((float) ($row["PjeIva{$i}"] ?? 0), 2);
      if ($base == 0.0 && $iva == 0.0 && $pje == 0.0) {
        continue;
      }
      $key = number_format($pje, 2, '.', '');
      if (!isset($slots[$key])) {
        $slots[$key] = ['pjeIva' => $pje, 'baseImponible' => 0.0, 'cuotaIva' => 0.0];
      }
      $slots[$key]['baseImponible'] += $base;
      $slots[$key]['cuotaIva'] += $iva;
    }
    $out = [];
    foreach ($slots as $s) {
      $out[] = [
        'pjeIva' => $s['pjeIva'],
        'baseImponible' => round($s['baseImponible'], 2),
        'cuotaIva' => round($s['cuotaIva'], 2),
      ];
    }
    usort($out, static fn (array $a, array $b) => $a['pjeIva'] <=> $b['pjeIva']);

    return $out;
  }

  /**
   * @param list<array{pjeIva: float, baseImponible: float, cuotaIva: float}> $desglose
   * @param array<string, array{pjeIva: float, baseImponible: float, cuotaIva: float}> $buckets
   */
  private function acumularDesgloseEnResumen(array $desglose, array &$buckets): void
  {
    foreach ($desglose as $d) {
      $key = number_format($d['pjeIva'], 2, '.', '');
      if (!isset($buckets[$key])) {
        $buckets[$key] = ['pjeIva' => $d['pjeIva'], 'baseImponible' => 0.0, 'cuotaIva' => 0.0];
      }
      $buckets[$key]['baseImponible'] += $d['baseImponible'];
      $buckets[$key]['cuotaIva'] += $d['cuotaIva'];
    }
  }

  private function fechaDia(mixed $value): ?string
  {
    if ($value === null || $value === '') {
      return null;
    }
    $s = trim((string) $value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
      return null;
    }

    return $s;
  }

  /**
   * @param array<string, mixed> $query
   * @return array{
   *   fechaDesde: string,
   *   fechaHasta: string,
   *   empresa: string,
   *   tipoDocumento: string,
   *   estado: string,
   *   formato: string,
   *   soloDivisaEu: bool,
   *   facturaDesde: int,
   *   facturaHasta: int,
   *   empresaDesde: string,
   *   empresaHasta: string,
   *   clienteDesde: string,
   *   clienteHasta: string,
   *   origenDesde: string,
   *   origenHasta: string,
   *   cierreSesionDesde: int,
   *   cierreSesionHasta: int,
   *   puestoDesde: string,
   *   puestoHasta: string,
   *   sesionDesde: int,
   *   sesionHasta: int
   * }
   */
  private function normalizarQuery(array $query): array
  {
    $fechaDesde = $this->fechaDia($query['fechaDesde'] ?? null);
    $fechaHasta = $this->fechaDia($query['fechaHasta'] ?? null);
    if ($fechaDesde === null || $fechaHasta === null) {
      throw new InvalidArgumentException('fechaDesde y fechaHasta son obligatorias (YYYY-MM-DD)');
    }
    if ($fechaDesde > $fechaHasta) {
      throw new InvalidArgumentException('La fecha desde no puede ser posterior a la fecha hasta');
    }

    $tipoDocumento = strtolower(trim((string) ($query['tipoDocumento'] ?? 'facturas')));
    if (!in_array($tipoDocumento, ['facturas', 'tickets'], true)) {
      $tipoDocumento = 'facturas';
    }

    $estado = strtolower(trim((string) ($query['estado'] ?? 'todos')));
    $formato = strtolower(trim((string) ($query['formato'] ?? 'normal')));
    if (!in_array($formato, ['normal', 'extendido', 'cliente', 'extendido_ctb'], true)) {
      $formato = 'normal';
    }

    $empresaUnica = ListadosFiltrosSql::normalizarEmpresaInput((string) ($query['empresa'] ?? ''));

    return [
      'fechaDesde' => $fechaDesde,
      'fechaHasta' => $fechaHasta,
      'empresa' => $empresaUnica,
      'tipoDocumento' => $tipoDocumento,
      'estado' => $estado !== '' ? $estado : 'todos',
      'formato' => $formato,
      'soloDivisaEu' => true,
      'facturaDesde' => (int) ($query['facturaDesde'] ?? 0),
      'facturaHasta' => (int) ($query['facturaHasta'] ?? 0),
      'empresaDesde' => trim((string) ($query['empresaDesde'] ?? '')),
      'empresaHasta' => trim((string) ($query['empresaHasta'] ?? '')),
      'clienteDesde' => trim((string) ($query['clienteDesde'] ?? '')),
      'clienteHasta' => trim((string) ($query['clienteHasta'] ?? '')),
      'origenDesde' => ListadosFiltrosSql::normalizarEmpresaInput((string) ($query['origenDesde'] ?? '')),
      'origenHasta' => ListadosFiltrosSql::normalizarEmpresaInput((string) ($query['origenHasta'] ?? '')),
      'cierreSesionDesde' => (int) ($query['cierreSesionDesde'] ?? 0),
      'cierreSesionHasta' => (int) ($query['cierreSesionHasta'] ?? 0),
      'puestoDesde' => trim((string) ($query['puestoDesde'] ?? '')),
      'puestoHasta' => trim((string) ($query['puestoHasta'] ?? '')),
      'sesionDesde' => (int) ($query['sesionDesde'] ?? 0),
      'sesionHasta' => (int) ($query['sesionHasta'] ?? 0),
    ];
  }

  /**
   * @param list<string> $where
   * @param array<string, mixed> $params
   * @param array<string, mixed> $q
   */
  private function aplicarRangosFactura(array &$where, array &$params, array $q, string $alias): void
  {
    ListadosFiltrosSql::filtroRangoTexto($where, $params, "{$alias}.[Empresa]", $q, 'empresaDesde', 'empresaHasta', 'empresa', 'iva_tienda');
    ListadosFiltrosSql::filtroRangoTexto($where, $params, "{$alias}.[Empresa]", $q, 'origenDesde', 'origenHasta', null, 'iva_origen');
    ListadosFiltrosSql::filtroRangoTexto($where, $params, "{$alias}.[Cliente]", $q, 'clienteDesde', 'clienteHasta');
    ListadosFiltrosSql::filtroRangoEntero($where, $params, "{$alias}.[Factura]", $q, 'facturaDesde', 'facturaHasta');
  }

  /**
   * Filtros TPV (puesto, sesión, cierre) vía albaranes ligados a la factura.
   *
   * @param list<string> $where
   * @param array<string, mixed> $params
   * @param array<string, mixed> $q
   */
  private function aplicarFiltrosCabeceraVentas(array &$where, array &$params, array $q, string $aliasFactura): void
  {
    $cab = [];
    ListadosFiltrosSql::filtroRangoTexto($cab, $params, 'c.[Puesto]', $q, 'puestoDesde', 'puestoHasta');
    ListadosFiltrosSql::filtroRangoEntero($cab, $params, 'c.[Sesion]', $q, 'sesionDesde', 'sesionHasta');
    $cierreDesde = (int) ($q['cierreSesionDesde'] ?? 0);
    $cierreHasta = (int) ($q['cierreSesionHasta'] ?? 0);
    if ($cierreDesde > 0 || $cierreHasta > 0) {
      if ($cierreDesde > 0 && $cierreHasta > 0 && $cierreDesde !== $cierreHasta) {
        $cab[] = 's.[Sesion] >= :cierreSes_d AND s.[Sesion] <= :cierreSes_h';
        $params['cierreSes_d'] = $cierreDesde;
        $params['cierreSes_h'] = $cierreHasta;
      } else {
        $v = $cierreDesde > 0 ? $cierreDesde : $cierreHasta;
        $cab[] = 's.[Sesion] = :cierreSes_eq';
        $params['cierreSes_eq'] = $v;
      }
      $cab[] = 'ISNULL(s.[Cerrada], 0) <> 0';
    }

    if ($cab === []) {
      return;
    }

    $joinSesion = ($cierreDesde > 0 || $cierreHasta > 0)
      ? ' INNER JOIN [Sesiones] s ON s.[Empresa] = c.[Empresa] AND s.[Puesto] = c.[Puesto] AND s.[Sesion] = c.[Sesion] '
      : '';

    $where[] = 'EXISTS (SELECT 1 FROM [AlbaranesVentasCab] c' . $joinSesion
      . ' WHERE c.[Empresa] = ' . $aliasFactura . '.[Empresa]'
      . ' AND c.[FacturaTipo] = ' . $aliasFactura . '.[FacturaTipo]'
      . ' AND c.[Factura] = ' . $aliasFactura . '.[Factura]'
      . ' AND ISNULL(c.[Anulado], 0) = 0'
      . ' AND ' . implode(' AND ', $cab) . ')';
  }

  private function etiquetaEstado(string $estado): string
  {
    return match (strtolower(trim($estado))) {
      'contabilizados' => 'Contabilizados',
      'no_contabilizados' => 'No contabilizados',
      'menos' => '-',
      'mas' => '+',
      'no_enviadas_lrod' => 'No enviadas LROD',
      default => 'Todos',
    };
  }

  private function fmtPje(float $p): string
  {
    $s = number_format($p, 2, '.', '');
    if (str_ends_with($s, '.00')) {
      return (string) (int) $p;
    }

    return rtrim(rtrim($s, '0'), '.') . '%';
  }

  /**
   * @param list<array{pjeIva: float, baseImponible: float, cuotaIva: float}> $desglose
   * @return array{pjeIva: float, baseImponible: float, cuotaIva: float}|null
   */
  private function buscarDesglose(array $desglose, float $pje): ?array
  {
    $key = number_format($pje, 2, '.', '');
    foreach ($desglose as $d) {
      if (number_format((float) $d['pjeIva'], 2, '.', '') === $key) {
        return $d;
      }
    }

    return null;
  }
}
