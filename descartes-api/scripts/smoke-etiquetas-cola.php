<?php

declare(strict_types=1);

/**
 * Smoke SC-cola (005 T014): 3 líneas con cantidades distintas persisten.
 * Uso: php scripts/smoke-etiquetas-cola.php
 * Limpia las líneas creadas al final.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

use Descartes\Api\Config\Database;
use Descartes\Api\Services\Compras\AlbaranCompraConsultaService;
use Descartes\Api\Services\Etiquetas\EtiquetaColaService;

echo "=== Smoke SC-cola (005 T014) ===\n\n";

try {
  $pdo = Database::fromEnv();
} catch (Throwable $e) {
  echo "[FAIL] Conexion BD: {$e->getMessage()}\n";
  exit(1);
}

$arts = $pdo->query(
  "SELECT TOP 3 RTRIM(Codigo) AS Codigo
   FROM Articulos
   ORDER BY Codigo"
)->fetchAll(PDO::FETCH_COLUMN);

if (count($arts) < 3) {
  // Reutilizar el mismo artículo en 3 líneas si hay pocos
  $one = $pdo->query(
    "SELECT TOP 1 RTRIM(Codigo) AS Codigo FROM Articulos"
  )->fetchColumn();
  if (!$one) {
    echo "[FAIL] No hay artículos en BD para la prueba\n";
    exit(1);
  }
  $arts = [trim((string) $one), trim((string) $one), trim((string) $one)];
} else {
  $arts = array_map(static fn ($c) => trim((string) $c), $arts);
}

$cola = new EtiquetaColaService($pdo, new AlbaranCompraConsultaService($pdo));
$cantidades = [1, 3, 5];
$creadas = [];

try {
  for ($i = 0; $i < 3; $i++) {
    $item = $cola->crear([
      'articulo' => $arts[$i],
      'cantidad' => $cantidades[$i],
      'puesto' => '01',
      'descripcion' => 'SMOKE-T014-' . ($i + 1),
    ]);
    $creadas[] = $item;
    echo sprintf(
      "[OK] Creada %s nroLin=%d cantidad=%d\n",
      $item['articulo'],
      $item['nroLin'],
      $item['cantidad']
    );
    if ((int) $item['cantidad'] !== $cantidades[$i]) {
      throw new RuntimeException(
        "Cantidad persistida incorrecta: esperaba {$cantidades[$i]}, got {$item['cantidad']}"
      );
    }
  }

  $list = $cola->listar(['puesto' => '01', 'page' => 1, 'pageSize' => 500]);
  $byNro = [];
  foreach ($list['items'] as $row) {
    $byNro[(int) $row['nroLin']] = $row;
  }

  foreach ($creadas as $i => $c) {
    $nro = (int) $c['nroLin'];
    if (!isset($byNro[$nro])) {
      throw new RuntimeException("Línea nroLin={$nro} no aparece en listar");
    }
    if ((int) $byNro[$nro]['cantidad'] !== $cantidades[$i]) {
      throw new RuntimeException(
        "Listar: nroLin={$nro} cantidad=" . $byNro[$nro]['cantidad'] . " esperaba {$cantidades[$i]}"
      );
    }
  }
  echo "[OK] Listar: 3 líneas con cantidades 1/3/5 visibles\n";

  $primera = $creadas[0];
  $upd = $cola->actualizar($primera['articulo'], (int) $primera['nroLin'], ['cantidad' => 7]);
  if ((int) $upd['cantidad'] !== 7) {
    throw new RuntimeException('Actualizar cantidad falló');
  }
  $re = $cola->obtener($primera['articulo'], (int) $primera['nroLin']);
  if ($re === null || (int) $re['cantidad'] !== 7) {
    throw new RuntimeException('Releer tras actualizar falló');
  }
  echo "[OK] Actualizar cantidad 1→7 persistido\n";

  echo "\n[PASS] SC-cola: 3 líneas con cantidades distintas persisten\n";
  $ok = true;
} catch (Throwable $e) {
  echo "[FAIL] {$e->getMessage()}\n";
  $ok = false;
}

echo "\n--- Limpieza ---\n";
foreach ($creadas as $c) {
  try {
    $cola->eliminar($c['articulo'], (int) $c['nroLin']);
    echo "[OK] Eliminada {$c['articulo']}/{$c['nroLin']}\n";
  } catch (Throwable $e) {
    echo "[AVISO] No se pudo eliminar {$c['articulo']}/{$c['nroLin']}: {$e->getMessage()}\n";
  }
}

exit($ok ? 0 : 1);
