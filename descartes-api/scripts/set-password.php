<?php

declare(strict_types=1);

/**
 * Fija contrasena en texto plano para un usuario (solo dev).
 * Uso: php scripts/set-password.php CODIGO NUEVA_PASSWORD
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

use Descartes\Api\Config\Database;

if ($argc < 3) {
  fwrite(STDERR, "Uso: php scripts/set-password.php CODIGO NUEVA_PASSWORD\n");
  exit(1);
}

$codigo = trim($argv[1]);
$plain = $argv[2];

if ($codigo === '' || $plain === '') {
  fwrite(STDERR, "Codigo y password no pueden estar vacios\n");
  exit(1);
}

$pdo = Database::fromEnv();

$stmt = $pdo->prepare('SELECT 1 FROM [Usuarios_Ges] WHERE [Codigo] = :codigo');
$stmt->execute(['codigo' => $codigo]);
if (!$stmt->fetch()) {
  fwrite(STDERR, "No existe usuario con Codigo '{$codigo}'\n");
  exit(1);
}

$upd = $pdo->prepare(
  "UPDATE [Usuarios_Ges] SET [PassWord] = :password, [Baja] = 0, [Rol] = COALESCE(NULLIF([Rol], ''), 'ADMIN') WHERE [Codigo] = :codigo"
);
$upd->execute(['password' => $plain, 'codigo' => $codigo]);

echo "Password actualizado para '{$codigo}' (texto plano). Rol ADMIN si estaba vacio.\n";
