<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

use Descartes\Api\Config\Database;
use Descartes\Api\Services\Listados\InformeTicketsListadoService;

$desde = $argv[1] ?? '2026-01-01';
$hasta = $argv[2] ?? $desde;
$formato = $argv[3] ?? 'superResumido';
$divisa = $argv[4] ?? '';

$pdo = Database::fromEnv();
$service = new InformeTicketsListadoService($pdo);
$data = $service->generar([
  'fechaDesde' => $desde,
  'fechaHasta' => $hasta,
  'formato' => $formato,
  'divisa' => $divisa,
]);

echo json_encode([
  'formato' => $data['formato'],
  'divisa' => $data['divisa'],
  'divisasConfiguradas' => $data['divisasConfiguradas'],
  'items' => count($data['items']),
  'dias' => count($data['resumenDiario']),
  'tiendas' => count($data['resumenTiendas']),
  'totales' => $data['totales'],
  'truncado' => $data['truncado'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

if ($data['items'] === []) {
  $st = $pdo->prepare(
    "SELECT RTRIM(ISNULL(FacturaTipo, '')) AS facturaTipo, COUNT(*) AS documentos,
            SUM(CASE WHEN ISNULL(Factura, 0) > 0 THEN 1 ELSE 0 END) AS numerados
     FROM AlbaranesVentasCab
     WHERE CONVERT(date, Fecha) >= CONVERT(date, :desde, 23)
       AND CONVERT(date, Fecha) <= CONVERT(date, :hasta, 23)
     GROUP BY RTRIM(ISNULL(FacturaTipo, ''))
     ORDER BY facturaTipo"
  );
  $st->execute(['desde' => $desde, 'hasta' => $hasta]);
  echo 'Cabeceras por FacturaTipo: '
    . json_encode($st->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
