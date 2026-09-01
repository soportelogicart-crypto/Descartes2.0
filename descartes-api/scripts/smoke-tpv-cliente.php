<?php

declare(strict_types=1);

/**
 * Smoke de la busqueda de clientes del TPV (006 US6).
 * Uso: php scripts/smoke-tpv-cliente.php <codigo|nif|nombre|telefono>
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

use Descartes\Api\Config\Database;
use Descartes\Api\Services\Tpv\TpvClienteService;

$query = $argv[1] ?? '';
if (trim($query) === '') {
  echo "Uso: php scripts/smoke-tpv-cliente.php <codigo|nif|nombre|telefono>\n";
  exit(1);
}

$servicio = new TpvClienteService(Database::fromEnv());
$items = $servicio->buscar($query);

printf("Coincidencias para \"%s\": %d\n", $query, count($items));
foreach ($items as $c) {
  printf(
    "  %-12s %-32s nif=%-12s pob=%-18s tarifa=%d\n",
    $c['codigo'],
    mb_substr($c['razonSocial'], 0, 32),
    $c['nif'],
    mb_substr($c['poblacion'], 0, 18),
    $c['tarifa']
  );
}

if ($items === []) {
  exit(2);
}
