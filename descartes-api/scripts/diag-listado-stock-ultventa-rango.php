<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

use Descartes\Api\Config\Database;
use Descartes\Api\Services\Listados\StockListadoService;

$query = [
  'agruparPor' => 'articulo',
  'anoDesde' => 2026,
  'stockFiltro' => 'todos',
  'ultimaVentaDesde' => '2026-09-01',
  'ultimaVentaHasta' => '2026-09-17',
];

$svc = new StockListadoService(Database::fromEnv());
$out = $svc->generar($query);
$codigos = array_map(fn ($r) => $r['grupoCodigo'], $out['items']);
$buscar = ['191', '192', '195', '198', '199', '200'];
$encontrados = array_values(array_intersect($buscar, $codigos));
echo json_encode([
  'filas' => count($out['items']),
  'buscadosEncontrados' => $encontrados,
  'buscadosFaltan' => array_values(array_diff($buscar, $encontrados)),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
