<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

use Descartes\Api\Config\Database;
use Descartes\Api\Config\EntityConfig;
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
  new ArticuloService(
    $pdo,
    new ArtPreciosRepository($pdo),
    new ArticuloStockRepository($pdo)
  )
);

echo "=== EntityConfig puestos-trabajo ===\n";
$config = EntityConfig::get('puestos-trabajo');
echo 'fields count: ' . count($config['fields'] ?? []) . "\n";
echo 'booleanFields count: ' . count($config['booleanFields'] ?? []) . "\n\n";

foreach (['puestos-trabajo', 'tiendas'] as $entidad) {
  echo "--- list {$entidad} ---\n";
  try {
    $query = ['pageSize' => 5];
    if ($entidad === 'puestos-trabajo') {
      $query['activo'] = true;
    }
    if ($entidad === 'tiendas') {
      $query['activo'] = true;
      $query['conParametros'] = true;
    }
    $r = $svc->list($entidad, $query);
    echo "OK total={$r['total']} items=" . count($r['items']) . "\n";
    foreach ($r['items'] as $item) {
      echo '  ' . ($item['codigo'] ?? '?') . "\n";
    }
  } catch (Throwable $e) {
    echo 'FAIL: ' . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
  }
  echo "\n";
}

echo "--- get puesto 01 ---\n";
try {
  $item = $svc->get('puestos-trabajo', '01');
  echo $item ? 'OK ' . json_encode($item, JSON_UNESCAPED_UNICODE) . "\n" : "NULL\n";
} catch (Throwable $e) {
  echo 'FAIL: ' . $e->getMessage() . "\n";
}

$bajaCol = $pdo->query("SELECT COL_LENGTH('dbo.Puestos', 'Baja')")->fetchColumn();
echo "\nPuestos.Baja column length: " . var_export($bajaCol, true) . "\n";
