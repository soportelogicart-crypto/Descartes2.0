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
echo "Original lines: " . count($filas) . "\n";

// Simula PDF que parte la línea de artículo en dos renglones (sin tab en la 2ª).
$broken = str_replace(
  "0010031961\t16,68\t1,00 16,68 10,00\t18,35\tPEONIA 3L",
  "0010031961\t16,68\n1,00 16,68 10,00\t18,35\tPEONIA 3L",
  $text
);
$filas2 = $m->invoke($inst, $broken, $plantilla);
echo "Broken line split: " . count($filas2) . "\n";

// Sin tabs (solo espacios).
$spaces = str_replace("\t", ' ', $text);
$filas3 = $m->invoke($inst, $spaces, $plantilla);
echo "Spaces only: " . count($filas3) . "\n";
