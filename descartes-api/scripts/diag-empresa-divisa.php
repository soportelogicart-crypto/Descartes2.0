<?php
require dirname(__DIR__) . '/vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();
$pdo = Descartes\Api\Config\Database::fromEnv();
$st = $pdo->query('SELECT TOP 3 RTRIM(Codigo) AS c, RTRIM(ISNULL(Divisa,\'\')) AS d FROM Empresas_Ges ORDER BY Codigo');
while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
  echo json_encode($r) . "\n";
}
