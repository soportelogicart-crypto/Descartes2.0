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
  'dimension' => 'dias-semana',
  'fechaDesde' => $desde,
  'fechaHasta' => $hasta,
  'diaSemanaAbc' => 'todos',
  'orden' => 'diaSemana',
  'graficoPor' => 'importe',
  'iva' => 'incluido',
  'valor' => 'precioMedio',
  'tipoVenta' => 'todos',
  'divisa' => 'EU',
  'imArticulos' => true,
]);

echo 'Plano: ' . ($abc['informePlanoDiasSemanaAbc'] ? 'si' : 'no') . "\n";
echo 'Total: ' . ($abc['totales']['importe'] ?? 0) . "\n\n";
foreach ($abc['grupos'] as $g) {
  echo ($g['codigo'] ?? '') . ' → imp ' . ($g['totales']['importe'] ?? 0) . "\n";
}
