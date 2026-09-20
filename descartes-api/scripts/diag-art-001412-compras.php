<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

$pdo = Descartes\Api\Config\Database::fromEnv();

$tables = ['AlbaranesComprasLin', 'AlbaranesComprasCab', 'PedidosProveedorLin'];
foreach ($pdo->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME LIKE '%Compra%' OR TABLE_NAME LIKE '%Proveedor%'") as $t) {
  // noop list
}

$sql = "SELECT TOP 5 RTRIM(c.Proveedor) prov, c.Fecha, l.Articulo, l.Cantidad
        FROM AlbaranesComprasLin l
        INNER JOIN AlbaranesComprasCab c ON c.Empresa=l.Empresa AND c.Albaran=l.Albaran AND c.Tipo=l.Tipo
        WHERE RTRIM(l.Articulo) IN ('001411','001412')
        ORDER BY c.Fecha DESC";
try {
  foreach ($pdo->query($sql) as $r) {
    echo json_encode($r) . "\n";
  }
} catch (Throwable $e) {
  echo 'Compras err: ' . $e->getMessage() . "\n";
}

$st = $pdo->query(
  "SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
   WHERE COLUMN_NAME LIKE '%Proveedor%' AND TABLE_NAME IN ('Articulos','ArtPrecios','Subfamilias','Familias')"
);
echo "\nCols:\n";
foreach ($st as $r) {
  echo $r['TABLE_NAME'] . '.' . $r['COLUMN_NAME'] . "\n";
}
