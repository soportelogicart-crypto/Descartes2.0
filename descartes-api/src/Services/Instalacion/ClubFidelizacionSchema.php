<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Instalacion;

use PDO;

/** Esquema club fidelización saldo (025). */
final class ClubFidelizacionSchema
{
  public static function asegurar(PDO $pdo): void
  {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
      self::crearTablasMysql($pdo);
    self::columnasClientesMysql($pdo);
    self::columnasAlbaranesMysql($pdo);
    self::semillaTipoCalculoMysql($pdo);

      return;
    }

    (new MigrationService())->aplicarArchivoIdempotente($pdo, '025-club-fidelizacion-saldo.sql');
  }

  private static function crearTablasMysql(PDO $pdo): void
  {
    $pdo->exec(
      'CREATE TABLE IF NOT EXISTS `FidelizacionSaldoLotes` (
         `Id` int NOT NULL AUTO_INCREMENT,
         `Cliente` varchar(9) NOT NULL,
         `Empresa` varchar(3) NOT NULL,
         `ImporteCentimos` int NOT NULL,
         `SaldoCentimosRestante` int NOT NULL,
         `GeneradoEn` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
         `CaducaEn` datetime NOT NULL,
         `TipoDocOrigen` varchar(1) NULL,
         `AlbaranOrigen` int NULL,
         PRIMARY KEY (`Id`),
         KEY `ix_fid_saldo_cliente_caduca` (`Cliente`, `CaducaEn`)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $pdo->exec(
      'CREATE TABLE IF NOT EXISTS `FidelizacionSaldoMovimientos` (
         `Id` int NOT NULL AUTO_INCREMENT,
         `LoteId` int NULL,
         `Cliente` varchar(9) NOT NULL,
         `TipoMov` varchar(20) NOT NULL,
         `Centimos` int NOT NULL,
         `Empresa` varchar(3) NOT NULL,
         `TipoDoc` varchar(1) NULL,
         `Albaran` int NULL,
         `CreadoEn` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
         PRIMARY KEY (`Id`)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $pdo->exec(
      'CREATE TABLE IF NOT EXISTS `FidelizacionValesClub` (
         `Id` int NOT NULL AUTO_INCREMENT,
         `Cliente` varchar(9) NOT NULL,
         `Empresa` varchar(3) NOT NULL,
         `Tipo` varchar(20) NOT NULL,
         `ImporteCentimos` int NOT NULL,
         `MinCompraCentimos` int NOT NULL,
         `ValidoDesde` datetime NOT NULL,
         `ValidoHasta` datetime NOT NULL,
         `Usado` tinyint(1) NOT NULL DEFAULT 0,
         `TipoDocUso` varchar(1) NULL,
         `AlbaranUso` int NULL,
         PRIMARY KEY (`Id`),
         KEY `ix_fid_vales_club_cliente` (`Cliente`, `Usado`, `ValidoHasta`)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $pdo->exec(
      'CREATE TABLE IF NOT EXISTS `ClubPromociones` (
         `Id` int NOT NULL AUTO_INCREMENT,
         `Empresa` varchar(3) NOT NULL,
         `Nombre` varchar(80) NOT NULL,
         `PjeDescuento` double NOT NULL,
         `FechaInicio` datetime NOT NULL,
         `FechaFin` datetime NOT NULL,
         `FiltrosJson` text NULL,
         `Activa` tinyint(1) NOT NULL DEFAULT 1,
         PRIMARY KEY (`Id`)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
  }

  private static function columnasClientesMysql(PDO $pdo): void
  {
    foreach ([
      'FechaAltaClub' => 'datetime NULL',
      'ConsentimientoEmailClub' => 'tinyint(1) NOT NULL DEFAULT 0',
      'ConsentimientoWhatsAppClub' => 'tinyint(1) NOT NULL DEFAULT 0',
      'ConsentimientoClubEn' => 'datetime NULL',
    ] as $col => $def) {
      if (!self::columnExists($pdo, 'Clientes', $col)) {
        $pdo->exec("ALTER TABLE `Clientes` ADD COLUMN `{$col}` {$def}");
      }
    }
  }

  private static function columnasAlbaranesMysql(PDO $pdo): void
  {
    foreach ([
      'ClubSaldoGeneradoCentimos' => 'int NOT NULL DEFAULT 0',
      'ClubSaldoCanjeadoCentimos' => 'int NOT NULL DEFAULT 0',
      'ClubPromoId' => 'int NULL',
      'ClubModoIncompatible' => 'tinyint(1) NOT NULL DEFAULT 0',
    ] as $col => $def) {
      if (!self::columnExists($pdo, 'AlbaranesVentasCab', $col)) {
        $pdo->exec("ALTER TABLE `AlbaranesVentasCab` ADD COLUMN `{$col}` {$def}");
      }
    }
  }

  private static function semillaTipoCalculoMysql(PDO $pdo): void
  {
    if (!self::tableExists($pdo, 'TiposCalculoFidelizacion')) {
      return;
    }
    $stmt = $pdo->prepare(
      'SELECT 1 FROM TiposCalculoFidelizacion WHERE TRIM(Codigo) = :c LIMIT 1'
    );
    $stmt->execute(['c' => 'CLUB_CA_AGUSTI']);
    if ($stmt->fetchColumn()) {
      return;
    }
    $cfg = '{"pjeAcumulacion":2.5,"caducidadMeses":12,"minSaldoCanjeVisibleEuros":1,'
      . '"bienvenida":{"importe":5,"minCompraElegible":30,"diasValidez":30},'
      . '"cumpleanos":{"importe":5,"minCompraElegible":30,"diasAltaMinimos":30},'
      . '"promoIncompatiblePct":10,"exclusiones":[]}';
    $ins = $pdo->prepare(
      'INSERT INTO TiposCalculoFidelizacion (Codigo, Nombre, Motor, Factor, Configuracion, Baja)
       VALUES (:c, :n, :m, 1, :cfg, 0)'
    );
    $ins->execute([
      'c' => 'CLUB_CA_AGUSTI',
      'n' => 'Club Ca l\'Agustí (saldo 2,5%)',
      'm' => 'CLUB_SALDO',
      'cfg' => $cfg,
    ]);
  }

  private static function tableExists(PDO $pdo, string $table): bool
  {
    $schema = trim((string) $pdo->query('SELECT DATABASE()')->fetchColumn());
    $stmt = $pdo->prepare(
      'SELECT 1 FROM information_schema.TABLES
       WHERE TABLE_SCHEMA = :s AND TABLE_NAME = :t'
    );
    $stmt->execute(['s' => $schema, 't' => $table]);

    return (bool) $stmt->fetchColumn();
  }

  private static function columnExists(PDO $pdo, string $table, string $column): bool
  {
    $schema = trim((string) $pdo->query('SELECT DATABASE()')->fetchColumn());
    $stmt = $pdo->prepare(
      'SELECT 1 FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA = :s AND TABLE_NAME = :t AND COLUMN_NAME = :c'
    );
    $stmt->execute(['s' => $schema, 't' => $table, 'c' => $column]);

    return (bool) $stmt->fetchColumn();
  }
}
