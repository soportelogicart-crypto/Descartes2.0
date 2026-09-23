<?php

declare(strict_types=1);

/**
 * Comprueba los filtros parciales de columna que resuelve el servidor
 * (ventas, albaranes de compra y pedidos a proveedor).
 * Uso: php scripts/diag-grid-filtros-columna.php 152
 */

require dirname(__DIR__) . '/vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

use Descartes\Api\Config\Database;
use Descartes\Api\Services\Compras\AlbaranCompraConsultaService;
use Descartes\Api\Services\Compras\PedidoProveedorConsultaService;
use Descartes\Api\Services\Ventas\VentaConsultaService;

$texto = trim((string) ($argv[1] ?? '1'));
$pdo = Database::fromEnv();

function out(string $title, array $res): void
{
  echo "\n=== {$title} ===\n";
  echo 'total=' . $res['total'] . ' devueltos=' . count($res['items']) . "\n";
  foreach (array_slice($res['items'], 0, 5) as $item) {
    echo '  ' . json_encode($item, JSON_UNESCAPED_UNICODE) . "\n";
  }
}

$ventas = new VentaConsultaService($pdo);
out(
  "Ventas albaranTexto={$texto}",
  $ventas->listar(['albaranTexto' => $texto, 'pageSize' => 5])
);
out(
  "Ventas facturaTexto={$texto}",
  $ventas->listar(['facturaTexto' => $texto, 'pageSize' => 5])
);

$compras = new AlbaranCompraConsultaService($pdo);
out(
  "Compras albaranTexto={$texto}",
  $compras->listar(['albaranTexto' => $texto, 'pageSize' => 5])
);

$pedidos = new PedidoProveedorConsultaService($pdo);
out(
  "Pedidos pedidoTexto={$texto}",
  $pedidos->listar(['pedidoTexto' => $texto, 'pageSize' => 5])
);
