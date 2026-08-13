<?php

declare(strict_types=1);

/**
 * Smoke SC-006 (005 T033): albarán compra ≥ 2 líneas → generar etiquetas → cola ≥ 2
 * → confirmar impresión (DELETE) → cola vacía de lo generado.
 *
 * Impresión física: manual (Electron). API cubre encolar + vaciar tras OK.
 *
 * Uso: php scripts/smoke-etiquetas-albaran.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

use Descartes\Api\Config\Database;
use Descartes\Api\Services\Compras\AlbaranCompraConsultaService;
use Descartes\Api\Services\Etiquetas\EtiquetaColaService;

echo "=== Smoke SC-006 (005 T033) — albarán → cola → imprimir ===\n\n";

try {
  $pdo = Database::fromEnv();
} catch (Throwable $e) {
  echo "[FAIL] Conexion: {$e->getMessage()}\n";
  exit(1);
}

$consulta = new AlbaranCompraConsultaService($pdo);
$cola = new EtiquetaColaService($pdo, $consulta);
$puestoCodigo = '01';

// Impresora etiquetas del puesto
$impRow = $pdo->query(
  "SELECT TOP 1 RTRIM(Puesto) AS Puesto,
          RTRIM(ISNULL(ImpresoraEtiquetas, '')) AS ImpresoraEtiquetas
   FROM [Puestos]
   WHERE RTRIM(ISNULL(ImpresoraEtiquetas, '')) <> ''
   ORDER BY CASE WHEN RTRIM(Puesto) = '01' THEN 0 ELSE 1 END, Puesto"
)->fetch(PDO::FETCH_ASSOC);
if ($impRow) {
  $puestoCodigo = trim((string) $impRow['Puesto']);
  echo "[OK] Puesto {$puestoCodigo} ImpresoraEtiquetas=" . trim((string) $impRow['ImpresoraEtiquetas']) . "\n";
} else {
  echo "[AVISO] Sin ImpresoraEtiquetas en puestos (impresión física fallará en UI)\n";
}

// Candidatos: albaranes con ≥ 2 líneas de artículo (más recientes primero)
$candidatos = $pdo->query(
  "SELECT TOP 20 c.Empresa, c.Albaran, COUNT(*) AS NLineas
   FROM AlbaranesCompraCab c
   INNER JOIN AlbaranesComprasLin l
     ON l.Empresa = c.Empresa AND l.Albaran = c.Albaran
   WHERE RTRIM(ISNULL(l.Articulo, '')) <> ''
   GROUP BY c.Empresa, c.Albaran
   HAVING COUNT(*) >= 2
   ORDER BY c.Albaran DESC"
)->fetchAll(PDO::FETCH_ASSOC);

if ($candidatos === []) {
  echo "[FAIL] No hay albarán de compra con ≥ 2 líneas de artículo\n";
  exit(1);
}

$creadas = [];
$ok = false;
$empresa = '';
$albaran = 0;

try {
  $elegido = null;
  $ultimoOmit = 0;

  foreach ($candidatos as $cand) {
    $emp = trim((string) $cand['Empresa']);
    $alb = (int) $cand['Albaran'];

    // Limpieza previa por si un intento anterior dejó basura (no debería)
    $probe = $cola->desdeAlbaranCompra([
      'empresa' => $emp,
      'albaran' => $alb,
      'puesto' => $puestoCodigo,
    ]);
    $n = count($probe['items'] ?? []);
    $omit = (int) ($probe['omitidas'] ?? 0);
    $ultimoOmit = $omit;

    if ($n >= 2) {
      $elegido = ['empresa' => $emp, 'albaran' => $alb, 'nLineas' => (int) $cand['NLineas']];
      $creadas = $probe['items'];
      break;
    }

    // Deshacer intento insuficiente
    foreach ($probe['items'] ?? [] as $item) {
      try {
        $cola->eliminar($item['articulo'], (int) $item['nroLin']);
      } catch (Throwable $e) {
        /* ignore */
      }
    }
  }

  if ($elegido === null) {
    throw new RuntimeException(
      "Ningún albarán generó ≥ 2 etiquetas (flags EAN / ArtBarras). " .
      "Último omitidas={$ultimoOmit}. Configure EAN o ImpEtiquetasSinEans."
    );
  }

  $empresa = $elegido['empresa'];
  $albaran = $elegido['albaran'];
  echo "[OK] Albarán {$empresa}/{$albaran} ({$elegido['nLineas']} líneas) → encoladas=" . count($creadas) . "\n";

  foreach ($creadas as $item) {
    if ((int) ($item['albaran'] ?? 0) !== $albaran) {
      throw new RuntimeException('Línea sin albaran enlazado');
    }
    if (trim((string) ($item['empresa'] ?? '')) === '') {
      throw new RuntimeException('Línea sin empresa');
    }
    if ((int) ($item['cantidad'] ?? 0) < 1) {
      throw new RuntimeException('Cantidad/copias < 1');
    }
    echo sprintf(
      "  · %s nroLin=%d copias=%d ean=%s\n",
      $item['articulo'],
      $item['nroLin'],
      $item['cantidad'],
      $item['ean'] ?? '—'
    );
  }

  // Listar cola: las N deben aparecer
  $list = $cola->listar(['puesto' => $puestoCodigo, 'page' => 1, 'pageSize' => 500]);
  $keys = [];
  foreach ($list['items'] as $row) {
    $keys[trim((string) $row['articulo']) . '|' . (int) $row['nroLin']] = true;
  }
  foreach ($creadas as $c) {
    $k = trim((string) $c['articulo']) . '|' . (int) $c['nroLin'];
    if (!isset($keys[$k])) {
      throw new RuntimeException("Cola no lista {$k} tras generar");
    }
  }
  echo "[OK] Listar cola: " . count($creadas) . " líneas visibles\n";

  // Simular impresión OK → confirmar DELETE
  $refs = array_map(
    static fn (array $c): array => [
      'articulo' => $c['articulo'],
      'nroLin' => (int) $c['nroLin'],
    ],
    $creadas
  );
  $conf = $cola->confirmarImpresion($refs);
  $elim = (int) ($conf['eliminadas'] ?? 0);
  if ($elim !== count($creadas)) {
    throw new RuntimeException("confirmarImpresion: esperaba {$elim}=" . count($creadas));
  }
  echo "[OK] Confirmar impresión: eliminadas={$elim}\n";

  foreach ($creadas as $c) {
    if ($cola->obtener($c['articulo'], (int) $c['nroLin']) !== null) {
      throw new RuntimeException("Sigue en cola tras imprimir: {$c['articulo']}/{$c['nroLin']}");
    }
  }
  echo "[OK] Cola vacía de lo generado desde el albarán\n";

  echo "\n[PASS] SC-006: albarán ≥2 líneas → cola ≥2 → imprimir vacía lo generado\n";
  $ok = true;
  $creadas = []; // ya borradas
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
      echo "[AVISO] {$e->getMessage()}\n";
    }
  }
}

echo "\nManual UI: Compras → Albarán → Generar etiquetas → menú Etiquetas → Imprimir.\n";

exit($ok ? 0 : 1);
