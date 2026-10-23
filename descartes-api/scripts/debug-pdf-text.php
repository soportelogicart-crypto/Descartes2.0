<?php

require dirname(__DIR__) . '/vendor/autoload.php';

$path = $argv[1] ?? '';
if ($path === '' || !is_file($path)) {
  fwrite(STDERR, "Uso: php debug-pdf-text.php ruta.pdf\n");
  exit(1);
}

$parser = new Smalot\PdfParser\Parser();
$pdf = $parser->parseFile($path);
$text = $pdf->getText();
echo 'LEN=' . strlen($text) . "\n";
echo $text;
