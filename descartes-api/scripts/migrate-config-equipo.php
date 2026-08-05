<?php

declare(strict_types=1);

/**
 * Aplica db/migrations/002-config-equipo.sql
 * Uso: php scripts/migrate-config-equipo.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

use Descartes\Api\Config\Database;

echo "=== Migracion ConfigEquipo ===\n";

try {
  $pdo = Database::fromEnv();
} catch (Throwable $e) {
  echo "[ERROR] Conexion: " . $e->getMessage() . "\n";
  exit(1);
}

$sql = <<<'SQL'
IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = 'ConfigEquipo' AND schema_id = SCHEMA_ID('dbo'))
BEGIN
    CREATE TABLE [dbo].[ConfigEquipo] (
        [EquipoId]      nvarchar(50)  NOT NULL,
        [EmpresaCodigo] nvarchar(3)   NOT NULL,
        [PuestoCodigo]  nvarchar(2)   NOT NULL,
        [Actualizado]   datetime      NOT NULL CONSTRAINT DF_ConfigEquipo_Actualizado DEFAULT (GETDATE()),
        CONSTRAINT [PK_ConfigEquipo] PRIMARY KEY CLUSTERED ([EquipoId])
    );
END
SQL;

try {
  $pdo->exec($sql);
  $exists = (int) $pdo->query(
    "SELECT COUNT(*) FROM sys.tables WHERE name = 'ConfigEquipo' AND schema_id = SCHEMA_ID('dbo')"
  )->fetchColumn();
  echo $exists ? "[OK] Tabla ConfigEquipo disponible\n" : "[ERROR] Tabla no creada\n";
  exit($exists ? 0 : 1);
} catch (Throwable $e) {
  echo "[ERROR] " . $e->getMessage() . "\n";
  exit(1);
}
