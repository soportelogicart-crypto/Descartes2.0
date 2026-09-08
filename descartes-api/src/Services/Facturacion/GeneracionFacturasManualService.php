<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Facturacion;

use PDO;

/**
 * Generador de facturas MANUAL (legacy FrmGeneracionFacturasManual + GeneracionFacturas → CreaFactura).
 *
 * MVP: listar albaranes de crédito pendientes + generar facturas agrupadas desde selección.
 */
final class GeneracionFacturasManualService
{
  private PDO $pdo;
  private RecibosFacturaService $recibos;

  public function __construct(PDO $pdo, RecibosFacturaService $recibos)
  {
    $this->pdo = $pdo;
    $this->recibos = $recibos;
  }

  /**
   * @param array<string, mixed> $query
   * @return array{items: list<array<string, mixed>>, totales: array{albaranes: int, importe: float}}
   */
  public function listarPendientes(array $query): array
  {
    // Sin filtro de FacturacionManual salvo que la UI lo pida (todos | normales | manuales).
    if (!isset($query['tipoCliente']) || trim((string) $query['tipoCliente']) === '') {
      $query['tipoCliente'] = 'todos';
    }

    [$where, $params] = $this->buildPendientesWhere($query);

    $sql = "SELECT TOP 2000
        a.Empresa, a.Tipo, a.Albaran, a.Fecha, a.Puesto, a.Cliente, a.RazonSocial, a.NIF,
        a.Importe, a.PagoaCuenta AS PagoACuenta, a.SujetoPasivo, a.PjeDto,
        ISNULL(a.PreFactura, 0) AS PreFactura,
        ISNULL(c.FacturacionDesglosada, 0) AS FacturacionDesglosada,
        (CASE WHEN ISNULL(c.EmpresaFacturacion, '') = '' THEN a.Cliente ELSE c.EmpresaFacturacion END) AS ClienteFacturacion
      FROM AlbaranesVentasCab a
      INNER JOIN Clientes c ON c.Codigo = a.Cliente
      WHERE {$where}
      ORDER BY a.Fecha, a.Empresa, a.Albaran";

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $items = [];
    $importe = 0.0;
    foreach ($rows as $r) {
      $imp = (float) ($r['Importe'] ?? 0);
      $importe += $imp;
      $items[] = [
        'empresa' => trim((string) ($r['Empresa'] ?? '')),
        'tipo' => trim((string) ($r['Tipo'] ?? '')),
        'albaran' => (int) ($r['Albaran'] ?? 0),
        'fecha' => $this->fmtFecha($r['Fecha'] ?? null),
        'puesto' => trim((string) ($r['Puesto'] ?? '')),
        'cliente' => trim((string) ($r['Cliente'] ?? '')),
        'clienteFacturacion' => trim((string) ($r['ClienteFacturacion'] ?? '')),
        'razonSocial' => trim((string) ($r['RazonSocial'] ?? '')),
        'nif' => trim((string) ($r['NIF'] ?? '')),
        'importe' => $imp,
        'pagoACuenta' => (float) ($r['PagoACuenta'] ?? 0),
        'sujetoPasivo' => !empty($r['SujetoPasivo']),
        'prefactura' => !empty($r['PreFactura']) && (int) $r['PreFactura'] !== 0,
      ];
    }

    return [
      'items' => $items,
      'totales' => [
        'albaranes' => count($items),
        'importe' => round($importe, 2),
      ],
    ];
  }

  /**
   * @param array<string, mixed> $body
   * @return array{facturas: list<array<string, mixed>>, totales: array{facturas: int, albaranes: int, importe: float}}
   */
  public function generar(array $body): array
  {
    $fechaFacturacion = trim((string) ($body['fechaFacturacion'] ?? ''));
    if ($fechaFacturacion === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaFacturacion)) {
      throw new \InvalidArgumentException('fechaFacturacion obligatoria (YYYY-MM-DD)');
    }

    $empresaFacturacion = trim((string) ($body['empresa'] ?? ''));
    if ($empresaFacturacion === '') {
      throw new \InvalidArgumentException('empresa (tienda facturación) obligatoria');
    }

    $agrupacion = strtolower(trim((string) ($body['agrupacion'] ?? 'separar')));
    if (!in_array($agrupacion, ['separar', 'agrupar'], true)) {
      $agrupacion = 'separar';
    }

    $formaPagoForzada = trim((string) ($body['formaPago'] ?? ''));
    $numFactura = (int) ($body['numFactura'] ?? 0);
    $esPrefactura = $this->esPrefacturaBody($body);
    if ($esPrefactura && $numFactura > 0) {
      throw new \InvalidArgumentException('No se puede forzar nº de factura en pre-facturas');
    }

    $sel = $body['albaranes'] ?? null;
    if (!is_array($sel) || $sel === []) {
      throw new \InvalidArgumentException('Debe seleccionar al menos un albarán');
    }

    $keys = [];
    foreach ($sel as $row) {
      if (!is_array($row)) {
        continue;
      }
      $e = trim((string) ($row['empresa'] ?? ''));
      $t = trim((string) ($row['tipo'] ?? 'A'));
      $a = (int) ($row['albaran'] ?? 0);
      if ($e === '' || $a <= 0) {
        continue;
      }
      $keys[$e . '|' . $t . '|' . $a] = ['empresa' => $e, 'tipo' => $t, 'albaran' => $a];
    }
    if ($keys === []) {
      throw new \InvalidArgumentException('Selección de albaranes inválida');
    }

