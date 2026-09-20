<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

use Descartes\Api\Config\Database;
use Descartes\Api\Services\Ventas\AbcVentasService;

$desde = ($argv[1] ?? '2026-01-01') . ' 00:00:00';
$hasta = ($argv[2] ?? '2026-01-31') . ' 23:59:59';
$vend = $argv[3] ?? '1';

$pdo = Database::fromEnv();

$where = [
  "l.[Articulo] <> 'NO'",
  'c.[Fecha] >= :fd',
  'c.[Fecha] <= :fh',
  'RTRIM(c.[Vendedor]) >= :vd',
  'RTRIM(c.[Vendedor]) <= :vh',
  "(c.[FacturaTipo] IS NULL OR LTRIM(RTRIM(c.[FacturaTipo])) = '' OR LTRIM(RTRIM(c.[FacturaTipo])) IN ('F', 'A', 'T'))",
];
$params = ['fd' => $desde, 'fh' => $hasta, 'vd' => $vend, 'vh' => $vend];

$cant = 'ISNULL(l.[Cantidad], 0)';
$precio = 'ISNULL(l.[Precio], 0)';
$impLin = 'ISNULL(l.[Importe], 0)';
$pjeLin = 'ISNULL(l.[PjeDto], 0)';
$pjeCab = 'ISNULL(c.[PjeDto], 0)';
$dtoSinIva = "CASE WHEN {$pjeLin} <> 0 THEN CASE WHEN {$pjeCab} <> 0 "
  . "THEN (({$cant} * {$precio}) - {$impLin}) + (({$impLin} / 100.0) * {$pjeCab}) "
  . "ELSE ({$cant} * {$precio}) - {$impLin} END "
  . "ELSE CASE WHEN {$pjeCab} <> 0 THEN ({$impLin} / 100.0) * {$pjeCab} ELSE 0 END END";
$importeCalc = "(({$cant} * {$precio}) - ({$dtoSinIva}))";
$importeLinCabDto = "({$impLin} - ({$impLin} / 100.0) * {$pjeCab})";

$sql = "SELECT
  RTRIM(l.[Articulo]) AS art,
  SUM({$cant}) AS u,
  SUM({$impLin}) AS sum_importe_lin,
  SUM({$importeCalc}) AS sum_calc,
  SUM({$importeLinCabDto}) AS sum_lin_menos_cab_dto,
  SUM({$cant} * {$precio}) AS sum_bruto
FROM [AlbaranesVentasCab] c
INNER JOIN [AlbaranesVentasLin] l ON c.[Empresa]=l.[Empresa] AND c.[Albaran]=l.[Albaran] AND c.[Tipo]=l.[Tipo]
WHERE " . implode(' AND ', $where) . "
GROUP BY RTRIM(l.[Articulo])
ORDER BY SUM({$importeCalc}) DESC";

$st = $pdo->prepare($sql);
$st->execute($params);

echo "Vendedor {$vend} {$desde} - {$hasta}\n\n";
$tot = ['u' => 0, 'lin' => 0, 'calc' => 0, 'lincab' => 0];
while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
  if (($r['art'] ?? '') === '198' || ($r['art'] ?? '') === '001423') {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
  }
  $tot['u'] += (float) $r['u'];
  $tot['lin'] += (float) $r['sum_importe_lin'];
  $tot['calc'] += (float) $r['sum_calc'];
  $tot['lincab'] += (float) $r['sum_lin_menos_cab_dto'];
}
echo "\nTOTALS: " . json_encode($tot, JSON_PRETTY_PRINT) . "\n";

$svc = new AbcVentasService($pdo);
$abc = $svc->generar([
  'dimension' => 'vendedores',
  'fechaDesde' => substr($desde, 0, 10),
  'fechaHasta' => substr($hasta, 0, 10),
  'vendedorDesde' => $vend,
  'vendedorHasta' => $vend,
  'orden' => 'margen',
  'iva' => 'incluido',
  'valor' => 'precioMedio',
  'tipoVenta' => 'todos',
  'imArticulos' => true,
]);
echo "\nABC API totales: importe=" . $abc['totales']['importe'] . ' dto=' . $abc['totales']['dto'] . "\n";

