<?php

declare(strict_types=1);

/**
 * Smoke SC-002 (005 T025): lote ≥ 10 líneas → confirmar impresión → cola vacía
 * de lo impreso; cantidades respetadas; impresora de puesto (`ImpresoraEtiquetas`).
 *
 * La impresión física (Electron printLabel) se valida manualmente; este script
 * cubre el contrato API: persistir cantidades + POST/confirmar DELETE.
 *
 * Uso: php scripts/smoke-etiquetas-imprimir.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

use Descartes\Api\Config\Database;
use Descartes\Api\Services\Compras\AlbaranCompraConsultaService;
use Descartes\Api\Services\Etiquetas\EtiquetaColaService;

echo "=== Smoke SC-002 (005 T025) ===\n\n";

try {
  $pdo = Database::fromEnv();
} catch (Throwable $e) {
  echo "[FAIL] Conexion BD: {$e->getMessage()}\n";
  exit(1);
}

$puestoCodigo = '01';

// —— Impresora de puesto (ImpresoraEtiquetas) ——
$impresora = null;
try {
  $stmt = $pdo->prepare(
    'SELECT TOP 1 RTRIM(Puesto) AS Codigo,
            RTRIM(ISNULL(ImpresoraEtiquetas, \'\')) AS ImpresoraEtiquetas
     FROM [Puestos]
     WHERE RTRIM(Puesto) = :codigo'
  );
  $stmt->execute(['codigo' => $puestoCodigo]);
  $puestoRow = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($puestoRow === false) {
    // Buscar cualquier puesto con impresora etiquetas
    $any = $pdo->query(
      "SELECT TOP 1 RTRIM(Puesto) AS Codigo,
              RTRIM(ISNULL(ImpresoraEtiquetas, '')) AS ImpresoraEtiquetas
       FROM [Puestos]
       WHERE RTRIM(ISNULL(ImpresoraEtiquetas, '')) <> ''
       ORDER BY Puesto"
    )->fetch(PDO::FETCH_ASSOC);
    if ($any) {
      $puestoCodigo = trim((string) $any['Codigo']);
      $impresora = trim((string) $any['ImpresoraEtiquetas']);
      echo "[OK] Puesto {$puestoCodigo} con ImpresoraEtiquetas={$impresora}\n";
    } else {
      echo "[AVISO] Ningún puesto tiene ImpresoraEtiquetas configurada ";
      echo "(la UI de impresión fallará hasta configurarla en Generales II)\n";
      $fallback = $pdo->query(
        "SELECT TOP 1 RTRIM(Puesto) AS Codigo FROM [Puestos] ORDER BY Puesto"
      )->fetchColumn();
      if ($fallback) {
        $puestoCodigo = trim((string) $fallback);
      }
    }
  } else {
    $impresora = trim((string) ($puestoRow['ImpresoraEtiquetas'] ?? ''));
    if ($impresora !== '') {
      echo "[OK] Puesto {$puestoCodigo} ImpresoraEtiquetas={$impresora}\n";
    } else {
      echo "[AVISO] Puesto {$puestoCodigo} sin ImpresoraEtiquetas (columna OK, valor vacío)\n";
    }
  }
} catch (Throwable $e) {
  echo "[FAIL] No se pudo leer ImpresoraEtiquetas (¿columna existe?): {$e->getMessage()}\n";
  exit(1);
}

// —— Artículos ——
$arts = $pdo->query(
  'SELECT TOP 10 RTRIM(Codigo) AS Codigo FROM [Articulos] ORDER BY Codigo'
)->fetchAll(PDO::FETCH_COLUMN);

if (count($arts) < 1) {
  echo "[FAIL] No hay artículos en BD\n";
  exit(1);
}

$arts = array_map(static fn ($c) => trim((string) $c), $arts);
while (count($arts) < 10) {
  $arts[] = $arts[count($arts) % max(1, count($arts))];
}
$arts = array_slice($arts, 0, 10);

$cola = new EtiquetaColaService($pdo, new AlbaranCompraConsultaService($pdo));
/** Cantidades distintas para verificar que se respetan en cola. */
$cantidades = [1, 2, 3, 4, 5, 1, 2, 3, 4, 5];
$creadas = [];
$ok = false;

