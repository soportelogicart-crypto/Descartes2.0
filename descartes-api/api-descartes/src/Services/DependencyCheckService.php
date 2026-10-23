<?php

declare(strict_types=1);

namespace Descartes\Api\Services;

use PDO;

final class DependencyCheckService
{
  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  public function puedeDarDeBaja(string $entidad, string $codigo): array
  {
    return match ($entidad) {
      'tiendas' => $this->checkTienda($codigo),
      'almacenes' => $this->checkAlmacen($codigo),
      'articulos' => $this->checkArticulo($codigo),
      'roles' => $this->checkRol($codigo),
      'puestos-trabajo' => $this->checkPuesto($codigo),
      'impuestos' => $this->checkImpuesto($codigo),
      'formas-pago' => $this->checkFormaPago($codigo),
      'trabajadores' => $this->checkTrabajador($codigo),
      'macrofamilias' => $this->checkMacrofamilia($codigo),
      'familias' => $this->checkFamilia($codigo),
      'subfamilias' => $this->checkSubfamilia($codigo),
      'agrupaciones' => $this->checkAgrupacion($codigo),
      'actividades' => $this->checkActividad($codigo),
      'intereses-comerciales' => $this->checkInteresComercial($codigo),
      'tipos-calculo-fidelizacion' => $this->checkTipoCalculoFidelizacion($codigo),
      default => ['ok' => true, 'dependencias' => []],
    };
  }

  private function checkTienda(string $codigo): array
  {
    $stmt = $this->pdo->prepare('SELECT [Central] FROM [Empresas_Ges] WHERE [Codigo] = :codigo');
    $stmt->execute(['codigo' => $codigo]);
    $row = $stmt->fetch();
    if ($row && (int) $row['Central'] === 1) {
      return ['ok' => false, 'dependencias' => ['Central=1']];
    }

    $deps = [];
    foreach (['Facturas' => 'Empresa', 'AlbaranesVentasCab' => 'Empresa'] as $table => $col) {
      if ($this->hasRows($table, $col, $codigo)) {
        $deps[] = $table;
      }
    }

    // El stock pertenece al almacén (posible compartido entre tiendas).
    // No bloquear la baja de la tienda por movimientos del almacén.

    return ['ok' => $deps === [], 'dependencias' => $deps];
  }

  private function checkAlmacen(string $codigo): array
  {
    $deps = [];
    if ($this->hasStockEnAlmacen((int) $codigo)) {
      $deps[] = 'Stock';
    }

    $stmt = $this->pdo->prepare(
      'SELECT COUNT(*) AS total FROM [Empresas_Ges] WHERE CAST([Almacen] AS int) = :almacen AND [Baja] = 0'
    );
    $stmt->execute(['almacen' => (int) $codigo]);
    $total = (int) ($stmt->fetch()['total'] ?? 0);
    if ($total > 0) {
      $deps[] = 'Empresas';
    }

    return ['ok' => $deps === [], 'dependencias' => $deps];
  }

  private function checkArticulo(string $codigo): array
  {
    $deps = [];
    if ($this->hasStockArticulo($codigo)) {
      $deps[] = 'Stock';
    }
    if ($this->hasRows('AlbaranesVentasLin', 'Articulo', $codigo)) {
      $deps[] = 'AlbaranesVentasLin';
    }

    return ['ok' => $deps === [], 'dependencias' => $deps];
  }

  private function checkRol(string $codigo): array
  {
    $stmt = $this->pdo->prepare(
      'SELECT COUNT(*) AS total FROM [Usuarios_Ges] WHERE [Rol] = :rol AND [Baja] = 0'
    );
    $stmt->execute(['rol' => $codigo]);
    $total = (int) ($stmt->fetch()['total'] ?? 0);

    return [
      'ok' => $total === 0,
      'dependencias' => $total > 0 ? ['Usuarios'] : [],
    ];
  }

  private function checkPuesto(string $codigo): array
  {
    $deps = [];
    $stmt = $this->pdo->prepare(
      'SELECT TOP 1 1 AS found FROM [Sesiones] WHERE [Puesto] = :puesto AND [Cerrada] = 0'
    );
    $stmt->execute(['puesto' => $codigo]);
    if ($stmt->fetch()) {
      $deps[] = 'Sesiones';
    }

    return ['ok' => $deps === [], 'dependencias' => $deps];
  }

  private function checkImpuesto(string $codigo): array
  {
    // FK Articulos_FK01 esta en NOCHECK (legacy): se permite borrar el registro.
    // Si hay articulos activos con ese codigo, se avisa pero no se bloquea.
    unset($codigo);
    return ['ok' => true, 'dependencias' => []];
  }

  private function checkFormaPago(string $codigo): array
  {
    $deps = [];
    if ($this->hasRows('Facturas', 'Fpago', $codigo)) {
      $deps[] = 'Facturas';
    }
    if ($this->hasRows('AlbaranesVentasCab', 'Fpago1', $codigo)) {
      $deps[] = 'AlbaranesVentasCab';
    }
    if ($this->hasRows('AlbaranesVentasCab', 'Fpago2', $codigo)) {
      $deps[] = 'AlbaranesVentasCab.Fpago2';
    }
    $stmt = $this->pdo->prepare(
      'SELECT TOP 1 1 AS found FROM [Arqueo] WHERE [Codigo] = :codigo
       AND (ISNULL([Entrado], 0) <> 0 OR ISNULL([Acumulado], 0) <> 0)'
    );
    $stmt->execute(['codigo' => $codigo]);
    if ($stmt->fetch()) {
      $deps[] = 'Arqueo';
    }

    return ['ok' => $deps === [], 'dependencias' => array_values(array_unique($deps))];
  }

