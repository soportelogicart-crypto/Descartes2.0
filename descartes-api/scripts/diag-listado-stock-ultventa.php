<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

use Descartes\Api\Config\Database;
use Descartes\Api\Services\Listados\StockListadoService;

$hoy = date('Y-m-d');
$query = [
  'agruparPor' => 'articulo',
  'anoDesde' => (int) date('Y'),
  'stockFiltro' => 'todos',
  'ultimaVentaDesde' => $hoy,
  'ultimaVentaHasta' => $hoy,
];

$svc = new StockListadoService(Database::fromEnv());
$out = $svc->generar($query);
echo json_encode([
  'hoy' => $hoy,
  'filas' => count($out['items']),
  'muestra' => array_slice($out['items'], 0, 3),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
