<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Listados;

use InvalidArgumentException;
use PDO;

/**
 * Existencias agregadas desde [Stock] (movimientos mensuales por almacén).
 */
final class StockListadoService
{
  private const LIMITE_FILAS = 10000;

  private const AGRUPAR = [
    'articulo',
    'familia',
    'subfamilia',
    'macrofamilia',
    'agrupacion',
    'proveedor',
  ];

  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  /** @param array<string, mixed> $query */
  public function generar(array $query): array
  {
    $agrupar = strtolower(trim((string) ($query['agruparPor'] ?? 'articulo')));
    if (!in_array($agrupar, self::AGRUPAR, true)) {
      throw new InvalidArgumentException(
        'agruparPor no válido. Use: ' . implode(', ', self::AGRUPAR)
      );
    }

    $almacen = (int) ($query['almacen'] ?? 0);
    if ($almacen < 0) {
      $almacen = 0;
    }

    $stockFiltro = $this->normalizarStockFiltro((string) ($query['stockFiltro'] ?? ''));
    $ocultarCero = filter_var($query['ocultarCero'] ?? null, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    if ($ocultarCero === null) {
      $ocultarCero = $stockFiltro === 'superior_0';
    }

    [$groupCod, $groupNom, $groupBySql] = $this->groupExpressions($agrupar);

    $havingParts = [];
    if ($ocultarCero || $stockFiltro === 'superior_0') {
      $havingParts[] = 'SUM(det.[unidades]) > 0.0001';
    }
    if ($stockFiltro === 'menor_0') {
      $havingParts[] = 'SUM(det.[unidades]) < -0.0001';
    } elseif ($stockFiltro === 'igual_0') {
      $havingParts[] = 'ABS(SUM(det.[unidades])) <= 0.0001';
    } elseif ($stockFiltro === 'diferente_0') {
      $havingParts[] = 'ABS(SUM(det.[unidades])) > 0.0001';
    }
    $having = $havingParts !== [] ? 'HAVING ' . implode(' AND ', $havingParts) : '';

    $incluirSinFilasStock = $agrupar === 'articulo' || $this->filtroMaestroDeArticuloActivo($query);

    $whereStock = [];
    $whereArticulo = [];
    $params = [];
    if ($almacen > 0) {
      $whereStock[] = 's.[Almacen] = :almacenLegacy';
      $params['almacenLegacy'] = $almacen;
    }
    ListadosFiltrosSql::filtroRangoEntero($whereStock, $params, 's.[Almacen]', $query, 'almacenDesde', 'almacenHasta');
    ListadosFiltrosSql::filtroRangoEntero($whereStock, $params, 's.[Año]', $query, 'anoDesde', 'anoHasta');
    ListadosFiltrosSql::filtroRangoEntero($whereStock, $params, 's.[Mes]', $query, 'mesDesde', 'mesHasta');
    ListadosFiltrosSql::filtroRangoTexto($whereArticulo, $params, 'f.[MacroFamilia]', $query, 'macrofamiliaDesde', 'macrofamiliaHasta', null, 'mf');
    ListadosFiltrosSql::filtroRangoTexto($whereArticulo, $params, 'a.[Familia]', $query, 'familiaDesde', 'familiaHasta', null, 'fam');
    ListadosFiltrosSql::filtroRangoTexto($whereArticulo, $params, 'a.[Subfamilia]', $query, 'subfamiliaDesde', 'subfamiliaHasta', null, 'sf');
    ListadosFiltrosSql::filtroRangoTexto($whereArticulo, $params, 'a.[Agrupacion]', $query, 'agrupacionDesde', 'agrupacionHasta', null, 'ag');
    ListadosFiltrosSql::filtroRangoTexto($whereArticulo, $params, 'a.[Codigo]', $query, 'articuloDesde', 'articuloHasta', null, 'art');
    ListadosFiltrosSql::filtroRangoTexto($whereArticulo, $params, 'a.[UltProveedor]', $query, 'proveedorDesde', 'proveedorHasta', null, 'prov');
    ListadosFiltrosSql::filtroRangoTexto($whereArticulo, $params, 'a.[Seccion]', $query, 'seccionDesde', 'seccionHasta', null, 'sec');
    ListadosFiltrosSql::filtroRangoTexto($whereArticulo, $params, 'a.[SubSeccion]', $query, 'subseccionDesde', 'subseccionHasta', null, 'ssec');
    ListadosFiltrosSql::filtroRangoFechaUltimaVentaArticulo($whereArticulo, $params, $query);
    ListadosFiltrosSql::filtroRangoFecha($whereArticulo, $params, 'a.[FechaAlta]', $query, 'fechaAltaDesde', 'fechaAltaHasta', 'fAlta');
    ListadosFiltrosSql::filtroRangoFecha($whereArticulo, $params, 'a.[FechaUltComp]', $query, 'ultCompraDesde', 'ultCompraHasta', 'ultComp');
    ListadosFiltrosSql::filtroRangoTexto($whereArticulo, $params, 'a.[Ubicacion]', $query, 'ubicacionDesde', 'ubicacionHasta', null, 'ubic');
    if ($stockFiltro === 'bloqueo_venta') {
      $whereArticulo[] = 'ISNULL(a.[BloqueoVenta], 0) <> 0';
    }

    if ($incluirSinFilasStock) {
      $onStock = $whereStock === [] ? '' : ' AND ' . implode(' AND ', $whereStock);
      $whereSql = $whereArticulo === [] ? '' : 'WHERE ' . implode(' AND ', $whereArticulo);
      $fromJoin = "FROM [Articulos] a
              LEFT JOIN [Stock] s ON RTRIM(s.[Codigo]) = RTRIM(a.[Codigo]){$onStock}
              LEFT JOIN [Familias] f ON RTRIM(f.[Codigo]) = RTRIM(a.[Familia])
              LEFT JOIN [Subfamilias] sf ON RTRIM(sf.[Subfamilia]) = RTRIM(a.[Subfamilia])
              LEFT JOIN [MacroFamilias] mf ON RTRIM(mf.[Codigo]) = RTRIM(f.[MacroFamilia])
              LEFT JOIN [Agrupaciones] ag ON RTRIM(ag.[Codigo]) = RTRIM(a.[Agrupacion])
              LEFT JOIN [Proveedores] p ON RTRIM(p.[Codigo]) = RTRIM(a.[UltProveedor])";
      $articuloSql = "RTRIM(ISNULL(a.[Codigo], ''))";
    } else {
      $where = array_merge($whereStock, $whereArticulo);
      $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
      $fromJoin = "FROM [Stock] s
              INNER JOIN [Articulos] a ON RTRIM(a.[Codigo]) = RTRIM(s.[Codigo])
              LEFT JOIN [Familias] f ON RTRIM(f.[Codigo]) = RTRIM(a.[Familia])
              LEFT JOIN [Subfamilias] sf ON RTRIM(sf.[Subfamilia]) = RTRIM(a.[Subfamilia])
              LEFT JOIN [MacroFamilias] mf ON RTRIM(mf.[Codigo]) = RTRIM(f.[MacroFamilia])
              LEFT JOIN [Agrupaciones] ag ON RTRIM(ag.[Codigo]) = RTRIM(a.[Agrupacion])
              LEFT JOIN [Proveedores] p ON RTRIM(p.[Codigo]) = RTRIM(a.[UltProveedor])";
      $articuloSql = "RTRIM(ISNULL(s.[Codigo], ''))";
    }

    $sql = "SELECT TOP " . (self::LIMITE_FILAS + 1) . "
              {$groupCod} AS grupoCodigo,
              {$groupNom} AS grupoNombre,
              SUM(det.[unidades]) AS unidades,
              COUNT(DISTINCT det.[articulo]) AS numArticulos
            FROM (
              SELECT
                {$articuloSql} AS articulo,
                RTRIM(ISNULL(a.[Descripcion], '')) AS descripcion,
                RTRIM(ISNULL(a.[Familia], '')) AS familiaCodigo,
                RTRIM(ISNULL(f.[Descripcion], '')) AS familiaNombre,
                RTRIM(ISNULL(a.[Subfamilia], '')) AS subfamiliaCodigo,
                RTRIM(ISNULL(sf.[Descripción], '')) AS subfamiliaNombre,
                RTRIM(ISNULL(f.[MacroFamilia], '')) AS macrofamiliaCodigo,
                RTRIM(ISNULL(mf.[Descripcion], '')) AS macrofamiliaNombre,
                RTRIM(ISNULL(a.[Agrupacion], '')) AS agrupacionCodigo,
                RTRIM(ISNULL(ag.[Descripcion], '')) AS agrupacionNombre,
                RTRIM(ISNULL(a.[UltProveedor], '')) AS proveedorCodigo,
                RTRIM(ISNULL(p.[RazonSocial], '')) AS proveedorNombre,
                (
                  ISNULL(s.[Entradas], 0) - ISNULL(s.[Salidas], 0) - ISNULL(s.[Ventas], 0)
                  + ISNULL(s.[TraspasosEntradas], 0) - ISNULL(s.[TraspasosSalidas], 0)
                ) AS unidades
              {$fromJoin}
              {$whereSql}
            ) det
            GROUP BY {$groupBySql}
            {$having}
            ORDER BY {$groupCod}";

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);

    $items = [];
    $totalUnidades = 0.0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $unidades = round((float) ($row['unidades'] ?? 0), 4);
      $totalUnidades += $unidades;
      $items[] = [
        'grupoCodigo' => (string) ($row['grupoCodigo'] ?? ''),
        'grupoNombre' => (string) ($row['grupoNombre'] ?? ''),
        'unidades' => $unidades,
        'numArticulos' => (int) ($row['numArticulos'] ?? 0),
      ];
    }

