<?php
require dirname(__DIR__) . '/vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();
$pdo = Descartes\Api\Config\Database::fromEnv();
$st = $pdo->query("SELECT COUNT(*) FROM Facturas WHERE Fecha>='2026-01-01' AND Fecha<'2026-04-01' AND RTRIM(FacturaTipo)='F'");
echo "F any: ".$st->fetchColumn()."\n";
$st = $pdo->query("SELECT COUNT(*) FROM Facturas WHERE Fecha>='2026-01-01' AND Fecha<'2026-04-01' AND RTRIM(FacturaTipo)='F' AND ISNULL(Importe,0)>0");
echo "F importe>0: ".$st->fetchColumn()."\n";
