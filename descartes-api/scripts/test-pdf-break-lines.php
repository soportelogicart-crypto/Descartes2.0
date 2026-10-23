<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$path = $argv[1] ?? '';
$parser = new Smalot\PdfParser\Parser();
$text = trim($parser->parseFile($path)->getText());

$cls = new ReflectionClass(\Descartes\Api\Services\Compras\AlbaranCompraPdfImportService::class);
$inst = $cls->newInstanceWithoutConstructor();
$m = $cls->getMethod('extraerFilas');
$m->setAccessible(true);
$plantilla = ['useHeuristics' => true, 'lineaPattern' => ''];

$norm = $cls->getMethod('normalizarTextoPdf');
$norm->setAccessible(true);
$broken = preg_replace('/\t/', "\n", $text) ?? $text;
$broken = $norm->invoke($inst, $broken);
echo 'tabs-to-newlines filas=' . count($m->invoke($inst, $broken, $plantilla)) . "\n";

$oneLine = preg_replace('/\R+/', ' ', $text) ?? $text;
echo 'one-line filas=' . count($m->invoke($inst, $oneLine, $plantilla)) . "\n";
