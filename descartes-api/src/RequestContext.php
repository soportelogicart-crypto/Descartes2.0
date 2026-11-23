<?php

declare(strict_types=1);

namespace Descartes\Api;

use PDO;
use RuntimeException;

final class RequestContext
{
  private static ?PDO $pdo = null;
  /** @var array<string, mixed>|null */
  private static ?array $config = null;
  private static bool $web = false;

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

  /** @param array<string, mixed>|null $config */
  public static function setConfig(?array $config): void
  {
    self::$config = $config;
  }

  /** @return array<string, mixed>|null */
  public static function getConfig(): ?array
  {
    return self::$config;
  }

  /** La petición viene de la API web: no se usa el .env como base. */
  public static function marcarWeb(): void
  {
    self::$web = true;
  }

  public static function esWeb(): bool
  {
    return self::$web;
  }
}
