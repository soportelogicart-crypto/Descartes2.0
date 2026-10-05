<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Listados;

use InvalidArgumentException;
use PDO;

/**
 * Informe legacy de tickets TPV. Los importes proceden de la cabecera fiscal:
 * no se aplica conversión porque el factor usado por el programa antiguo no
 * está almacenado de forma inequívoca en el documento.
 */
final class InformeTicketsListadoService
{
  private const LIMITE_FILAS = 10000;

  private PDO $pdo;

  /** @var array<string, int>|null */
  private ?array $agrupacionFormasPago = null;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  /** @param array<string, mixed> $query */
  public function generar(array $query): array
  {
    $q = $this->normalizarQuery($query);
    [$where, $params] = $this->crearFiltros($q);
    $divisasConfiguradas = $this->obtenerDivisasConfiguradas($q);
    $this->validarDivisa($q['divisa'], $divisasConfiguradas);

    $slotColumns = [];
    for ($i = 1; $i <= 6; $i++) {
      $slotColumns[] = "c.[ImporteBase{$i}]";
      $slotColumns[] = "c.[PjeIva{$i}]";
      $slotColumns[] = "c.[ImporteIva{$i}]";
      $slotColumns[] = "c.[PjeRec{$i}]";
      $slotColumns[] = "c.[ImporteRec{$i}]";
    }

    $sql = 'SELECT TOP ' . (self::LIMITE_FILAS + 1) . "
              RTRIM(c.[Empresa]) AS empresa,
              RTRIM(ISNULL(eg.[Nombre], '')) AS tiendaNombre,
              RTRIM(ISNULL(eg.[Divisa], '')) AS tiendaDivisa,
              RTRIM(ISNULL(eg.[DivisaAlt], '')) AS tiendaDivisaAlt,
              RTRIM(ISNULL(c.[Tipo], '')) AS tipo,
              c.[Albaran] AS albaran,
              CONVERT(varchar(10), c.[Fecha], 23) AS fecha,
              c.[Factura] AS numeroTicket,
              RTRIM(ISNULL(c.[Puesto], '')) AS puesto,
              c.[Sesion] AS sesion,
              RTRIM(ISNULL(c.[Agente], '')) AS agente,
              RTRIM(ISNULL(c.[Representante], '')) AS representante,
              RTRIM(ISNULL(c.[Cliente], '')) AS cliente,
              RTRIM(ISNULL(c.[NIF], '')) AS nif,
              RTRIM(ISNULL(c.[RazonSocial], '')) AS razonSocial,
              RTRIM(ISNULL(c.[OrGen], '')) AS origen,
              RTRIM(ISNULL(c.[Vendedor], '')) AS vendedor,
              RTRIM(ISNULL(c.[Estado], '')) AS estado,
              RTRIM(ISNULL(c.[Fpago1], '')) AS fpago1,
              ISNULL(c.[ImpFpago1], 0) AS impFpago1,
              RTRIM(ISNULL(c.[Fpago2], '')) AS fpago2,
              ISNULL(c.[ImpFpago2], 0) AS impFpago2,
              ISNULL(c.[Importe], 0) AS importe,
              ISNULL(articulos.numeroArticulos, 0) AS numeroArticulos,
              " . implode(",\n              ", $slotColumns) . "
            FROM [AlbaranesVentasCab] c
            LEFT JOIN [Empresas_Ges] eg
              ON RTRIM(eg.[Codigo]) = RTRIM(c.[Empresa])
            OUTER APPLY (
              SELECT SUM(ISNULL(l.[Cantidad], 0)) AS numeroArticulos
              FROM [AlbaranesVentasLin] l
              WHERE l.[Empresa] = c.[Empresa]
                AND l.[Tipo] = c.[Tipo]
                AND l.[Albaran] = c.[Albaran]
                AND RTRIM(UPPER(ISNULL(l.[Articulo], ''))) <> 'NO'
            ) articulos
            WHERE " . implode(' AND ', $where) . '
            ORDER BY RTRIM(c.[Empresa]), c.[Fecha], c.[Factura], c.[Albaran]';

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);

