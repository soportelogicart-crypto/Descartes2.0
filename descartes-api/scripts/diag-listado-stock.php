<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

use Descartes\Api\Config\Database;
use Descartes\Api\Services\Listados\StockListadoService;

$query = [
  'agruparPor' => $argv[1] ?? 'macrofamilia',
  'anoDesde' => (int) ($argv[2] ?? 2026),
  'ocultarCero' => 'false',
  'stockFiltro' => 'todos',
];

try {
  $svc = new StockListadoService(Database::fromEnv());
  $out = $svc->generar($query);
  echo json_encode([
    'query' => $query,
    'filas' => count($out['items']),
    'totales' => $out['totales'],
  ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $e) {
  fwrite(STDERR, $e->getMessage() . PHP_EOL);
  exit(1);
}
