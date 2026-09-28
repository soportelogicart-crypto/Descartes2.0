<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

use Descartes\Api\Config\Database;

// Uso: php diag-puesto-tienda.php <puesto> <empresa> [base1,base2]
// Comprueba lo que pide la impresion: /mantenimiento/puestos-trabajo/{p} y /tiendas/{e}.
$puesto = trim((string) ($argv[1] ?? '07'));
$empresa = trim((string) ($argv[2] ?? '1'));
$bases = array_values(array_filter(array_map('trim', explode(',', (string) ($argv[3] ?? '')))));

$base = Database::resolveConfig();
if ($bases === []) {
  $bases = [$base['database']];
}

foreach ($bases as $database) {
  $pdo = Database::createPdo(array_merge($base, ['database' => $database]));
  echo "=== {$database} ===" . PHP_EOL;

  // La API prueba el codigo tal cual y rellenado a 2 digitos ("7" -> "07").
  $variantes = array_values(array_unique([$puesto, str_pad($puesto, 2, '0', STR_PAD_LEFT)]));
  $ph = implode(',', array_fill(0, count($variantes), '?'));
  $st = $pdo->prepare(
    "SELECT LTRIM(RTRIM(Puesto)) AS Puesto, Descripcion, ISNULL(Baja, 0) AS Baja
     FROM Puestos WHERE LTRIM(RTRIM(Puesto)) IN ({$ph})"
  );
  $st->execute($variantes);
  $filas = $st->fetchAll(PDO::FETCH_ASSOC);
  if ($filas === []) {
    echo "  Puesto {$puesto}: NO EXISTE" . PHP_EOL;
    $todos = $pdo->query(
      "SELECT TOP 15 LTRIM(RTRIM(Puesto)) AS Puesto, Descripcion, ISNULL(Baja, 0) AS Baja
       FROM Puestos ORDER BY Puesto"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($todos as $row) {
      printf("    disponible: %-4s %-30s baja=%d%s", $row['Puesto'], (string) $row['Descripcion'], (int) $row['Baja'], PHP_EOL);
    }
  } else {
    foreach ($filas as $row) {
      printf("  Puesto %-4s %-30s baja=%d%s", $row['Puesto'], (string) $row['Descripcion'], (int) $row['Baja'], PHP_EOL);
    }
  }

  $st = $pdo->prepare(
    "SELECT LTRIM(RTRIM(Codigo)) AS Codigo, Nombre, ISNULL(Baja, 0) AS Baja
     FROM Empresas_Ges WHERE LTRIM(RTRIM(Codigo)) = ?"
  );
  $st->execute([$empresa]);
  $tienda = $st->fetch(PDO::FETCH_ASSOC);
  if ($tienda === false) {
    echo "  Tienda {$empresa}: NO EXISTE" . PHP_EOL;
    $todas = $pdo->query(
      "SELECT TOP 10 LTRIM(RTRIM(Codigo)) AS Codigo, Nombre, ISNULL(Baja, 0) AS Baja
       FROM Empresas_Ges ORDER BY Codigo"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($todas as $row) {
      printf("    disponible: %-4s %-30s baja=%d%s", $row['Codigo'], (string) $row['Nombre'], (int) $row['Baja'], PHP_EOL);
    }
  } else {
    printf("  Tienda %-4s %-30s baja=%d%s", $tienda['Codigo'], (string) $tienda['Nombre'], (int) $tienda['Baja'], PHP_EOL);
  }
  echo PHP_EOL;
}
