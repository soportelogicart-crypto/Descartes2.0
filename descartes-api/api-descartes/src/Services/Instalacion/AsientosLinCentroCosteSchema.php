<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Instalacion;

use PDO;

/** Columna CentroCoste en AsientosLin (024). */
final class AsientosLinCentroCosteSchema
{
  public static function asegurar(PDO $pdo): void
  {
    if (!self::tableExists($pdo, 'AsientosLin')) {
      return;
    }

    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
      if (!self::columnExists($pdo, 'AsientosLin', 'CentroCoste')) {
        $pdo->exec(
          'ALTER TABLE `AsientosLin` ADD COLUMN `CentroCoste` varchar(10) NULL DEFAULT \'\''
        );
      }

      return;
    }

    (new MigrationService())->aplicarArchivoIdempotente($pdo, '024-asientos-lin-centro-coste.sql');
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
