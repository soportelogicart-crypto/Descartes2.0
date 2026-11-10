<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Listados;

use InvalidArgumentException;
use PDO;

/**
 * Detalle de ventas (legacy: Informe de ventas detallado).
 * Una fila por línea de albarán o ticket, agrupada por cliente, con totales por cliente y general.
 */
final class DetalleVentasListadoService
{
  private const LIMITE_FILAS = 10000;

  /** Rangos de texto: [columna SQL, nombre del filtro en la query (xDesde / xHasta)]. */
  private const RANGOS_TEXTO = [
    ['c.[Empresa]', 'tienda'],
    ['RTRIM(c.[Puesto])', 'puesto'],
    ['l.[Articulo]', 'articulo'],
    ['a.[Familia]', 'familia'],
    ['a.[Subfamilia]', 'subfamilia'],
    ['f.[MacroFamilia]', 'macroFamilia'],
    ['a.[Agrupacion]', 'agrupacion'],
    ['a.[UltProveedor]', 'proveedor'],
    ['a.[Seccion]', 'seccion'],
    ['a.[SubSeccion]', 'subSeccion'],
    ['c.[Vendedor]', 'vendedor'],
    ['c.[Cliente]', 'cliente'],
    ['cl.[Actividad]', 'actividad'],
    ['cl.[CodigoPostal]', 'codigoPostal'],
    ['cl.[TipoDescuento]', 'tipoDescuento'],
    ['l.[LoteVenta]', 'lote'],
  ];

  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  /** @param array<string, mixed> $query */
  public function generar(array $query): array
  {
    [$fechaDesde, $fechaHasta, $tipoVenta] = $this->normalizarQuery($query);
    $where = [
      "RTRIM(UPPER(ISNULL(l.[Articulo], ''))) <> 'NO'",
      'ISNULL(c.[Anulado], 0) = 0',
      "RTRIM(ISNULL(c.[Estado], '')) <> 'B'",
    ];
    $params = [];

    if ($tipoVenta === 'tickets') {
      $where[] = "RTRIM(ISNULL(c.[FacturaTipo], '')) = 'T'";
    } elseif ($tipoVenta === 'albaranes') {
      $where[] = "RTRIM(ISNULL(c.[FacturaTipo], '')) <> 'T'";
    }

    ListadosFiltrosSql::filtroRangoFecha($where, $params, 'c.[Fecha]', $query, 'fechaDesde', 'fechaHasta', 'dv_fecha');
    foreach (self::RANGOS_TEXTO as [$columna, $nombre]) {
      ListadosFiltrosSql::filtroRangoTexto(
        $where,
        $params,
        $columna,
        $query,
        $nombre . 'Desde',
        $nombre . 'Hasta',
        $nombre,
        'dv_' . $nombre
      );
    }
    ListadosFiltrosSql::filtroRangoEntero($where, $params, 'c.[Factura]', $query, 'facturaDesde', 'facturaHasta', 'factura');

    $sql = 'SELECT TOP ' . (self::LIMITE_FILAS + 1) . "
              RTRIM(c.[Empresa]) AS empresa,
              RTRIM(ISNULL(c.[Cliente], '')) AS clienteCodigo,
              RTRIM(ISNULL(cl.[RazonSocial], '')) AS clienteNombre,
              CONVERT(varchar(10), c.[Fecha], 23) AS fecha,
              c.[Albaran] AS albaran,
              RTRIM(ISNULL(c.[Tipo], '')) AS tipo,
              RTRIM(ISNULL(c.[FacturaTipo], '')) AS facturaTipo,
              c.[Factura] AS factura,
              RTRIM(ISNULL(l.[Articulo], '')) AS articulo,
              RTRIM(ISNULL(l.[Descripcion], '')) AS descripcion,
              ISNULL(l.[Precio], 0) AS precio,
              ISNULL(l.[Cantidad], 0) AS unidades,
              ISNULL(l.[PjeDto], 0) AS pjeDto,
              ISNULL(l.[Importe], 0) AS importe
            FROM [AlbaranesVentasCab] c
            INNER JOIN [AlbaranesVentasLin] l
              ON c.[Empresa] = l.[Empresa] AND c.[Albaran] = l.[Albaran] AND c.[Tipo] = l.[Tipo]
            LEFT JOIN [Articulos] a ON a.[Codigo] = l.[Articulo]
            LEFT JOIN [Familias] f ON RTRIM(f.[Codigo]) = RTRIM(a.[Familia])
            LEFT JOIN [Clientes] cl ON cl.[Codigo] = c.[Cliente]
            WHERE " . implode(' AND ', $where) . "
            ORDER BY RTRIM(ISNULL(c.[Cliente], '')), c.[Fecha], c.[Albaran], l.[NroLin]";

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);

