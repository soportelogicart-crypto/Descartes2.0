<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

$pdo = Descartes\Api\Config\Database::fromEnv();

$queries = [
  'aut_788' => "SELECT TOP 10 Albaran, Operacion, Autorizacion, identificadorRTS, NumRefTP, Importe, Fecha
    FROM Autorizaciones WHERE Autorizacion LIKE '%788%' ORDER BY Fecha DESC",
  'ope_317663' => "SELECT TOP 10 Albaran, Operacion, Autorizacion, identificadorRTS, Importe, Fecha
    FROM Autorizaciones WHERE Operacion LIKE '%317663%' ORDER BY Fecha DESC",
  'recientes' => 'SELECT TOP 8 Albaran, Operacion, Autorizacion, identificadorRTS, Importe, Fecha
    FROM Autorizaciones ORDER BY Fecha DESC',
];

try {
  $n = (int) $pdo->query('SELECT COUNT(*) FROM Autorizaciones')->fetchColumn();
  echo "total={$n}\n";
} catch (Throwable $e) {
  echo 'count_err=' . $e->getMessage() . "\n";
}

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