$st2 = $pdo->query(
  "SELECT TOP 3 l.Cantidad, l.Precio, l.Importe, l.PjeIva, l.PjeDto, c.PjeDto AS cabDto, c.FacturaTipo
   FROM AlbaranesVentasLin l
   INNER JOIN AlbaranesVentasCab c ON c.Empresa=l.Empresa AND c.Albaran=l.Albaran AND c.Tipo=l.Tipo
   WHERE RTRIM(l.Articulo)='198' AND RTRIM(c.Vendedor)='1'
     AND c.Fecha>='2026-01-01' AND c.Fecha<='2026-01-31 23:59:59'"
);
echo "\nSample lines 198:\n";
foreach ($st2 as $r) {
  echo json_encode($r) . "\n";
}

$ivaExpr = 'SUM(l.Importe * (1.0 + ISNULL(l.PjeIva,0)/100.0))';
$st3 = $pdo->prepare(
  "SELECT SUM(l.Cantidad) u, SUM(l.Importe) imp, {$ivaExpr} imp_con_iva
   FROM AlbaranesVentasLin l
   INNER JOIN AlbaranesVentasCab c ON c.Empresa=l.Empresa AND c.Albaran=l.Albaran AND c.Tipo=l.Tipo
   WHERE RTRIM(c.Vendedor)=:v AND c.Fecha>=:fd AND c.Fecha<=:fh AND l.Articulo<>'NO'
     AND (c.FacturaTipo IS NULL OR LTRIM(RTRIM(c.FacturaTipo))='' OR LTRIM(RTRIM(c.FacturaTipo)) IN ('F','A','T'))"
);
$st3->execute(['v' => $vend, 'fd' => $desde, 'fh' => $hasta]);
echo "\nSum importe vs importe+pjeIva line: " . json_encode($st3->fetch(PDO::FETCH_ASSOC)) . "\n";

$st4 = $pdo->query(
  "SELECT SUM(l.Importe*(1.0+ISNULL(l.PjeIva,0)/100.0)) FROM AlbaranesVentasLin l
   INNER JOIN AlbaranesVentasCab c ON c.Empresa=l.Empresa AND c.Albaran=l.Albaran AND c.Tipo=l.Tipo
   WHERE RTRIM(l.Articulo)='198' AND RTRIM(c.Vendedor)='1'
     AND c.Fecha>='2026-01-01' AND c.Fecha<='2026-01-31 23:59:59'"
);
echo 'Art 198 sum importe*(1+iva): ' . $st4->fetchColumn() . "\n";

$fac = '(1.0 + COALESCE(NULLIF(l.PjeIva, 0), 0) / 100.0)';
$st5 = $pdo->prepare(
  "SELECT
     SUM({$fac} * l.Importe) AS imp_fac_lin,
     SUM({$fac} * (({$cant} * {$precio}) - ({$dtoSinIva}))) AS imp_fac_calc
   FROM AlbaranesVentasCab c
   INNER JOIN AlbaranesVentasLin l ON c.Empresa=l.Empresa AND c.Albaran=l.Albaran AND c.Tipo=l.Tipo
   WHERE RTRIM(c.Vendedor)=:v AND c.Fecha>=:fd AND c.Fecha<=:fh AND l.Articulo<>'NO'
     AND (c.FacturaTipo IS NULL OR LTRIM(RTRIM(c.FacturaTipo))='' OR LTRIM(RTRIM(c.FacturaTipo)) IN ('F','A','T'))"
);
$st5->execute(['v' => $vend, 'fd' => $desde, 'fh' => $hasta]);
echo 'Importe incluido: lin vs calc: ' . json_encode($st5->fetch(PDO::FETCH_ASSOC)) . "\n";

$st6 = $pdo->prepare(
  "SELECT COUNT(*) n, SUM(l.Importe) imp
   FROM AlbaranesVentasLin l
   INNER JOIN AlbaranesVentasCab c ON c.Empresa=l.Empresa AND c.Albaran=l.Albaran AND c.Tipo=l.Tipo
   LEFT JOIN Articulos a ON a.Codigo=l.Articulo
   LEFT JOIN Impuestos i ON i.Codigo=COALESCE(NULLIF(RTRIM(a.Impuesto),''),'NO')
   WHERE RTRIM(c.Vendedor)=:v AND c.Fecha>=:fd AND c.Fecha<=:fh AND l.Articulo<>'NO'
     AND ISNULL(l.PjeIva,0)=0 AND ISNULL(i.PjeIVA,0)<>0"
);
$st6->execute(['v' => $vend, 'fd' => $desde, 'fh' => $hasta]);
echo 'Lines PjeIva=0 but art IVA: ' . json_encode($st6->fetch(PDO::FETCH_ASSOC)) . "\n";
