<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

use Descartes\Api\Config\Database;

$codigos = array_slice($argv, 1);
if ($codigos === []) {
  $codigos = ['198', '192', '199', '200', '191', '195'];
}
$desde = '2026-09-01';
$hasta = '2026-09-17';

$pdo = Database::fromEnv();
$in = implode(',', array_map(fn ($c) => $pdo->quote(trim($c)), $codigos));

$sql = "SELECT RTRIM(a.[Codigo]) AS codigo,
               CONVERT(varchar(10), a.[FechaUltimaVenta], 120) AS fechaUltimaVenta,
               (SELECT CONVERT(varchar(10), MAX(c.[Fecha]), 120)
                FROM [AlbaranesVentasLin] l
                INNER JOIN [AlbaranesVentasCab] c
                  ON c.[Empresa] = l.[Empresa] AND c.[Albaran] = l.[Albaran]
                WHERE RTRIM(l.[Articulo]) = RTRIM(a.[Codigo])
                  AND ISNULL(c.[Anulado], 0) = 0
                  AND RTRIM(ISNULL(c.[Estado], '')) <> 'B'
                  AND c.[Fecha] >= CONVERT(datetime, :desde, 120)
                  AND c.[Fecha] < DATEADD(day, 1, CONVERT(date, :hasta, 120))
               ) AS maxVentaEnRango,
               (SELECT COUNT(*)
                FROM [Stock] s
                WHERE RTRIM(s.[Codigo]) = RTRIM(a.[Codigo]) AND s.[Año] = 2026
               ) AS filasStock2026
        FROM [Articulos] a
        WHERE RTRIM(a.[Codigo]) IN ($in)
        ORDER BY a.[Codigo]";

$stmt = $pdo->prepare($sql);
$stmt->execute(['desde' => $desde . ' 00:00:00', 'hasta' => $hasta]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
