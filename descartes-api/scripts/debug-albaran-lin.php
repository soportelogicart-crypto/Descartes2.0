<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Descartes\Api\Config\Database;

$albaran = (int) ($argv[1] ?? 22600615);
$empresa = $argv[2] ?? '1';

try {
  $pdo = Database::fromEnv();
  $st = $pdo->prepare(
    'SELECT NroLin, Articulo, Cantidad FROM AlbaranesVentasLin
     WHERE Empresa = :e AND Tipo = \'A\' AND Albaran = :a ORDER BY NroLin'
  );
  $st->execute(['e' => $empresa, 'a' => $albaran]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  $stCab = $pdo->prepare(
    'SELECT 1 AS ok FROM AlbaranesVentasCab WHERE Empresa = :e AND Tipo = \'A\' AND Albaran = :a'
  );
  $stCab->execute(['e' => $empresa, 'a' => $albaran]);
  echo json_encode([
    'albaran' => $albaran,
    'lineCount' => count($rows),
    'lineas' => $rows,
    'cabExists' => (bool) $stCab->fetch(),
  ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $e) {
  fwrite(STDERR, $e->getMessage() . "\n");
  exit(1);
}
