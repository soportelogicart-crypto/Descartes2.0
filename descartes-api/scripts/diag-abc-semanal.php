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
  'dimension' => 'semanal',
  'fechaDesde' => $desde,
  'fechaHasta' => $hasta,
  'diaSemanaAbc' => 'todos',
  'desgloseSemanal' => 'importe',
  'orden' => 'horas',
  'iva' => 'incluido',
  'valor' => 'precioMedio',
  'tipoVenta' => 'todos',
  'divisa' => 'EU',
  'imArticulos' => true,
]);

$m = $abc['matrizSemanal'] ?? null;
if (!$m) {
  echo "Sin matrizSemanal\n";
  exit(1);
}

echo 'Filas: ' . count($m['filas']) . ' · Columnas: ' . count($m['columnas']) . "\n";
echo 'Total general: ' . ($m['totalGeneral'] ?? 0) . "\n\n";
foreach ($m['filas'] as $f) {
  echo ($f['nombre'] ?? $f['codigo']) . ' → total ' . ($f['total'] ?? 0) . "\n";
}
