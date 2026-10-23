<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$root = $argv[1] ?? '';
$parser = new Smalot\PdfParser\Parser();
$cls = new ReflectionClass(\Descartes\Api\Services\Compras\AlbaranCompraPdfImportService::class);
$inst = $cls->newInstanceWithoutConstructor();
$norm = $cls->getMethod('normalizarTextoPdf');
$norm->setAccessible(true);
$leg = $cls->getMethod('textoPareceLegible');
$leg->setAccessible(true);
$ext = $cls->getMethod('extraerFilas');
$ext->setAccessible(true);
$plantilla = ['useHeuristics' => true, 'lineaPattern' => ''];

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($it as $file) {
  if (!$file->isFile() || strtolower($file->getExtension()) !== 'pdf') {
    continue;
  }
  try {
    $text = $norm->invoke($inst, trim($parser->parseFile($file->getPathname())->getText()));
    if (!$leg->invoke($inst, $text)) {
      continue;
    }
    $filas = $ext->invoke($inst, $text, $plantilla);
    $len = mb_strlen($text);
    if ($filas === [] && $len > 500) {
      echo $len . "\t" . $file->getPathname() . "\n";
    }
  } catch (Throwable) {
  }
}
