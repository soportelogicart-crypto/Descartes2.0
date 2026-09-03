<?php

declare(strict_types=1);

/**
 * Aplica migraciones pendientes (misma logica que el asistente web).
 * Uso: php scripts/migrate.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

use Descartes\Api\Config\Database;
use Descartes\Api\Config\InstalacionConfig;
use Descartes\Api\Services\Instalacion\MigrationService;

echo "=== Descartes 2.0 - migraciones ===\n\n";

$config = Database::resolveConfig();
$origen = InstalacionConfig::isConfigured() ? 'var/instalacion.json' : '.env';
echo "Origen: {$origen}\n";
echo "Servidor: {$config['server']}\n";
echo "BD:       {$config['database']}\n\n";

$service = new MigrationService();
$estado = $service->estado($config);

if (!$estado['conexionOk']) {
  echo "[ERROR] Conexion: {$estado['errorConexion']}\n";
  exit(1);
}

if ($estado['pendientes'] === []) {
  echo "[OK] Sin migraciones pendientes ({$estado['total']} registradas).\n";
  exit(0);
}

echo 'Pendientes: ' . implode(', ', $estado['pendientes']) . "\n\n";

$resultado = $service->aplicarPendientes($config);
foreach ($resultado['aplicadas'] as $file) {
  echo "[OK] {$file}\n";
}
foreach ($resultado['errores'] as $err) {
  echo "[ERROR] {$err}\n";
}

exit($resultado['ok'] ? 0 : 1);
