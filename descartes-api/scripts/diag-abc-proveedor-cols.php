<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

$pdo = Descartes\Api\Config\Database::fromEnv();

$st = $pdo->query(
  "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_NAME = 'AlbaranesVentasLin' AND COLUMN_NAME LIKE '%Prov%'
   ORDER BY 1"
);
echo "Lin columns Prov:\n";
foreach ($st as $r) {
  echo $r['COLUMN_NAME'] . "\n";
}

$st2 = $pdo->query(
  "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_NAME = 'Articulos' AND COLUMN_NAME LIKE '%Prov%'
   ORDER BY 1"
);
echo "\nArticulos columns Prov:\n";
foreach ($st2 as $r) {
  echo $r['COLUMN_NAME'] . "\n";
}

$tipo = "(c.[FacturaTipo] IS NULL OR LTRIM(RTRIM(c.[FacturaTipo])) = '' OR LTRIM(RTRIM(c.[FacturaTipo])) IN ('F', 'A', 'T'))";
$sql = "SELECT RTRIM(l.Articulo) art, RTRIM(a.UltProveedor) ult, SUM(l.Cantidad) u, SUM(l.Importe) imp
        FROM AlbaranesVentasCab c
        INNER JOIN AlbaranesVentasLin l ON c.Empresa=l.Empresa AND c.Albaran=l.Albaran AND c.Tipo=l.Tipo
        LEFT JOIN Articulos a ON a.Codigo=l.Articulo
        WHERE l.Articulo<>'NO' AND CONVERT(date,c.Fecha)>='2026-01-01' AND CONVERT(date,c.Fecha)<='2026-01-31'
          AND ISNULL(c.Anulado,0)=0 AND RTRIM(ISNULL(c.Estado,''))<>'B' AND {$tipo}
          AND RTRIM(ISNULL(a.UltProveedor,'')) IN ('297','340')
        GROUP BY RTRIM(l.Articulo), RTRIM(a.UltProveedor)
        ORDER BY 1";
echo "\nArts 297/340:\n";
foreach ($pdo->query($sql) as $r) {
  echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}

$q2 = "SELECT RTRIM(l.Articulo) a, RTRIM(a.UltProveedor) ult,
       RTRIM(ISNULL(l.ProveedorAsignado, '')) pas, l.Cantidad c, l.Importe i, c.Albaran
       FROM AlbaranesVentasLin l
       INNER JOIN AlbaranesVentasCab c ON c.Empresa=l.Empresa AND c.Albaran=l.Albaran AND c.Tipo=l.Tipo
       LEFT JOIN Articulos a ON a.Codigo=l.Articulo
       WHERE CONVERT(date, c.Fecha) >= '2026-01-01' AND CONVERT(date, c.Fecha) <= '2026-01-31'
         AND RTRIM(l.Articulo) IN ('001411','001412','348','1')
       ORDER BY l.Articulo";
echo "\nLine detail:\n";
foreach ($pdo->query($q2) as $r) {
  echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}
