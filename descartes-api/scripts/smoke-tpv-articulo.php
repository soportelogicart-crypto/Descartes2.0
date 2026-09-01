<?php

declare(strict_types=1);

/**
 * Smoke de entrada por codigo / Alternativo / EAN en el TPV (006 US2/US5).
 * Uso: php scripts/smoke-tpv-articulo.php <texto> [tarifa] [ambito]
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

use Descartes\Api\Config\Database;
use Descartes\Api\Repositories\ArtBarrasRepository;
use Descartes\Api\Services\Tpv\TpvArticuloService;

$query = $argv[1] ?? '';
$tarifa = (int) ($argv[2] ?? 1);
$ambito = trim((string) ($argv[3] ?? 'todos'));

if (trim($query) === '') {
  echo "Uso: php scripts/smoke-tpv-articulo.php <texto> [tarifa] [todos|articulo|macrofamilia|familia|subfamilia|agrupacion]\n";
  exit(1);
}

$pdo = Database::fromEnv();
$servicio = new TpvArticuloService($pdo, new ArtBarrasRepository($pdo));

echo "Buscando \"{$query}\" con tarifa {$tarifa}...\n";

$art = $servicio->resolver($query, $tarifa);
if ($art === null) {
  echo "NO ENCONTRADO por codigo/Alternativo/EAN, probando busqueda por texto...\n";
  $items = $servicio->buscar($query, $tarifa, 10, $ambito);
  if (!$items) {
    echo "NO ENCONTRADO: tampoco hay coincidencias por descripcion\n";
    exit(2);
  }
  foreach ($items as $item) {
    printf(
      "  %-15s %-35s %8.2f  %s > %s > %s > %s\n",
      $item['codigo'],
      $item['descripcion'],
      $item['precio'],
      $item['macroFamiliaDescripcion'],
      $item['familiaDescripcion'],
      $item['subfamiliaDescripcion'],
      $item['agrupacionDescripcion']
    );
  }
  exit(0);
}

printf(
  "OK  codigo=%s  match=%s  uds/paquete=%s\n    descripcion=%s\n    precio=%.2f  iva=%.2f  bloqueado=%s\n",
  $art['codigo'],
  $art['matchPor'],
  $art['unidadesPaquete'],
  $art['descripcion'],
  $art['precio'],
  $art['iva'],
  !empty($art['bloqueado']) ? 'SI' : 'no'
);

if ((float) $art['precio'] <= 0) {
  echo "    AVISO: sin PVP en la tarifa, el TPV pedira precio al cajero\n";
}
