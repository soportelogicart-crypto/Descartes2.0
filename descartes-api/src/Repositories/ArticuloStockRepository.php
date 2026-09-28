<?php

declare(strict_types=1);

namespace Descartes\Api\Repositories;

use PDO;

final class ArticuloStockRepository
{
  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  public function findByArticulo(string $codigo): array
  {
    $stmt = $this->pdo->prepare(
      'SELECT s.[Almacen] AS almacenCodigo,
              a.[Descripcion] AS almacenDescripcion,
              SUM(
                ISNULL(s.[Entradas], 0) - ISNULL(s.[Salidas], 0) - ISNULL(s.[Ventas], 0)
                + ISNULL(s.[TraspasosEntradas], 0) - ISNULL(s.[TraspasosSalidas], 0)
              ) AS cantidad
       FROM [Stock] s
       LEFT JOIN [Almacenes] a ON a.[Codigo] = s.[Almacen]
       WHERE s.[Codigo] = :codigo
       GROUP BY s.[Almacen], a.[Descripcion]
       HAVING ABS(SUM(
         ISNULL(s.[Entradas], 0) - ISNULL(s.[Salidas], 0) - ISNULL(s.[Ventas], 0)
         + ISNULL(s.[TraspasosEntradas], 0) - ISNULL(s.[TraspasosSalidas], 0)
       )) > 0.0001
       ORDER BY s.[Almacen]'
    );
    $stmt->execute(['codigo' => $codigo]);

    $items = [];
    while ($row = $stmt->fetch()) {
      $items[] = [
        'almacenCodigo' => (int) $row['almacenCodigo'],
        'almacenDescripcion' => (string) ($row['almacenDescripcion'] ?? ''),
        'cantidad' => round((float) $row['cantidad'], 4),
      ];
    }

    return $items;
  }

  /**
   * Mismo stock por almacén que findByArticulo, agrupado por el código pedido.
   *
   * @param list<string> $codigos
   * @return array<string, list<array{almacenCodigo: int, almacenDescripcion: string, cantidad: float}>>
   */
  public function findByArticulos(array $codigos): array
  {
    $unicos = [];
    foreach ($codigos as $codigo) {
      $codigo = (string) $codigo;
      if ($codigo !== '') {
        $unicos[$codigo] = $codigo;
      }
    }
    $codigos = array_values($unicos);
    $out = [];
    foreach ($codigos as $codigo) {
      $out[$codigo] = [];
    }

    $cantidadSql = 'ISNULL(s.[Entradas], 0) - ISNULL(s.[Salidas], 0) - ISNULL(s.[Ventas], 0)
            + ISNULL(s.[TraspasosEntradas], 0) - ISNULL(s.[TraspasosSalidas], 0)';
    foreach (array_chunk($codigos, 400) as $chunk) {
      $placeholders = [];
      $params = [];
      foreach ($chunk as $i => $codigo) {
        $clave = 'c' . $i;
        $placeholders[] = ':' . $clave;
        $params[$clave] = $codigo;
      }
      $stmt = $this->pdo->prepare(
        'SELECT s.[Codigo] AS codigo,
                s.[Almacen] AS almacenCodigo,
                a.[Descripcion] AS almacenDescripcion,
                SUM(' . $cantidadSql . ') AS cantidad
         FROM [Stock] s
         LEFT JOIN [Almacenes] a ON a.[Codigo] = s.[Almacen]
         WHERE s.[Codigo] IN (' . implode(', ', $placeholders) . ')
         GROUP BY s.[Codigo], s.[Almacen], a.[Descripcion]
         HAVING ABS(SUM(' . $cantidadSql . ')) > 0.0001
         ORDER BY s.[Codigo], s.[Almacen]'
      );
      $stmt->execute($params);
      while ($row = $stmt->fetch()) {
        $stock = [
          'almacenCodigo' => (int) $row['almacenCodigo'],
          'almacenDescripcion' => (string) ($row['almacenDescripcion'] ?? ''),
          'cantidad' => round((float) $row['cantidad'], 4),
        ];
        $claveFila = rtrim((string) $row['codigo']);
        foreach ($chunk as $codigo) {
          if (rtrim($codigo) === $claveFila) {
            $out[$codigo][] = $stock;
          }
        }
      }
    }

    return $out;
  }

  public function tieneStockActivo(string $codigo): bool
  {
    $stmt = $this->pdo->prepare(
      'SELECT TOP 1 1 AS found FROM [Stock] WHERE [Codigo] = :codigo
       AND (
         ABS(ISNULL([Entradas], 0) - ISNULL([Salidas], 0) - ISNULL([Ventas], 0)
           + ISNULL([TraspasosEntradas], 0) - ISNULL([TraspasosSalidas], 0)) > 0.0001
         OR ISNULL([Entradas], 0) <> 0 OR ISNULL([Salidas], 0) <> 0 OR ISNULL([Ventas], 0) <> 0
       )'
    );
    $stmt->execute(['codigo' => $codigo]);

    return (bool) $stmt->fetch();
  }
}
