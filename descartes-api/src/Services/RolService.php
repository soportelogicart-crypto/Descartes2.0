<?php

declare(strict_types=1);

namespace Descartes\Api\Services;

use Descartes\Api\Repositories\RolPermisoRepository;
use Descartes\Api\Repositories\RolRepository;

final class RolService
{
  /** @var list<string> */
  public const MODULOS = [
    'mantenimiento',
    'empresas',
    'tiendas',
    'usuarios',
    'roles',
    'trabajadores',
    'puestos',
    'puestos-parametros',
    'puestos-trabajo',
    'articulos',
    'macrofamilias',
    'familias',
    'subfamilias',
    'agrupaciones',
    'secciones',
    'subsecciones',
    'clientes',
    'actividades',
    'intereses-comerciales',
    'oferta-clientes',
    'campanas',
    'proveedores',
    'oferta-proveedores',
    'almacenes',
    'impuestos',
    'formas-pago',
    'compras',
    'ventas',
    'ventas-arqueo',
    'ventas-arqueo-desglose',
    'ventas-anulaciones',
    'ventas-cobros-pagos',
    'ventas-vales',
    'ventas-pedidos',
    'ventas-abc',
    'facturacion',
    'facturacion-manual',
    'facturacion-generacion',
    'facturacion-impresion',
    'facturacion-diario',
    'facturacion-albaranes-pendientes',
    'facturacion-retroceso',
    'inventario',
    'listados',
    'tpv',
  ];

  /**
   * Si un modulo hijo no tiene fila guardada, hereda del padre (roles antiguos).
   *
   * @var array<string, string>
   */
  public const MODULO_PADRE = [
    'macrofamilias' => 'articulos',
    'familias' => 'articulos',
    'subfamilias' => 'articulos',
    'agrupaciones' => 'articulos',
    'secciones' => 'articulos',
    'subsecciones' => 'articulos',
    'actividades' => 'clientes',
    'intereses-comerciales' => 'clientes',
    'oferta-clientes' => 'clientes',
    'campanas' => 'clientes',
    'oferta-proveedores' => 'proveedores',
    'puestos-parametros' => 'puestos',
    'puestos-trabajo' => 'puestos',
    'ventas-arqueo' => 'ventas',
    'ventas-arqueo-desglose' => 'ventas',
    'ventas-anulaciones' => 'ventas',
    'ventas-cobros-pagos' => 'ventas',
    'ventas-vales' => 'ventas',
    'ventas-pedidos' => 'ventas',
    'ventas-abc' => 'ventas',
    'facturacion-manual' => 'facturacion',
    'facturacion-generacion' => 'facturacion',
    'facturacion-impresion' => 'facturacion',
    'facturacion-diario' => 'facturacion',
    'facturacion-albaranes-pendientes' => 'facturacion',
    'facturacion-retroceso' => 'facturacion',
  ];

  private RolRepository $rolRepository;
  private RolPermisoRepository $rolPermisoRepository;
  private DependencyCheckService $dependencyCheckService;

  public function __construct(
    RolRepository $rolRepository,
    RolPermisoRepository $rolPermisoRepository,
    DependencyCheckService $dependencyCheckService
  ) {
    $this->rolRepository = $rolRepository;
    $this->rolPermisoRepository = $rolPermisoRepository;
    $this->dependencyCheckService = $dependencyCheckService;
  }

  public function obtenerPermisos(string $rolCodigo): array
  {
    $this->assertRolExists($rolCodigo);

    $stored = $this->rolPermisoRepository->findByRol($rolCodigo);
    $byModulo = [];
    foreach ($stored as $row) {
      $byModulo[$row['modulo']] = $row;
    }

    $result = [];
    foreach (self::MODULOS as $modulo) {
      $result[] = $byModulo[$modulo] ?? $this->permisoPorDefecto($modulo, $byModulo);
    }

    return $result;
  }

  public function actualizarPermisos(string $rolCodigo, array $permisos): array
  {
    $this->assertRolExists($rolCodigo);
    $this->validarPermisos($permisos);
    $this->rolPermisoRepository->replaceForRol($rolCodigo, $permisos);

    return $this->obtenerPermisos($rolCodigo);
  }

  public function assertPuedeEliminarRol(string $rolCodigo): void
  {
    $check = $this->dependencyCheckService->puedeDarDeBaja('roles', $rolCodigo);
    if (!$check['ok']) {
      throw new DependencyException(
        'No se puede dar de baja el rol porque tiene usuarios activos asignados',
        $check['dependencias']
      );
    }
  }

  private function assertRolExists(string $rolCodigo): void
  {
    if (!$this->rolRepository->exists($rolCodigo)) {
      throw new \RuntimeException('Rol no encontrado');
    }
  }

  /**
   * @param array<string, array<string, mixed>> $byModulo
   * @return array{modulo: string, ver: bool, crear: bool, editar: bool, eliminar: bool}
   */
  private function permisoPorDefecto(string $modulo, array $byModulo): array
  {
    $padre = self::MODULO_PADRE[$modulo] ?? null;
    if ($padre !== null && isset($byModulo[$padre])) {
      $base = $byModulo[$padre];
      return [
        'modulo' => $modulo,
        'ver' => (bool) $base['ver'],
        'crear' => (bool) $base['crear'],
        'editar' => (bool) $base['editar'],
        'eliminar' => (bool) $base['eliminar'],
      ];
    }

    return [
      'modulo' => $modulo,
      'ver' => false,
      'crear' => false,
      'editar' => false,
      'eliminar' => false,
    ];
  }

  private function validarPermisos(array $permisos): void
  {
    if ($permisos === []) {
      throw new \InvalidArgumentException('La matriz de permisos no puede estar vacia');
    }

    foreach ($permisos as $permiso) {
      if (!isset($permiso['modulo']) || !in_array($permiso['modulo'], self::MODULOS, true)) {
        throw new \InvalidArgumentException('Modulo de permiso no valido');
      }
    }
  }
}