try {
  for ($i = 0; $i < 10; $i++) {
    $item = $cola->crear([
      'articulo' => $arts[$i],
      'cantidad' => $cantidades[$i],
      'puesto' => $puestoCodigo,
      'descripcion' => 'SMOKE-T025-' . ($i + 1),
    ]);
    $creadas[] = $item;
    if ((int) $item['cantidad'] !== $cantidades[$i]) {
      throw new RuntimeException(
        "Cantidad persistida incorrecta línea " . ($i + 1) .
        ": esperaba {$cantidades[$i]}, got {$item['cantidad']}"
      );
    }
  }
  echo sprintf("[OK] Creadas %d líneas (cantidades %s)\n", count($creadas), implode('/', $cantidades));

  $list = $cola->listar(['puesto' => $puestoCodigo, 'page' => 1, 'pageSize' => 500]);
  $keys = [];
  foreach ($list['items'] as $row) {
    $keys[trim((string) $row['articulo']) . '|' . (int) $row['nroLin']] = $row;
  }
  foreach ($creadas as $i => $c) {
    $k = trim((string) $c['articulo']) . '|' . (int) $c['nroLin'];
    if (!isset($keys[$k])) {
      throw new RuntimeException("Línea {$k} no aparece en listar");
    }
    if ((int) $keys[$k]['cantidad'] !== $cantidades[$i]) {
      throw new RuntimeException(
        "Listar cantidad distinta en {$k}: " . $keys[$k]['cantidad'] . " vs {$cantidades[$i]}"
      );
    }
  }
  echo "[OK] Listar: 10 líneas visibles con cantidades correctas\n";

  // Parcial: confirmar 7 primeras → deben desaparecer; 3 restantes siguen
  $parcial = array_slice($creadas, 0, 7);
  $restantes = array_slice($creadas, 7);
  $refs = array_map(
    static fn (array $c): array => [
      'articulo' => $c['articulo'],
      'nroLin' => (int) $c['nroLin'],
    ],
    $parcial
  );
  $res = $cola->confirmarImpresion($refs);
  if ((int) ($res['eliminadas'] ?? 0) !== 7) {
    throw new RuntimeException(
      'confirmarImpresion parcial: esperaba eliminadas=7, got ' . ($res['eliminadas'] ?? 'null')
    );
  }
  echo "[OK] Confirmar parcial: eliminadas=7\n";

  foreach ($parcial as $c) {
    if ($cola->obtener($c['articulo'], (int) $c['nroLin']) !== null) {
      throw new RuntimeException(
        "Línea impresa sigue en cola: {$c['articulo']}/{$c['nroLin']}"
      );
    }
  }
  foreach ($restantes as $c) {
    if ($cola->obtener($c['articulo'], (int) $c['nroLin']) === null) {
      throw new RuntimeException(
        "Línea no impresa desapareció: {$c['articulo']}/{$c['nroLin']}"
      );
    }
  }
  echo "[OK] Cola: solo quedan las 3 no confirmadas\n";

  // Completar lote: vaciar el resto
  $refsRest = array_map(
    static fn (array $c): array => [
      'articulo' => $c['articulo'],
      'nroLin' => (int) $c['nroLin'],
    ],
    $restantes
  );
  $res2 = $cola->confirmarImpresion($refsRest);
  if ((int) ($res2['eliminadas'] ?? 0) !== 3) {
    throw new RuntimeException(
      'confirmarImpresion resto: esperaba eliminadas=3, got ' . ($res2['eliminadas'] ?? 'null')
    );
  }

  foreach ($creadas as $c) {
    if ($cola->obtener($c['articulo'], (int) $c['nroLin']) !== null) {
      throw new RuntimeException(
        "Tras lote completo sigue en cola: {$c['articulo']}/{$c['nroLin']}"
      );
    }
  }
  echo "[OK] Lote completo: cola vacía de las 10 líneas impresas\n";

  // Idempotencia: re-confirmar no falla y eliminadas=0
  $res3 = $cola->confirmarImpresion($refs);
  if ((int) ($res3['eliminadas'] ?? -1) !== 0) {
    throw new RuntimeException('Re-confirmar debería eliminar 0');
  }
  echo "[OK] Re-confirmar mismo lote: eliminadas=0\n";

  if ($impresora !== null && $impresora !== '') {
    echo "[OK] ImpresoraEtiquetas del puesto lista para Electron printLabel\n";
  } else {
    echo "[AVISO] Configure ImpresoraEtiquetas en el puesto para impresión física\n";
  }

  echo "\n[PASS] SC-002: lote ≥10 respeta cantidades; cola vacía de lo confirmado\n";
  $ok = true;
  $creadas = []; // ya borradas por confirmar
} catch (Throwable $e) {
  echo "[FAIL] {$e->getMessage()}\n";
  $ok = false;
}

if ($creadas !== []) {
  echo "\n--- Limpieza residual ---\n";
  foreach ($creadas as $c) {
    try {
      $cola->eliminar($c['articulo'], (int) $c['nroLin']);
      echo "[OK] Eliminada {$c['articulo']}/{$c['nroLin']}\n";
    } catch (Throwable $e) {
      echo "[AVISO] No se pudo eliminar {$c['articulo']}/{$c['nroLin']}: {$e->getMessage()}\n";
    }
  }
}

echo "\nManual UI (Electron): Etiquetas → 10 líneas → Preview → Imprimir → cola vacía;\n";
echo "  comprobar que sale por ImpresoraEtiquetas del puesto.\n";

exit($ok ? 0 : 1);
