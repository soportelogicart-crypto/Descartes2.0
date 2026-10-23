<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$path = $argv[1] ?? '';
$targetLen = (int) ($argv[2] ?? 2726);
if ($path === '' || !is_file($path)) {
  exit(1);
}

$parser = new Smalot\PdfParser\Parser();
$text = trim($parser->parseFile($path)->getText());

$cls = new ReflectionClass(\Descartes\Api\Services\Compras\AlbaranCompraPdfImportService::class);
$inst = $cls->newInstanceWithoutConstructor();
$norm = $cls->getMethod('normalizarTextoPdf');
$norm->setAccessible(true);
$legible = $cls->getMethod('textoPareceLegible');
$legible->setAccessible(true);
$extraerFilas = $cls->getMethod('extraerFilas');
$extraerFilas->setAccessible(true);
$plantilla = ['useHeuristics' => true, 'lineaPattern' => ''];

foreach ([$text, mb_substr($text, 0, $targetLen), mb_substr($text, 0, $targetLen + 200)] as $i => $chunk) {
  $chunk = $norm->invoke($inst, $chunk);
  $f = $extraerFilas->invoke($inst, $chunk, $plantilla);
  echo "chunk$i len=" . mb_strlen($chunk) . ' legible=' . ($legible->invoke($inst, $chunk) ? 'y' : 'n') . ' filas=' . count($f) . "\n";
}
