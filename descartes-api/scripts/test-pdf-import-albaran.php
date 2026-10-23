<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Descartes\Api\Config\Database;
use Descartes\Api\Repositories\ArtBarrasRepository;
use Descartes\Api\Services\Compras\AlbaranCompraImportService;
use Descartes\Api\Services\Compras\AlbaranCompraPdfImportService;

$path = $argv[1] ?? '';
if ($path === '' || !is_file($path)) {
  fwrite(STDERR, "Uso: php test-pdf-import-albaran.php ruta.pdf\n");
  exit(1);
}

$raw = file_get_contents($path);
if ($raw === false) {
  exit(1);
}

$pdf = new AlbaranCompraPdfImportService(
  new AlbaranCompraImportService(Database::getConnection(), new ArtBarrasRepository(Database::getConnection()))
);

try {
  $res = $pdf->previewPdf([
    'proveedor' => '0001',
    'empresa' => '1',
    'almacen' => 1,
    'pdfBase64' => base64_encode($raw),
  ]);
  echo json_encode([
    'suAlbaran' => $res['suAlbaran'] ?? null,
    'lineasDetectadas' => $res['lineasDetectadas'] ?? count($res['lineas'] ?? []),
    'lineas' => $res['lineas'] ?? [],
    'avisos' => $res['avisos'] ?? [],
  ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $e) {
  fwrite(STDERR, $e->getMessage() . "\n");
  exit(1);
}
