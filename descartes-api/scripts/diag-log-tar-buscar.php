<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

$aut = $argv[1] ?? '000325';
$clr = $argv[2] ?? '6014';
$imp = isset($argv[3]) ? (float) $argv[3] : 0.10;

$svc = new Descartes\Api\Services\Ventas\RedsysLogTarjetaService();
$hit = $svc->buscarCobro($aut, $clr, $imp);
echo json_encode($hit, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