    $items = [];
    $truncado = false;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      if (count($items) >= self::LIMITE_FILAS) {
        $truncado = true;
        break;
      }
      $items[] = $this->mapTicket($row);
    }

    $resumen = $this->crearResumen($items);
    $exportar = $q['formato'] === 'exportar' ? $this->crearFilasExportacion($items) : [];
    $fechasTickets = array_column($items, 'fecha');
    $fechaDesde = $q['fechaDesde'] ?? ($fechasTickets !== [] ? min($fechasTickets) : null);
    $fechaHasta = $q['fechaHasta'] ?? ($fechasTickets !== [] ? max($fechasTickets) : null);

    return [
      'formato' => $q['formato'],
      'divisa' => $q['divisa'],
      'divisaConvertida' => false,
      'divisasConfiguradas' => $divisasConfiguradas,
      'fechaDesde' => $fechaDesde,
      'fechaHasta' => $fechaHasta,
      // Compatibilidad con consumidores del contrato anterior.
      'empresa' => $q['empresa'],
      'puesto' => $q['puesto'],
      'vendedor' => $q['vendedor'],
      'soloNumerados' => true,
      'items' => $items,
      'resumenDiario' => $resumen['dias'],
      'resumenTiendas' => $resumen['tiendas'],
      'totales' => $resumen['general'],
      'exportar' => $exportar,
      'truncado' => $truncado,
      'limite' => self::LIMITE_FILAS,
    ];
  }

  /**
   * @param array<string, mixed> $q
   * @return array{0: list<string>, 1: array<string, mixed>}
   */
  private function crearFiltros(array $q): array
  {
    $where = [
      "RTRIM(c.[FacturaTipo]) = 'T'",
      'ISNULL(c.[Factura], 0) > 0',
      'ISNULL(c.[Anulado], 0) = 0',
      "RTRIM(ISNULL(c.[Estado], '')) <> 'B'",
    ];
    $params = [];

    ListadosFiltrosSql::filtroRangoTexto($where, $params, 'c.[Empresa]', $q, 'empresaDesde', 'empresaHasta', 'empresa', 'it_empresa');
    ListadosFiltrosSql::filtroRangoTexto($where, $params, 'c.[Puesto]', $q, 'puestoDesde', 'puestoHasta', 'puesto', 'it_puesto');
    ListadosFiltrosSql::filtroRangoEntero($where, $params, 'c.[Sesion]', $q, 'sesionDesde', 'sesionHasta', 'sesion');
    ListadosFiltrosSql::filtroRangoFecha($where, $params, 'c.[Fecha]', $q, 'fechaDesde', 'fechaHasta', 'it_fecha');
    ListadosFiltrosSql::filtroRangoTexto($where, $params, 'c.[Agente]', $q, 'agenteDesde', 'agenteHasta', 'agente', 'it_agente');
    ListadosFiltrosSql::filtroRangoTexto($where, $params, 'c.[Representante]', $q, 'representanteDesde', 'representanteHasta', 'representante', 'it_representante');
    ListadosFiltrosSql::filtroRangoTexto($where, $params, 'c.[Cliente]', $q, 'clienteDesde', 'clienteHasta', 'cliente', 'it_cliente');
    ListadosFiltrosSql::filtroRangoEntero($where, $params, 'c.[Factura]', $q, 'ticketDesde', 'ticketHasta', 'ticket');
    ListadosFiltrosSql::filtroRangoTexto($where, $params, 'c.[OrGen]', $q, 'origenDesde', 'origenHasta', 'origen', 'it_origen');
    ListadosFiltrosSql::filtroRangoTexto($where, $params, 'c.[Vendedor]', $q, 'vendedorDesde', 'vendedorHasta', 'vendedor', 'it_vendedor');

    return [$where, $params];
  }

  /**
   * La validación usa las tiendas del rango solicitado. No se añade un
   * predicado por moneda: DivisaAlt es una moneda válida de la misma tienda.
   *
   * @param array<string, mixed> $q
   * @return list<string>
   */
  private function obtenerDivisasConfiguradas(array $q): array
  {
    $where = [];
    $params = [];
    ListadosFiltrosSql::filtroRangoTexto(
      $where,
      $params,
      'eg.[Codigo]',
      $q,
      'empresaDesde',
      'empresaHasta',
      'empresa',
      'it_div_empresa'
    );

    $sql = "SELECT DISTINCT RTRIM(v.divisa) AS divisa
            FROM [Empresas_Ges] eg
            CROSS APPLY (VALUES (eg.[Divisa]), (eg.[DivisaAlt])) v(divisa)
            WHERE RTRIM(ISNULL(v.divisa, '')) <> ''";
    if ($where !== []) {
      $sql .= ' AND ' . implode(' AND ', $where);
    }
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);

    $out = [];
    while (($value = $stmt->fetchColumn()) !== false) {
      $value = strtoupper(trim((string) $value));
      if ($value !== '') {
        $out[$value] = true;
      }
    }
    $divisas = array_keys($out);
    sort($divisas, SORT_STRING);

    return $divisas;
  }

  /** @param list<string> $configuradas */
  private function validarDivisa(string $divisa, array $configuradas): void
  {
    if ($divisa === '') {
      return;
    }
    if ($configuradas !== [] && !in_array($divisa, $configuradas, true)) {
      throw new InvalidArgumentException(
        'La divisa no está configurada como Divisa o DivisaAlt en las tiendas seleccionadas'
      );
    }
  }

  /** @param array<string, mixed> $row @return array<string, mixed> */
  private function mapTicket(array $row): array
  {
    $desglose = [];
    $base = 0.0;
    $iva = 0.0;
    $recargo = 0.0;
    for ($i = 1; $i <= 6; $i++) {
      $slotBase = round((float) ($row["ImporteBase{$i}"] ?? 0), 2);
      $slotIva = round((float) ($row["ImporteIva{$i}"] ?? 0), 2);
      $slotRecargo = round((float) ($row["ImporteRec{$i}"] ?? 0), 2);
      $pjeIva = round((float) ($row["PjeIva{$i}"] ?? 0), 2);
      $pjeRecargo = round((float) ($row["PjeRec{$i}"] ?? 0), 2);
      if ($slotBase == 0.0 && $slotIva == 0.0 && $slotRecargo == 0.0 && $pjeIva == 0.0 && $pjeRecargo == 0.0) {
        continue;
      }
      $desglose[] = [
        'posicion' => $i,
        'base' => $slotBase,
        'pjeIva' => $pjeIva,
        'iva' => $slotIva,
        'pjeRecargo' => $pjeRecargo,
        'recargo' => $slotRecargo,
        'importe' => round($slotBase + $slotIva + $slotRecargo, 2),
      ];
      $base += $slotBase;
      $iva += $slotIva;
      $recargo += $slotRecargo;
    }

    $importe = round((float) ($row['importe'] ?? 0), 2);
    $fpago1 = (string) ($row['fpago1'] ?? '');
    $fpago2 = (string) ($row['fpago2'] ?? '');
    $impFpago1 = round((float) ($row['impFpago1'] ?? 0), 2);
    $impFpago2 = round((float) ($row['impFpago2'] ?? 0), 2);

    return [
      'empresa' => (string) ($row['empresa'] ?? ''),
      'tiendaNombre' => (string) ($row['tiendaNombre'] ?? ''),
      'tiendaDivisa' => (string) ($row['tiendaDivisa'] ?? ''),
      'tiendaDivisaAlt' => (string) ($row['tiendaDivisaAlt'] ?? ''),
      'tipo' => (string) ($row['tipo'] ?? ''),
      'albaran' => (int) ($row['albaran'] ?? 0),
      'fecha' => (string) ($row['fecha'] ?? ''),
      'numeroTicket' => (int) ($row['numeroTicket'] ?? 0),
      'facturaTipo' => 'T',
      'puesto' => (string) ($row['puesto'] ?? ''),
      'sesion' => $row['sesion'] !== null ? (int) $row['sesion'] : null,
      'agente' => (string) ($row['agente'] ?? ''),
      'representante' => (string) ($row['representante'] ?? ''),
      'cliente' => (string) ($row['cliente'] ?? ''),
      'nif' => (string) ($row['nif'] ?? ''),
      'razonSocial' => (string) ($row['razonSocial'] ?? ''),
      'origen' => (string) ($row['origen'] ?? ''),
      'vendedor' => (string) ($row['vendedor'] ?? ''),
      'estado' => (string) ($row['estado'] ?? ''),
      // Claves antiguas y nuevas.
      'formaPago' => $fpago1,
      'fpago1' => $fpago1,
      'impFpago1' => $impFpago1,
      'fpago2' => $fpago2,
      'impFpago2' => $impFpago2,
      'formasPago' => $this->formasPagoTicket($fpago1, $impFpago1, $fpago2, $impFpago2, $importe),
      'desgloseIva' => $desglose,
      'base' => round($base, 2),
      'baseImponible' => round($base, 2),
      'iva' => round($iva, 2),
      'cuotaIva' => round($iva, 2),
      'recargo' => round($recargo, 2),
      'importe' => $importe,
      'importeTotal' => $importe,
      'numeroArticulos' => round((float) ($row['numeroArticulos'] ?? 0), 3),
      'esAbono' => $importe < 0,
    ];
  }

  /**
   * @return list<array{codigo: string, importe: float}>
   */
  private function formasPagoTicket(
    string $fpago1,
    float $impFpago1,
    string $fpago2,
    float $impFpago2,
    float $importe
  ): array {
    if ($fpago1 !== '' && $fpago2 === '' && $impFpago1 == 0.0 && $impFpago2 == 0.0) {
      $impFpago1 = $importe;
    }
    $out = [];
    if ($fpago1 !== '') {
      $out[] = [
        'codigo' => $fpago1,
        'importe' => round($impFpago1, 2),
        'agrupacion' => $this->agrupacionFormaPago($fpago1),
      ];
    }
    if ($fpago2 !== '') {
      $out[] = [
        'codigo' => $fpago2,
        'importe' => round($impFpago2, 2),
        'agrupacion' => $this->agrupacionFormaPago($fpago2),
      ];
    }

    return $out;
  }

  private function agrupacionFormaPago(string $codigo): int
  {
    $codigo = trim($codigo);
    if ($codigo === '') {
      return -1;
    }
    if ($this->agrupacionFormasPago === null) {
      $this->agrupacionFormasPago = [];
      try {
        $rows = $this->pdo->query(
          'SELECT RTRIM(Codigo) AS Codigo, ISNULL(Agrupacion, -1) AS Agrupacion FROM FormasPago'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
          $this->agrupacionFormasPago[trim((string) ($row['Codigo'] ?? ''))] = (int) ($row['Agrupacion'] ?? -1);
        }
      } catch (\Throwable) {
        $this->agrupacionFormasPago = [];
      }
    }

    return $this->agrupacionFormasPago[$codigo] ?? -1;
  }

  /**
   * @param list<array<string, mixed>> $items
   * @return array{dias: list<array<string, mixed>>, tiendas: list<array<string, mixed>>, general: array<string, mixed>}
   */
  private function crearResumen(array $items): array
  {
    /** @var array<string, array<string, mixed>> $dias */
    $dias = [];
    /** @var array<string, array<string, mixed>> $tiendas */
    $tiendas = [];
    $general = $this->resumenVacio('', '', '', '');

    foreach ($items as $item) {
      $empresa = (string) $item['empresa'];
      $fecha = (string) $item['fecha'];
      if (!isset($tiendas[$empresa])) {
        $tiendas[$empresa] = $this->resumenVacio(
          $empresa,
          (string) $item['tiendaNombre'],
          (string) $item['tiendaDivisa'],
          (string) $item['tiendaDivisaAlt']
        );
      }
      $diaKey = $empresa . '|' . $fecha;
      if (!isset($dias[$diaKey])) {
        $dias[$diaKey] = $this->resumenVacio(
          $empresa,
          (string) $item['tiendaNombre'],
          (string) $item['tiendaDivisa'],
          (string) $item['tiendaDivisaAlt'],
          $fecha
        );
      }
      $this->acumularResumen($dias[$diaKey], $item);
      $this->acumularResumen($tiendas[$empresa], $item);
      $this->acumularResumen($general, $item);
    }

    foreach ($dias as &$dia) {
      $this->finalizarResumen($dia);
    }
    unset($dia);
    foreach ($tiendas as &$tienda) {
      $this->finalizarResumen($tienda);
    }
    unset($tienda);
    $this->finalizarResumen($general);

    return [
      'dias' => array_values($dias),
      'tiendas' => array_values($tiendas),
      'general' => $general,
    ];
  }

  /** @return array<string, mixed> */
  private function resumenVacio(
    string $empresa,
    string $tiendaNombre,
    string $tiendaDivisa,
    string $tiendaDivisaAlt,
    ?string $fecha = null
  ): array {
    return [
      'empresa' => $empresa,
      'tiendaNombre' => $tiendaNombre,
      'tiendaDivisa' => $tiendaDivisa,
      'tiendaDivisaAlt' => $tiendaDivisaAlt,
      'fecha' => $fecha,
      'tickets' => 0,
      'ticketsVenta' => 0,
      'abonos' => 0,
      'numeroArticulos' => 0.0,
      'primerTicket' => null,
      'ultimoTicket' => null,
      'base' => 0.0,
      'baseImponible' => 0.0,
      'iva' => 0.0,
      'cuotaIva' => 0.0,
      'recargo' => 0.0,
      'importe' => 0.0,
      'importeTotal' => 0.0,
      'importeMinimo' => null,
      'importeMaximo' => null,
      'importeMedio' => 0.0,
      '_iva' => [],
      '_pagos' => [],
      '_perfil' => [
        'efectivo' => ['importe' => 0.0, 'conteo' => 0],
        'cheques' => ['importe' => 0.0, 'conteo' => 0],
        'tarjetas' => ['importe' => 0.0, 'conteo' => 0],
        'creditos' => ['importe' => 0.0, 'conteo' => 0],
        'vales' => ['importe' => 0.0, 'conteo' => 0],
        'otros' => ['importe' => 0.0, 'conteo' => 0],
      ],
    ];
  }

  /** @param array<string, mixed> $resumen @param array<string, mixed> $item */
  private function acumularResumen(array &$resumen, array $item): void
  {
    $numero = (int) $item['numeroTicket'];
    $importe = (float) $item['importe'];
    $resumen['tickets']++;
    if (!empty($item['esAbono'])) {
      $resumen['abonos']++;
    } else {
      $resumen['ticketsVenta']++;
    }
    $resumen['numeroArticulos'] += (float) $item['numeroArticulos'];
    $resumen['primerTicket'] = $resumen['primerTicket'] === null ? $numero : min($resumen['primerTicket'], $numero);
    $resumen['ultimoTicket'] = $resumen['ultimoTicket'] === null ? $numero : max($resumen['ultimoTicket'], $numero);
    foreach (['base', 'iva', 'recargo', 'importe'] as $campo) {
      $resumen[$campo] += (float) $item[$campo];
    }
    $resumen['importeMinimo'] = $resumen['importeMinimo'] === null ? $importe : min($resumen['importeMinimo'], $importe);
    $resumen['importeMaximo'] = $resumen['importeMaximo'] === null ? $importe : max($resumen['importeMaximo'], $importe);

    foreach ($item['desgloseIva'] as $iva) {
      $key = number_format((float) $iva['pjeIva'], 2, '.', '') . '|' . number_format((float) $iva['pjeRecargo'], 2, '.', '');
      if (!isset($resumen['_iva'][$key])) {
        $resumen['_iva'][$key] = [
          'pjeIva' => (float) $iva['pjeIva'],
          'pjeRecargo' => (float) $iva['pjeRecargo'],
          'base' => 0.0,
          'iva' => 0.0,
          'recargo' => 0.0,
          'importe' => 0.0,
        ];
      }
      foreach (['base', 'iva', 'recargo', 'importe'] as $campo) {
        $resumen['_iva'][$key][$campo] += (float) $iva[$campo];
      }
    }
    foreach ($item['formasPago'] as $pago) {
      $codigo = (string) $pago['codigo'];
      $importePago = (float) $pago['importe'];
      $agrupacion = (int) ($pago['agrupacion'] ?? $this->agrupacionFormaPago($codigo));
      if (!isset($resumen['_pagos'][$codigo])) {
        $resumen['_pagos'][$codigo] = [
          'codigo' => $codigo,
          'importe' => 0.0,
          'conteo' => 0,
          'agrupacion' => $agrupacion,
        ];
      }
      $resumen['_pagos'][$codigo]['importe'] += $importePago;
      $resumen['_pagos'][$codigo]['conteo']++;
      $perfil = match ($agrupacion) {
        0 => 'efectivo',
        1 => 'cheques',
        2 => 'tarjetas',
        3 => 'creditos',
        4 => 'vales',
        default => 'otros',
      };
      $resumen['_perfil'][$perfil]['importe'] += $importePago;
      $resumen['_perfil'][$perfil]['conteo']++;
    }
  }

  /** @param array<string, mixed> $resumen */
  private function finalizarResumen(array &$resumen): void
  {
    foreach (['base', 'iva', 'recargo', 'importe'] as $campo) {
      $resumen[$campo] = round((float) $resumen[$campo], 2);
    }
    $resumen['baseImponible'] = $resumen['base'];
    $resumen['cuotaIva'] = $resumen['iva'];
    $resumen['importeTotal'] = $resumen['importe'];
    $resumen['numeroArticulos'] = round((float) $resumen['numeroArticulos'], 3);
    $resumen['importeMinimo'] = $resumen['importeMinimo'] !== null ? round((float) $resumen['importeMinimo'], 2) : null;
    $resumen['importeMaximo'] = $resumen['importeMaximo'] !== null ? round((float) $resumen['importeMaximo'], 2) : null;
    $resumen['importeMedio'] = $resumen['tickets'] > 0
      ? round((float) $resumen['importe'] / (int) $resumen['tickets'], 2)
      : 0.0;

    $resumen['desgloseIva'] = array_values($resumen['_iva']);
    usort($resumen['desgloseIva'], static fn (array $a, array $b): int => $a['pjeIva'] <=> $b['pjeIva']);
    foreach ($resumen['desgloseIva'] as &$iva) {
      foreach (['base', 'iva', 'recargo', 'importe'] as $campo) {
        $iva[$campo] = round((float) $iva[$campo], 2);
      }
    }
    unset($iva);

    $resumen['formasPago'] = array_values($resumen['_pagos']);
    usort($resumen['formasPago'], static fn (array $a, array $b): int => strcmp($a['codigo'], $b['codigo']));
    foreach ($resumen['formasPago'] as &$pago) {
      $pago['importe'] = round((float) $pago['importe'], 2);
    }
    unset($pago);

    $importeTotal = (float) $resumen['importe'];
    $perfil = [];
    foreach ($resumen['_perfil'] as $clave => $fila) {
      $importePerfil = round((float) $fila['importe'], 2);
      $perfil[$clave] = [
        'importe' => $importePerfil,
        'conteo' => (int) $fila['conteo'],
        'pje' => $importeTotal != 0.0
          ? round($importePerfil * 100.0 / $importeTotal, 2)
          : 0.0,
      ];
    }
    $resumen['perfilPago'] = $perfil;
    unset($resumen['_iva'], $resumen['_pagos'], $resumen['_perfil']);
  }

  /**
   * @param list<array<string, mixed>> $items
   * @return list<array<string, mixed>>
   */
  private function crearFilasExportacion(array $items): array
  {
    $out = [];
    foreach ($items as $item) {
      foreach ($item['desgloseIva'] as $iva) {
        $out[] = [
          'empresa' => $item['empresa'],
          'tiendaNombre' => $item['tiendaNombre'],
          'fecha' => $item['fecha'],
          'numeroTicket' => $item['numeroTicket'],
          'tipo' => 'Ticket',
          'cliente' => $item['cliente'],
          'razonSocial' => $item['razonSocial'],
          'nif' => $item['nif'],
          'pjeIva' => $iva['pjeIva'],
          'base' => $iva['base'],
          'iva' => $iva['iva'],
          'pjeRecargo' => $iva['pjeRecargo'],
          'recargo' => $iva['recargo'],
          'importe' => $iva['importe'],
        ];
      }
    }

    return $out;
  }

  /**
   * @param array<string, mixed> $query
   * @return array<string, mixed>
   */
  private function normalizarQuery(array $query): array
  {
    // Fechas opcionales: se puede pedir solo por sesión, tienda, ticket… (el TOP limita el volumen).
    $fechaDesde = ListadosFiltrosSql::fechaDiaInput($query['fechaDesde'] ?? null);
    $fechaHasta = ListadosFiltrosSql::fechaDiaInput($query['fechaHasta'] ?? null);
    if ($fechaDesde !== null && $fechaHasta !== null && $fechaDesde > $fechaHasta) {
      throw new InvalidArgumentException('La fecha desde no puede ser posterior a la fecha hasta');
    }

    $formato = trim((string) ($query['formato'] ?? 'desglosado'));
    $formatoLower = strtolower($formato);
    $formatos = [
      'desglosado' => 'desglosado',
      'resumido' => 'resumido',
      'superresumido' => 'superResumido',
      'exportar' => 'exportar',
    ];
    if (!isset($formatos[$formatoLower])) {
      throw new InvalidArgumentException('formato debe ser desglosado, resumido, superResumido o exportar');
    }

    $empresa = ListadosFiltrosSql::normalizarEmpresaInput((string) ($query['empresa'] ?? ($query['tienda'] ?? '')));
    $ticket = $query['ticket'] ?? ($query['factura'] ?? null);

    return [
      'formato' => $formatos[$formatoLower],
      'divisa' => strtoupper(trim((string) ($query['divisa'] ?? ''))),
      'fechaDesde' => $fechaDesde,
      'fechaHasta' => $fechaHasta,
      'empresa' => $empresa,
      'empresaDesde' => trim((string) ($query['empresaDesde'] ?? ($query['tiendaDesde'] ?? ''))),
      'empresaHasta' => trim((string) ($query['empresaHasta'] ?? ($query['tiendaHasta'] ?? ''))),
      'puesto' => trim((string) ($query['puesto'] ?? '')),
      'puestoDesde' => trim((string) ($query['puestoDesde'] ?? '')),
      'puestoHasta' => trim((string) ($query['puestoHasta'] ?? '')),
      'sesion' => (int) ($query['sesion'] ?? 0),
      'sesionDesde' => (int) ($query['sesionDesde'] ?? 0),
      'sesionHasta' => (int) ($query['sesionHasta'] ?? 0),
      'agente' => trim((string) ($query['agente'] ?? '')),
      'agenteDesde' => trim((string) ($query['agenteDesde'] ?? '')),
      'agenteHasta' => trim((string) ($query['agenteHasta'] ?? '')),
      'representante' => trim((string) ($query['representante'] ?? '')),
      'representanteDesde' => trim((string) ($query['representanteDesde'] ?? '')),
      'representanteHasta' => trim((string) ($query['representanteHasta'] ?? '')),
      'cliente' => trim((string) ($query['cliente'] ?? '')),
      'clienteDesde' => trim((string) ($query['clienteDesde'] ?? '')),
      'clienteHasta' => trim((string) ($query['clienteHasta'] ?? '')),
      'ticket' => (int) ($ticket ?? 0),
      'ticketDesde' => (int) ($query['ticketDesde'] ?? ($query['facturaDesde'] ?? 0)),
      'ticketHasta' => (int) ($query['ticketHasta'] ?? ($query['facturaHasta'] ?? 0)),
      'origen' => trim((string) ($query['origen'] ?? '')),
      'origenDesde' => trim((string) ($query['origenDesde'] ?? '')),
      'origenHasta' => trim((string) ($query['origenHasta'] ?? '')),
      'vendedor' => trim((string) ($query['vendedor'] ?? '')),
      'vendedorDesde' => trim((string) ($query['vendedorDesde'] ?? '')),
      'vendedorHasta' => trim((string) ($query['vendedorHasta'] ?? '')),
    ];
  }
}
