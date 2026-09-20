<?php

declare(strict_types=1);

use Descartes\Api\Middleware\ClienteDbMiddleware;
use Descartes\Api\Middleware\CorsMiddleware;
use Descartes\Api\Middleware\SessionMiddleware;
use Descartes\Api\Http\ErrorResponse;
use Descartes\Api\Logging\FileLogger;
use Descartes\Api\RequestContext;
use Psr\Log\LoggerInterface;
use Descartes\Api\Repositories\ArtBarrasRepository;
use Descartes\Api\Repositories\ArtPreciosRepository;
use Descartes\Api\Repositories\ArticuloStockRepository;
use Descartes\Api\Repositories\EscandallosRepository;
use Descartes\Api\Repositories\PlantasRepository;
use Descartes\Api\Repositories\ClientesContactosRepository;
use Descartes\Api\Repositories\ClientesDireccionesRepository;
use Descartes\Api\Repositories\ClientesEstadisticaRepository;
use Descartes\Api\Repositories\ClientesRiesgoRepository;
use Descartes\Api\Repositories\CodigoPostalRepository;
use Descartes\Api\Repositories\ConfigEquipoRepository;
use Descartes\Api\Repositories\DocumentoPlantillasRepository;
use Descartes\Api\Repositories\EmpresaClienteRepository;
use Descartes\Api\Repositories\CampanasRepository;
use Descartes\Api\Repositories\OfertasClientesRepository;
use Descartes\Api\Repositories\ProveedoresContactosRepository;
use Descartes\Api\Repositories\ProveedoresEstadisticaRepository;
use Descartes\Api\Repositories\RolPermisoRepository;
use Descartes\Api\Repositories\RolRepository;
use Descartes\Api\Services\ArticuloService;
use Descartes\Api\Services\ConfigEquipoService;
use Descartes\Api\Services\DocumentoPlantillasService;
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
  $container->set(ArtBarrasRepository::class, static fn (ContainerInterface $c) => new ArtBarrasRepository($c->get(PDO::class)));
  $container->set(EscandallosRepository::class, static fn (ContainerInterface $c) => new EscandallosRepository($c->get(PDO::class)));
  $container->set(PlantasRepository::class, static fn (ContainerInterface $c) => new PlantasRepository($c->get(PDO::class)));
  $container->set(ClientesDireccionesRepository::class, static fn (ContainerInterface $c) => new ClientesDireccionesRepository($c->get(PDO::class)));
  $container->set(ClientesContactosRepository::class, static fn (ContainerInterface $c) => new ClientesContactosRepository($c->get(PDO::class)));
  $container->set(ClientesEstadisticaRepository::class, static fn (ContainerInterface $c) => new ClientesEstadisticaRepository($c->get(PDO::class)));
  $container->set(ClientesRiesgoRepository::class, static fn (ContainerInterface $c) => new ClientesRiesgoRepository($c->get(PDO::class)));
  $container->set(ProveedoresContactosRepository::class, static fn (ContainerInterface $c) => new ProveedoresContactosRepository($c->get(PDO::class)));
  $container->set(ProveedoresEstadisticaRepository::class, static fn (ContainerInterface $c) => new ProveedoresEstadisticaRepository($c->get(PDO::class)));
  $container->set(CampanasRepository::class, static fn (ContainerInterface $c) => new CampanasRepository($c->get(PDO::class)));
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
    $c->get(ArticuloService::class),
    $c->get(ClientesRiesgoRepository::class)
  ));

  $container->set(\Descartes\Api\Controllers\ArticuloController::class, static fn (ContainerInterface $c) => new \Descartes\Api\Controllers\ArticuloController(
    $c->get(ArticuloStockRepository::class),
    $c->get(ArtBarrasRepository::class),
    $c->get(EscandallosRepository::class),
    $c->get(PlantasRepository::class),
    $c->get(MantenimientoService::class),
    $c->get(PDO::class)
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

  $container->set(DocumentoPlantillasRepository::class, static fn (ContainerInterface $c) => new DocumentoPlantillasRepository($c->get(PDO::class)));
  $container->set(DocumentoPlantillasService::class, static fn (ContainerInterface $c) => new DocumentoPlantillasService(
    $c->get(DocumentoPlantillasRepository::class)
  ));

  $container->set(RolRepository::class, static fn (ContainerInterface $c) => new RolRepository($c->get(PDO::class)));
  $container->set(RolPermisoRepository::class, static fn (ContainerInterface $c) => new RolPermisoRepository($c->get(PDO::class)));
  $container->set(RolService::class, static fn (ContainerInterface $c) => new RolService(
    $c->get(RolRepository::class),
    $c->get(RolPermisoRepository::class),
    $c->get(DependencyCheckService::class)
  ));

  $container->set(\Descartes\Api\Services\Ventas\VentaConsultaService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Ventas\VentaConsultaService($c->get(PDO::class)));
  $container->set(\Descartes\Api\Services\Ventas\VentaEmailService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Ventas\VentaEmailService(
    $c->get(\Descartes\Api\Services\Ventas\VentaConsultaService::class),
    $c->get(PDO::class)
  ));
  $container->set(\Descartes\Api\Services\Compras\AlbaranCompraConsultaService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Compras\AlbaranCompraConsultaService($c->get(PDO::class)));
  $container->set(\Descartes\Api\Services\Compras\AlbaranCompraEscrituraService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Compras\AlbaranCompraEscrituraService(
    $c->get(PDO::class),
    $c->get(\Descartes\Api\Services\Compras\AlbaranCompraConsultaService::class)
  ));
  $container->set(\Descartes\Api\Services\Compras\AlbaranCompraConversionVentaService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Compras\AlbaranCompraConversionVentaService(
    $c->get(PDO::class),
    $c->get(\Descartes\Api\Services\Compras\AlbaranCompraConsultaService::class),
    $c->get(\Descartes\Api\Services\Ventas\VentaEscrituraService::class)
  ));
  $container->set(\Descartes\Api\Services\Compras\PedidoProveedorConsultaService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Compras\PedidoProveedorConsultaService($c->get(PDO::class)));
  $container->set(\Descartes\Api\Services\Compras\PedidoProveedorEscrituraService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Compras\PedidoProveedorEscrituraService(
    $c->get(PDO::class),
    $c->get(\Descartes\Api\Services\Compras\PedidoProveedorConsultaService::class)
  ));
  $container->set(\Descartes\Api\Services\Compras\PedidoProveedorRecepcionService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Compras\PedidoProveedorRecepcionService(
    $c->get(PDO::class),
    $c->get(\Descartes\Api\Services\Compras\PedidoProveedorConsultaService::class),
    $c->get(\Descartes\Api\Services\Compras\PedidoProveedorEscrituraService::class),
    $c->get(\Descartes\Api\Services\Compras\AlbaranCompraEscrituraService::class)
  ));
  $container->set(\Descartes\Api\Services\Compras\FacturaCompraConsultaService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Compras\FacturaCompraConsultaService($c->get(PDO::class)));
  $container->set(\Descartes\Api\Services\Tpv\TpvContextoService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Tpv\TpvContextoService(
    $c->get(PDO::class),
    $c->get(\Descartes\Api\Services\Ventas\ArqueoService::class)
  ));
  $container->set(\Descartes\Api\Services\Tpv\TpvTecladoService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Tpv\TpvTecladoService($c->get(PDO::class)));
  $container->set(\Descartes\Api\Services\Tpv\TpvArticuloService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Tpv\TpvArticuloService(
    $c->get(PDO::class),
    $c->get(ArtBarrasRepository::class)
  ));
  $container->set(\Descartes\Api\Services\Tpv\TpvClienteService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Tpv\TpvClienteService($c->get(PDO::class)));
  $container->set(\Descartes\Api\Services\Etiquetas\EtiquetaColaService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Etiquetas\EtiquetaColaService(
    $c->get(PDO::class),
    $c->get(\Descartes\Api\Services\Compras\AlbaranCompraConsultaService::class)
  ));
  $container->set(\Descartes\Api\Services\Ventas\ArqueoService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Ventas\ArqueoService($c->get(PDO::class)));
  $container->set(\Descartes\Api\Services\Ventas\DesgloseArqueoVentasService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Ventas\DesgloseArqueoVentasService($c->get(PDO::class)));
  $container->set(\Descartes\Api\Services\Ventas\DispositivoPuestoService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Ventas\DispositivoPuestoService($c->get(PDO::class)));
  $container->set(\Descartes\Api\Services\Ventas\FidelizacionService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Ventas\FidelizacionService(
    $c->get(PDO::class)
  ));
  $container->set(\Descartes\Api\Services\Facturacion\RecibosFacturaService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Facturacion\RecibosFacturaService($c->get(PDO::class)));
  $container->set(\Descartes\Api\Services\Ventas\VentaEscrituraService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Ventas\VentaEscrituraService(
    $c->get(PDO::class),
    $c->get(\Descartes\Api\Services\Ventas\VentaConsultaService::class),
    $c->get(\Descartes\Api\Services\Ventas\ArqueoService::class),
    $c->get(\Descartes\Api\Services\Ventas\FidelizacionService::class),
    $c->get(\Descartes\Api\Services\Facturacion\RecibosFacturaService::class)
  ));
  $container->set(\Descartes\Api\Services\Ventas\AnulacionConsultaService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Ventas\AnulacionConsultaService($c->get(PDO::class)));
  $container->set(\Descartes\Api\Services\Ventas\CobroPagoConsultaService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Ventas\CobroPagoConsultaService($c->get(PDO::class)));
  $container->set(\Descartes\Api\Services\Ventas\ValeService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Ventas\ValeService($c->get(PDO::class)));
  $container->set(\Descartes\Api\Services\Ventas\PedidoClienteService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Ventas\PedidoClienteService(
    $c->get(PDO::class),
    $c->get(\Descartes\Api\Services\Ventas\VentaEscrituraService::class)
  ));
  $container->set(\Descartes\Api\Services\Ventas\AbcVentasService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Ventas\AbcVentasService($c->get(PDO::class)));
  $container->set(\Descartes\Api\Services\Compras\AbcComprasService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Compras\AbcComprasService($c->get(PDO::class)));
  $container->set(\Descartes\Api\Services\Listados\StockListadoService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Listados\StockListadoService($c->get(PDO::class)));
  $container->set(\Descartes\Api\Services\Listados\StockMinimosListadoService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Listados\StockMinimosListadoService($c->get(PDO::class)));
  $container->set(\Descartes\Api\Services\Listados\InformeIvaListadoService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Listados\InformeIvaListadoService($c->get(PDO::class)));
  $container->set(\Descartes\Api\Services\Listados\InformeTicketsListadoService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Listados\InformeTicketsListadoService($c->get(PDO::class)));
  $container->set(\Descartes\Api\Services\Listados\ExtractoClientesListadoService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Listados\ExtractoClientesListadoService($c->get(PDO::class), $c->get(ClientesRiesgoRepository::class)));
  $container->set(\Descartes\Api\Services\Facturacion\GeneracionFacturasManualService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Facturacion\GeneracionFacturasManualService(
    $c->get(PDO::class),
    $c->get(\Descartes\Api\Services\Facturacion\RecibosFacturaService::class)
  ));
  $container->set(\Descartes\Api\Services\Facturacion\ImpresionFacturasService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Facturacion\ImpresionFacturasService($c->get(PDO::class)));
  $container->set(\Descartes\Api\Services\Facturacion\ImpresionRecibosService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Facturacion\ImpresionRecibosService($c->get(PDO::class)));
  $container->set(\Descartes\Api\Services\Facturacion\FacturaEmailService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Facturacion\FacturaEmailService(
    $c->get(PDO::class),
    $c->get(\Descartes\Api\Services\Facturacion\ImpresionFacturasService::class)
  ));
  $container->set(\Descartes\Api\Services\Facturacion\ConexionContableService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Facturacion\ConexionContableService($c->get(PDO::class)));
  $container->set(\Descartes\Api\Services\Facturacion\TraspasoContableService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Facturacion\TraspasoContableService(
    $c->get(PDO::class),
    $c->get(\Descartes\Api\Services\Facturacion\ConexionContableService::class)
  ));
  $container->set(\Descartes\Api\Services\Facturacion\TraspasoComercialService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Facturacion\TraspasoComercialService($c->get(PDO::class)));
  $container->set(\Descartes\Api\Services\Facturacion\AlbaranesPeriodicosConsultaService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Facturacion\AlbaranesPeriodicosConsultaService($c->get(PDO::class)));
  $container->set(\Descartes\Api\Services\Facturacion\AlbaranesPeriodicosEscrituraService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Facturacion\AlbaranesPeriodicosEscrituraService(
    $c->get(PDO::class),
    $c->get(\Descartes\Api\Services\Facturacion\AlbaranesPeriodicosConsultaService::class)
  ));
  $container->set(\Descartes\Api\Services\Facturacion\AlbaranesPeriodicosService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Facturacion\AlbaranesPeriodicosService($c->get(PDO::class)));
  $container->set(\Descartes\Api\Services\Facturacion\DiarioFacturacionService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Facturacion\DiarioFacturacionService($c->get(\Descartes\Api\Services\Facturacion\ImpresionFacturasService::class)));
  $container->set(\Descartes\Api\Services\Facturacion\AlbaranesPendientesService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Facturacion\AlbaranesPendientesService(
    $c->get(\Descartes\Api\Services\Facturacion\GeneracionFacturasManualService::class),
    $c->get(\Descartes\Api\Services\Ventas\VentaConsultaService::class)
  ));
  $container->set(\Descartes\Api\Services\Facturacion\RetrocesoFacturaService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Facturacion\RetrocesoFacturaService(
    $c->get(PDO::class),
    $c->get(\Descartes\Api\Services\Facturacion\RecibosFacturaService::class)
  ));

  $container->set(\Descartes\Api\Services\Instalacion\MigrationService::class, static fn () => new \Descartes\Api\Services\Instalacion\MigrationService());
  $container->set(\Descartes\Api\Services\Instalacion\InstalacionBootstrapService::class, static fn () => new \Descartes\Api\Services\Instalacion\InstalacionBootstrapService());
  $container->set(\Descartes\Api\Services\Instalacion\SchemaRepairService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Instalacion\SchemaRepairService(
    $c->get(\Descartes\Api\Services\Instalacion\MigrationService::class)
  ));
  $container->set(\Descartes\Api\Services\Instalacion\InstalacionService::class, static fn (ContainerInterface $c) => new \Descartes\Api\Services\Instalacion\InstalacionService(
    $c->get(\Descartes\Api\Services\Instalacion\MigrationService::class),
    $c->get(\Descartes\Api\Services\Instalacion\InstalacionBootstrapService::class),
    $c->get(\Descartes\Api\Services\Instalacion\SchemaRepairService::class)
  ));

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