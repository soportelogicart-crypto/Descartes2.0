<?php

declare(strict_types=1);

/**
 * Smoke del mapeo de teclado tactil TPV (006 US2).
 * Uso: php scripts/smoke-tpv-teclado.php [H_GENERAL] [H_NIVEL]
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

use Descartes\Api\Config\Database;
use Descartes\Api\Repositories\ArtBarrasRepository;
use Descartes\Api\Services\Tpv\TpvArticuloService;
use Descartes\Api\Services\Tpv\TpvTecladoService;

$general = $argv[1] ?? '001';
$nivel = $argv[2] ?? '000';
$tarifa = (int) ($argv[3] ?? 1);

$pdo = Database::fromEnv();
$servicio = new TpvTecladoService($pdo);
$articulos = new TpvArticuloService($pdo, new ArtBarrasRepository($pdo));
$data = $servicio->obtenerNivel($general, $nivel);

if ($data === null) {
  echo "Teclado {$general} no encontrado\n";
  exit(1);
}

echo "Teclado {$data['general']} — {$data['nombre']} — nivel {$data['nivel']}\n\n";
printf("%-6s %-5s %-5s %-6s %-5s %-18s %-10s %-8s %-9s %-9s\n",
  'TECLA', 'FILA', 'COL', 'ANCHO', 'ALTO', 'ETIQ1', 'TIPO', 'ARTIC.', 'DESTINO', 'COLOR');

foreach ($data['botones'] as $b) {
  printf("%-6s %-5s %-5s %-6s %-5s %-18s %-10s %-8s %-9s %-9s\n",
    $b['tecla'],
    $b['fila'],
    $b['columna'],
    $b['ancho'],
    $b['alto'],
    (string) ($b['etiqueta1'] ?? ''),
    $b['tipo'],
    (string) ($b['articulo'] ?? ''),
    (string) ($b['nivelDestino'] ?? ''),
    (string) ($b['colorFondo'] ?? '')
  );
}

echo "\nPrecios (tarifa {$tarifa}) de los botones de articulo:\n";
foreach ($data['botones'] as $b) {
  if ($b['tipo'] !== 'articulo' || $b['articulo'] === null) {
    continue;
  }
  $art = $articulos->obtenerPrecio((string) $b['articulo'], $tarifa);
  if ($art === null) {
    echo "  {$b['articulo']}: NO EXISTE en Articulos\n";
    continue;
  }
  $nota = $art['precio'] > 0 ? '' : '  <-- sin PVP: el TPV pedira precio';
  printf(
    "  %-10s %-28s precio=%8.2f iva=%5.2f%s\n",
    $art['codigo'],
    substr($art['descripcion'], 0, 28),
    $art['precio'],
    $art['iva'],
    $nota
  );
}
