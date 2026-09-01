<?php

declare(strict_types=1);

/**
 * Smoke del contexto de caja TPV (006 US1/US3).
 * Uso: php scripts/smoke-tpv-contexto.php <empresa> <puesto>
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

use Descartes\Api\Config\Database;
use Descartes\Api\Services\Tpv\TpvContextoService;
use Descartes\Api\Services\Ventas\ArqueoService;

$empresa = $argv[1] ?? '';
$puesto = $argv[2] ?? '';

if (trim($empresa) === '' || trim($puesto) === '') {
  echo "Uso: php scripts/smoke-tpv-contexto.php <empresa> <puesto>\n";
  exit(1);
}

$pdo = Database::fromEnv();
$servicio = new TpvContextoService($pdo, new ArqueoService($pdo));

$ctx = $servicio->resolver($empresa, $puesto);

printf(
  "Tienda %s  Puesto %s  Sesion %d  Teclado %s  Tarifa %d\n",
  $ctx['empresa'],
  $ctx['puesto'],
  $ctx['sesion'],
  $ctx['tecladoGeneral'],
  $ctx['tarifa']
);

$formas = $ctx['formasPago'] ?? [];
printf("Formas de pago de contado (CobroDeArqueo): %d\n", count($formas));
foreach ($formas as $f) {
  printf(
    "  %-4s %-24s cajon=%s copias=%d\n",
    $f['codigo'],
    $f['descripcion'],
    !empty($f['abrirCajon']) ? 'SI' : 'no',
    $f['copiasTicket']
  );
}

if ($formas === []) {
  echo "AVISO: sin formas de pago de contado, el TPV no podra cobrar\n";
  exit(2);
}
