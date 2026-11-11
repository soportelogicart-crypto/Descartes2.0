<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Instalacion;

use PDO;

/**
 * Columnas de las mejoras de gestión. Idempotente en MySQL y SQL Server.
 */
final class MejorasGestionSchema
{
  private static bool $comprobado = false;

  public static function asegurar(PDO $pdo): void
  {
    if (self::$comprobado) {
      return;
    }
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $columnas = [
      ['Clientes', 'EmailAlbaranes', 'VARCHAR(200) NULL', 'NVARCHAR(200) NULL'],
      ['PedidosClientesLin', 'Anotacion', 'VARCHAR(80) NULL', 'NVARCHAR(80) NULL'],
      ['PedidosClientesLin', 'Transportista', 'VARCHAR(25) NULL', 'NVARCHAR(25) NULL'],
      ['AlbaranesVentasCab', 'ClienteFinal', 'VARCHAR(80) NULL', 'NVARCHAR(80) NULL'],
      ['AlbaranesVentasCab', 'Comisionista', 'VARCHAR(40) NULL', 'NVARCHAR(40) NULL'],
      ['AlbaranesVentasCab', 'PjeComision', 'DECIMAL(9,2) NULL', 'DECIMAL(9,2) NULL'],
      ['AlbaranesVentasLin', 'Anotacion', 'VARCHAR(80) NULL', 'NVARCHAR(80) NULL'],
      ['Articulos', 'LlevaSobre', 'TINYINT NULL DEFAULT 0', 'BIT NULL DEFAULT 0'],
      ['Articulos', 'SobresPorUnidad', 'INT NULL DEFAULT 1', 'INT NULL DEFAULT 1'],
      ['Puestos', 'ImpresoraEtiquetasEncargo', 'VARCHAR(100) NULL', 'NVARCHAR(100) NULL'],
      ['Puestos', 'FormatoEtiquetasEncargo', 'VARCHAR(100) NULL', 'NVARCHAR(100) NULL'],
    ];
    foreach ($columnas as [$tabla, $columna, $mysql, $sqlsrv]) {
      if (self::existe($pdo, $driver, $tabla, $columna)) {
        continue;
      }
      if ($driver === 'mysql') {
        $pdo->exec('ALTER TABLE `' . $tabla . '` ADD COLUMN `' . $columna . '` ' . $mysql);
      } else {
        $pdo->exec('ALTER TABLE [' . $tabla . '] ADD [' . $columna . '] ' . $sqlsrv);
      }
    }
    self::asegurarPagosACuenta($pdo, $driver);
    self::$comprobado = true;
  }

  private static function asegurarPagosACuenta(PDO $pdo, string $driver): void
  {
    if (self::existeTabla($pdo, $driver, 'PagosACuentaAlbaran')) {
      return;
    }
    if ($driver === 'mysql') {
      $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `PagosACuentaAlbaran` (
          `Empresa` VARCHAR(10) NOT NULL,
          `Tipo` VARCHAR(1) NOT NULL,
          `Albaran` INT NOT NULL,
          `Nro` INT NOT NULL,
          `NroMov` INT NOT NULL,
          `Fecha` DATETIME NULL,
          `Importe` DECIMAL(18,2) NOT NULL,
          `FormaPago` VARCHAR(5) NULL,
          `Puesto` VARCHAR(10) NULL,
          `Sesion` INT NOT NULL,
          `EmpresaArqueo` VARCHAR(10) NULL,
          `Contabilizado` TINYINT NOT NULL DEFAULT 0,
          PRIMARY KEY (`Empresa`, `Tipo`, `Albaran`, `Nro`, `NroMov`)
        )'
      );
      return;
    }
    $pdo->exec(
      'CREATE TABLE [PagosACuentaAlbaran] (
        [Empresa] NVARCHAR(10) NOT NULL,
        [Tipo] NVARCHAR(1) NOT NULL,
        [Albaran] INT NOT NULL,
        [Nro] INT NOT NULL,
        [NroMov] INT NOT NULL,
        [Fecha] DATETIME NULL,
        [Importe] DECIMAL(18,2) NOT NULL,
        [FormaPago] NVARCHAR(5) NULL,
        [Puesto] NVARCHAR(10) NULL,
        [Sesion] INT NOT NULL,
        [EmpresaArqueo] NVARCHAR(10) NULL,
        [Contabilizado] BIT NOT NULL CONSTRAINT [DF_PagosACuentaAlbaran_Contab] DEFAULT 0,
        CONSTRAINT [PK_PagosACuentaAlbaran] PRIMARY KEY ([Empresa], [Tipo], [Albaran], [Nro], [NroMov])
      )'
    );
  }

  private static function existeTabla(PDO $pdo, string $driver, string $tabla): bool
  {
    if ($driver === 'mysql') {
      $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
      );
    } else {
      $stmt = $pdo->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = :t'
      );
    }
    $stmt->execute(['t' => $tabla]);

    return (bool) $stmt->fetchColumn();
  }

  private static function existe(PDO $pdo, string $driver, string $tabla, string $columna): bool
  {
    if ($driver === 'mysql') {
      $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
      );
    } else {
      $stmt = $pdo->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_NAME = :t AND COLUMN_NAME = :c'
      );
    }
    $stmt->execute(['t' => $tabla, 'c' => $columna]);

    return (bool) $stmt->fetchColumn();
  }
}
