<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

use Descartes\Api\Config\Database;

$desde = ($argv[1] ?? '2026-09-01') . ' 00:00:00';
$hasta = ($argv[2] ?? '2026-09-30') . ' 23:59:59';

$pdo = Database::fromEnv();

$sql = "SELECT RTRIM(f.[Empresa]) AS empresa,
               RTRIM(f.[FacturaTipo]) AS ft,
               f.[Factura] AS num,
               CONVERT(varchar(10), f.[Fecha], 23) AS fecha,
               ISNULL(f.[Importe], 0) AS importe,
               ISNULL(f.[ImporteBase1],0)+ISNULL(f.[ImporteBase2],0)+ISNULL(f.[ImporteBase3],0)
                 +ISNULL(f.[ImporteBase4],0)+ISNULL(f.[ImporteBase5],0)+ISNULL(f.[ImporteBase6],0) AS base,
               ISNULL(f.[ImporteIva1],0)+ISNULL(f.[ImporteIva2],0)+ISNULL(f.[ImporteIva3],0)
                 +ISNULL(f.[ImporteIva4],0)+ISNULL(f.[ImporteIva5],0)+ISNULL(f.[ImporteIva6],0) AS iva
        FROM [Facturas] f
        WHERE f.[Fecha] >= CONVERT(datetime, :d, 120)
          AND f.[Fecha] <= CONVERT(datetime, :h, 120)
          AND RTRIM(f.[FacturaTipo]) = 'F'
        ORDER BY f.[Fecha], f.[Factura]";

$st = $pdo->prepare($sql);
$st->execute(['d' => $desde, 'h' => $hasta]);
$totB = 0;
$totI = 0;
$tot = 0;
$n = 0;
while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
  $n++;
  $totB += (float) $row['base'];
  $totI += (float) $row['iva'];
  $tot += (float) $row['importe'];
  echo sprintf(
    "%s F-%s tot=%s base=%s iva=%s\n",
    $row['fecha'],
    $row['num'],
    round((float) $row['importe'], 2),
    round((float) $row['base'], 2),
    round((float) $row['iva'], 2)
  );
}
echo "COUNT=$n base=" . round($totB, 2) . " iva=" . round($totI, 2) . " tot=" . round($tot, 2) . PHP_EOL;
