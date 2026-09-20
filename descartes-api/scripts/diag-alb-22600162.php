<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

$pdo = Descartes\Api\Config\Database::fromEnv();

$sql = "SELECT l.NroLin, RTRIM(l.Articulo) a, RTRIM(l.ProveedorAsignado) pas,
        RTRIM(a.UltProveedor) ult, l.Cantidad, l.Importe, l.PrecioMedio
        FROM AlbaranesVentasLin l
        LEFT JOIN Articulos a ON a.Codigo=l.Articulo
        WHERE l.Empresa='1' AND l.Albaran=22600162 AND l.Tipo='A'
        ORDER BY l.NroLin";
foreach ($pdo->query($sql) as $r) {
  echo json_encode($r) . "\n";
}
