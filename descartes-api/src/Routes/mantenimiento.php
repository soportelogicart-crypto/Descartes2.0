<?php

declare(strict_types=1);

use Descartes\Api\Config\EntityConfig;
use Descartes\Api\Controllers\ArticuloController;
use Descartes\Api\Controllers\ClienteController;
use Descartes\Api\Controllers\CodigoPostalController;
use Descartes\Api\Controllers\ConfigEquipoController;
use Descartes\Api\Controllers\DocumentoPlantillasController;
use Descartes\Api\Controllers\EmpresaClienteController;
use Descartes\Api\Controllers\MantenimientoController;
use Descartes\Api\Controllers\AlbaranesPeriodicosController;
use Descartes\Api\Controllers\CampanaController;
use Descartes\Api\Controllers\OfertaClienteController;
use Descartes\Api\Controllers\OfertaProveedorController;
use Descartes\Api\Controllers\ProveedorController;
use Descartes\Api\Controllers\RolController;
use Descartes\Api\Middleware\AuthMiddleware;
use Descartes\Api\Middleware\PermissionMiddleware;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app): void {
  $setPermiso = function (string $accion): callable {
    return function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($accion) {
      $route = $request->getAttribute('__route__');
      $entidad = $route?->getArgument('entidad');
      $config = $entidad ? EntityConfig::get($entidad) : null;

      if ($config !== null) {
        $request = $request
          ->withAttribute('permisoModulo', $config['modulo'])
          ->withAttribute('permisoAccion', $accion);
      }

      return $handler->handle($request);
    };
  };

  $setPermisoModulo = function (string $modulo, string $accion): callable {
    return function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($modulo, $accion) {
      return $handler->handle(
        $request
          ->withAttribute('permisoModulo', $modulo)
          ->withAttribute('permisoAccion', $accion)
      );
    };
  };

  $app->group('/api/mantenimiento', function (RouteCollectorProxy $group) use ($setPermiso, $setPermisoModulo) {
    $group->get('/empresas', [EmpresaClienteController::class, 'get'])
      ->add($setPermisoModulo('empresas', 'ver'));
    $group->put('/empresas', [EmpresaClienteController::class, 'put'])
      ->add($setPermisoModulo('empresas', 'editar'));

    $group->get('/roles/{codigo}/permisos', [RolController::class, 'getPermisos'])
      ->add($setPermisoModulo('roles', 'ver'));
    $group->put('/roles/{codigo}/permisos', [RolController::class, 'putPermisos'])
      ->add($setPermisoModulo('roles', 'editar'));

    $group->get('/articulos/siguiente-codigo', [ArticuloController::class, 'siguienteCodigo'])
      ->add($setPermisoModulo('articulos', 'crear'));
    $group->get('/articulos/resolver', [ArticuloController::class, 'resolver'])
      ->add($setPermisoModulo('articulos', 'ver'));
    $group->get('/articulos/ean-lookup', [ArticuloController::class, 'eanLookup'])
      ->add($setPermisoModulo('articulos', 'ver'));
    $group->get('/articulos/{codigo}/stock', [ArticuloController::class, 'getStock'])
      ->add($setPermisoModulo('articulos', 'ver'));
    $group->get('/articulos/{codigo}/eans', [ArticuloController::class, 'listEans'])
      ->add($setPermisoModulo('articulos', 'ver'));
    $group->put('/articulos/{codigo}/eans', [ArticuloController::class, 'putEans'])
      ->add($setPermisoModulo('articulos', 'editar'));
    $group->get('/articulos/{codigo}/escandallo', [ArticuloController::class, 'listEscandallo'])
      ->add($setPermisoModulo('articulos', 'ver'));
    $group->put('/articulos/{codigo}/escandallo', [ArticuloController::class, 'putEscandallo'])
      ->add($setPermisoModulo('articulos', 'editar'));
    $group->get('/articulos/{codigo}/ficha-botanica', [ArticuloController::class, 'getFichaBotanica'])
      ->add($setPermisoModulo('articulos', 'ver'));
    $group->put('/articulos/{codigo}/ficha-botanica', [ArticuloController::class, 'putFichaBotanica'])
      ->add($setPermisoModulo('articulos', 'editar'));
    $group->delete('/articulos/{codigo}/ficha-botanica', [ArticuloController::class, 'deleteFichaBotanica'])
      ->add($setPermisoModulo('articulos', 'eliminar'));
    $group->get('/fichas-botanicas', [ArticuloController::class, 'searchFichasBotanicas'])
      ->add($setPermisoModulo('articulos', 'ver'));
    $group->get('/fichas-botanicas/grupos', [ArticuloController::class, 'listGruposFicha'])
      ->add($setPermisoModulo('articulos', 'ver'));
    $group->get('/fichas-botanicas/{codigo}', [ArticuloController::class, 'getPlanta'])
      ->add($setPermisoModulo('articulos', 'ver'));

    // Referencia geográfica: cualquier usuario autenticado (ventas, clientes, etc.).
    $group->get('/codigos-postales/{codigo}', [CodigoPostalController::class, 'lookup']);

    $group->get('/clientes/siguiente-codigo', [ClienteController::class, 'siguienteCodigo'])
      ->add($setPermisoModulo('clientes', 'crear'));
    $group->get('/clientes/check-nif', [ClienteController::class, 'checkNif'])
      ->add($setPermisoModulo('clientes', 'ver'));
    $group->get('/clientes/{codigo}/cuenta-contable', [ClienteController::class, 'cuentaContable'])
      ->add($setPermisoModulo('clientes', 'ver'));
    $group->post('/clientes/{codigo}/cuenta-contable', [ClienteController::class, 'crearCuentaContable'])
      ->add($setPermisoModulo('clientes', 'editar'));
    $group->get('/clientes/{codigo}/direcciones', [ClienteController::class, 'listDirecciones'])
      ->add($setPermisoModulo('clientes', 'ver'));
    $group->post('/clientes/{codigo}/direcciones', [ClienteController::class, 'createDireccion'])
      ->add($setPermisoModulo('clientes', 'editar'));
    $group->put('/clientes/{codigo}/direcciones/{tipo}/{nroLin}', [ClienteController::class, 'updateDireccion'])
      ->add($setPermisoModulo('clientes', 'editar'));
    $group->delete('/clientes/{codigo}/direcciones/{tipo}/{nroLin}', [ClienteController::class, 'deleteDireccion'])
      ->add($setPermisoModulo('clientes', 'editar'));

    $group->get('/clientes/{codigo}/contactos', [ClienteController::class, 'listContactos'])
      ->add($setPermisoModulo('clientes', 'ver'));
    $group->post('/clientes/{codigo}/contactos', [ClienteController::class, 'createContacto'])
      ->add($setPermisoModulo('clientes', 'editar'));
    $group->put('/clientes/{codigo}/contactos/{num}/{nroLin}', [ClienteController::class, 'updateContacto'])
      ->add($setPermisoModulo('clientes', 'editar'));
    $group->delete('/clientes/{codigo}/contactos/{num}/{nroLin}', [ClienteController::class, 'deleteContacto'])
      ->add($setPermisoModulo('clientes', 'editar'));

    $group->get('/clientes/{codigo}/estadistica', [ClienteController::class, 'estadistica'])
      ->add($setPermisoModulo('clientes', 'ver'));
    $group->put('/clientes/{codigo}/estadistica/{anio}/{mes}', [ClienteController::class, 'putPrevision'])
      ->add($setPermisoModulo('clientes', 'editar'));
    $group->get('/clientes/{codigo}/consumo', [ClienteController::class, 'consumo'])
      ->add($setPermisoModulo('clientes', 'ver'));

    $group->get('/proveedores/siguiente-codigo', [ProveedorController::class, 'siguienteCodigo'])
      ->add($setPermisoModulo('proveedores', 'crear'));
    $group->get('/proveedores/{codigo}/contactos', [ProveedorController::class, 'listContactos'])
      ->add($setPermisoModulo('proveedores', 'ver'));
    $group->post('/proveedores/{codigo}/contactos', [ProveedorController::class, 'createContacto'])
      ->add($setPermisoModulo('proveedores', 'editar'));
    $group->put('/proveedores/{codigo}/contactos/{num}/{nroLin}', [ProveedorController::class, 'updateContacto'])
      ->add($setPermisoModulo('proveedores', 'editar'));
    $group->delete('/proveedores/{codigo}/contactos/{num}/{nroLin}', [ProveedorController::class, 'deleteContacto'])
      ->add($setPermisoModulo('proveedores', 'editar'));
    $group->get('/proveedores/{codigo}/estadistica', [ProveedorController::class, 'estadistica'])
      ->add($setPermisoModulo('proveedores', 'ver'));

    $group->get('/campanas', [CampanaController::class, 'list'])
      ->add($setPermisoModulo('campanas', 'ver'));
    $group->post('/campanas', [CampanaController::class, 'create'])
      ->add($setPermisoModulo('campanas', 'crear'));
    $group->get('/campanas/{empresa}/{campana}', [CampanaController::class, 'get'])
      ->add($setPermisoModulo('campanas', 'ver'));
    $group->put('/campanas/{empresa}/{campana}', [CampanaController::class, 'update'])
      ->add($setPermisoModulo('campanas', 'editar'));
    $group->delete('/campanas/{empresa}/{campana}', [CampanaController::class, 'delete'])
      ->add($setPermisoModulo('campanas', 'eliminar'));

    $group->get('/albaranes-periodicos', [AlbaranesPeriodicosController::class, 'list'])
      ->add($setPermisoModulo('albaranes-periodicos', 'ver'));
    $group->post('/albaranes-periodicos', [AlbaranesPeriodicosController::class, 'create'])
      ->add($setPermisoModulo('albaranes-periodicos', 'crear'));
    $group->get('/albaranes-periodicos/plantillas', [AlbaranesPeriodicosController::class, 'buscarPlantillas'])
      ->add($setPermisoModulo('albaranes-periodicos', 'crear'));
    $group->get('/albaranes-periodicos/{empresa}/{tipo}/{albaran}', [AlbaranesPeriodicosController::class, 'get'])
      ->add($setPermisoModulo('albaranes-periodicos', 'ver'));
    $group->put('/albaranes-periodicos/{empresa}/{tipo}/{albaran}', [AlbaranesPeriodicosController::class, 'update'])
      ->add($setPermisoModulo('albaranes-periodicos', 'editar'));
    $group->delete('/albaranes-periodicos/{empresa}/{tipo}/{albaran}', [AlbaranesPeriodicosController::class, 'delete'])
      ->add($setPermisoModulo('albaranes-periodicos', 'eliminar'));
    $group->post('/albaranes-periodicos/{empresa}/{tipo}/{albaran}/generar', [AlbaranesPeriodicosController::class, 'generar'])
      ->add($setPermisoModulo('facturacion-manual', 'crear'));

    $group->get('/oferta-clientes', [OfertaClienteController::class, 'list'])
      ->add($setPermisoModulo('oferta-clientes', 'ver'));
    $group->post('/oferta-clientes', [OfertaClienteController::class, 'create'])
      ->add($setPermisoModulo('oferta-clientes', 'crear'));
    $group->get('/oferta-clientes/{articulo}/{cliente}', [OfertaClienteController::class, 'get'])
      ->add($setPermisoModulo('oferta-clientes', 'ver'));
    $group->put('/oferta-clientes/{articulo}/{cliente}', [OfertaClienteController::class, 'update'])
      ->add($setPermisoModulo('oferta-clientes', 'editar'));
    $group->delete('/oferta-clientes/{articulo}/{cliente}', [OfertaClienteController::class, 'delete'])
      ->add($setPermisoModulo('oferta-clientes', 'eliminar'));

    $group->get('/oferta-proveedores', [OfertaProveedorController::class, 'list'])
      ->add($setPermisoModulo('oferta-proveedores', 'ver'));
    $group->post('/oferta-proveedores', [OfertaProveedorController::class, 'create'])
      ->add($setPermisoModulo('oferta-proveedores', 'crear'));
    $group->get('/oferta-proveedores/{articulo}/{proveedor}', [OfertaProveedorController::class, 'get'])
      ->add($setPermisoModulo('oferta-proveedores', 'ver'));
    $group->put('/oferta-proveedores/{articulo}/{proveedor}', [OfertaProveedorController::class, 'update'])
      ->add($setPermisoModulo('oferta-proveedores', 'editar'));
    $group->delete('/oferta-proveedores/{articulo}/{proveedor}', [OfertaProveedorController::class, 'delete'])
      ->add($setPermisoModulo('oferta-proveedores', 'eliminar'));

    // Config de este PC (INI legacy)
    $group->get('/config-equipo/{equipoId}', [ConfigEquipoController::class, 'get'])
      ->add($setPermisoModulo('puestos-parametros', 'ver'));
    $group->put('/config-equipo/{equipoId}', [ConfigEquipoController::class, 'put'])
      ->add($setPermisoModulo('puestos-parametros', 'ver'));

    $group->get('/documento-plantillas', [DocumentoPlantillasController::class, 'list']);
    // Lectura autenticada (puestos y configuración); escritura sigue con facturación
    $group->get('/documento-plantillas/{id}', [DocumentoPlantillasController::class, 'get']);
    $group->post('/documento-plantillas', [DocumentoPlantillasController::class, 'create'])
      ->add($setPermisoModulo('facturacion', 'editar'));
    $group->post('/documento-plantillas/sembrar', [DocumentoPlantillasController::class, 'sembrar'])
      ->add($setPermisoModulo('facturacion', 'editar'));
    $group->put('/documento-plantillas/{id}', [DocumentoPlantillasController::class, 'update'])
      ->add($setPermisoModulo('facturacion', 'editar'));
    $group->delete('/documento-plantillas/{id}', [DocumentoPlantillasController::class, 'delete'])
      ->add($setPermisoModulo('facturacion', 'editar'));
    $group->post('/documento-plantillas/{id}/activar', [DocumentoPlantillasController::class, 'activar'])
      ->add($setPermisoModulo('facturacion', 'editar'));

    $group->get('/{entidad}', [MantenimientoController::class, 'list'])->add($setPermiso('ver'));
    $group->get('/{entidad}/{codigo}', [MantenimientoController::class, 'get'])->add($setPermiso('ver'));
    $group->post('/{entidad}', [MantenimientoController::class, 'create'])->add($setPermiso('crear'));
    $group->put('/{entidad}/{codigo}', [MantenimientoController::class, 'update'])->add($setPermiso('editar'));
    $group->delete('/{entidad}/{codigo}', [MantenimientoController::class, 'delete'])->add($setPermiso('eliminar'));
  })
    ->add(PermissionMiddleware::class)
    ->add(AuthMiddleware::class);
};
