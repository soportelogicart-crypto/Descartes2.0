<?php

declare(strict_types=1);

namespace Descartes\Api\Repositories;

use PDO;

final class EmpresaClienteRepository
{
  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  public function findCentral(): ?array
  {
    $stmt = $this->pdo->query('SELECT TOP 1 * FROM [Empresas] WHERE [Central] = 1');
    $row = $stmt->fetch();

    return $row ?: null;
  }

  public function updateCentral(array $columns): void
  {
    if ($columns === []) {
      return;
    }

    $sets = [];
    foreach (array_keys($columns) as $col) {
      $sets[] = "[{$col}] = :{$col}";
    }

    $sql = 'UPDATE [Empresas] SET ' . implode(', ', $sets) . ' WHERE [Central] = 1';
    $stmt = $this->pdo->prepare($sql);
    foreach ($columns as $col => $value) {
      $stmt->bindValue(':' . $col, $value);
    }
    $stmt->execute();
  }

  public function countFacturasEmitidas(): int
  {
    $stmt = $this->pdo->query('SELECT COUNT(*) AS total FROM [Facturas]');
    $row = $stmt->fetch();

    return (int) ($row['total'] ?? 0);
  }
}
