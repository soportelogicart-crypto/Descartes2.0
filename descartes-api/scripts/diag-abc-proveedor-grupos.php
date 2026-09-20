<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

use Descartes\Api\Config\Database;
use Descartes\Api\Services\Ventas\AbcVentasService;

$desde = $argv[1] ?? '2026-01-01';
$hasta = $argv[2] ?? '2026-01-31';

$svc = new AbcVentasService(Database::fromEnv());
$abc = $svc->generar([
  'dimension' => 'proveedores',
  'fechaDesde' => $desde,
  'fechaHasta' => $hasta,
  'orden' => 'margen',
  'iva' => 'incluido',
  'valor' => 'precioMedio',
  'tipoVenta' => 'todos',
  'divisa' => 'EU',
  'imArticulos' => true,
]);

echo 'TOTAL: ' . json_encode($abc['totales'], JSON_UNESCAPED_UNICODE) . "\n\n";
foreach ($abc['grupos'] as $g) {
  echo sprintf(
    "%s | %s | u=%s dto=%s imp=%s coste=%s margen=%s\n",
    $g['codigo'],
    $g['nombre'],
    $g['totales']['unidades'],
    $g['totales']['dto'],
    $g['totales']['importe'],
    $g['totales']['coste'],
    $g['totales']['margen'],
  );
}
