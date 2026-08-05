<?php

declare(strict_types=1);

namespace Descartes\Api\Repositories;

use PDO;

final class ArtPreciosRepository
{
  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  public function findByArticulo(string $codigo): array
  {
    $stmt = $this->pdo->prepare(
      'SELECT [DesdeCantidad], [Precio] FROM [ArtPrecios] WHERE [Codigo] = :codigo ORDER BY [DesdeCantidad]'
    );
    $stmt->execute(['codigo' => $codigo]);

    $items = [];
    while ($row = $stmt->fetch()) {
      $items[] = [
        'desdeCantidad' => (int) $row['DesdeCantidad'],
        'precio' => (float) $row['Precio'],
      ];
    }

    return $items;
  }

  public function replaceForArticulo(string $codigo, array $precios): void
  {
    $delete = $this->pdo->prepare('DELETE FROM [ArtPrecios] WHERE [Codigo] = :codigo');
    $delete->execute(['codigo' => $codigo]);

    if ($precios === []) {
      return;
    }

    $insert = $this->pdo->prepare(
      'INSERT INTO [ArtPrecios] ([Codigo], [DesdeCantidad], [Precio]) VALUES (:codigo, :desdeCantidad, :precio)'
    );

    foreach ($precios as $precio) {
      $desde = (int) ($precio['desdeCantidad'] ?? 0);
      if ($desde < 0) {
        throw new \InvalidArgumentException('La cantidad minima del precio escalonado no puede ser negativa');
      }
      $insert->execute([
        'codigo' => $codigo,
        'desdeCantidad' => $desde,
        'precio' => (float) ($precio['precio'] ?? 0),
      ]);
    }
  }
}
