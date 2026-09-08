<?php

declare(strict_types=1);

namespace Descartes\Api\Services;

use PDO;

/**
 * Vínculo tienda ↔ almacén vía campo legacy Empresas_Ges.Almacen (almacén principal).
 * Varias tiendas pueden compartir el mismo almacén; no se crea tabla puente.
 */
final class TiendaAlmacenService
{
  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  public function getAlmacenPrincipal(string $tiendaCodigo): ?int
  {
    $stmt = $this->pdo->prepare(
      'SELECT [Almacen] FROM [Empresas_Ges] WHERE [Codigo] = :codigo'
    );
    $stmt->execute(['codigo' => $tiendaCodigo]);
    $row = $stmt->fetch();
    if (!$row || $row['Almacen'] === null || (float) $row['Almacen'] <= 0) {
      return null;
    }

    return (int) $row['Almacen'];
  }

  public function listTiendasPorAlmacen(int $almacenCodigo): array
  {
    $stmt = $this->pdo->prepare(
      'SELECT [Codigo] FROM [Empresas_Ges] WHERE CAST([Almacen] AS int) = :almacen AND [Baja] = 0 ORDER BY [Codigo]'
    );
    $stmt->execute(['almacen' => $almacenCodigo]);
    $codigos = [];
    while ($row = $stmt->fetch()) {
      $codigos[] = (string) $row['Codigo'];
    }

    return $codigos;
  }

  public function assertAlmacenActivo(int $almacenCodigo): void
  {
    $stmt = $this->pdo->prepare(
      'SELECT [Baja] FROM [Almacenes] WHERE [Codigo] = :codigo'
    );
    $stmt->execute(['codigo' => $almacenCodigo]);
    $row = $stmt->fetch();
    if (!$row) {
      throw new \InvalidArgumentException('El almacen indicado no existe');
    }
    if ((int) $row['Baja'] === 1) {
      throw new \InvalidArgumentException('El almacen indicado no esta activo');
    }
  }
}
