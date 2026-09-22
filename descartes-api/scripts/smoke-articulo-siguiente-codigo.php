<?php

declare(strict_types=1);

/**
 * Diagnóstico: siguiente código artículo por tienda.
 * Uso: php scripts/smoke-articulo-siguiente-codigo.php [empresa]
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

use Descartes\Api\Config\Database;
use Descartes\Api\Repositories\ArtBarrasRepository;
use Descartes\Api\Services\ArticuloGeneracionCodigosService;

$pdo = Database::fromEnv();
$svc = new ArticuloGeneracionCodigosService($pdo, new ArtBarrasRepository($pdo));

$empresaArg = $argv[1] ?? '';

echo "=== Empresas_Ges (GenArticulos / Prefijo / UltEan) ===\n";
$stmt = $pdo->query(
  "SELECT RTRIM(Codigo) AS Codigo,
          CAST(ISNULL(GenArticulos,0) AS int) AS GenArticulos,
          CAST(ISNULL(Prefijo,0) AS float) AS Prefijo,
          CAST(ISNULL(UltEan,0) AS int) AS UltEan
   FROM Empresas_Ges
   ORDER BY Codigo"
);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
  echo sprintf(
    "  %s  GenArticulos=%s  Prefijo=%s  UltEan=%s\n",
    $row['Codigo'],
    $row['GenArticulos'],
    $row['Prefijo'],
    $row['UltEan']
  );
}

echo "\n=== previewSiguiente(empresa=" . ($empresaArg !== '' ? $empresaArg : '(vacío)') . ") ===\n";
print_r($svc->previewSiguiente($empresaArg));
