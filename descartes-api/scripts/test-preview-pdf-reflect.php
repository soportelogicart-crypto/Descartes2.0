<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$path = $argv[1] ?? '';
if ($path === '' || !is_file($path)) {
  exit(1);
}

$raw = file_get_contents($path);
if ($raw === false) {
  exit(1);
}

$cls = new ReflectionClass(\Descartes\Api\Services\Compras\AlbaranCompraPdfImportService::class);
$inst = $cls->newInstanceWithoutConstructor();

$extraerTexto = $cls->getMethod('extraerTextoPdf');
$extraerTexto->setAccessible(true);
$legible = $cls->getMethod('textoPareceLegible');
$legible->setAccessible(true);
$extraerFilas = $cls->getMethod('extraerFilas');
$extraerFilas->setAccessible(true);
$cargar = $cls->getMethod('cargarPlantilla');
$cargar->setAccessible(true);

try {
  $texto = $extraerTexto->invoke($inst, $raw);
} catch (Throwable $e) {
  echo "extraerTexto ERROR: " . $e->getMessage() . "\n";
  exit(1);
}

echo 'len=' . strlen(trim($texto)) . ' legible=' . ($legible->invoke($inst, $texto) ? 'yes' : 'no') . "\n";
$plantilla = $cargar->invoke($inst, '0001');
$filas = $extraerFilas->invoke($inst, $texto, $plantilla);
echo 'filas=' . count($filas) . "\n";
if ($filas === [] && strlen($texto) > 0) {
  echo "sample:\n" . mb_substr($texto, 0, 400) . "\n";
}
