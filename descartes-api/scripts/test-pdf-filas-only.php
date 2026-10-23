<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$path = $argv[1] ?? '';
if ($path === '' || !is_file($path)) {
  exit(1);
}

$parser = new Smalot\PdfParser\Parser();
$text = trim($parser->parseFile($path)->getText());

$service = new ReflectionClass(\Descartes\Api\Services\Compras\AlbaranCompraPdfImportService::class);
$inst = $service->newInstanceWithoutConstructor();
$m = $service->getMethod('extraerFilas');
$m->setAccessible(true);
$plantilla = ['useHeuristics' => true, 'lineaPattern' => ''];
$filas = $m->invoke($inst, $text, $plantilla);
echo json_encode($filas, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
