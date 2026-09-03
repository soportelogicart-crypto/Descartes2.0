<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions([
  \Descartes\Api\Controllers\InstalacionController::class => DI\autowire(),
  \Descartes\Api\Controllers\AuthController::class => DI\autowire(),
  \Descartes\Api\Controllers\MantenimientoController::class => DI\autowire(),
  \Descartes\Api\Controllers\EmpresaClienteController::class => DI\autowire(),
  \Descartes\Api\Controllers\ConfigEquipoController::class => DI\autowire(),
  \Descartes\Api\Controllers\DocumentoPlantillasController::class => DI\autowire(),
  \Descartes\Api\Controllers\RolController::class => DI\autowire(),
  \Descartes\Api\Controllers\ClienteController::class => DI\autowire(),
  \Descartes\Api\Controllers\CodigoPostalController::class => DI\autowire(),
  \Descartes\Api\Controllers\CampanaController::class => DI\autowire(),
  \Descartes\Api\Controllers\AlbaranesPeriodicosController::class => DI\autowire(),
  \Descartes\Api\Controllers\OfertaClienteController::class => DI\autowire(),
  \Descartes\Api\Controllers\OfertaProveedorController::class => DI\autowire(),
  \Descartes\Api\Controllers\ProveedorController::class => DI\autowire(),
  \Descartes\Api\Controllers\ArticuloController::class => DI\autowire(),
  \Descartes\Api\Controllers\VentasController::class => DI\autowire(),
  \Descartes\Api\Controllers\ComprasController::class => DI\autowire(),
  \Descartes\Api\Controllers\TpvController::class => DI\autowire(),
  \Descartes\Api\Controllers\EtiquetasController::class => DI\autowire(),
  \Descartes\Api\Controllers\FacturacionController::class => DI\autowire(),
  \Descartes\Api\Controllers\LogController::class => DI\autowire(),
  \Descartes\Api\Middleware\PermissionMiddleware::class => DI\autowire(),
]);
$container = $containerBuilder->build();

AppFactory::setContainer($container);
$app = AppFactory::create();

$basePath = $_ENV['APP_BASE_PATH'] ?? '';
if ($basePath === '') {
  $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
  if ($basePath === '/' || $basePath === '.') {
    $basePath = '';
  }
}
$app->setBasePath($basePath);

($bootstrap = require __DIR__ . '/../src/bootstrap.php')($app);
(require __DIR__ . '/../src/Routes/instalacion.php')($app);
(require __DIR__ . '/../src/Routes/auth.php')($app);
(require __DIR__ . '/../src/Routes/mantenimiento.php')($app);
(require __DIR__ . '/../src/Routes/ventas.php')($app);
(require __DIR__ . '/../src/Routes/compras.php')($app);
(require __DIR__ . '/../src/Routes/tpv.php')($app);
(require __DIR__ . '/../src/Routes/etiquetas.php')($app);
(require __DIR__ . '/../src/Routes/facturacion.php')($app);

$app->options('/{routes:.+}', function ($request, $response) {
  return $response;
});

$app->run();