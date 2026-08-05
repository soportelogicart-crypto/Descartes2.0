<?php

declare(strict_types=1);

require 'C:/xampp/htdocs/descartes-api/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable('C:/xampp/htdocs/descartes-api');
$dotenv->safeLoad();

use Descartes\Api\Config\Database;
use Descartes\Api\Repositories\ArtPreciosRepository;
use Descartes\Api\Repositories\ArticuloStockRepository;
use Descartes\Api\Services\ArticuloService;
use Descartes\Api\Services\DependencyCheckService;
use Descartes\Api\Services\MantenimientoService;
use Descartes\Api\Services\TiendaAlmacenService;

$pdo = Database::fromEnv();
$svc = new MantenimientoService(
  $pdo,
  new DependencyCheckService($pdo),
  new TiendaAlmacenService($pdo),
  new ArticuloService($pdo, new ArtPreciosRepository($pdo), new ArticuloStockRepository($pdo))
);

foreach (['puestos-trabajo' => ['activo' => true, 'pageSize' => 3], 'tiendas' => ['activo' => true, 'conParametros' => true, 'pageSize' => 10]] as $entidad => $query) {
  try {
    $r = $svc->list($entidad, $query);
    echo "{$entidad} OK total={$r['total']}\n";
    $item = $svc->get($entidad, (string) $r['items'][0]['codigo']);
    echo "  get first OK keys=" . count($item ?? []) . "\n";
  } catch (Throwable $e) {
    echo "{$entidad} FAIL: {$e->getMessage()}\n";
  }
}

$puesto = $svc->get('puestos-trabajo', '01');
if ($puesto && !empty($puesto['tiendaCodigo'])) {
  $tienda = $svc->get('tiendas', (string) $puesto['tiendaCodigo']);
  echo 'tienda arqueo: ' . ($tienda['codigo'] ?? '?') . ' almacen=' . ($tienda['almacenCodigo'] ?? '?') . "\n";
}
