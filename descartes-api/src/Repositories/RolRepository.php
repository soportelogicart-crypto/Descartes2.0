<?php

declare(strict_types=1);

namespace Descartes\Api\Repositories;

use PDO;

final class RolRepository
{
  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  public function exists(string $codigo): bool
  {
    $stmt = $this->pdo->prepare('SELECT TOP 1 1 AS found FROM [Roles] WHERE [Codigo] = :codigo');
    $stmt->execute(['codigo' => $codigo]);

    return (bool) $stmt->fetch();
  }
}
