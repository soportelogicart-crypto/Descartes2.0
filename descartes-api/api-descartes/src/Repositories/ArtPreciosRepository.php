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

  /**
   * Mismos escalones que findByArticulo, agrupados por el código pedido.
   *
   * @param list<string> $codigos
   * @return array<string, list<array{desdeCantidad: int, precio: float}>>
   */
  public function findByArticulos(array $codigos): array
  {
    $codigos = $this->codigosUnicos($codigos);
    $out = [];
    foreach ($codigos as $codigo) {
      $out[$codigo] = [];
    }
    foreach (array_chunk($codigos, 400) as $chunk) {
      [$sqlIn, $params] = $this->parametrosIn($chunk);
      $stmt = $this->pdo->prepare(
        'SELECT [Codigo], [DesdeCantidad], [Precio] FROM [ArtPrecios]
         WHERE [Codigo] IN (' . $sqlIn . ')
         ORDER BY [Codigo], [DesdeCantidad]'
      );
      $stmt->execute($params);
      while ($row = $stmt->fetch()) {
        $precio = [
          'desdeCantidad' => (int) $row['DesdeCantidad'],
          'precio' => (float) $row['Precio'],
        ];
        $claveFila = rtrim((string) $row['Codigo']);
        foreach ($chunk as $codigo) {
          if (rtrim($codigo) === $claveFila) {
            $out[$codigo][] = $precio;
          }
        }
      }
    }

    return $out;
  }

  /** @param list<string> $codigos @return list<string> */
  private function codigosUnicos(array $codigos): array
  {
    $out = [];
    foreach ($codigos as $codigo) {
      $codigo = (string) $codigo;
      if ($codigo !== '') {
        $out[$codigo] = $codigo;
      }
    }
    return array_values($out);
  }

  /**
   * @param list<string> $codigos
   * @return array{0: string, 1: array<string, string>}
   */
  private function parametrosIn(array $codigos): array
  {
    $placeholders = [];
    $params = [];
    foreach ($codigos as $i => $codigo) {
      $clave = 'c' . $i;
      $placeholders[] = ':' . $clave;
      $params[$clave] = $codigo;
    }
    return [implode(', ', $placeholders), $params];
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
