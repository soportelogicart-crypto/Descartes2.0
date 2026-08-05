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
