<?php

declare(strict_types=1);

namespace Descartes\Api;

use PDO;
use RuntimeException;

final class RequestContext
{
  private static ?PDO $pdo = null;

  public static function setPdo(PDO $pdo): void
  {
    self::$pdo = $pdo;
  }

  public static function getPdo(): PDO
  {
    if (self::$pdo === null) {
      throw new RuntimeException('Conexion PDO no inicializada');
    }
    return self::$pdo;
  }
}
