<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

$pdo = Descartes\Api\Config\Database::fromEnv();
echo 'DB_NAME=' . ($_ENV['DB_NAME'] ?? '') . "\n";

$queries = [
  'count' => 'SELECT COUNT(*) AS n FROM Autorizaciones',
  'recientes' => 'SELECT TOP 5 Albaran, Operacion, Autorizacion, NumRefTP, identificadorRTS, Importe, Fecha, Empresa
    FROM Autorizaciones ORDER BY Fecha DESC',
  'aut_like_788' => "SELECT TOP 10 Albaran, Operacion, Autorizacion, NumRefTP, Importe, Fecha, Empresa
    FROM Autorizaciones WHERE Autorizacion LIKE '%788%'",
  'clr_9111' => "SELECT TOP 10 Albaran, Operacion, Autorizacion, NumRefTP, Importe, Fecha, Empresa
    FROM Autorizaciones WHERE LTRIM(RTRIM(NumRefTP)) = '9111'",
  'importe_010' => 'SELECT TOP 10 Albaran, Operacion, Autorizacion, NumRefTP, Importe, Fecha, Empresa
    FROM Autorizaciones WHERE ABS(Importe - 0.10) < 0.001 ORDER BY Fecha DESC',
  'tarjeta_9111' => "SELECT TOP 10 Albaran, Operacion, Autorizacion, NumRefTP, Tarjeta, Importe, Fecha
    FROM Autorizaciones WHERE Tarjeta LIKE '%9111%'",
];

foreach ($queries as $label => $sql) {
  echo "=== {$label} ===\n";
  try {
    foreach ($pdo->query($sql) as $row) {
      echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
    }
  } catch (Throwable $e) {
    echo $e->getMessage() . "\n";
  }
}
