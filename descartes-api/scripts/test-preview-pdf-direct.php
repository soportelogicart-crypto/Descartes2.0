<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Descartes\Api\Services\Compras\AlbaranCompraPdfImportService;

$path = $argv[1] ?? '';
if ($path === '' || !is_file($path)) {
  fwrite(STDERR, "Uso: php test-preview-pdf-direct.php ruta.pdf\n");
  exit(1);
}

$raw = file_get_contents($path);
if ($raw === false) {
  exit(1);
}

$importStub = new class {
  /** @param array<string, mixed> $body */
  public function previewFilas(array $body): array
  {
    return ['lineas' => [], 'avisos' => []];
  }
};

/** @phpstan-ignore-next-line */
$pdf = new AlbaranCompraPdfImportService($importStub);

try {
  $res = $pdf->previewPdf([
    'proveedor' => '0001',
    'empresa' => '1',
    'almacen' => 1,
    'pdfBase64' => base64_encode($raw),
  ]);
  echo json_encode([
    'ok' => true,
    'lineasDetectadas' => $res['lineasDetectadas'] ?? 0,
    'suAlbaran' => $res['suAlbaran'] ?? null,
  ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $e) {
  fwrite(STDERR, $e->getMessage() . "\n");
  exit(1);
}
