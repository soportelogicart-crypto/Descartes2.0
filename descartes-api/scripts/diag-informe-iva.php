<?php

declare(strict_types=1);

/**
 * Diagnóstico local: cuenta filas informe IVA (sin imprimir credenciales).
 * Uso: php scripts/diag-informe-iva.php 2026-09-01 2026-09-30 [empresa]
 */

require dirname(__DIR__) . '/vendor/autoload.php';

(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

use Descartes\Api\Config\Database;
use Descartes\Api\Services\Listados\InformeIvaListadoService;

$desde = $argv[1] ?? '2026-09-01';
$hasta = $argv[2] ?? '2026-09-30';
$empresa = $argv[3] ?? '';

$pdo = Database::fromEnv();
$svc = new InformeIvaListadoService($pdo);
$out = $svc->generar([
  'fechaDesde' => $desde,
  'fechaHasta' => $hasta,
  'empresa' => $empresa,
  'tipoDocumento' => 'facturas',
  'facturaDesde' => (int) ($argv[4] ?? 0),
  'facturaHasta' => (int) ($argv[5] ?? 0),
]);

echo json_encode([
  'desde' => $desde,
  'hasta' => $hasta,
  'empresa' => $empresa,
  'tickets' => count($out['items']),
  'totales' => $out['totales'],
  'tiposIva' => array_column($out['resumenPorIva'], 'pjeIva'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
