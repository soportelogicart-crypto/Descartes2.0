<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Listados;

/** Resuelve módulos RolPermisos para informes con submenú (stock, ABC). */
final class ListadosPermisosModulo
{
  /** @var list<string> */
  private const STOCK_AGRUPAR = [
    'macrofamilia',
    'subfamilia',
    'familia',
    'articulo',
    'agrupacion',
    'proveedor',
  ];

  /** @var list<string> */
  private const ABC_DIMENSION = [
    'dias-semana',
    'semanal',
    'horas',
    'macrofamilias',
    'subfamilias',
    'familias',
    'articulos',
    'agrupaciones',
    'clientes',
    'proveedores',
    'secciones',
    'subsecciones',
    'perfiles',
    'vendedores',
  ];

  public static function stock(?string $agruparPor): string
  {
    $k = strtolower(trim((string) $agruparPor));
    if ($k === '' || !in_array($k, self::STOCK_AGRUPAR, true)) {
      $k = 'articulo';
    }

    return 'listados-stock-' . $k;
  }

  public static function abc(?string $dimension): string
  {
    $k = strtolower(trim((string) $dimension));
    if ($k === '' || !in_array($k, self::ABC_DIMENSION, true)) {
      $k = 'vendedores';
    }

    return 'ventas-abc-' . $k;
  }

  /** @return list<string> */
  public static function todosSubmodulos(): array
  {
    $mods = [];
    foreach (self::STOCK_AGRUPAR as $k) {
      $mods[] = 'listados-stock-' . $k;
    }
    foreach (self::ABC_DIMENSION as $k) {
      $mods[] = 'ventas-abc-' . $k;
    }

    return $mods;
  }

  /** @return array<string, string> */
  public static function moduloPadreSubmodulos(): array
  {
    $map = [];
    foreach (self::STOCK_AGRUPAR as $k) {
      $map['listados-stock-' . $k] = 'listados-stock';
    }
    foreach (self::ABC_DIMENSION as $k) {
      $map['ventas-abc-' . $k] = 'ventas-abc';
    }

    return $map;
  }
}
