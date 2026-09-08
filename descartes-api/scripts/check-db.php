<?php

declare(strict_types=1);

/**
 * Diagnostico local (no exponer en produccion).
 * Uso: php scripts/check-db.php [codigo_usuario]
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

use Descartes\Api\Config\Database;

$codigo = $argv[1] ?? null;

echo "=== Descartes API - diagnostico BD ===\n\n";

try {
  $config = Database::resolveConfig();
  $pdo = Database::createPdo($config);
  echo "[OK] Conexion PDO a SQL Server\n";
  echo "     Servidor: " . $config['server'] . "\n";
  echo "     BD:       " . $config['database'] . "\n\n";
} catch (Throwable $e) {
  echo "[ERROR] No se pudo conectar a la BD\n";
  echo "        " . $e->getMessage() . "\n";
  exit(1);
}

$tables = ['Roles', 'RolPermisos', 'Usuarios_Ges'];
foreach ($tables as $table) {
  $stmt = $pdo->query("SELECT COUNT(*) AS n FROM [{$table}]");
  $n = (int) ($stmt->fetch()['n'] ?? 0);
  echo "[INFO] Tabla {$table}: {$n} filas\n";
}

$stmt = $pdo->query("SELECT COUNT(*) AS n FROM [Roles] WHERE [Codigo] = 'ADMIN'");
$hasAdminRole = (int) ($stmt->fetch()['n'] ?? 0) > 0;
echo $hasAdminRole ? "[OK] Rol ADMIN existe\n" : "[AVISO] Rol ADMIN no existe (ejecutar migracion)\n";

$stmt = $pdo->query(
  "SELECT [Codigo], [Nombre], [Rol], [Baja],
          CASE
            WHEN [PassWord] IS NULL OR LTRIM(RTRIM([PassWord])) = '' THEN 'vacio'
            WHEN [PassWord] LIKE '\$2y\$%' OR [PassWord] LIKE '\$2a\$%' THEN 'bcrypt'
            ELSE 'legacy_texto'
          END AS tipo_password,
          LEN([PassWord]) AS len_password
   FROM [Usuarios_Ges]
   ORDER BY [Codigo]"
);
echo "\n--- Usuarios_Ges ---\n";
printf("%-8s %-20s %-8s %-5s %-14s %s\n", 'Codigo', 'Nombre', 'Rol', 'Baja', 'TipoPass', 'Len');
while ($row = $stmt->fetch()) {
  printf(
    "%-8s %-20s %-8s %-5s %-14s %s\n",
    (string) $row['Codigo'],
    substr((string) ($row['Nombre'] ?? ''), 0, 20),
    (string) ($row['Rol'] ?? '(null)'),
    (string) $row['Baja'],
    (string) $row['tipo_password'],
    (string) $row['len_password']
  );
}

if ($codigo !== null) {
  echo "\n--- Prueba login (sin mostrar password) ---\n";
  $stmt = $pdo->prepare(
    'SELECT [Codigo], [PassWord], [Baja] FROM [Usuarios_Ges] WHERE [Codigo] = :codigo'
  );
  $stmt->execute(['codigo' => trim($codigo)]);
  $row = $stmt->fetch();
  if (!$row) {
    echo "[ERROR] No existe usuario con Codigo '{$codigo}'\n";
    exit(1);
  }
  if ((int) $row['Baja'] === 1) {
    echo "[ERROR] Usuario dado de baja (Baja=1)\n";
  } else {
    echo "[OK] Usuario activo\n";
  }
  $stored = (string) ($row['PassWord'] ?? '');
  if ($stored === '') {
    echo "[ERROR] PassWord vacio - no puede iniciar sesion\n";
  } elseif (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$2a$')) {
    echo "[INFO] Password en bcrypt. Probar con la contrasena que se uso al crear/resetear.\n";
    echo "       Para fijar 'admin123': php scripts/set-password.php {$codigo} admin123\n";
  } else {
    echo "[INFO] Password legacy (texto plano en BD). Debe coincidir EXACTAMENTE con PassWord.\n";
    echo "       O resetear: php scripts/set-password.php {$codigo} admin123\n";
  }
}

echo "\n=== Fin ===\n";
