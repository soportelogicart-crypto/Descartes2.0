<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

use Descartes\Api\Config\Database;

$desde = ($argv[1] ?? '2026-01-01') . ' 00:00:00';
$hasta = ($argv[2] ?? '2026-03-31') . ' 23:59:59';

$pdo = Database::fromEnv();

echo "=== Facturas Importe < 0 ===\n";
$st = $pdo->prepare(
  "SELECT RTRIM(Empresa) AS e, RTRIM(FacturaTipo) AS ft, Factura, CONVERT(varchar(10), Fecha, 23) AS f,
          Importe, RTRIM(ISNULL(Fpago,'')) AS fpago, ISNULL(TrasCtb,0) AS ctb
   FROM Facturas
   WHERE Fecha >= CONVERT(datetime, :d, 120) AND Fecha <= CONVERT(datetime, :h, 120)
     AND ISNULL(Importe,0) < 0
   ORDER BY Fecha"
);
$st->execute(['d' => $desde, 'h' => $hasta]);
while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
  echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n=== Abonos A EU (importe any sign) ===\n";
$st = $pdo->prepare(
  "SELECT RTRIM(Empresa) AS e, Factura, CONVERT(varchar(10), Fecha, 23) AS f, Importe, RTRIM(ISNULL(Fpago,'')) AS fpago
   FROM Facturas
   WHERE Fecha >= CONVERT(datetime, :d, 120) AND Fecha <= CONVERT(datetime, :h, 120)
     AND RTRIM(FacturaTipo) = 'A'
   ORDER BY Fecha"
);
$st->execute(['d' => $desde, 'h' => $hasta]);
while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
  echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n=== Albaranes cab Importe < 0 (muestra 20) ===\n";
$st = $pdo->prepare(
  "SELECT TOP 20 RTRIM(Empresa) AS e, Tipo, Albaran, RTRIM(FacturaTipo) AS ft, Factura,
          CONVERT(varchar(10), Fecha, 23) AS f, Importe,
          RTRIM(ISNULL(Fpago1,'')) AS fp1, RTRIM(ISNULL(Fpago2,'')) AS fp2
   FROM AlbaranesVentasCab
   WHERE Fecha >= CONVERT(datetime, :d, 120) AND Fecha <= CONVERT(datetime, :h, 120)
     AND ISNULL(Anulado,0)=0 AND RTRIM(ISNULL(Estado,''))<>'B'
     AND ISNULL(Importe,0) < 0
   ORDER BY Fecha DESC"
);
$st->execute(['d' => $desde, 'h' => $hasta]);
while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
  echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n=== Albaranes agrupados por doc (T/F) sum importe < 0 ===\n";
$st = $pdo->prepare(
  "SELECT TOP 20 RTRIM(c.Empresa) AS e, RTRIM(c.FacturaTipo) AS ft, c.Factura AS num,
          CONVERT(varchar(10), MIN(c.Fecha), 23) AS f, SUM(ISNULL(c.Importe,0)) AS imp
   FROM AlbaranesVentasCab c
   WHERE c.Fecha >= CONVERT(datetime, :d, 120) AND c.Fecha <= CONVERT(datetime, :h, 120)
     AND ISNULL(c.Anulado,0)=0 AND RTRIM(ISNULL(c.Estado,''))<>'B'
     AND ISNULL(c.Factura,0) > 0
   GROUP BY RTRIM(c.Empresa), RTRIM(c.FacturaTipo), c.Factura
   HAVING SUM(ISNULL(c.Importe,0)) < 0
   ORDER BY MIN(c.Fecha)"
);
$st->execute(['d' => $desde, 'h' => $hasta]);
while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
  echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
}
