<?php
require dirname(__DIR__) . '/vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();
$pdo = Descartes\Api\Config\Database::fromEnv();
$st = $pdo->query("SELECT RTRIM(Codigo) AS c, RTRIM(ISNULL(Divisa,'')) AS d FROM Tiendas ORDER BY Codigo");
while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
  echo $r['c'] . ' divisa=' . ($r['d'] === '' ? '(vacío)' : $r['d']) . "\n";
}