    $truncado = count($items) > self::LIMITE_FILAS;
    if ($truncado) {
      $items = array_slice($items, 0, self::LIMITE_FILAS);
    }

    return [
      'agruparPor' => $agrupar,
      'almacen' => $almacen,
      'items' => $items,
      'totales' => [
        'unidades' => round($totalUnidades, 4),
        'filas' => count($items),
      ],
      'truncado' => $truncado,
      'limite' => self::LIMITE_FILAS,
    ];
  }

  /** Valores legacy (positivo/cero/negativo) y etiquetas 1.0 (superior_0, …). */
  private function normalizarStockFiltro(string $raw): string
  {
    $v = strtolower(trim($raw));
    return match ($v) {
      'positivo', 'superior_0' => 'superior_0',
      'negativo', 'menor_0' => 'menor_0',
      'cero', 'igual_0' => 'igual_0',
      'diferente_0' => 'diferente_0',
      'bloqueo_venta' => 'bloqueo_venta',
      default => 'todos',
    };
  }

  /** Filtros de maestro (fechas artículo): deben listarse aunque no haya filas [Stock] en el año pedido. */
  private function filtroMaestroDeArticuloActivo(array $query): bool
  {
    foreach (
      [
        'ultimaVentaDesde',
        'ultimaVentaHasta',
        'fechaAltaDesde',
        'fechaAltaHasta',
        'ultCompraDesde',
        'ultCompraHasta',
      ] as $key
    ) {
      if (ListadosFiltrosSql::fechaDiaInput($query[$key] ?? null) !== null) {
        return true;
      }
    }

    return false;
  }

  /** @return array{0: string, 1: string, 2: string} */
  private function groupExpressions(string $agrupar): array
  {
    switch ($agrupar) {
      case 'familia':
        return [
          'RTRIM(ISNULL(det.[familiaCodigo], \'\'))',
          'MAX(RTRIM(ISNULL(det.[familiaNombre], \'\')))',
          'RTRIM(ISNULL(det.[familiaCodigo], \'\'))',
        ];
      case 'subfamilia':
        return [
          'RTRIM(ISNULL(det.[subfamiliaCodigo], \'\'))',
          'MAX(RTRIM(ISNULL(det.[subfamiliaNombre], \'\')))',
          'RTRIM(ISNULL(det.[subfamiliaCodigo], \'\'))',
        ];
      case 'macrofamilia':
        return [
          'RTRIM(ISNULL(det.[macrofamiliaCodigo], \'\'))',
          'MAX(RTRIM(ISNULL(det.[macrofamiliaNombre], \'\')))',
          'RTRIM(ISNULL(det.[macrofamiliaCodigo], \'\'))',
        ];
      case 'agrupacion':
        return [
          'RTRIM(ISNULL(det.[agrupacionCodigo], \'\'))',
          'MAX(RTRIM(ISNULL(det.[agrupacionNombre], \'\')))',
          'RTRIM(ISNULL(det.[agrupacionCodigo], \'\'))',
        ];
      case 'proveedor':
        return [
          'RTRIM(ISNULL(det.[proveedorCodigo], \'\'))',
          'MAX(RTRIM(ISNULL(det.[proveedorNombre], \'\')))',
          'RTRIM(ISNULL(det.[proveedorCodigo], \'\'))',
        ];
      case 'articulo':
      default:
        return [
          'RTRIM(ISNULL(det.[articulo], \'\'))',
          'MAX(RTRIM(ISNULL(det.[descripcion], \'\')))',
          'RTRIM(ISNULL(det.[articulo], \'\'))',
        ];
    }
  }
}
