<?php

/**
 * Reenvía /api/* (public/.htaccess) al PHP de Descartes en /api-descartes/public.
 *
 * Por defecto usa el mismo host que la gestión (no hace falta pegar dominios a mano).
 * Pruebas contra el XAMPP de casa: descomente $backendManual abajo.
 */
$backendManual = '';
// $backendManual = 'http://213.96.60.240:9080/descartes-api/public';

if ($backendManual !== '') {
  $backend = rtrim($backendManual, '/');
} else {
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
  $scheme = $https ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $backend = $scheme . '://' . $host . '/api-descartes/public';
}

$uri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($uri, PHP_URL_PATH) ?: '';
$query = parse_url($uri, PHP_URL_QUERY);

if (!preg_match('#/api(?:/|$)#', $path)) {
  http_response_code(404);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['error' => 'Ruta de API no válida']);
  exit;
}

$apiPath = $path;
if (preg_match('#/descartes(/api(?:/.*)?)$#', $path, $m)) {
  $apiPath = $m[1];
}

$url = $backend . $apiPath;
if ($query) {
  $url .= '?' . $query;
}

$headers = [];
foreach (function_exists('getallheaders') ? getallheaders() : [] as $name => $value) {
  $key = strtolower((string) $name);
  if (in_array($key, ['host', 'content-length', 'connection'], true)) {
    continue;
  }
  $headers[] = $name . ': ' . $value;
}

$body = file_get_contents('php://input');
if ($body === false) {
  $body = '';
}

$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_CUSTOMREQUEST => $_SERVER['REQUEST_METHOD'] ?? 'GET',
  CURLOPT_HTTPHEADER => $headers,
  CURLOPT_POSTFIELDS => $body,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HEADER => true,
  CURLOPT_FOLLOWLOCATION => false,
  CURLOPT_TIMEOUT => 60,
  CURLOPT_CONNECTTIMEOUT => 10,
]);

$raw = curl_exec($ch);
if ($raw === false) {
  http_response_code(502);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['error' => 'No se pudo contactar con la API: ' . curl_error($ch)]);
  curl_close($ch);
  exit;
}

$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
curl_close($ch);

$headerBlob = substr($raw, 0, $headerSize);
$responseBody = substr($raw, $headerSize);

http_response_code($status > 0 ? $status : 502);

foreach (explode("\r\n", $headerBlob) as $line) {
  if ($line === '' || stripos($line, 'HTTP/') === 0) {
    continue;
  }
  $skip = ['transfer-encoding', 'content-length', 'connection'];
  $name = strtolower(strtok($line, ':'));
  if (in_array($name, $skip, true)) {
    continue;
  }
  header($line, false);
}

echo $responseBody;
