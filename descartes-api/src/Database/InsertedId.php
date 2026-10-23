<?php

declare(strict_types=1);

namespace Descartes\Api\Database;

use PDO;
use PDOStatement;

/** Id devuelto por OUTPUT INSERTED en SQL Server, o el autonumérico en MySQL. */
final class InsertedId
{
  public static function leer(PDO $pdo, PDOStatement $stmt): int
  {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
      return (int) $pdo->lastInsertId();
    }

    return (int) $stmt->fetchColumn();
  }
}
