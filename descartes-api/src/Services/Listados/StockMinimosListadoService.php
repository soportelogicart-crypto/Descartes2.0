<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Listados;

use PDO;

/**
 * Artículos por debajo del mínimo definido en [Minimo] (por almacén).
 */
final class StockMinimosListadoService
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
    $almacen = (int) ($query['almacen'] ?? 0);
    if ($almacen < 0) {
      $almacen = 0;
    }

    $sql = 'SELECT TOP ' . (self::LIMITE_FILAS + 1) . "
              RTRIM(ISNULL(m.[Articulo], '')) AS articulo,
              RTRIM(ISNULL(a.[Descripcion], '')) AS descripcion,
              m.[Almacen] AS almacen,
              RTRIM(ISNULL(al.[Descripcion], '')) AS almacenNombre,
              ISNULL(m.[Minimo], 0) AS minimo,
              ISNULL(m.[Optimo], 0) AS optimo,
              ISNULL(st.[unidades], 0) AS stockActual,
              ISNULL(m.[Minimo], 0) - ISNULL(st.[unidades], 0) AS faltan,
              RTRIM(ISNULL(m.[Proveedor], '')) AS proveedorCodigo
            FROM [Minimo] m
            INNER JOIN [Articulos] a ON RTRIM(a.[Codigo]) = RTRIM(m.[Articulo])
            LEFT JOIN [Almacenes] al ON al.[Codigo] = m.[Almacen]
            LEFT JOIN (
              SELECT
                RTRIM(ISNULL(s.[Codigo], '')) AS articulo,
                s.[Almacen] AS almacen,
                SUM(
                  ISNULL(s.[Entradas], 0) - ISNULL(s.[Salidas], 0) - ISNULL(s.[Ventas], 0)
                  + ISNULL(s.[TraspasosEntradas], 0) - ISNULL(s.[TraspasosSalidas], 0)
                ) AS unidades
              FROM [Stock] s
              GROUP BY s.[Codigo], s.[Almacen]
            ) st ON st.[articulo] = RTRIM(m.[Articulo]) AND st.[almacen] = m.[Almacen]
            WHERE ISNULL(m.[Minimo], 0) > 0
              AND ISNULL(st.[unidades], 0) < ISNULL(m.[Minimo], 0)
              AND (:almacen = 0 OR m.[Almacen] = :almacen)
            ORDER BY m.[Almacen], m.[Articulo]";

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute(['almacen' => $almacen]);

    $items = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $items[] = [
        'articulo' => (string) ($row['articulo'] ?? ''),
        'descripcion' => (string) ($row['descripcion'] ?? ''),
        'almacen' => (int) ($row['almacen'] ?? 0),
        'almacenNombre' => (string) ($row['almacenNombre'] ?? ''),
        'minimo' => round((float) ($row['minimo'] ?? 0), 4),
        'optimo' => round((float) ($row['optimo'] ?? 0), 4),
        'stockActual' => round((float) ($row['stockActual'] ?? 0), 4),
        'faltan' => round((float) ($row['faltan'] ?? 0), 4),
        'proveedorCodigo' => (string) ($row['proveedorCodigo'] ?? ''),
      ];
    }

    $truncado = count($items) > self::LIMITE_FILAS;
    if ($truncado) {
      $items = array_slice($items, 0, self::LIMITE_FILAS);
    }

    return [
      'almacen' => $almacen,
      'items' => $items,
      'totales' => ['filas' => count($items)],
      'truncado' => $truncado,
      'limite' => self::LIMITE_FILAS,
    ];
  }
}
