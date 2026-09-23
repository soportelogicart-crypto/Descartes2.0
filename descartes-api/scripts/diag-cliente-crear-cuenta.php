<?php

declare(strict_types=1);

/**
 * Prueba del alta de cuenta contable de cliente.
 * Por defecto deshace lo creado (la BD queda igual que antes).
 * Uso: php scripts/diag-cliente-crear-cuenta.php 001001261 [--conservar]
 */

require dirname(__DIR__) . '/vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

use Descartes\Api\Config\Database;
use Descartes\Api\Services\ClienteCuentaContableService;

$codigo = trim((string) ($argv[1] ?? ''));
$conservar = in_array('--conservar', array_slice($argv, 1), true);
if ($codigo === '') {
  fwrite(STDERR, "Indique el codigo de cliente\n");
  exit(1);
}

$pdo = Database::fromEnv();
$service = new ClienteCuentaContableService($pdo);

echo "=== ESTADO INICIAL ===\n";
echo json_encode($service->estado($codigo), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

echo "\n=== CREAR ===\n";
try {
  $res = $service->crear($codigo);
  echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} catch (\Throwable $e) {
  echo 'ERROR (' . $e->getCode() . '): ' . $e->getMessage() . "\n";
  exit(1);
}

$cuenta = (string) $res['cuenta'];

echo "\n=== FILA EN Cuentas ===\n";
$st = $pdo->prepare('SELECT * FROM Cuentas WHERE RTRIM(Codigo) = :c');
$st->execute(['c' => $cuenta]);
echo json_encode($st->fetch(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

echo "\n=== ESTADO FINAL ===\n";
echo json_encode($service->estado($codigo), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

echo "\n=== SEGUNDO INTENTO (debe fallar con 409) ===\n";
try {
  $service->crear($codigo);
  echo "NO FALLO (revisar)\n";
} catch (\Throwable $e) {
  echo 'codigo=' . $e->getCode() . ' mensaje=' . $e->getMessage() . "\n";
}

if ($conservar) {
  echo "\n(--conservar: se deja la cuenta creada)\n";
  exit(0);
}

echo "\n=== DESHACER ===\n";
if ($res['creada']) {
  $pdo->prepare('DELETE FROM Cuentas WHERE RTRIM(Codigo) = :c')->execute(['c' => $cuenta]);
  echo "Cuenta {$cuenta} borrada\n";
}
$pdo->prepare('UPDATE Clientes SET CuentaCtb2 = 0 WHERE RTRIM(Codigo) = :c')->execute(['c' => $codigo]);
echo "Cliente {$codigo} desenlazado\n";
echo json_encode($service->estado($codigo), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
