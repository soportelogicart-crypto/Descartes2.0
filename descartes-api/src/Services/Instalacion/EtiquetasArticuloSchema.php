<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Instalacion;

use PDO;

/**
 * NroLin de la cola de etiquetas debe numerarse solo.
 * En bases importadas la columna existe pero no es AUTO_INCREMENT y MySQL
 * exige que sea la primera columna de un índice.
 */
final class EtiquetasArticuloSchema
{
  private static bool $comprobado = false;

  public static function asegurar(PDO $pdo): void
  {
    if (self::$comprobado) {
      return;
    }
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
      self::$comprobado = true;
      return;
    }
    if (!self::tablaExiste($pdo)) {
      self::$comprobado = true;
      return;
    }

    if (!self::nroLinEsIndice($pdo)) {
      $pdo->exec(
        'ALTER TABLE `EtiquetasArticulo` ADD INDEX `ix_EtiquetasArticulo_NroLin` (`NroLin`)'
      );
    }
    if (!self::nroLinEsAutonumerico($pdo)) {
      $pdo->exec(
        'ALTER TABLE `EtiquetasArticulo` MODIFY `NroLin` INT NOT NULL AUTO_INCREMENT'
      );
    }
    self::$comprobado = true;
  }

  private static function tablaExiste(PDO $pdo): bool
  {
    $stmt = $pdo->query(
      "SELECT 1 FROM information_schema.TABLES
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'EtiquetasArticulo'"
    );

    return (bool) $stmt->fetchColumn();
  }

  private static function nroLinEsAutonumerico(PDO $pdo): bool
  {
    $stmt = $pdo->query(
      "SELECT EXTRA FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'EtiquetasArticulo' AND COLUMN_NAME = 'NroLin'"
    );
    $extra = strtolower((string) $stmt->fetchColumn());

    return str_contains($extra, 'auto_increment');
  }

  private static function nroLinEsIndice(PDO $pdo): bool
  {
    $stmt = $pdo->query(
      "SELECT 1 FROM information_schema.STATISTICS
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'EtiquetasArticulo'
         AND COLUMN_NAME = 'NroLin' AND SEQ_IN_INDEX = 1"
    );

    return (bool) $stmt->fetchColumn();
  }
}
