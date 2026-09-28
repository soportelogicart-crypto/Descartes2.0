<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

$pdo = Descartes\Api\Config\Database::fromEnv();
$sql = "SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
  WHERE COLUMN_NAME IN ('Autorizacion','NumRefTP','identificadorRTS','Operacion')
  ORDER BY TABLE_NAME, COLUMN_NAME";
foreach ($pdo->query($sql) as $row) {
  echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
}