    /** @var array<string, array<string, mixed>> $grupos */
    $grupos = [];
    $totalLineas = 0;
    $totalUnidades = 0.0;
    $totalImporte = 0.0;
    $truncado = false;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      if ($totalLineas >= self::LIMITE_FILAS) {
        $truncado = true;
        break;
      }
      $linea = $this->mapLinea($row);
      $clave = $linea['clienteCodigo'];
      if (!isset($grupos[$clave])) {
        $grupos[$clave] = [
          'clienteCodigo' => $clave,
          'clienteNombre' => (string) ($row['clienteNombre'] ?? ''),
          'lineas' => [],
          'totales' => ['lineas' => 0, 'unidades' => 0.0, 'importe' => 0.0],
        ];
      }
      $grupos[$clave]['lineas'][] = $linea;
      $grupos[$clave]['totales']['lineas']++;
      $grupos[$clave]['totales']['unidades'] += $linea['unidades'];
      $grupos[$clave]['totales']['importe'] += $linea['importe'];
      $totalLineas++;
      $totalUnidades += $linea['unidades'];
      $totalImporte += $linea['importe'];
    }

    foreach ($grupos as &$grupo) {
      $grupo['totales']['unidades'] = round($grupo['totales']['unidades'], 3);
      $grupo['totales']['importe'] = round($grupo['totales']['importe'], 2);
    }
    unset($grupo);

    return [
      'fechaDesde' => $fechaDesde,
      'fechaHasta' => $fechaHasta,
      'tipoVenta' => $tipoVenta,
      'grupos' => array_values($grupos),
      'totales' => [
        'lineas' => $totalLineas,
        'clientes' => count($grupos),
        'unidades' => round($totalUnidades, 3),
        'importe' => round($totalImporte, 2),
      ],
      'truncado' => $truncado,
      'limite' => self::LIMITE_FILAS,
    ];
  }

  /**
   * @param array<string, mixed> $query
   * @return array{0: ?string, 1: ?string, 2: string}
   */
  private function normalizarQuery(array $query): array
  {
    $fechaDesde = ListadosFiltrosSql::fechaDiaInput($query['fechaDesde'] ?? null);
    $fechaHasta = ListadosFiltrosSql::fechaDiaInput($query['fechaHasta'] ?? null);
    if ($fechaDesde !== null && $fechaHasta !== null && $fechaDesde > $fechaHasta) {
      throw new InvalidArgumentException('La fecha desde no puede ser posterior a la fecha hasta');
    }

    $tipoVenta = strtolower(trim((string) ($query['tipoVenta'] ?? 'todos')));
    if (!in_array($tipoVenta, ['todos', 'albaranes', 'tickets'], true)) {
      throw new InvalidArgumentException('tipoVenta debe ser todos, albaranes o tickets');
    }

    return [$fechaDesde, $fechaHasta, $tipoVenta];
  }

  /**
   * @param array<string, mixed> $row
   * @return array<string, mixed>
   */
  private function mapLinea(array $row): array
  {
    return [
      'empresa' => (string) ($row['empresa'] ?? ''),
      'clienteCodigo' => (string) ($row['clienteCodigo'] ?? ''),
      'fecha' => (string) ($row['fecha'] ?? ''),
      'albaran' => (int) ($row['albaran'] ?? 0),
      'tipo' => (string) ($row['tipo'] ?? ''),
      'esTicket' => trim((string) ($row['facturaTipo'] ?? '')) === 'T',
      'factura' => $row['factura'] !== null ? (int) $row['factura'] : null,
      'articulo' => (string) ($row['articulo'] ?? ''),
      'descripcion' => (string) ($row['descripcion'] ?? ''),
      'precio' => round((float) ($row['precio'] ?? 0), 2),
      'unidades' => round((float) ($row['unidades'] ?? 0), 3),
      'pjeDto' => round((float) ($row['pjeDto'] ?? 0), 2),
      'importe' => round((float) ($row['importe'] ?? 0), 2),
    ];
  }
}
