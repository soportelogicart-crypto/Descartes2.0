<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

use Descartes\Api\Config\Database;

$pdo = Database::fromEnv();
$nums = [226505, 226506, 226507, 226508, 226509, 226510];
foreach ($nums as $n) {
  $st = $pdo->prepare("SELECT RTRIM(Empresa) e, RTRIM(FacturaTipo) ft, Factura, Fecha, Importe, RTRIM(ISNULL(Estado,'')) est FROM Facturas WHERE Factura=:f AND RTRIM(FacturaTipo)='F'");
  $st->execute(['f' => $n]);
  $r = $st->fetch(PDO::FETCH_ASSOC);
  echo $n . ' ' . json_encode($r, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
