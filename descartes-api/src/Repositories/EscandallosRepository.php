<?php

declare(strict_types=1);

namespace Descartes\Api\Repositories;

use PDO;

final class EscandallosRepository
{
  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  public function articuloExiste(string $codigo): bool
  {
    $stmt = $this->pdo->prepare('SELECT 1 FROM [Articulos] WHERE RTRIM([Codigo]) = :codigo');
    $stmt->execute(['codigo' => $codigo]);
    return (bool) $stmt->fetchColumn();
  }

  /** @return list<array{ingrediente: string, descripcion: string, cantidad: float}> */
  public function findByArticulo(string $codigo): array
  {
    $stmt = $this->pdo->prepare(
      'SELECT e.[Ingrediente], e.[Cantidad], a.[Descripcion]
       FROM [Escandallos] e
       LEFT JOIN [Articulos] a ON RTRIM(a.[Codigo]) = RTRIM(e.[Ingrediente])
       WHERE RTRIM(e.[Articulo]) = :codigo
       ORDER BY e.[Ingrediente]'
    );
    $stmt->execute(['codigo' => $codigo]);

    $items = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $items[] = [
        'ingrediente' => trim((string) ($row['Ingrediente'] ?? '')),
        'descripcion' => trim((string) ($row['Descripcion'] ?? '')),
        'cantidad' => (float) ($row['Cantidad'] ?? 0),
      ];
    }

    return $items;
  }

  /**
   * @param list<array<string, mixed>> $items
   * @return list<array{ingrediente: string, descripcion: string, cantidad: float}>
   */
  public function replaceForArticulo(string $codigo, array $items): array
  {
    $normalizados = [];
    $vistos = [];
    foreach ($items as $item) {
      $ing = trim((string) ($item['ingrediente'] ?? ''));
      if ($ing === '') {
        continue;
      }
      if (strcasecmp($ing, $codigo) === 0) {
        throw new \InvalidArgumentException('Un articulo no puede ser ingrediente de si mismo');
      }
      $key = strtoupper($ing);
      if (isset($vistos[$key])) {
        throw new \InvalidArgumentException("Ingrediente duplicado: {$ing}");
      }
      $vistos[$key] = true;

      if (!$this->articuloExiste($ing)) {
        throw new \InvalidArgumentException("El ingrediente {$ing} no existe en Articulos");
      }

      $cantidad = (float) ($item['cantidad'] ?? 0);
      if ($cantidad < 0) {
        throw new \InvalidArgumentException('La cantidad del escandallo no puede ser negativa');
      }

      $normalizados[] = [
        'ingrediente' => $ing,
        'cantidad' => $cantidad,
      ];
    }

    $this->pdo->beginTransaction();
    try {
      $delete = $this->pdo->prepare('DELETE FROM [Escandallos] WHERE RTRIM([Articulo]) = :codigo');
      $delete->execute(['codigo' => $codigo]);

      if ($normalizados !== []) {
        $insert = $this->pdo->prepare(
          'INSERT INTO [Escandallos] ([Articulo], [Ingrediente], [Cantidad])
           VALUES (:articulo, :ingrediente, :cantidad)'
        );
        foreach ($normalizados as $n) {
          $insert->execute([
            'articulo' => $codigo,
            'ingrediente' => $n['ingrediente'],
            'cantidad' => $n['cantidad'],
          ]);
        }
      }

      $this->pdo->commit();
    } catch (\Throwable $e) {
      if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      throw $e;
    }

    return $this->findByArticulo($codigo);
  }
}
