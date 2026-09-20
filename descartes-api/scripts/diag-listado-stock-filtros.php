<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

use Descartes\Api\Config\Database;
use Descartes\Api\Services\Listados\StockListadoService;

$s = new StockListadoService(Database::fromEnv());
$base = ['agruparPor' => 'articulo', 'ocultarCero' => 'false'];

foreach (['todos', 'superior_0', 'menor_0', 'igual_0', 'diferente_0', 'bloqueo_venta'] as $f) {
  $out = $s->generar($base + ['stockFiltro' => $f]);
  echo $f . ': ' . $out['totales']['filas'] . ' filas' . PHP_EOL;
}
