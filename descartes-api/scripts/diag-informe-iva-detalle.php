<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

use Descartes\Api\Config\Database;
use Descartes\Api\Services\Listados\InformeIvaListadoService;

$desde = $argv[1] ?? '2026-09-01';
$hasta = $argv[2] ?? '2026-09-30';

$pdo = Database::fromEnv();
$svc = new InformeIvaListadoService($pdo);
$out = $svc->generar([
  'fechaDesde' => $desde,
  'fechaHasta' => $hasta,
  'empresa' => '',
  'soloNumerados' => '1',
]);

$lines = [];
foreach ($out['items'] as $r) {
  $lines[] = sprintf(
    '%s %s-%s %s base=%s iva=%s tot=%s',
    $r['fecha'],
    $r['facturaTipo'],
    $r['numeroTicket'] ?? 0,
    substr($r['razonSocial'] ?: $r['cliente'], 0, 30),
    $r['baseImponible'],
    $r['cuotaIva'],
    $r['importeTotal']
  );
}
echo 'COUNT=' . count($lines) . PHP_EOL;
echo implode(PHP_EOL, $lines) . PHP_EOL;
echo 'TOTALES ' . json_encode($out['totales'], JSON_UNESCAPED_UNICODE) . PHP_EOL;
