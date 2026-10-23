<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$path = $argv[1] ?? 'c:/descartes-2.0/albaranes/DiferidaYrectificatva.pdf';
$parser = new Smalot\PdfParser\Parser();
$text = trim($parser->parseFile($path)->getText());
$text = str_replace("\t", ' ', $text);

$cls = new ReflectionClass(\Descartes\Api\Services\Compras\AlbaranCompraPdfImportService::class);
$inst = $cls->newInstanceWithoutConstructor();
$norm = $cls->getMethod('normalizarTextoPdf');
$norm->setAccessible(true);
$ext = $cls->getMethod('extraerFilas');
$ext->setAccessible(true);
$text = $norm->invoke($inst, $text);
echo 'len=' . mb_strlen($text) . ' filas=' . count($ext->invoke($inst, $text, ['useHeuristics' => true, 'lineaPattern' => ''])) . "\n";
