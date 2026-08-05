<?php

declare(strict_types=1);

namespace Descartes\Api\Config;

final class EntityConfig
{
  public static function all(): array
  {
    return require __DIR__ . '/entities.php';
  }

  public static function get(string $entidad): ?array
  {
    $all = self::all();
    return $all[$entidad] ?? null;
  }

  public static function assertExists(string $entidad): array
  {
    $config = self::get($entidad);
    if ($config === null) {
      throw new \InvalidArgumentException("Entidad desconocida: {$entidad}");
    }
    return $config;
  }
}
