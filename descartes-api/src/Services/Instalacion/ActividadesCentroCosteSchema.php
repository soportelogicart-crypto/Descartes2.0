<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Instalacion;

use PDO;

/** Columna CodigoCentroCoste en Actividades (021); sin DescripcionCentroCoste (022). */
final class ActividadesCentroCosteSchema
{
  public static function asegurar(PDO $pdo): void
  {
    if (!self::tableExists($pdo, 'Actividades')) {
      return;
    }

    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
      if (!self::columnExists($pdo, 'Actividades', 'CodigoCentroCoste')) {
        $pdo->exec(
          'ALTER TABLE `Actividades` ADD COLUMN `CodigoCentroCoste` varchar(10) NULL DEFAULT \'\''
        );
      }
      self::eliminarDescripcionCentroCosteSiExiste($pdo);

      return;
    }

    (new MigrationService())->aplicarArchivoIdempotente($pdo, '021-actividades-centro-coste.sql');
    (new MigrationService())->aplicarArchivoIdempotente($pdo, '022-actividades-drop-descripcion-centro-coste.sql');
  }

  public static function columnasOk(PDO $pdo): bool
  {
    if (!self::tableExists($pdo, 'Actividades')) {
      return true;
    }

    return self::columnExists($pdo, 'Actividades', 'CodigoCentroCoste')
      && !self::columnExists($pdo, 'Actividades', 'DescripcionCentroCoste');
  }

  private static function eliminarDescripcionCentroCosteSiExiste(PDO $pdo): void
  {
    if (!self::columnExists($pdo, 'Actividades', 'DescripcionCentroCoste')) {
      return;
    }
    $pdo->exec('ALTER TABLE `Actividades` DROP COLUMN `DescripcionCentroCoste`');
  }

  private static function tableExists(PDO $pdo, string $table): bool
  {
    $schema = self::schemaActual($pdo);
    $stmt = $pdo->prepare(
      'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
       WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table'
    );
    $stmt->execute(['schema' => $schema, 'table' => $table]);

    return (bool) $stmt->fetchColumn();
  }

  private static function columnExists(PDO $pdo, string $table, string $column): bool
  {
    $schema = self::schemaActual($pdo);
    $stmt = $pdo->prepare(
      'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
       WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table AND COLUMN_NAME = :column'
    );
    $stmt->execute(['schema' => $schema, 'table' => $table, 'column' => $column]);

    return (bool) $stmt->fetchColumn();
  }

  private static function schemaActual(PDO $pdo): string
  {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
      $nombre = $pdo->query('SELECT DATABASE()')->fetchColumn();

      return trim((string) $nombre);
    }

    return 'dbo';
  }
}
