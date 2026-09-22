<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Facturacion;

use PDO;

/**
 * Columna AlbaranesPeriodicos.Activo (pausar sin perder la configuracion).
 *
 * Se crea sola la primera vez que se usa el modulo en cada conexion, para no
 * depender del asistente de instalacion. La migracion 015 hace lo mismo en
 * instalaciones nuevas.
 */
final class AlbaranesPeriodicosEsquema
{
  /** @var array<string, bool> */
  private static array $cache = [];

  public static function tieneActivo(PDO $pdo): bool
  {
    $clave = spl_object_hash($pdo);
    if (!array_key_exists($clave, self::$cache)) {
      self::$cache[$clave] = self::existeColumna($pdo) || self::crearColumna($pdo);
    }

    return self::$cache[$clave];
  }

  /** Columna Activo en el SELECT, o un 1 constante si no se pudo crear. */
  public static function selectActivo(PDO $pdo, string $alias = 'p'): string
  {
    if (!self::tieneActivo($pdo)) {
      return 'CAST(1 AS bit) AS Activo';
    }

    return $alias === '' ? 'Activo' : "{$alias}.Activo";
  }

  private static function existeColumna(PDO $pdo): bool
  {
    try {
      $sql = "SELECT CASE WHEN COL_LENGTH('dbo.AlbaranesPeriodicos', 'Activo') IS NULL
                THEN 0 ELSE 1 END";

      return (bool) $pdo->query($sql)->fetchColumn();
    } catch (\Throwable $e) {
      return false;
    }
  }

  private static function crearColumna(PDO $pdo): bool
  {
    try {
      $existeTabla = (bool) $pdo
        ->query("SELECT CASE WHEN OBJECT_ID('dbo.AlbaranesPeriodicos', 'U') IS NULL
                   THEN 0 ELSE 1 END")
        ->fetchColumn();
      if (!$existeTabla) {
        return false;
      }
      // DEFAULT sin nombre: DF_AlbaranesPeriodicos_* puede estar ocupado.
      $pdo->exec('ALTER TABLE [dbo].[AlbaranesPeriodicos] ADD [Activo] bit NOT NULL DEFAULT ((1))');
      // Las pausadas del apaño anterior (Periodicidad = 0) quedan inactivas.
      $pdo->exec('UPDATE [dbo].[AlbaranesPeriodicos] SET [Activo] = 0 WHERE [Periodicidad] <= 0');

      return true;
    } catch (\Throwable $e) {
      // Otra petición pudo crearla a la vez, o el usuario de BD no tiene permisos DDL.
      return self::existeColumna($pdo);
    }
  }
}
