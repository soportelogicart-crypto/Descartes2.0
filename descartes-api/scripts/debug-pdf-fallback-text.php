<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$path = $argv[1] ?? '';
$raw = file_get_contents($path);
if ($raw === false) {
  exit(1);
}

$service = new ReflectionClass(\Descartes\Api\Services\Compras\AlbaranCompraPdfImportService::class);
$inst = $service->newInstanceWithoutConstructor();
$basic = $service->getMethod('extraerTextoPdfBasico');
$basic->setAccessible(true);
$text = trim((string) $basic->invoke($inst, $raw));
echo "LEN=" . strlen($text) . "\n";
echo substr($text, 0, 800) . "\n";

$m = $service->getMethod('extraerFilas');
$m->setAccessible(true);
$filas = $m->invoke($inst, $text, ['useHeuristics' => true, 'lineaPattern' => '']);
echo "\nFilas fallback: " . count($filas) . "\n";
