<?php

declare(strict_types=1);

use Descartes\Api\Middleware\ClienteDbMiddleware;
use Descartes\Api\Middleware\CorsMiddleware;
use Descartes\Api\Middleware\SessionMiddleware;
use Descartes\Api\Http\ErrorResponse;
use Descartes\Api\Logging\FileLogger;
use Descartes\Api\RequestContext;
use Psr\Log\LoggerInterface;
use Descartes\Api\Repositories\ArtPreciosRepository;
use Descartes\Api\Repositories\ArticuloStockRepository;
use Descartes\Api\Repositories\ClientesContactosRepository;
use Descartes\Api\Repositories\ClientesDireccionesRepository;
use Descartes\Api\Repositories\CodigoPostalRepository;
use Descartes\Api\Repositories\ConfigEquipoRepository;
use Descartes\Api\Repositories\EmpresaClienteRepository;
use Descartes\Api\Repositories\OfertasClientesRepository;
use Descartes\Api\Repositories\RolPermisoRepository;
use Descartes\Api\Repositories\RolRepository;
use Descartes\Api\Services\ArticuloService;
use Descartes\Api\Services\ConfigEquipoService;
use Descartes\Api\Services\DependencyCheckService;
use Descartes\Api\Services\TiendaAlmacenService;
use Descartes\Api\Services\EmpresaClienteService;
use Descartes\Api\Services\MantenimientoService;
use Descartes\Api\Services\PermissionService;
use Descartes\Api\Services\RolService;
use Psr\Container\ContainerInterface;
use Slim\App;

