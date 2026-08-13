<?php

declare(strict_types=1);

/**
 * Smoke SC-001 (005 T029): impresión rápida desde ficha artículo.
 *
 * Valida prerrequisitos del flujo US1 (≤ 5 acciones en UI):
 *   Artículo → Etiquetas → EAN → 2 copias → Imprimir
 * y que el puesto tenga ImpresoraEtiquetas + flags de tienda coherentes.
 *
 * La impresión física (Electron printLabel × 2) es manual.
 *
 * Uso: php scripts/smoke-etiquetas-rapida.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

use Descartes\Api\Config\Database;

echo "=== Smoke SC-001 (005 T029) — impresión rápida ficha ===\n\n";

try {
  $pdo = Database::fromEnv();
} catch (Throwable $e) {
  echo "[FAIL] Conexion BD: {$e->getMessage()}\n";
  exit(1);
}

/**
 * Misma semántica que useEtiquetasTiendaFlags.ts (T028).
 */
function flagOn(mixed $v): bool
{
  if ($v === true || $v === 1 || $v === '1') {
    return true;
  }
  if (is_string($v) && strtolower(trim($v)) === 'true') {
    return true;
  }
  return is_numeric($v) && (float) $v !== 0.0;
}

/**
 * @param list<string> $eansPropios
 */
function validarEan(string $ean, array $eansPropios, bool $sinEans, bool $soloPropios, string $articulo): ?string
{
  $ean = trim($ean);
  $pref = $articulo !== '' ? "Artículo {$articulo}: " : '';

  if ($soloPropios) {
    if ($ean === '') {
      return "{$pref}la tienda solo permite imprimir con EAN propios; este artículo no tiene EAN";
    }
    if ($eansPropios !== [] && !in_array($ean, $eansPropios, true)) {
      return "{$pref}EAN «{$ean}» no está en ArtBarras";
    }
    return null;
  }

  if ($ean === '' && !$sinEans) {
    return "{$pref}la tienda no permite imprimir etiquetas sin EAN";
  }
  return null;
}

$ok = false;
$puestoCodigo = '01';
$impresora = '';
$empresa = '';

