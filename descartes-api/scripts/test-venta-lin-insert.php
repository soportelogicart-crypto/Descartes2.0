<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Descartes\Api\Config\Database;
use Descartes\Api\Services\Ventas\VentaConsultaService;
use Descartes\Api\Services\Ventas\VentaEscrituraService;

try {
  $pdo = Database::fromEnv();
  $consulta = new VentaConsultaService($pdo);
  $svc = new VentaEscrituraService($pdo, $consulta, new \Descartes\Api\Services\Ventas\ArqueoService($pdo));

  $res = $svc->reservarAlbaran('1', null);
  $alb = (int) $res['albaran'];

  $svc->crear([
    'empresa' => '1',
    'albaran' => $alb,
    'cliente' => '1',
    'lineas' => [
      ['articulo' => '001410', 'cantidad' => 1, 'precio' => 1.0, 'descripcion' => 'T1'],
      ['articulo' => '001411', 'cantidad' => 1, 'precio' => 2.0, 'descripcion' => 'T2'],
    ],
  ]);
  echo "OK albaran=$alb\n";
} catch (Throwable $e) {
  echo 'ERR: ' . $e->getMessage() . "\n";
  exit(1);
}
