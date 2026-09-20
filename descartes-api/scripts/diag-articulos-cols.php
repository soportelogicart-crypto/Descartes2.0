<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

use Descartes\Api\Config\Database;

$pdo = Database::fromEnv();
$sql = "SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_NAME = 'Articulos'
          AND (COLUMN_NAME LIKE '%Fecha%' OR COLUMN_NAME LIKE '%Venta%'
               OR COLUMN_NAME LIKE '%Ubic%' OR COLUMN_NAME LIKE '%Ult%')
        ORDER BY COLUMN_NAME";
foreach ($pdo->query($sql) as $r) {
  echo $r['COLUMN_NAME'] . ' ' . $r['DATA_TYPE'] . PHP_EOL;
}