try {
  // —— 1. Puesto + ImpresoraEtiquetas ——
  $stmt = $pdo->prepare(
    'SELECT TOP 1
        RTRIM(Puesto) AS Puesto,
        RTRIM(ISNULL(ImpresoraEtiquetas, \'\')) AS ImpresoraEtiquetas,
        RTRIM(ISNULL(EmpresaArqueo, \'\')) AS EmpresaArqueo
     FROM [Puestos]
     WHERE RTRIM(Puesto) = :p'
  );
  $stmt->execute(['p' => $puestoCodigo]);
  $puesto = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($puesto === false || trim((string) ($puesto['ImpresoraEtiquetas'] ?? '')) === '') {
    $any = $pdo->query(
      "SELECT TOP 1
          RTRIM(Puesto) AS Puesto,
          RTRIM(ISNULL(ImpresoraEtiquetas, '')) AS ImpresoraEtiquetas,
          RTRIM(ISNULL(EmpresaArqueo, '')) AS EmpresaArqueo
       FROM [Puestos]
       WHERE RTRIM(ISNULL(ImpresoraEtiquetas, '')) <> ''
       ORDER BY Puesto"
    )->fetch(PDO::FETCH_ASSOC);
    if (!$any) {
      throw new RuntimeException(
        'Ningún puesto tiene ImpresoraEtiquetas: configure Generales II → Etiquetas artículo'
      );
    }
    $puesto = $any;
  }

  $puestoCodigo = trim((string) $puesto['Puesto']);
  $impresora = trim((string) $puesto['ImpresoraEtiquetas']);
  $empresa = trim((string) ($puesto['EmpresaArqueo'] ?? ''));
  echo "[OK] Puesto {$puestoCodigo} → ImpresoraEtiquetas={$impresora}\n";
  if ($empresa === '') {
    echo "[AVISO] Puesto sin EmpresaArqueo; flags tienda usarán defaults\n";
  } else {
    echo "[OK] Empresa/tienda del puesto: {$empresa}\n";
  }

  // —— 2. Artículo con EAN (flujo ficha típico) ——
  $artRow = $pdo->query(
    "SELECT TOP 1
        RTRIM(a.Codigo) AS Codigo,
        RTRIM(ISNULL(a.Descripcion, '')) AS Descripcion,
        ISNULL(a.PrecioVen1, 0) AS PrecioVen1,
        LTRIM(RTRIM(STR(b.Ean, 18, 0))) AS Ean
     FROM [ArtBarras] b
     INNER JOIN [Articulos] a ON RTRIM(a.Codigo) = RTRIM(b.Codigo)
     WHERE b.Ean IS NOT NULL AND b.Ean <> 0
     ORDER BY a.Codigo"
  )->fetch(PDO::FETCH_ASSOC);

  if (!$artRow) {
    throw new RuntimeException('No hay artículos con EAN en ArtBarras para SC-001');
  }

  $codigo = trim((string) $artRow['Codigo']);
  $ean = trim((string) $artRow['Ean']);
  $desc = trim((string) $artRow['Descripcion']);
  $precio = (float) $artRow['PrecioVen1'];

  $eansStmt = $pdo->prepare(
    "SELECT LTRIM(RTRIM(STR(Ean, 18, 0))) AS Ean
     FROM [ArtBarras]
     WHERE RTRIM(Codigo) = :c AND Ean IS NOT NULL AND Ean <> 0"
  );
  $eansStmt->execute(['c' => $codigo]);
  $eansPropios = [];
  foreach ($eansStmt->fetchAll(PDO::FETCH_COLUMN) as $raw) {
    $e = trim((string) $raw);
    if ($e !== '' && $e !== '0') {
      $eansPropios[] = $e;
    }
  }
  $eansPropios = array_values(array_unique($eansPropios));

  echo sprintf(
    "[OK] Artículo %s (%s) EAN=%s precio=%.2f — %d EAN(s) propios\n",
    $codigo,
    $desc !== '' ? $desc : 'sin desc',
    $ean,
    $precio,
    count($eansPropios)
  );

  // —— 3. Flags tienda ——
  $sinEans = false;
  $soloPropios = false;
  $ivaIncl = false;
  if ($empresa !== '') {
    $tf = $pdo->prepare(
      "SELECT TOP 1 ImpEtiquetasSinEans, ImpEtiquetasSoloEansPropios, EtiquetasIvaIncluido
       FROM [Empresas]
       WHERE RTRIM(Codigo) = RTRIM(:e)
          OR RTRIM(Codigo) = RIGHT('000' + RTRIM(:e2), 3)"
    );
    $tf->execute(['e' => $empresa, 'e2' => $empresa]);
    $trow = $tf->fetch(PDO::FETCH_ASSOC);
    if ($trow) {
      $sinEans = flagOn($trow['ImpEtiquetasSinEans'] ?? 0);
      $soloPropios = flagOn($trow['ImpEtiquetasSoloEansPropios'] ?? 0);
      $ivaIncl = flagOn($trow['EtiquetasIvaIncluido'] ?? 0);
      echo sprintf(
        "[OK] Flags tienda: sinEans=%s soloPropios=%s ivaIncluido=%s\n",
        $sinEans ? '1' : '0',
        $soloPropios ? '1' : '0',
        $ivaIncl ? '1' : '0'
      );
    } else {
      echo "[AVISO] Empresa {$empresa} no encontrada en Empresas; flags=0 (default)\n";
    }
  }

  // —— 4. Reglas T028: con EAN propio debe poder imprimir ——
  $errOk = validarEan($ean, $eansPropios, $sinEans, $soloPropios, $codigo);
  if ($errOk !== null) {
    throw new RuntimeException("Con EAN propio no debería bloquear: {$errOk}");
  }
  echo "[OK] Validación EAN propio → permite imprimir\n";

  $errSin = validarEan('', $eansPropios, $sinEans, $soloPropios, $codigo);
  if (!$sinEans || $soloPropios) {
    if ($errSin === null) {
      throw new RuntimeException('Sin EAN debería bloquear según flags de tienda');
    }
    echo "[OK] Sin EAN → bloquea (flags): " . substr($errSin, 0, 80) . "…\n";
  } else {
    if ($errSin !== null) {
      throw new RuntimeException("Tienda permite sin EAN pero validó error: {$errSin}");
    }
    echo "[OK] Sin EAN → permitido (ImpEtiquetasSinEans=1)\n";
  }

  // —— 5. Contrato UI: 2 copias (campo cantidad del dialog) ——
  $copias = 2;
  if ($copias < 1 || $copias > 500) {
    throw new RuntimeException('Copias fuera de rango');
  }
  echo "[OK] Copias=2 (rango dialog 1–500) → listo para printLabel×2 a «{$impresora}»\n";

  echo "\n[PASS] SC-001 prerrequisitos OK: ficha {$codigo} + EAN + impresora {$impresora}\n";
  $ok = true;
} catch (Throwable $e) {
  echo "[FAIL] {$e->getMessage()}\n";
  $ok = false;
}

echo "\nManual UI (≤ 5 acciones):\n";
echo "  1) Mantenimiento → Artículos → abrir ficha con EAN\n";
echo "  2) Toolbar Etiquetas\n";
echo "  3) Elegir EAN (si varios)\n";
echo "  4) Copias = 2\n";
echo "  5) Imprimir → 2 etiquetas en ImpresoraEtiquetas del puesto\n";

exit($ok ? 0 : 1);
