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
      ['AlbaranesVentasCab', 'ImporteDtoFidelizacion', 'DOUBLE NOT NULL DEFAULT 0', 'FLOAT NOT NULL DEFAULT 0'],
      ['AlbaranesVentasCab', 'PuntosFidelizacionCompra', 'DOUBLE NOT NULL DEFAULT 0', 'FLOAT NOT NULL DEFAULT 0'],
      ['AlbaranesVentasCab', 'PuntosFidelizacionAcumulados', 'DOUBLE NOT NULL DEFAULT 0', 'FLOAT NOT NULL DEFAULT 0'],
      ['AlbaranesVentasLin', 'Anotacion', 'VARCHAR(80) NULL', 'NVARCHAR(80) NULL'],
      ['Articulos', 'LlevaSobre', 'TINYINT NULL DEFAULT 0', 'BIT NULL DEFAULT 0'],
      ['Articulos', 'SobresPorUnidad', 'INT NULL DEFAULT 1', 'INT NULL DEFAULT 1'],
      ['Puestos', 'ImpresoraEtiquetasEncargo', 'VARCHAR(100) NULL', 'NVARCHAR(100) NULL'],
      ['Puestos', 'FormatoEtiquetasEncargo', 'VARCHAR(100) NULL', 'NVARCHAR(100) NULL'],
      // Vendedor y usuario del puesto: en bases recuperadas de SQL Server no existen y el guardado los omitia en silencio.
      ['Puestos', 'Trabajador', 'VARCHAR(4) NULL', 'NVARCHAR(4) NULL'],
      ['Puestos', 'Usuario', 'VARCHAR(6) NULL', 'NVARCHAR(6) NULL'],
      // Otras tiendas en las que puede trabajar este puesto, ademas de la de arqueo.
      ['Puestos', 'TiendasExtra', 'VARCHAR(200) NULL', 'NVARCHAR(200) NULL'],
      // Otras tiendas en las que puede trabajar este usuario, ademas de la de arqueo del puesto.
      ['Usuarios_Ges', 'TiendasExtra', 'VARCHAR(200) NULL', 'NVARCHAR(200) NULL'],
      // Idioma por defecto (ca | es | en) y color de base del usuario (migracion 030).
      ['Usuarios_Ges', 'Idioma', 'VARCHAR(5) NULL', 'NVARCHAR(5) NULL'],
      ['Usuarios_Ges', 'ColorTema', 'VARCHAR(20) NULL', 'NVARCHAR(20) NULL'],
      ['Usuarios_Ges', 'AyudasMenu', 'TINYINT NULL DEFAULT 1', 'BIT NULL'],
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
    self::asegurarPagosACuentaPedido($pdo, $driver);
    self::asegurarConfigInstalacion($pdo, $driver);
    self::asegurarCodigosJerarquiaTexto($pdo, $driver);
    self::$comprobado = true;
  }

  /**
   * Codigos de la jerarquia de articulos que en MySQL eran numericos (y perdian los ceros
   * de delante: 001 quedaba como 1). En SQL Server ya son texto.
   *
   * @var array<string, list<string>>
   */
  private const CODIGOS_JERARQUIA_TEXTO = [
    'Agrupaciones' => ['Codigo'],
    'MacroFamilias' => ['Codigo'],
    'Familias' => ['Codigo', 'MacroFamilia'],
    'Subfamilias' => ['Subfamilia', 'Familia'],
    'Articulos' => ['Agrupacion', 'Familia', 'Subfamilia'],
    'ListadoTarifas01' => ['Agrupacion', 'Familia', 'SubFamilia'],
  ];

  private const TIPOS_NUMERICOS_MYSQL = ['tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'double', 'float'];

  /**
   * Convierte a VARCHAR(6) las columnas numericas de la jerarquia de articulos (solo MySQL).
   * Los valores se conservan ("1" sigue siendo "1"); a partir de aqui admiten ceros delante.
   * Si algo falla se deja como estaba: el programa sigue funcionando con codigos numericos.
   */
  private static function asegurarCodigosJerarquiaTexto(PDO $pdo, string $driver): void
  {
    if ($driver !== 'mysql') {
      return;
    }
    try {
      $tablas = array_keys(self::CODIGOS_JERARQUIA_TEXTO);
      $marcas = implode(', ', array_fill(0, count($tablas), '?'));
      $stmt = $pdo->prepare(
        'SELECT TABLE_NAME AS tabla, COLUMN_NAME AS columna, DATA_TYPE AS tipo,
                IS_NULLABLE AS acepta_nulo, COLUMN_DEFAULT AS por_defecto
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $marcas . ')'
      );
      $stmt->execute($tablas);
      foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
        $tabla = (string) ($col['tabla'] ?? '');
        $columna = (string) ($col['columna'] ?? '');
        $tipo = strtolower((string) ($col['tipo'] ?? ''));
        if (!in_array($tipo, self::TIPOS_NUMERICOS_MYSQL, true)) {
          continue;
        }
        $deseadas = null;
        foreach (self::CODIGOS_JERARQUIA_TEXTO as $nombre => $columnas) {
          if (strcasecmp($nombre, $tabla) === 0) {
            $deseadas = $columnas;
            break;
          }
        }
        if ($deseadas === null || !in_array(strtolower($columna), array_map('strtolower', $deseadas), true)) {
          continue;
        }
        $nulo = strtoupper((string) ($col['acepta_nulo'] ?? 'YES')) === 'NO' ? 'NOT NULL' : 'NULL';
        // MariaDB devuelve el literal: NULL como texto y las cadenas entre comillas.
        $porDefecto = $col['por_defecto'] ?? null;
        if ($porDefecto !== null) {
          $porDefecto = trim((string) $porDefecto);
          if (preg_match("/^'(.*)'$/s", $porDefecto, $m) === 1) {
            $porDefecto = $m[1];
          } elseif (strcasecmp($porDefecto, 'NULL') === 0) {
            $porDefecto = null;
          }
        }
        $defecto = $porDefecto === null ? '' : ' DEFAULT ' . $pdo->quote((string) $porDefecto);
        $pdo->exec(
          'ALTER TABLE `' . $tabla . '` MODIFY `' . $columna . '` VARCHAR(6) ' . $nulo . $defecto
        );
      }
    } catch (\Throwable $e) {
      error_log('Codigos de jerarquia a texto: ' . $e->getMessage());
    }
  }

  /** Ajustes de toda la instalación (no de un PC): clave/valor. */
  private static function asegurarConfigInstalacion(PDO $pdo, string $driver): void
  {
    if (self::existeTabla($pdo, $driver, 'ConfigInstalacion')) {
      return;
    }
    if ($driver === 'mysql') {
      $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `ConfigInstalacion` (
          `Clave` VARCHAR(40) NOT NULL,
          `Valor` VARCHAR(500) NULL,
          `Actualizado` DATETIME NULL,
          PRIMARY KEY (`Clave`)
        )'
      );
      return;
    }
    $pdo->exec(
      'CREATE TABLE [ConfigInstalacion] (
        [Clave] NVARCHAR(40) NOT NULL,
        [Valor] NVARCHAR(500) NULL,
        [Actualizado] DATETIME NULL,
        CONSTRAINT [PK_ConfigInstalacion] PRIMARY KEY ([Clave])
      )'
    );
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

  private static function asegurarPagosACuentaPedido(PDO $pdo, string $driver): void
  {
    if (self::existeTabla($pdo, $driver, 'PagosACuentaPedido')) {
      return;
    }
    if ($driver === 'mysql') {
      $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `PagosACuentaPedido` (
          `Empresa` VARCHAR(10) NOT NULL,
          `Pedido` INT NOT NULL,
          `Nro` INT NOT NULL,
          `NroMov` INT NOT NULL,
          `Fecha` DATETIME NULL,
          `Importe` DECIMAL(18,2) NOT NULL,
          `FormaPago` VARCHAR(5) NULL,
          `Puesto` VARCHAR(10) NULL,
          `Sesion` INT NOT NULL,
          `EmpresaArqueo` VARCHAR(10) NULL,
          `Contabilizado` TINYINT NOT NULL DEFAULT 0,
          PRIMARY KEY (`Empresa`, `Pedido`, `Nro`, `NroMov`)
        )'
      );
      return;
    }
    $pdo->exec(
      'CREATE TABLE [PagosACuentaPedido] (
        [Empresa] NVARCHAR(10) NOT NULL,
        [Pedido] INT NOT NULL,
        [Nro] INT NOT NULL,
        [NroMov] INT NOT NULL,
        [Fecha] DATETIME NULL,
        [Importe] DECIMAL(18,2) NOT NULL,
        [FormaPago] NVARCHAR(5) NULL,
        [Puesto] NVARCHAR(10) NULL,
        [Sesion] INT NOT NULL,
        [EmpresaArqueo] NVARCHAR(10) NULL,
        [Contabilizado] BIT NOT NULL CONSTRAINT [DF_PagosACuentaPedido_Contab] DEFAULT 0,
        CONSTRAINT [PK_PagosACuentaPedido] PRIMARY KEY ([Empresa], [Pedido], [Nro], [NroMov])
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
