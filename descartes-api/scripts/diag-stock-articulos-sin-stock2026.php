<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

use Descartes\Api\Config\Database;

$codigos = ['191', '195', '199', '200'];
$pdo = Database::fromEnv();
$in = implode(',', array_map(fn ($c) => $pdo->quote($c), $codigos));

$sql = "SELECT RTRIM(a.[Codigo]) AS codigo,
               (SELECT COUNT(*) FROM [Stock] s WHERE RTRIM(s.[Codigo]) = RTRIM(a.[Codigo])) AS filasStockTotal,
               (SELECT COUNT(*) FROM [Stock] s WHERE RTRIM(s.[Codigo]) = RTRIM(a.[Codigo]) AND s.[Año] = 2026) AS filasStock2026,
               (SELECT SUM(ISNULL(s.[Entradas],0)-ISNULL(s.[Salidas],0)-ISNULL(s.[Ventas],0)+ISNULL(s.[TraspasosEntradas],0)-ISNULL(s.[TraspasosSalidas],0))
                FROM [Stock] s WHERE RTRIM(s.[Codigo]) = RTRIM(a.[Codigo]) AND s.[Año] = 2026) AS unidades2026
        FROM [Articulos] a WHERE RTRIM(a.[Codigo]) IN ($in)";

foreach ($pdo->query($sql) as $r) {
  echo json_encode($r, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
