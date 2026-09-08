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

echo "=== Puestos y EmpresaArqueo ===\n";
$stmt = $pdo->query('SELECT [Puesto], [Descripcion], [EmpresaArqueo] FROM [Puestos] ORDER BY [Puesto]');
while ($row = $stmt->fetch()) {
  $p = trim((string) $row['Puesto']);
  $ea = $row['EmpresaArqueo'];
  $eaTrim = trim((string) ($ea ?? ''));
  echo "Puesto={$p} EA=[{$eaTrim}] desc={$row['Descripcion']}\n";

  try {
    $item = $svc->get('puestos-trabajo', $p);
    if (!$item) {
      echo "  GET puesto: NULL\n";
      continue;
    }
    $tc = trim((string) ($item['tiendaCodigo'] ?? ''));
    echo "  API tiendaCodigo=[{$tc}]\n";
    if ($tc === '' || $tc === '0') {
      echo "  SKIP tienda (vacio o 0)\n";
      continue;
    }
    $tienda = $svc->get('tiendas', $tc);
    if (!$tienda) {
      echo "  GET tienda {$tc}: NULL\n";
      continue;
    }
    echo "  Tienda: {$tienda['codigo']} - {$tienda['nombre']} almacen={$tienda['almacenCodigo']}\n";
    $alm = $tienda['almacenCodigo'] ?? null;
    if ($alm !== null && (int) $alm > 0) {
      $a = $svc->get('almacenes', (string) $alm);
      echo $a ? "  Almacen: {$a['codigo']} - {$a['descripcion']}\n" : "  GET almacen {$alm}: NULL\n";
    }
  } catch (Throwable $e) {
    echo '  ERROR: ' . $e->getMessage() . "\n";
  }
}

echo "\n=== Parametros empresas ===\n";
$stmt = $pdo->query('SELECT p.[Empresa], e.[Nombre], e.[Almacen] FROM [Parametros] p LEFT JOIN [Empresas_Ges] e ON RTRIM(e.[Codigo]) = RTRIM(p.[Empresa])');
while ($row = $stmt->fetch()) {
  echo "Empresa={$row['Empresa']} {$row['Nombre']} Almacen={$row['Almacen']}\n";
}
