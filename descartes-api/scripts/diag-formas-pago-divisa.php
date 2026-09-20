<?php
require dirname(__DIR__) . '/vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();
$pdo = Descartes\Api\Config\Database::fromEnv();
try {
  $st = $pdo->query(
    "SELECT TOP 30 RTRIM(Codigo) AS c, RTRIM(ISNULL(Divisa,'')) AS d
     FROM FormasPago ORDER BY Codigo"
  );
  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    echo $r['c'] . ' => ' . ($r['d'] === '' ? '(vacío)' : $r['d']) . "\n";
  }
} catch (Throwable $e) {
  echo 'Error: ' . $e->getMessage() . "\n";
  $st = $pdo->query("SELECT TOP 1 * FROM FormasPago");
  $row = $st->fetch(PDO::FETCH_ASSOC);
  echo 'Columns: ' . implode(', ', array_keys($row ?: [])) . "\n";
}
