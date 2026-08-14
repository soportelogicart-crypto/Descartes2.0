<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Descartes\Api\Config\Database;
use Descartes\Api\Services\Compras\AlbaranCompraConsultaService;
use Descartes\Api\Services\Compras\AlbaranCompraEscrituraService;

$pdo = Database::fromEnv();
$svc = new AlbaranCompraEscrituraService($pdo, new AlbaranCompraConsultaService($pdo));
$ref = new ReflectionClass($svc);
$m = $ref->getMethod('prepararLineasYTotales');
$m->setAccessible(true);
$lineas = [
  ['articulo' => '9999', 'cantidad' => 1, 'precio' => 6.2, 'pjeDto' => 0, 'dto1' => 0, 'dto2' => 0, 'dto3' => 0],
  ['articulo' => '9996', 'cantidad' => 1, 'precio' => 7.27, 'pjeDto' => 0, 'dto1' => 0, 'dto2' => 0, 'dto3' => 0],
  ['articulo' => '9997', 'cantidad' => 1, 'precio' => 4, 'pjeDto' => 0, 'dto1' => 0, 'dto2' => 0, 'dto3' => 0],
  ['articulo' => '9998', 'cantidad' => 1, 'precio' => 12, 'pjeDto' => 0, 'dto1' => 0, 'dto2' => 0, 'dto3' => 0],
  ['articulo' => '9995', 'cantidad' => 1, 'precio' => 10.91, 'pjeDto' => 0, 'dto1' => 0, 'dto2' => 0, 'dto3' => 0],
];
[$out, $tot] = $m->invoke($svc, $lineas, ['importeTransporte' => 10, 'brutoConTransporte' => 50]);
echo "With Bruto+Trans=50:\n";
echo "Coef: {$tot['coeficienteTransporte']}\n";
echo "ImporteAlb: {$tot['importeAlb']}\n";
echo "Line1 precio: {$out[0]['precio']}\n";