  private function checkTrabajador(string $codigo): array
  {
    $deps = [];
    $stmt = $this->pdo->prepare(
      'SELECT TOP 1 1 AS found FROM [Puestos] WHERE [Trabajador] = :trabajador AND [Baja] = 0'
    );
    $stmt->execute(['trabajador' => $codigo]);
    if ($stmt->fetch()) {
      $deps[] = 'Puestos';
    }

    return ['ok' => $deps === [], 'dependencias' => $deps];
  }

  private function checkActividad(string $codigo): array
  {
    $deps = [];
    $stmt = $this->pdo->prepare(
      'SELECT TOP 1 1 AS found FROM [Clientes] WHERE [Actividad] = :codigo AND [Baja] = 0'
    );
    $stmt->execute(['codigo' => $codigo]);
    if ($stmt->fetch()) {
      $deps[] = 'Clientes';
    }

    return ['ok' => $deps === [], 'dependencias' => $deps];
  }

  private function checkInteresComercial(string $codigo): array
  {
    $deps = [];
    // InteresesComerciales en Clientes puede contener varios codigos concatenados
    $stmt = $this->pdo->prepare(
      "SELECT TOP 1 1 AS found FROM [Clientes]
       WHERE [Baja] = 0
         AND (
           [InteresesComerciales] = :exacto
           OR [InteresesComerciales] LIKE :inicio
           OR [InteresesComerciales] LIKE :medio
           OR [InteresesComerciales] LIKE :fin
         )"
    );
    $stmt->execute([
      'exacto' => $codigo,
      'inicio' => $codigo . '%',
      'medio' => '%' . $codigo . '%',
      'fin' => '%' . $codigo,
    ]);
    if ($stmt->fetch()) {
      $deps[] = 'Clientes';
    }

    return ['ok' => $deps === [], 'dependencias' => $deps];
  }

  private function checkTipoCalculoFidelizacion(string $codigo): array
  {
    $stmt = $this->pdo->prepare(
      'SELECT TOP 1 1 AS found FROM [Empresas_Ges]
       WHERE RTRIM(ISNULL([TipoCalculoFidelizacion], \'\')) = :codigo
         AND ISNULL([Baja], 0) = 0'
    );
    $stmt->execute(['codigo' => trim($codigo)]);
    $usado = (bool) $stmt->fetch();

    return [
      'ok' => !$usado,
      'dependencias' => $usado ? ['Empresas'] : [],
    ];
  }

  private function checkMacrofamilia(string $codigo): array
  {
    $deps = [];
    if ($this->hasRows('Familias', 'MacroFamilia', $codigo)) {
      $deps[] = 'Familias';
    }

    return ['ok' => $deps === [], 'dependencias' => $deps];
  }

  private function checkFamilia(string $codigo): array
  {
    $deps = [];
    if ($this->hasRows('Subfamilias', 'Familia', $codigo)) {
      $deps[] = 'Subfamilias';
    }

    $stmt = $this->pdo->prepare(
      'SELECT TOP 1 1 AS found FROM [Articulos] WHERE [Familia] = :codigo AND [FechaBaja] IS NULL'
    );
    $stmt->execute(['codigo' => $codigo]);
    if ($stmt->fetch()) {
      $deps[] = 'Articulos';
    }

    return ['ok' => $deps === [], 'dependencias' => $deps];
  }

  private function checkSubfamilia(string $codigo): array
  {
    $deps = [];
    $stmt = $this->pdo->prepare(
      'SELECT TOP 1 1 AS found FROM [Articulos] WHERE [Subfamilia] = :codigo AND [FechaBaja] IS NULL'
    );
    $stmt->execute(['codigo' => $codigo]);
    if ($stmt->fetch()) {
      $deps[] = 'Articulos';
    }

    return ['ok' => $deps === [], 'dependencias' => $deps];
  }

  private function checkAgrupacion(string $codigo): array
  {
    $deps = [];
    $stmt = $this->pdo->prepare(
      'SELECT TOP 1 1 AS found FROM [Articulos] WHERE [Agrupacion] = :codigo AND [FechaBaja] IS NULL'
    );
    $stmt->execute(['codigo' => $codigo]);
    if ($stmt->fetch()) {
      $deps[] = 'Articulos';
    }

    return ['ok' => $deps === [], 'dependencias' => $deps];
  }

  private function hasRows(string $table, string $column, string $value): bool
  {
    $sql = sprintf('SELECT TOP 1 1 AS found FROM [%s] WHERE [%s] = :value', $table, $column);
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute(['value' => $value]);
    return (bool) $stmt->fetch();
  }

  private function hasStockEnAlmacen(int $almacen): bool
  {
    $stmt = $this->pdo->prepare(
      'SELECT TOP 1 1 AS found FROM [Stock] WHERE [Almacen] = :almacen
       AND (ISNULL([Entradas],0) <> 0 OR ISNULL([Salidas],0) <> 0 OR ISNULL([Ventas],0) <> 0)'
    );
    $stmt->execute(['almacen' => $almacen]);
    return (bool) $stmt->fetch();
  }

  private function hasStockArticulo(string $codigo): bool
  {
    $stmt = $this->pdo->prepare(
      'SELECT TOP 1 1 AS found FROM [Stock] WHERE [Codigo] = :codigo
       AND (
         ABS(ISNULL([Entradas], 0) - ISNULL([Salidas], 0) - ISNULL([Ventas], 0)
           + ISNULL([TraspasosEntradas], 0) - ISNULL([TraspasosSalidas], 0)) > 0.0001
         OR ISNULL([Entradas], 0) <> 0 OR ISNULL([Salidas], 0) <> 0 OR ISNULL([Ventas], 0) <> 0
       )'
    );
    $stmt->execute(['codigo' => $codigo]);
    return (bool) $stmt->fetch();
  }
}
