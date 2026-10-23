<?php

/**
 * Entrada de la SPA: no se cachea. El JS/CSS en assets/ sí (llevan hash).
 */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: text/html; charset=utf-8');

$index = __DIR__ . '/index.html';
if (!is_readable($index)) {
  http_response_code(500);
  echo 'Falta index.html';
  exit;
}

readfile($index);
