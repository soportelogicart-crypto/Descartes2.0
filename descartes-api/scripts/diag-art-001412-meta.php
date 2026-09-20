<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

$pdo = Descartes\Api\Config\Database::fromEnv();

$sql = "SELECT RTRIM(Codigo) c, RTRIM(UltProveedor) up, RTRIM(Subfamilia) sf, RTRIM(Familia) fa
        FROM Articulos WHERE RTRIM(Codigo) IN ('001411','001412','348','1')";
foreach ($pdo->query($sql) as $r) {
  echo json_encode($r) . "\n";
}

$st = $pdo->query(
  "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.COLUMNS
   WHERE COLUMN_NAME = 'UltProveedor' GROUP BY TABLE_NAME"
);
echo "Tables UltProveedor:\n";
foreach ($st as $r) {
  echo $r['TABLE_NAME'] . "\n";
}
