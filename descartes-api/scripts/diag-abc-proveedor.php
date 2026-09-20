<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

use Descartes\Api\Config\Database;
use Descartes\Api\Services\Ventas\AbcVentasService;

$desde = ($argv[1] ?? '2026-01-01');
$hasta = ($argv[2] ?? '2026-01-31');

$pdo = Database::fromEnv();
$svc = new AbcVentasService($pdo);

$base = [
  'fechaDesde' => $desde,
  'fechaHasta' => $hasta,
  'orden' => 'margen',
  'iva' => 'incluido',
  'valor' => 'precioMedio',
  'tipoVenta' => 'todos',
  'imArticulos' => true,
];

foreach (['proveedores', 'vendedores', 'secciones'] as $dim) {
  $abc = $svc->generar([...$base, 'dimension' => $dim]);
  echo "{$dim}: importe={$abc['totales']['importe']} dto={$abc['totales']['dto']} grupos=" . count($abc['grupos']) . "\n";
}

$fd = $desde . ' 00:00:00';
$fh = $hasta . ' 23:59:59';
$tipo = "(c.[FacturaTipo] IS NULL OR LTRIM(RTRIM(c.[FacturaTipo])) = '' OR LTRIM(RTRIM(c.[FacturaTipo])) IN ('F', 'A', 'T'))";

$st = $pdo->prepare(
  "SELECT
     SUM(CASE WHEN ISNULL(c.[Anulado],0)=1 OR RTRIM(ISNULL(c.[Estado],''))='B' THEN 1 ELSE 0 END) AS lineas_anul,
     SUM(CASE WHEN ISNULL(c.[Anulado],0)=0 AND RTRIM(ISNULL(c.[Estado],''))<>'B' THEN 1 ELSE 0 END) AS lineas_ok,
     COUNT(*) AS lineas_tot
   FROM [AlbaranesVentasCab] c
   INNER JOIN [AlbaranesVentasLin] l ON c.[Empresa]=l.[Empresa] AND c.[Albaran]=l.[Albaran] AND c.[Tipo]=l.[Tipo]
   WHERE l.[Articulo]<>'NO' AND c.[Fecha]>=:fd AND c.[Fecha]<=:fh AND {$tipo}"
);
$st->execute(['fd' => $fd, 'fh' => $fh]);
echo "\nLineas anuladas vs ok: " . json_encode($st->fetch(PDO::FETCH_ASSOC)) . "\n";

$pjeIva = 'ISNULL(l.[PjeIva], 0)';
$factor = "(1.0 + ({$pjeIva}) / 100.0)";
$cant = 'ISNULL(l.[Cantidad], 0)';
$precio = 'ISNULL(l.[Precio], 0)';
$impLin = 'ISNULL(l.[Importe], 0)';
$pjeLin = 'ISNULL(l.[PjeDto], 0)';
$pjeCab = 'ISNULL(c.[PjeDto], 0)';
$dtoSinIva = "CASE WHEN {$pjeLin} <> 0 THEN CASE WHEN {$pjeCab} <> 0 "
  . "THEN (({$cant} * {$precio}) - {$impLin}) + (({$impLin} / 100.0) * {$pjeCab}) "
  . "ELSE ({$cant} * {$precio}) - {$impLin} END "
  . "ELSE CASE WHEN {$pjeCab} <> 0 THEN ({$impLin} / 100.0) * {$pjeCab} ELSE 0 END END";
$importeExpr = "({$factor} * (({$cant} * {$precio}) - ({$dtoSinIva})))";

$extraOk = ' AND ISNULL(c.[Anulado], 0) = 0 AND RTRIM(ISNULL(c.[Estado], \'\')) <> \'B\'';

foreach (['sin_filtro_anulado' => '', 'con_filtro_anulado' => $extraOk] as $label => $extra) {
  $st2 = $pdo->prepare(
    "SELECT SUM({$importeExpr}) AS importe
     FROM [AlbaranesVentasCab] c
     INNER JOIN [AlbaranesVentasLin] l ON c.[Empresa]=l.[Empresa] AND c.[Albaran]=l.[Albaran] AND c.[Tipo]=l.[Tipo]
     LEFT JOIN [Articulos] a ON a.[Codigo] = l.[Articulo]
     WHERE l.[Articulo]<>'NO' AND c.[Fecha]>=:fd AND c.[Fecha]<=:fh AND {$tipo}{$extra}"
  );
  $st2->execute(['fd' => $fd, 'fh' => $fh]);
  $imp = round((float) $st2->fetchColumn(), 2);
  echo "{$label} importe total: {$imp}\n";
}

$st3 = $pdo->prepare(
  "SELECT RTRIM(c.[Empresa]) AS empresa, COUNT(*) AS lineas
   FROM [AlbaranesVentasCab] c
   INNER JOIN [AlbaranesVentasLin] l ON c.[Empresa]=l.[Empresa] AND c.[Albaran]=l.[Albaran] AND c.[Tipo]=l.[Tipo]
   WHERE l.[Articulo]<>'NO' AND c.[Fecha]>=:fd AND c.[Fecha]<=:fh AND {$tipo}
   GROUP BY RTRIM(c.[Empresa]) ORDER BY 1"
);
$st3->execute(['fd' => $fd, 'fh' => $fh]);
echo "\nLineas por empresa:\n";
foreach ($st3 as $r) {
  echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}

$st4 = $pdo->prepare(
  "SELECT
     SUM(CASE WHEN RTRIM(ISNULL(a.[UltProveedor], '')) = '' THEN 1 ELSE 0 END) AS sin_prov,
     SUM(CASE WHEN RTRIM(ISNULL(a.[UltProveedor], '')) <> '' THEN 1 ELSE 0 END) AS con_prov,
     SUM(CASE WHEN RTRIM(ISNULL(a.[UltProveedor], '')) = '' THEN {$importeExpr} ELSE 0 END) AS imp_sin_prov,
     SUM(CASE WHEN RTRIM(ISNULL(a.[UltProveedor], '')) <> '' THEN {$importeExpr} ELSE 0 END) AS imp_con_prov
   FROM [AlbaranesVentasCab] c
   INNER JOIN [AlbaranesVentasLin] l ON c.[Empresa]=l.[Empresa] AND c.[Albaran]=l.[Albaran] AND c.[Tipo]=l.[Tipo]
   LEFT JOIN [Articulos] a ON a.[Codigo] = l.[Articulo]
   WHERE l.[Articulo]<>'NO' AND c.[Fecha]>=:fd AND c.[Fecha]<=:fh AND {$tipo}"
);
$st4->execute(['fd' => $fd, 'fh' => $fh]);
echo "\nUltProveedor vacio: " . json_encode($st4->fetch(PDO::FETCH_ASSOC)) . "\n";

$st5 = $pdo->query('SELECT RTRIM(Codigo) c, RTRIM(ISNULL(Divisa,\'EU\')) d FROM Empresas_Ges ORDER BY 1');
echo "\nEmpresas_Ges divisa:\n";
foreach ($st5 as $r) {
  echo json_encode($r) . "\n";
}
