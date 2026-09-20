<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

$pdo = Descartes\Api\Config\Database::fromEnv();
$tipo = "(c.[FacturaTipo] IS NULL OR LTRIM(RTRIM(c.[FacturaTipo])) = '' OR LTRIM(RTRIM(c.[FacturaTipo])) IN ('F', 'A', 'T'))";

$sql = "SELECT RTRIM(l.Articulo) art, MAX(RTRIM(a.Descripcion)) desc_a,
        RTRIM(ISNULL(a.UltProveedor,'')) ult,
        SUM(l.Cantidad) u, SUM(l.Importe) imp
        FROM AlbaranesVentasCab c
        INNER JOIN AlbaranesVentasLin l ON c.Empresa=l.Empresa AND c.Albaran=l.Albaran AND c.Tipo=l.Tipo
        LEFT JOIN Articulos a ON a.Codigo=l.Articulo
        WHERE l.Articulo<>'NO' AND CONVERT(date,c.Fecha)>='2026-01-01' AND CONVERT(date,c.Fecha)<='2026-01-31'
          AND ISNULL(c.Anulado,0)=0 AND RTRIM(ISNULL(c.Estado,''))<>'B' AND {$tipo}
          AND (RTRIM(ISNULL(a.UltProveedor,'')) = '297' OR RTRIM(l.Descripcion) LIKE '%TPV%' OR RTRIM(a.Descripcion) LIKE '%TPV%')
        GROUP BY RTRIM(l.Articulo), RTRIM(ISNULL(a.UltProveedor,''))
        ORDER BY SUM(l.Importe) DESC";

foreach ($pdo->query($sql) as $r) {
  echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}
