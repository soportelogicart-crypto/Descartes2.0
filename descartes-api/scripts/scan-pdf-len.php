<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$root = $argv[1] ?? '';
if ($root === '') {
  exit(1);
}

$parser = new Smalot\PdfParser\Parser();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($it as $file) {
  if (!$file->isFile() || strtolower($file->getExtension()) !== 'pdf') {
    continue;
  }
  try {
    $text = trim($parser->parseFile($file->getPathname())->getText());
    $len = strlen($text);
    if ($len >= 2500 && $len <= 2950) {
      echo $len . "\t" . $file->getPathname() . "\n";
    }
  } catch (Throwable) {
    // skip
  }
}
