<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

$pdo = Descartes\Api\Config\Database::fromEnv();
$semanaInicioSql = "DATEADD(day, -((DATEDIFF(day, '19000101', c.[Fecha]) % 7)), CAST(c.[Fecha] AS date))";
$diaSql = "(DATEDIFF(day, '19000101', c.[Fecha]) % 7)";

$sql = "SELECT
  CAST(c.[Fecha] AS date) AS f,
  {$semanaInicioSql} AS lun,
  DATEPART(iso_week, c.[Fecha]) AS isoW,
  {$diaSql} AS diaIdx,
  SUM(ISNULL(l.[Importe],0)) AS imp
FROM [AlbaranesVentasCab] c
INNER JOIN [AlbaranesVentasLin] l
  ON c.[Empresa]=l.[Empresa] AND c.[Albaran]=l.[Albaran] AND c.[Tipo]=l.[Tipo]
WHERE c.[Fecha]>='2026-01-01' AND c.[Fecha]<='2026-01-31 23:59:59'
  AND l.[Articulo]<>'NO'
GROUP BY CAST(c.[Fecha] AS date), {$semanaInicioSql}, DATEPART(iso_week, c.[Fecha]), {$diaSql}
ORDER BY f";

foreach ($pdo->query($sql) as $r) {
  echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}