return function (App $app): void {
  $container = $app->getContainer();

  $container->set(PDO::class, static fn () => RequestContext::getPdo());

  $container->set(PermissionService::class, static fn (ContainerInterface $c) => new PermissionService($c->get(PDO::class)));

  $container->set(DependencyCheckService::class, static fn (ContainerInterface $c) => new DependencyCheckService($c->get(PDO::class)));

  $container->set(TiendaAlmacenService::class, static fn (ContainerInterface $c) => new TiendaAlmacenService($c->get(PDO::class)));

  $container->set(ArtPreciosRepository::class, static fn (ContainerInterface $c) => new ArtPreciosRepository($c->get(PDO::class)));
  $container->set(ArticuloStockRepository::class, static fn (ContainerInterface $c) => new ArticuloStockRepository($c->get(PDO::class)));
  $container->set(ClientesDireccionesRepository::class, static fn (ContainerInterface $c) => new ClientesDireccionesRepository($c->get(PDO::class)));
  $container->set(ClientesContactosRepository::class, static fn (ContainerInterface $c) => new ClientesContactosRepository($c->get(PDO::class)));
  $container->set(OfertasClientesRepository::class, static fn (ContainerInterface $c) => new OfertasClientesRepository($c->get(PDO::class)));
  $container->set(CodigoPostalRepository::class, static fn (ContainerInterface $c) => new CodigoPostalRepository($c->get(PDO::class)));
  $container->set(ArticuloService::class, static fn (ContainerInterface $c) => new ArticuloService(
    $c->get(PDO::class),
    $c->get(ArtPreciosRepository::class),
    $c->get(ArticuloStockRepository::class)
  ));

  $container->set(MantenimientoService::class, static fn (ContainerInterface $c) => new MantenimientoService(
    $c->get(PDO::class),
    $c->get(DependencyCheckService::class),
    $c->get(TiendaAlmacenService::class),
    $c->get(ArticuloService::class)
  ));

  $container->set(EmpresaClienteRepository::class, static fn (ContainerInterface $c) => new EmpresaClienteRepository($c->get(PDO::class)));

  $container->set(EmpresaClienteService::class, static fn (ContainerInterface $c) => new EmpresaClienteService(
    $c->get(EmpresaClienteRepository::class)
  ));

  $container->set(ConfigEquipoRepository::class, static fn (ContainerInterface $c) => new ConfigEquipoRepository($c->get(PDO::class)));
  $container->set(ConfigEquipoService::class, static fn (ContainerInterface $c) => new ConfigEquipoService(
    $c->get(ConfigEquipoRepository::class),
    $c->get(PDO::class)
  ));

  $container->set(RolRepository::class, static fn (ContainerInterface $c) => new RolRepository($c->get(PDO::class)));
  $container->set(RolPermisoRepository::class, static fn (ContainerInterface $c) => new RolPermisoRepository($c->get(PDO::class)));
  $container->set(RolService::class, static fn (ContainerInterface $c) => new RolService(
    $c->get(RolRepository::class),
    $c->get(RolPermisoRepository::class),
    $c->get(DependencyCheckService::class)
  ));

  $container->set(\Descartes\Api\Services\Ventas\VentaConsultaService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Ventas\VentaConsultaService($c->get(PDO::class)));
  $container->set(\Descartes\Api\Services\Ventas\ArqueoService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Ventas\ArqueoService($c->get(PDO::class)));
  $container->set(\Descartes\Api\Services\Ventas\DesgloseArqueoVentasService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Ventas\DesgloseArqueoVentasService($c->get(PDO::class)));
  $container->set(\Descartes\Api\Services\Ventas\DispositivoPuestoService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Ventas\DispositivoPuestoService($c->get(PDO::class)));
  $container->set(\Descartes\Api\Services\Ventas\VentaEscrituraService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Ventas\VentaEscrituraService(
    $c->get(PDO::class),
    $c->get(\Descartes\Api\Services\Ventas\VentaConsultaService::class),
    $c->get(\Descartes\Api\Services\Ventas\ArqueoService::class)
  ));
  $container->set(\Descartes\Api\Services\Ventas\AnulacionConsultaService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Ventas\AnulacionConsultaService($c->get(PDO::class)));
  $container->set(\Descartes\Api\Services\Ventas\CobroPagoConsultaService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Ventas\CobroPagoConsultaService($c->get(PDO::class)));
  $container->set(\Descartes\Api\Services\Ventas\ValeService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Ventas\ValeService($c->get(PDO::class)));
  $container->set(\Descartes\Api\Services\Ventas\PedidoClienteService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Ventas\PedidoClienteService(
    $c->get(PDO::class),
    $c->get(\Descartes\Api\Services\Ventas\VentaEscrituraService::class)
  ));
  $container->set(\Descartes\Api\Services\Ventas\AbcVentasService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Ventas\AbcVentasService($c->get(PDO::class)));
  $container->set(\Descartes\Api\Services\Facturacion\GeneracionFacturasManualService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Facturacion\GeneracionFacturasManualService($c->get(PDO::class)));
  $container->set(\Descartes\Api\Services\Facturacion\ImpresionFacturasService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Facturacion\ImpresionFacturasService($c->get(PDO::class)));
  $container->set(\Descartes\Api\Services\Facturacion\TraspasoComercialService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Facturacion\TraspasoComercialService($c->get(PDO::class)));
  $container->set(\Descartes\Api\Services\Facturacion\AlbaranesPeriodicosService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Facturacion\AlbaranesPeriodicosService($c->get(PDO::class)));
  $container->set(\Descartes\Api\Services\Facturacion\DiarioFacturacionService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Facturacion\DiarioFacturacionService($c->get(\Descartes\Api\Services\Facturacion\ImpresionFacturasService::class)));
  $container->set(\Descartes\Api\Services\Facturacion\AlbaranesPendientesService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Facturacion\AlbaranesPendientesService($c->get(\Descartes\Api\Services\Facturacion\GeneracionFacturasManualService::class)));
  $container->set(\Descartes\Api\Services\Facturacion\RetrocesoFacturaService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Facturacion\RetrocesoFacturaService($c->get(PDO::class)));

  $container->set(LoggerInterface::class, static fn () => new FileLogger());
  $container->set(FileLogger::class, static fn (ContainerInterface $c) => $c->get(LoggerInterface::class));
  ErrorResponse::setLogger($container->get(LoggerInterface::class));

  $app->addRoutingMiddleware();
  $app->addBodyParsingMiddleware();
  $errorMiddleware = $app->addErrorMiddleware(true, true, true);
  $logger = $container->get(LoggerInterface::class);
  $errorMiddleware->setDefaultErrorHandler(
    static function (
      $request,
      $exception,
      bool $displayErrorDetails,
      bool $logErrors,
      bool $logErrorDetails
    ) use ($logger) {
      return \Descartes\Api\Http\ApiErrorHandler::handle(
        $request,
        $exception,
        $displayErrorDetails,
        $logErrors,
        $logErrorDetails,
        $logger
      );
    }
  );

  $app->add(ClienteDbMiddleware::class);
  $app->add(SessionMiddleware::class);
  $app->add(CorsMiddleware::class);
};