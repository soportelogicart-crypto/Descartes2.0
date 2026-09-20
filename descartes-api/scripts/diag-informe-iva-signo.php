<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

use Descartes\Api\Config\Database;
use Descartes\Api\Services\Listados\InformeIvaListadoService;

$desde = $argv[1] ?? '2026-01-01';
$hasta = $argv[2] ?? '2026-03-31';

$pdo = Database::fromEnv();
$svc = new InformeIvaListadoService($pdo);

echo "=== InformeIva API estado=menos ===\n";
$r = $svc->generar([
  'fechaDesde' => $desde,
  'fechaHasta' => $hasta,
  'estado' => 'menos',
]);
echo 'items=' . count($r['items']) . ' tot=' . ($r['totales']['importeTotal'] ?? 0) . "\n";

echo "=== InformeIva API estado=mas ===\n";
$r2 = $svc->generar([
  'fechaDesde' => $desde,
  'fechaHasta' => $hasta,
  'estado' => 'mas',
]);
echo 'items=' . count($r2['items']) . ' tot=' . ($r2['totales']['importeTotal'] ?? 0) . "\n";

$d = $desde . ' 00:00:00';
$h = $hasta . ' 23:59:59';

$queries = [
  'F EU importe<0' => "RTRIM(FacturaTipo)='F' AND RTRIM(ISNULL(Fpago,''))='EU' AND ISNULL(Importe,0)<0",
  'F EU importe>0' => "RTRIM(FacturaTipo)='F' AND RTRIM(ISNULL(Fpago,''))='EU' AND ISNULL(Importe,0)>0",
  'A EU' => "RTRIM(FacturaTipo)='A' AND RTRIM(ISNULL(Fpago,''))='EU'",
  'A EU importe>0' => "RTRIM(FacturaTipo)='A' AND RTRIM(ISNULL(Fpago,''))='EU' AND ISNULL(Importe,0)>0",
  'A EU importe<0' => "RTRIM(FacturaTipo)='A' AND RTRIM(ISNULL(Fpago,''))='EU' AND ISNULL(Importe,0)<0",
  'any importe<0' => 'ISNULL(Importe,0)<0',
];

echo "\n=== Facturas raw counts ($desde .. $hasta) ===\n";
foreach ($queries as $label => $w) {
  $st = $pdo->prepare(
    "SELECT COUNT(*) FROM Facturas WHERE Fecha >= CONVERT(datetime, :d, 120)
     AND Fecha <= CONVERT(datetime, :h, 120) AND {$w}"
  );
  $st->execute(['d' => $d, 'h' => $h]);
  echo $label . ': ' . $st->fetchColumn() . "\n";
}

echo "\n=== Albaranes T numerados (agrupados) negativos ===\n";
$sql = "SELECT COUNT(*) FROM (
  SELECT c.Factura, SUM(ISNULL(c.Importe,0)) AS imp
  FROM AlbaranesVentasCab c
  WHERE c.Fecha >= CONVERT(datetime, :d, 120) AND c.Fecha <= CONVERT(datetime, :h, 120)
    AND ISNULL(c.Anulado,0)=0 AND RTRIM(ISNULL(c.Estado,''))<>'B'
    AND RTRIM(c.FacturaTipo)='T' AND ISNULL(c.Factura,0)>0
    AND (RTRIM(ISNULL(c.Fpago1,''))='EU' OR RTRIM(ISNULL(c.Fpago2,''))='EU')
  GROUP BY RTRIM(c.Empresa), RTRIM(c.FacturaTipo), c.Factura
  HAVING SUM(ISNULL(c.Importe,0)) < 0
) x";
$st = $pdo->prepare($sql);
$st->execute(['d' => $d, 'h' => $h]);
echo 'tickets negativos: ' . $st->fetchColumn() . "\n";