    $albaranes = $this->cargarAlbaranesParaGenerar(array_values($keys));
    if ($albaranes === []) {
      throw new \RuntimeException('Ningún albarán pendiente coincide con la selección', 404);
    }

    $veces = $agrupacion === 'separar' ? 2 : 1;
    $creadas = [];
    $totalImporte = 0.0;
    $totalAlb = 0;
    $facturaManualRestante = $numFactura > 0 ? $numFactura : 0;

    $this->pdo->beginTransaction();
    try {
      for ($h = 1; $h <= $veces; $h++) {
        $grupos = $this->agruparAlbaranes($albaranes, $veces, $h);
        foreach ($grupos as $grupo) {
          $mFactura = $facturaManualRestante;
          $creada = $this->crearFacturaGrupo(
            $empresaFacturacion,
            $fechaFacturacion,
            $grupo,
            $formaPagoForzada,
            $mFactura,
            $esPrefactura
          );
          if ($facturaManualRestante > 0) {
            $facturaManualRestante = 0;
          }
          $creadas[] = $creada;
          $totalImporte += (float) $creada['importe'];
          $totalAlb += count($creada['albaranes']);
        }
      }
      $this->pdo->commit();
    } catch (\Throwable $e) {
      if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      throw $e;
    }

    return [
      'facturas' => $creadas,
      'tipoFacturacion' => $esPrefactura ? 'prefacturas' : 'facturas',
      'totales' => [
        'facturas' => count($creadas),
        'albaranes' => $totalAlb,
        'importe' => round($totalImporte, 2),
      ],
    ];
  }

  /**
   * Vista previa de generación automática (legacy GeneracionFacturas / frmListado).
   *
   * @param array<string, mixed> $query
   * @return array{
   *   totales: array{albaranes: int, importe: float, gruposEstimados: int},
   *   omitidosImporteMinimo: int,
   *   grupos: list<array<string, mixed>>
   * }
   */
  public function previewAutomatico(array $query): array
  {
    $empresaFacturacion = trim((string) ($query['empresa'] ?? ''));
    $filtros = $query;
    unset($filtros['empresa']);
    if ($empresaFacturacion !== '' && trim((string) ($filtros['empresaDesde'] ?? '')) === '') {
      $filtros['empresaDesde'] = $empresaFacturacion;
    }
    if (trim((string) ($filtros['empresaHasta'] ?? '')) === ''
      && trim((string) ($filtros['empresaDesde'] ?? '')) !== ''
    ) {
      $filtros['empresaHasta'] = $filtros['empresaDesde'];
    }
    $fechaFact = trim((string) ($query['fechaFacturacion'] ?? ''));
    if (trim((string) ($filtros['fechaHasta'] ?? '')) === '' && $fechaFact !== '') {
      $filtros['fechaHasta'] = $fechaFact;
    }

    $albaranes = $this->cargarAlbaranesPorFiltros($filtros);
    $agrupacion = strtolower(trim((string) ($query['agrupacion'] ?? 'separar')));
    $veces = $agrupacion === 'agrupar' ? 1 : 2;
    $importeMinimo = (float) ($query['importeMinimo'] ?? 0);

    $importe = 0.0;
    $albaranesOk = 0;
    $omitidos = 0;
    $gruposOut = [];
    for ($h = 1; $h <= $veces; $h++) {
      foreach ($this->agruparAlbaranes($albaranes, $veces, $h) as $grupo) {
        $sum = 0.0;
        foreach ($grupo as $alb) {
          $sum += (float) ($alb['Importe'] ?? 0);
        }
        if ($importeMinimo != 0.0 && $sum < $importeMinimo) {
          $omitidos++;
          continue;
        }
        $gruposOut[] = $this->resumenGrupo($grupo, $sum, count($gruposOut) + 1);
        $albaranesOk += count($grupo);
        $importe += $sum;
      }
    }

    return [
      'totales' => [
        'albaranes' => $albaranesOk,
        'importe' => round($importe, 2),
        'gruposEstimados' => count($gruposOut),
      ],
      'omitidosImporteMinimo' => $omitidos,
      'grupos' => $gruposOut,
    ];
  }

  /**
   * @param list<array<string, mixed>> $grupo
   * @return array<string, mixed>
   */
  private function resumenGrupo(array $grupo, float $importe, int $indice): array
  {
    $primero = $grupo[0] ?? [];
    $albaranes = [];
    foreach ($grupo as $alb) {
      $albaranes[] = [
        'empresa' => trim((string) ($alb['Empresa'] ?? '')),
        'tipo' => trim((string) ($alb['Tipo'] ?? '')),
        'albaran' => (int) ($alb['Albaran'] ?? 0),
        'fecha' => $this->fechaIso($alb['Fecha'] ?? null),
        'importe' => round((float) ($alb['Importe'] ?? 0), 2),
      ];
    }

    return [
      'indice' => $indice,
      'cliente' => trim((string) ($primero['ClienteFacturacion'] ?? $primero['Cliente'] ?? '')),
      'razonSocial' => trim((string) ($primero['RazonSocial'] ?? '')),
      'sujetoPasivo' => !empty($primero['SujetoPasivo']),
      'importe' => round($importe, 2),
      'albaranes' => $albaranes,
    ];
  }

  private function fechaIso(mixed $valor): string
  {
    if ($valor === null || $valor === '') {
      return '';
    }
    $ts = strtotime((string) $valor);
    return $ts === false ? '' : date('Y-m-d', $ts);
  }

  /**
   * Generación automática por filtros (sin selección manual de filas).
   *
   * @param array<string, mixed> $body
   * @return array{facturas: list<array<string, mixed>>, totales: array{facturas: int, albaranes: int, importe: float}, omitidosImporteMinimo: int}
   */
  public function generarAutomatico(array $body): array
  {
    $fechaFacturacion = trim((string) ($body['fechaFacturacion'] ?? ''));
    if ($fechaFacturacion === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaFacturacion)) {
      throw new \InvalidArgumentException('fechaFacturacion obligatoria (YYYY-MM-DD)');
    }

    $empresaFacturacion = trim((string) ($body['empresa'] ?? ''));
    if ($empresaFacturacion === '') {
      throw new \InvalidArgumentException('empresa (tienda facturación) obligatoria');
    }

    $agrupacion = strtolower(trim((string) ($body['agrupacion'] ?? 'separar')));
    if (!in_array($agrupacion, ['separar', 'agrupar'], true)) {
      $agrupacion = 'separar';
    }

    $importeMinimo = (float) ($body['importeMinimo'] ?? 0);
    $esPrefactura = $this->esPrefacturaBody($body);
    $filtros = $body;
    // No usar empresa como igualdad exacta de albarán; rangos empresaDesde/Hasta.
    unset($filtros['empresa']);
    if (trim((string) ($filtros['empresaDesde'] ?? '')) === '') {
      $filtros['empresaDesde'] = $empresaFacturacion;
    }
    if (trim((string) ($filtros['empresaHasta'] ?? '')) === '') {
      $filtros['empresaHasta'] = $filtros['empresaDesde'];
    }
    if (trim((string) ($filtros['fechaHasta'] ?? '')) === '' && $fechaFacturacion !== '') {
      $filtros['fechaHasta'] = $fechaFacturacion;
    }

    $albaranes = $this->cargarAlbaranesPorFiltros($filtros);
    if ($albaranes === []) {
      throw new \RuntimeException('No hay albaranes pendientes con esos filtros', 404);
    }

    $veces = $agrupacion === 'separar' ? 2 : 1;
    $creadas = [];
    $totalImporte = 0.0;
    $totalAlb = 0;
    $omitidos = 0;

    $this->pdo->beginTransaction();
    try {
      for ($h = 1; $h <= $veces; $h++) {
        foreach ($this->agruparAlbaranes($albaranes, $veces, $h) as $grupo) {
          $sum = 0.0;
          foreach ($grupo as $alb) {
            $sum += (float) ($alb['Importe'] ?? 0);
          }
          if ($importeMinimo != 0.0 && $sum < $importeMinimo) {
            $omitidos++;
            continue;
          }
          $creada = $this->crearFacturaGrupo(
            $empresaFacturacion,
            $fechaFacturacion,
            $grupo,
            '',
            0,
            $esPrefactura
          );
          $creadas[] = $creada;
          $totalImporte += (float) $creada['importe'];
          $totalAlb += count($creada['albaranes']);
        }
      }
      $this->pdo->commit();
    } catch (\Throwable $e) {
      if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      throw $e;
    }

    if ($creadas === []) {
      throw new \RuntimeException(
        $omitidos > 0
          ? 'Ningún grupo supera el importe mínimo'
          : 'No se generó ninguna factura',
        404
      );
    }

    return [
      'facturas' => $creadas,
      'tipoFacturacion' => $esPrefactura ? 'prefacturas' : 'facturas',
      'totales' => [
        'facturas' => count($creadas),
        'albaranes' => $totalAlb,
        'importe' => round($totalImporte, 2),
      ],
      'omitidosImporteMinimo' => $omitidos,
    ];
  }

  /**
   * @param array<string, mixed> $query
   * @return list<array<string, mixed>>
   */
  private function cargarAlbaranesPorFiltros(array $query): array
  {
    [$where, $params] = $this->buildPendientesWhere($query);

    $sql = "SELECT TOP 5000
        a.Empresa, a.Tipo, a.Albaran, a.Fecha, a.Puesto, a.Cliente, a.RazonSocial, a.NIF,
        a.Importe, a.ImporteDtos, a.PjeDto, a.PagoaCuenta AS PagoACuenta, a.SujetoPasivo,
        a.Factura, a.FacturaTipo, a.Estado,
        a.ImporteBase1, a.ImporteBase2, a.ImporteBase3, a.ImporteBase4, a.ImporteBase5, a.ImporteBase6,
        a.PjeIva1, a.PjeIva2, a.PjeIva3, a.PjeIva4, a.PjeIva5, a.PjeIva6,
        a.ImporteIva1, a.ImporteIva2, a.ImporteIva3, a.ImporteIva4, a.ImporteIva5, a.ImporteIva6,
        a.PjeRec1, a.PjeRec2, a.PjeRec3, a.PjeRec4, a.PjeRec5, a.PjeRec6,
        a.ImporteRec1, a.ImporteRec2, a.ImporteRec3, a.ImporteRec4, a.ImporteRec5, a.ImporteRec6,
        (CASE WHEN ISNULL(c.EmpresaFacturacion, '') = '' THEN a.Cliente ELSE c.EmpresaFacturacion END) AS ClienteFacturacion,
        ISNULL(c.FacturacionDesglosada, 0) AS FacturacionDesglosada,
        c.FormaPago AS FormaPagoCliente, c.PreFacturacion
      FROM AlbaranesVentasCab a
      INNER JOIN Clientes c ON c.Codigo = a.Cliente
      WHERE {$where}
      ORDER BY
        (CASE WHEN ISNULL(c.EmpresaFacturacion, '') = '' THEN a.Cliente ELSE c.EmpresaFacturacion END),
        a.SujetoPasivo, a.PjeDto, a.Albaran";

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }

  /**
   * @param array<string, mixed> $query
   * @return array{0: string, 1: array<string, mixed>}
   */
  private function buildPendientesWhere(array $query): array
  {
    $where = [
      "a.Cliente <> 'ZZZZZZZZZ'",
      '(a.Estado IS NULL OR a.Estado <> \'B\')',
      'ISNULL(a.Factura, 0) = 0',
      "(a.FacturaTipo IS NULL OR LTRIM(RTRIM(a.FacturaTipo)) = ''
        OR (a.FacturaTipo = 'Z' AND ISNULL(a.FacturaRetroceso, 0) > 0))",
    ];
    $params = [];

    $empresa = trim((string) ($query['empresa'] ?? ''));
    if ($empresa !== '') {
      $where[] = 'a.Empresa = :empresa';
      $params['empresa'] = $empresa;
    }

    $fechaDesde = trim((string) ($query['fechaDesde'] ?? ''));
    if ($fechaDesde !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaDesde)) {
      $where[] = 'a.Fecha >= CONVERT(datetime, :fechaDesde, 120)';
      $params['fechaDesde'] = $fechaDesde . ' 00:00:00';
    }
    $fechaHasta = trim((string) ($query['fechaHasta'] ?? ''));
    if ($fechaHasta !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaHasta)) {
      $where[] = 'a.Fecha <= CONVERT(datetime, :fechaHasta, 120)';
      $params['fechaHasta'] = $fechaHasta . ' 23:59:59';
    }

    $clienteDesde = trim((string) ($query['clienteDesde'] ?? ''));
    if ($clienteDesde !== '') {
      $where[] = 'a.Cliente >= :clienteDesde';
      $params['clienteDesde'] = $clienteDesde;
    }
    $clienteHasta = trim((string) ($query['clienteHasta'] ?? ''));
    if ($clienteHasta !== '') {
      $where[] = 'a.Cliente <= :clienteHasta';
      $params['clienteHasta'] = $clienteHasta;
    }

    $cliente = trim((string) ($query['cliente'] ?? ''));
    if ($cliente !== '') {
      $where[] = 'a.Cliente = :cliente';
      $params['cliente'] = $cliente;
    }

    $albaranDesde = (int) ($query['albaranDesde'] ?? 0);
    if ($albaranDesde > 0) {
      $where[] = 'a.Albaran >= :albaranDesde';
      $params['albaranDesde'] = $albaranDesde;
    }
    $albaranHasta = (int) ($query['albaranHasta'] ?? 0);
    if ($albaranHasta > 0) {
      $where[] = 'a.Albaran <= :albaranHasta';
      $params['albaranHasta'] = $albaranHasta;
    }

    $empresaDesde = trim((string) ($query['empresaDesde'] ?? ''));
    if ($empresaDesde !== '') {
      $where[] = 'a.Empresa >= :empresaDesde';
      $params['empresaDesde'] = $empresaDesde;
    }
    $empresaHasta = trim((string) ($query['empresaHasta'] ?? ''));
    if ($empresaHasta !== '') {
      $where[] = 'a.Empresa <= :empresaHasta';
      $params['empresaHasta'] = $empresaHasta;
    }

    $puestoDesde = trim((string) ($query['puestoDesde'] ?? ''));
    if ($puestoDesde !== '') {
      $where[] = 'a.Puesto >= :puestoDesde';
      $params['puestoDesde'] = $puestoDesde;
    }
    $puestoHasta = trim((string) ($query['puestoHasta'] ?? ''));
    if ($puestoHasta !== '') {
      $where[] = 'a.Puesto <= :puestoHasta';
      $params['puestoHasta'] = $puestoHasta;
    }

    $vendedorDesde = trim((string) ($query['vendedorDesde'] ?? ''));
    if ($vendedorDesde !== '') {
      $where[] = 'a.Vendedor >= :vendedorDesde';
      $params['vendedorDesde'] = $vendedorDesde;
    }
    $vendedorHasta = trim((string) ($query['vendedorHasta'] ?? ''));
    if ($vendedorHasta !== '') {
      $where[] = 'a.Vendedor <= :vendedorHasta';
      $params['vendedorHasta'] = $vendedorHasta;
    }

    $fpagoDesde = trim((string) ($query['fpagoDesde'] ?? ''));
    if ($fpagoDesde !== '') {
      $where[] = 'a.Fpago1 >= :fpagoDesde';
      $params['fpagoDesde'] = $fpagoDesde;
    }
    $fpagoHasta = trim((string) ($query['fpagoHasta'] ?? ''));
    if ($fpagoHasta !== '') {
      $where[] = 'a.Fpago1 <= :fpagoHasta';
      $params['fpagoHasta'] = $fpagoHasta;
    }

    $tipoCliente = strtolower(trim((string) ($query['tipoCliente'] ?? 'normales')));
    if ($tipoCliente === 'normales') {
      $where[] = 'ISNULL(c.FacturacionManual, 0) = 0';
    } elseif ($tipoCliente === 'manuales') {
      $where[] = 'ISNULL(c.FacturacionManual, 0) <> 0';
    }
    // 'todos' → sin filtro

    // Prefactura: Todos / Con Prefactura / Sin Prefactura (legacy Combo Selección).
    $seleccion = strtolower(trim((string) ($query['seleccion'] ?? 'todos')));
    if ($seleccion === 'con_prefactura' || $seleccion === 'prefacturados') {
      $where[] = 'ISNULL(a.PreFactura, 0) <> 0';
    } elseif ($seleccion === 'sin_prefactura' || $seleccion === 'sin_prefacturar') {
      $where[] = 'ISNULL(a.PreFactura, 0) = 0';
    }

    return [implode(' AND ', $where), $params];
  }

  /**
   * @param list<array{empresa: string, tipo: string, albaran: int}> $keys
   * @return list<array<string, mixed>>
   */
  private function cargarAlbaranesParaGenerar(array $keys): array
  {
    $out = [];
    $sql = "SELECT
        a.Empresa, a.Tipo, a.Albaran, a.Fecha, a.Puesto, a.Cliente, a.RazonSocial, a.NIF,
        a.Importe, a.ImporteDtos, a.PjeDto, a.PagoaCuenta AS PagoACuenta, a.SujetoPasivo,
        a.Factura, a.FacturaTipo, a.Estado,
        a.ImporteBase1, a.ImporteBase2, a.ImporteBase3, a.ImporteBase4, a.ImporteBase5, a.ImporteBase6,
        a.PjeIva1, a.PjeIva2, a.PjeIva3, a.PjeIva4, a.PjeIva5, a.PjeIva6,
        a.ImporteIva1, a.ImporteIva2, a.ImporteIva3, a.ImporteIva4, a.ImporteIva5, a.ImporteIva6,
        a.PjeRec1, a.PjeRec2, a.PjeRec3, a.PjeRec4, a.PjeRec5, a.PjeRec6,
        a.ImporteRec1, a.ImporteRec2, a.ImporteRec3, a.ImporteRec4, a.ImporteRec5, a.ImporteRec6,
        (CASE WHEN ISNULL(c.EmpresaFacturacion, '') = '' THEN a.Cliente ELSE c.EmpresaFacturacion END) AS ClienteFacturacion,
        ISNULL(c.FacturacionDesglosada, 0) AS FacturacionDesglosada,
        c.FormaPago AS FormaPagoCliente, c.PreFacturacion
      FROM AlbaranesVentasCab a
      INNER JOIN Clientes c ON c.Codigo = a.Cliente
      WHERE a.Empresa = :e AND a.Tipo = :t AND a.Albaran = :a
        AND ISNULL(a.Factura, 0) = 0
        AND (a.Estado IS NULL OR a.Estado <> 'B')
        AND (a.FacturaTipo IS NULL OR LTRIM(RTRIM(a.FacturaTipo)) = ''
          OR (a.FacturaTipo = 'Z' AND ISNULL(a.FacturaRetroceso, 0) > 0))";

    $stmt = $this->pdo->prepare($sql);
    foreach ($keys as $k) {
      $stmt->execute(['e' => $k['empresa'], 't' => $k['tipo'], 'a' => $k['albaran']]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      if ($row !== false) {
        $out[] = $row;
      }
    }

    usort($out, static function (array $a, array $b): int {
      $c = strcmp((string) $a['ClienteFacturacion'], (string) $b['ClienteFacturacion']);
      if ($c !== 0) {
        return $c;
      }
      $c = ((int) !empty($a['SujetoPasivo'])) <=> ((int) !empty($b['SujetoPasivo']));
      if ($c !== 0) {
        return $c;
      }
      $c = ((float) ($a['PjeDto'] ?? 0)) <=> ((float) ($b['PjeDto'] ?? 0));
      if ($c !== 0) {
        return $c;
      }
      return ((int) $a['Albaran']) <=> ((int) $b['Albaran']);
    });

    return $out;
  }

  /**
   * @param list<array<string, mixed>> $albaranes
   * @return list<list<array<string, mixed>>>
   */
  private function agruparAlbaranes(array $albaranes, int $veces, int $pasada): array
  {
    $filtrados = [];
    foreach ($albaranes as $alb) {
      $imp = (float) ($alb['Importe'] ?? 0);
      if ($veces === 1 || ($pasada === 1 && $imp < 0) || ($pasada === 2 && $imp >= 0)) {
        $filtrados[] = $alb;
      }
    }

    $grupos = [];
    $actual = [];
    $antCliente = null;
    $antSujeto = null;
    $antPjeDto = null;

    foreach ($filtrados as $alb) {
      $cliente = (string) ($alb['ClienteFacturacion'] ?? $alb['Cliente'] ?? '');
      $sujeto = !empty($alb['SujetoPasivo']);
      $pjeDto = (float) ($alb['PjeDto'] ?? 0);
      $desglosada = !empty($alb['FacturacionDesglosada']);

      $rompe = $actual !== [] && (
        $cliente !== $antCliente
        || $sujeto !== $antSujeto
        || abs($pjeDto - (float) $antPjeDto) > 0.0001
        || $desglosada
      );

      if ($rompe) {
        $grupos[] = $actual;
        $actual = [];
      }

      $actual[] = $alb;
      $antCliente = $cliente;
      $antSujeto = $sujeto;
      $antPjeDto = $pjeDto;

      if ($desglosada) {
        $grupos[] = $actual;
        $actual = [];
        $antCliente = null;
      }
    }

    if ($actual !== []) {
      $grupos[] = $actual;
    }

    return $grupos;
  }

  /**
   * @param list<array<string, mixed>> $grupo
   * @return array<string, mixed>
   */
  private function crearFacturaGrupo(
    string $empresaFacturacion,
    string $fecha,
    array $grupo,
    string $formaPagoForzada,
    int $numFacturaManual,
    bool $esPrefactura = false
  ): array {
    $primero = $grupo[0];
    $cliente = trim((string) ($primero['ClienteFacturacion'] ?? $primero['Cliente'] ?? ''));
    if ($cliente === '') {
      throw new \InvalidArgumentException('Cliente obligatorio para facturar');
    }

    $sujetoPasivo = !empty($primero['SujetoPasivo']) ? 1 : 0;
    $pjeDto = (float) ($primero['PjeDto'] ?? 0);

    $slots = array_fill(1, 6, ['base' => 0.0, 'pjeIva' => 0.0, 'impIva' => 0.0, 'pjeRec' => 0.0, 'impRec' => 0.0]);
    $importeDtos = 0.0;
    $importe = 0.0;
    $pagoACuenta = 0.0;
    $refs = [];

    foreach ($grupo as $alb) {
      $importeDtos += (float) ($alb['ImporteDtos'] ?? 0);
      $importe += (float) ($alb['Importe'] ?? 0);
      $pagoACuenta += (float) ($alb['PagoACuenta'] ?? 0);
      $refs[] = [
        'empresa' => trim((string) $alb['Empresa']),
        'tipo' => trim((string) $alb['Tipo']),
        'albaran' => (int) $alb['Albaran'],
        'fecha' => $this->fechaIso($alb['Fecha'] ?? null),
        'importe' => round((float) ($alb['Importe'] ?? 0), 2),
      ];
      for ($i = 1; $i <= 6; $i++) {
        $base = (float) ($alb["ImporteBase{$i}"] ?? 0);
        if (abs($base) < 0.0000001) {
          continue;
        }
        $this->acumularSlot(
          $slots,
          $base,
          (float) ($alb["PjeIva{$i}"] ?? 0),
          (float) ($alb["ImporteIva{$i}"] ?? 0),
          (float) ($alb["PjeRec{$i}"] ?? 0),
          (float) ($alb["ImporteRec{$i}"] ?? 0)
        );
      }
    }

    $fpagoCodigo = $formaPagoForzada;
    if ($fpagoCodigo === '') {
      $fpagoCodigo = trim((string) ($primero['FormaPagoCliente'] ?? ''));
    }

    // Generación albaranes→factura (crédito): Estado G = diferida.
    // NO confundir con "Factura contado diferida" (FacturaContadoDiferida=1 + UltFacturaDiferida),
    // que solo aplica al tipificar contado diferido en TPV/venta (Agrupacion=3 y CobroDeArqueo).
    // Legacy CreaFactura desde GeneracionFacturas: Estado G, flag 0, contador UltFactura.
    $estado = 'G';
    $facturaContadoDiferida = false;

    $facturaTipo = ($importe < 0 && $this->empresaFacturasRectificativas($empresaFacturacion)) ? 'A' : 'F';
    $tabla = $esPrefactura ? 'PreFacturas' : 'Facturas';

    if ($esPrefactura) {
      if ($numFacturaManual > 0) {
        throw new \InvalidArgumentException('No se puede forzar nº en pre-facturas');
      }
      $factura = $this->nextNumeroFactura($empresaFacturacion, 'UltPreFactura', false);
    } elseif ($numFacturaManual > 0) {
      $factura = $numFacturaManual;
    } else {
      $factura = $this->nextNumeroFactura(
        $empresaFacturacion,
        $facturaTipo === 'A' ? 'UltAbono' : 'UltFactura',
        false
      );
    }

    $existe = $this->pdo->prepare(
      "SELECT 1 FROM {$tabla} WHERE Empresa = :e AND FacturaTipo = :ft AND Factura = :f"
    );
    $existe->execute(['e' => $empresaFacturacion, 'ft' => $facturaTipo, 'f' => $factura]);
    if ($existe->fetchColumn() !== false) {
      $etiqueta = $esPrefactura ? 'Pre-factura' : 'Factura';
      throw new \RuntimeException("{$etiqueta} {$facturaTipo}/{$factura} ya existe", 409);
    }

    $sql = "INSERT INTO {$tabla} (
        Empresa, FacturaTipo, Factura, Cliente, Fecha,
        ImporteBase1, ImporteBase2, ImporteBase3, ImporteBase4, ImporteBase5, ImporteBase6,
        PjeIva1, PjeIva2, PjeIva3, PjeIva4, PjeIva5, PjeIva6,
        ImporteIva1, ImporteIva2, ImporteIva3, ImporteIva4, ImporteIva5, ImporteIva6,
        PjeRec1, PjeRec2, PjeRec3, PjeRec4, PjeRec5, PjeRec6,
        ImporteRec1, ImporteRec2, ImporteRec3, ImporteRec4, ImporteRec5, ImporteRec6,
        PjeDto, ImporteDtos, Importe, Fpago, Estado,
        TrasCtb, TrasModem, Impresa, ImporteLiquidado, FacturaContadoDiferida,
        PjeRetIrpf, BasRetIrpf, ImpRetIrpf, PagoACuenta, CobroEnTienda, SujetoPasivo,
        TrasformacionTicketFactura, AlbaranTicketTransformado
      ) VALUES (
        :empresa, :facturaTipo, :factura, :cliente, CONVERT(datetime, :fecha, 120),
        :b1, :b2, :b3, :b4, :b5, :b6,
        :pi1, :pi2, :pi3, :pi4, :pi5, :pi6,
        :ii1, :ii2, :ii3, :ii4, :ii5, :ii6,
        :pr1, :pr2, :pr3, :pr4, :pr5, :pr6,
        :ir1, :ir2, :ir3, :ir4, :ir5, :ir6,
        :pjeDto, :importeDtos, :importe, :fpago, :estado,
        0, 0, 0, :importeLiquidado, :contadoDiferida,
        0, 0, 0, :pagoACuenta, 0, :sujetoPasivo,
        0, 0
      )";

    $this->pdo->prepare($sql)->execute([
      'empresa' => $empresaFacturacion,
      'facturaTipo' => $facturaTipo,
      'factura' => $factura,
      'cliente' => $cliente,
      'fecha' => $fecha . ' 00:00:00',
      'b1' => $slots[1]['base'],
      'b2' => $slots[2]['base'],
      'b3' => $slots[3]['base'],
      'b4' => $slots[4]['base'],
      'b5' => $slots[5]['base'],
      'b6' => $slots[6]['base'],
      'pi1' => $slots[1]['pjeIva'],
      'pi2' => $slots[2]['pjeIva'],
      'pi3' => $slots[3]['pjeIva'],
      'pi4' => $slots[4]['pjeIva'],
      'pi5' => $slots[5]['pjeIva'],
      'pi6' => $slots[6]['pjeIva'],
      'ii1' => $slots[1]['impIva'],
      'ii2' => $slots[2]['impIva'],
      'ii3' => $slots[3]['impIva'],
      'ii4' => $slots[4]['impIva'],
      'ii5' => $slots[5]['impIva'],
      'ii6' => $slots[6]['impIva'],
      'pr1' => $slots[1]['pjeRec'],
      'pr2' => $slots[2]['pjeRec'],
      'pr3' => $slots[3]['pjeRec'],
      'pr4' => $slots[4]['pjeRec'],
      'pr5' => $slots[5]['pjeRec'],
      'pr6' => $slots[6]['pjeRec'],
      'ir1' => $slots[1]['impRec'],
      'ir2' => $slots[2]['impRec'],
      'ir3' => $slots[3]['impRec'],
      'ir4' => $slots[4]['impRec'],
      'ir5' => $slots[5]['impRec'],
      'ir6' => $slots[6]['impRec'],
      'pjeDto' => $pjeDto,
      'importeDtos' => round($importeDtos, 2),
      'importe' => round($importe, 2),
      'fpago' => $fpagoCodigo !== '' ? $fpagoCodigo : null,
      'estado' => $estado,
      'importeLiquidado' => 0.0,
      'contadoDiferida' => $facturaContadoDiferida ? 1 : 0,
      'pagoACuenta' => round($pagoACuenta, 2),
      'sujetoPasivo' => $sujetoPasivo,
    ]);

    $recibos = $esPrefactura
      ? []
      : $this->recibos->generar($empresaFacturacion, $facturaTipo, $factura);

    if ($esPrefactura) {
      $upd = $this->pdo->prepare(
        'UPDATE AlbaranesVentasCab SET
            PreFacturaTipo = :facturaTipo,
            PreFactura = :factura,
            PreEmpresaFacturacion = :empresaFacturacion,
            Seleccion = 0
         WHERE Empresa = :e AND Tipo = :t AND Albaran = :a
           AND ISNULL(Factura, 0) = 0'
      );
    } else {
      $upd = $this->pdo->prepare(
        'UPDATE AlbaranesVentasCab SET
            FacturaTipo = :facturaTipo,
            Factura = :factura,
            EmpresaFacturacion = :empresaFacturacion,
            Seleccion = 0
         WHERE Empresa = :e AND Tipo = :t AND Albaran = :a
           AND ISNULL(Factura, 0) = 0'
      );
    }

    foreach ($refs as $ref) {
      $upd->execute([
        'facturaTipo' => $facturaTipo,
        'factura' => $factura,
        'empresaFacturacion' => $empresaFacturacion,
        'e' => $ref['empresa'],
        't' => $ref['tipo'],
        'a' => $ref['albaran'],
      ]);
      if ($upd->rowCount() < 1) {
        throw new \RuntimeException(
          "Albarán {$ref['empresa']}/{$ref['tipo']}/{$ref['albaran']} ya no está pendiente",
          409
        );
      }
    }

    return [
      'empresa' => $empresaFacturacion,
      'facturaTipo' => $facturaTipo,
      'factura' => $factura,
      'cliente' => $cliente,
      'razonSocial' => trim((string) ($primero['RazonSocial'] ?? '')),
      'importe' => round($importe, 2),
      'estado' => $estado,
      'prefactura' => $esPrefactura,
      'recibos' => $recibos,
      'albaranes' => $refs,
    ];
  }

  /** @param array<string, mixed> $body */
  private function esPrefacturaBody(array $body): bool
  {
    $t = strtolower(trim((string) ($body['tipoFacturacion'] ?? $body['tipo'] ?? 'facturas')));
    return in_array($t, ['prefacturas', 'prefactura', 'pre'], true);
  }

  /**
   * @param array<int, array{base: float, pjeIva: float, impIva: float, pjeRec: float, impRec: float}> $slots
   */
  private function acumularSlot(
    array &$slots,
    float $base,
    float $pjeIva,
    float $impIva,
    float $pjeRec,
    float $impRec
  ): void {
    for ($s = 1; $s <= 6; $s++) {
      $vacio = abs($slots[$s]['base']) < 0.0000001 && abs($slots[$s]['pjeIva']) < 0.0000001;
      $mismo = abs($slots[$s]['pjeIva'] - $pjeIva) < 0.0001
        && abs($slots[$s]['pjeRec'] - $pjeRec) < 0.0001;
      if ($vacio || $mismo) {
        if ($vacio) {
          $slots[$s]['pjeIva'] = $pjeIva;
          $slots[$s]['pjeRec'] = $pjeRec;
        }
        $slots[$s]['base'] += $base;
        $slots[$s]['impIva'] += $impIva;
        $slots[$s]['impRec'] += $impRec;
        return;
      }
    }
  }

  private function nextNumeroFactura(string $empresa, string $campo, bool $facturacionDiferida): int
  {
    $allowed = ['UltFactura', 'UltAbono', 'UltPreFactura'];
    if (!in_array($campo, $allowed, true)) {
      throw new \InvalidArgumentException('Contador factura no permitido');
    }

    // Prefacturas: solo UltPreFactura (sin contador diferido).
    if ($campo === 'UltPreFactura') {
      $facturacionDiferida = false;
    }

    $cols = $campo;
    if ($facturacionDiferida) {
      $cols .= $campo === 'UltFactura' ? ', UltFacturaDiferida' : ', UltAbonoDiferido';
    }
    $stmt = $this->pdo->prepare(
      "SELECT {$cols} FROM Empresas_Ges WITH (UPDLOCK, ROWLOCK) WHERE Codigo = :e"
    );
    $stmt->execute(['e' => $empresa]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      throw new \RuntimeException('Tienda no encontrada', 404);
    }

    $campoUsar = $campo;
    if ($facturacionDiferida) {
      $alt = $campo === 'UltFactura' ? 'UltFacturaDiferida' : 'UltAbonoDiferido';
      if (array_key_exists($alt, $row)
        && (int) ($row[$alt] ?? 0) !== 0
        && (int) ($row[$alt] ?? 0) > (int) ($row[$campo] ?? 0)
      ) {
        $campoUsar = $alt;
      }
    }

    $n = (int) ($row[$campoUsar] ?? 0) + 1;
    $this->pdo->prepare("UPDATE Empresas_Ges SET [{$campoUsar}] = :n WHERE Codigo = :e")
      ->execute(['n' => $n, 'e' => $empresa]);

    if ($this->contadorFacturasAnioMesActivo()) {
      $n = (int) (date('ym') . str_pad((string) $n, 5, '0', STR_PAD_LEFT));
    }
    return $n;
  }

  private function contadorFacturasAnioMesActivo(): bool
  {
    $paths = [
      'C:\\DesOra\\DesParametros.ini',
      '\\DesOra\\DesParametros.ini',
      dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'DesParametros.ini',
    ];
    foreach ($paths as $path) {
      if (!is_readable($path)) {
        continue;
      }
      $txt = (string) @file_get_contents($path);
      if (preg_match('/ContadorFacturasA[nñ]oMes\s*=\s*S/iu', $txt)) {
        return true;
      }
    }
    return false;
  }

  private function empresaFacturasRectificativas(string $empresa): bool
  {
    try {
      $st = $this->pdo->prepare('SELECT FacturasRectificativas FROM Empresas_Ges WHERE Codigo = :e');
      $st->execute(['e' => $empresa]);
      $v = $st->fetchColumn();
      return $v !== false && (int) $v !== 0;
    } catch (\Throwable $e) {
      return false;
    }
  }

  private function fmtFecha(mixed $v): string
  {
    if ($v === null || $v === '') {
      return '';
    }
    if ($v instanceof \DateTimeInterface) {
      return $v->format('Y-m-d');
    }
    $s = (string) $v;
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $s, $m)) {
      return $m[1];
    }
    return $s;
  }
}
