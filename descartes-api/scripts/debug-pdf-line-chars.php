<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$path = $argv[1] ?? '';
if ($path === '' || !is_file($path)) {
  exit(1);
}

$parser = new Smalot\PdfParser\Parser();
$text = trim($parser->parseFile($path)->getText());

foreach (preg_split('/\R/u', $text) ?: [] as $line) {
  if (!str_contains($line, '0010031961') && !str_contains($line, '454')) {
    continue;
  }
  echo 'LINE JSON: ' . json_encode($line, JSON_UNESCAPED_UNICODE) . "\n";
  echo 'tabs=' . substr_count($line, "\t") . ' len=' . strlen($line) . "\n\n";
}
