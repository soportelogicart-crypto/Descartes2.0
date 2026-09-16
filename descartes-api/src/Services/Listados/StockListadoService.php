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

    $ocultarCero = filter_var($query['ocultarCero'] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    $ocultarCero = $ocultarCero !== false;

    [$groupCod, $groupNom, $groupBySql] = $this->groupExpressions($agrupar);

    $having = $ocultarCero ? 'HAVING ABS(SUM(det.[unidades])) > 0.0001' : '';

    $sql = "SELECT TOP " . (self::LIMITE_FILAS + 1) . "
              {$groupCod} AS grupoCodigo,
              {$groupNom} AS grupoNombre,
              SUM(det.[unidades]) AS unidades,
              COUNT(DISTINCT det.[articulo]) AS numArticulos
            FROM (
              SELECT
                RTRIM(ISNULL(s.[Codigo], '')) AS articulo,
                RTRIM(ISNULL(a.[Descripcion], '')) AS descripcion,
                RTRIM(ISNULL(a.[Familia], '')) AS familiaCodigo,
                RTRIM(ISNULL(f.[Descripcion], '')) AS familiaNombre,
                RTRIM(ISNULL(a.[Subfamilia], '')) AS subfamiliaCodigo,
                RTRIM(ISNULL(sf.[Descripcion], '')) AS subfamiliaNombre,
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
              FROM [Stock] s
              INNER JOIN [Articulos] a ON RTRIM(a.[Codigo]) = RTRIM(s.[Codigo])
              LEFT JOIN [Familias] f ON RTRIM(f.[Codigo]) = RTRIM(a.[Familia])
              LEFT JOIN [Subfamilias] sf ON RTRIM(sf.[Codigo]) = RTRIM(a.[Subfamilia])
              LEFT JOIN [MacroFamilias] mf ON RTRIM(mf.[Codigo]) = RTRIM(f.[MacroFamilia])
              LEFT JOIN [Agrupaciones] ag ON RTRIM(ag.[Codigo]) = RTRIM(a.[Agrupacion])
              LEFT JOIN [Proveedores] p ON RTRIM(p.[Codigo]) = RTRIM(a.[UltProveedor])
              WHERE (:almacen = 0 OR s.[Almacen] = :almacen)
            ) det
            GROUP BY {$groupBySql}
            {$having}
            ORDER BY {$groupCod}";

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute(['almacen' => $almacen]);

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
