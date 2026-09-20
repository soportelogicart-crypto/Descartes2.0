<?php
require dirname(__DIR__) . '/vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();
$pdo = Descartes\Api\Config\Database::fromEnv();
$st = $pdo->query(
  "SELECT RTRIM(ISNULL(Fpago,'')) AS fp, COUNT(*) AS n
   FROM Facturas
   WHERE Fecha >= '2026-01-01' AND Fecha < '2026-04-01' AND RTRIM(FacturaTipo) = 'F'
   GROUP BY RTRIM(ISNULL(Fpago,''))
   ORDER BY n DESC"
);
while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
  echo ($r['fp'] === '' ? '(vacío)' : $r['fp']) . ': ' . $r['n'] . "\n";
}
