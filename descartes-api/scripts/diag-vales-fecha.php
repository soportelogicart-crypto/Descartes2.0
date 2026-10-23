<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

use Descartes\Api\Config\Database;
use Descartes\Api\Services\Ventas\ValeService;

$fecha = $argv[1] ?? date('Y-m-d');

$svc = new ValeService(Database::fromEnv());
$sin = $svc->listar(['estado' => 'pendientes', 'alcance' => 'liquidacion', 'pageSize' => 500]);
$con = $svc->listar([
  'estado' => 'pendientes',
  'alcance' => 'liquidacion',
  'fechaDesde' => $fecha,
  'fechaHasta' => $fecha,
  'pageSize' => 500,
]);

echo "Fecha filtro: {$fecha}\n";
echo 'Sin filtro fecha: total=' . $sin['total'] . ' items=' . count($sin['items']) . "\n";
echo 'Con filtro fecha: total=' . $con['total'] . ' items=' . count($con['items']) . "\n";

if ($con['items'] !== []) {
  $fechas = array_unique(array_map(static fn ($v) => substr((string) ($v['fecha'] ?? ''), 0, 10), $con['items']));
  echo 'Fechas en resultado filtrado: ' . implode(', ', $fechas) . "\n";
}
