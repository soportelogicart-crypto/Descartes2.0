<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Listados;

use PDO;

/**
 * Comisiones opcionales de la cabecera del pedido: una de proveedor (importe
 * fijo) y dos de trabajador (porcentaje sobre el importe del pedido).
 */
final class ComisionesPedidoListadoService
{
  private const LIMITE = 10000;

  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  /** @param array<string, mixed> $query */
  public function proveedor(array $query): array
  {
    $where = [
      "RTRIM(ISNULL(p.[ProveedorComision], '')) <> ''",
      'ISNULL(p.[ImporteComisionProveedor], 0) > 0',
    ];
    $params = [];
    $this->filtrosComunes($where, $params, $query);
    ListadosFiltrosSql::filtroRangoTexto(
      $where,
      $params,
      'p.[ProveedorComision]',
      $query,
      'proveedorDesde',
      'proveedorHasta',
      null,
      'prov'
    );

    $sql = 'SELECT TOP ' . (self::LIMITE + 1) . "
              RTRIM(p.[Empresa]) AS empresa,
              p.[Pedido] AS pedido,
              CONVERT(varchar(10), p.[Fecha], 23) AS fecha,
              RTRIM(ISNULL(p.[Cliente], '')) AS cliente,
              RTRIM(ISNULL(p.[RazonSocial], '')) AS clienteNombre,
              ISNULL(p.[Importe], 0) AS importePedido,
              RTRIM(p.[ProveedorComision]) AS proveedor,
              RTRIM(ISNULL(pr.[RazonSocial], '')) AS proveedorNombre,
              ISNULL(p.[ImporteComisionProveedor], 0) AS comision
            FROM [PedidosClientes] p
            LEFT JOIN [Proveedores] pr ON RTRIM(pr.[Codigo]) = RTRIM(p.[ProveedorComision])
            WHERE " . implode(' AND ', $where) . '
            ORDER BY p.[Fecha], p.[Pedido]';

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);
    $items = [];
    $truncado = false;
    $importePedido = 0.0;
    $comision = 0.0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      if (count($items) >= self::LIMITE) {
        $truncado = true;
        break;
      }
      $fila = $this->filaProveedor($row);
      $items[] = $fila;
      $importePedido += $fila['importePedido'];
      $comision += $fila['comision'];
    }

    return [
      'items' => $items,
      'totales' => [
        'lineas' => count($items),
        'importePedido' => round($importePedido, 2),
        'comision' => round($comision, 2),
      ],
      'truncado' => $truncado,
      'limite' => self::LIMITE,
    ];
  }

  /** @param array<string, mixed> $query */
  public function trabajadores(array $query): array
  {
    $items = array_merge(
      $this->filasTrabajador($query, 1),
      $this->filasTrabajador($query, 2)
    );
    usort($items, static function (array $a, array $b): int {
      $fecha = strcmp((string) $a['fecha'], (string) $b['fecha']);
      if ($fecha !== 0) {
        return $fecha;
      }
      if ($a['pedido'] !== $b['pedido']) {
        return $a['pedido'] <=> $b['pedido'];
      }

      return strcmp((string) $a['trabajador'], (string) $b['trabajador']);
    });

    $truncado = count($items) > self::LIMITE;
    if ($truncado) {
      $items = array_slice($items, 0, self::LIMITE);
    }
    $comision = 0.0;
    foreach ($items as $fila) {
      $comision += $fila['comision'];
    }

    return [
      'items' => $items,
      'totales' => [
        'lineas' => count($items),
        'comision' => round($comision, 2),
      ],
      'truncado' => $truncado,
      'limite' => self::LIMITE,
    ];
  }

  /**
   * @param array<string, mixed> $query
   * @return list<array<string, mixed>>
   */
  private function filasTrabajador(array $query, int $slot): array
  {
    $codigo = 'p.[TrabajadorComision' . $slot . ']';
    $pje = 'p.[PjeComision' . $slot . ']';
    $where = [
      "RTRIM(ISNULL({$codigo}, '')) <> ''",
      "ISNULL({$pje}, 0) > 0",
    ];
    $params = [];
    $this->filtrosComunes($where, $params, $query);
    ListadosFiltrosSql::filtroRangoTexto(
      $where,
      $params,
      $codigo,
      $query,
      'trabajadorDesde',
      'trabajadorHasta',
      null,
      'trab' . $slot
    );

    $sql = 'SELECT TOP ' . (self::LIMITE + 1) . "
              RTRIM(p.[Empresa]) AS empresa,
              p.[Pedido] AS pedido,
              CONVERT(varchar(10), p.[Fecha], 23) AS fecha,
              RTRIM(ISNULL(p.[Cliente], '')) AS cliente,
              RTRIM(ISNULL(p.[RazonSocial], '')) AS clienteNombre,
              ISNULL(p.[Importe], 0) AS importePedido,
              RTRIM({$codigo}) AS trabajador,
              RTRIM(ISNULL(v.[Nombre], '')) AS trabajadorNombre,
              ISNULL({$pje}, 0) AS pje
            FROM [PedidosClientes] p
            LEFT JOIN [Vendedores] v ON RTRIM(v.[Codigo]) = RTRIM({$codigo})
            WHERE " . implode(' AND ', $where) . '
            ORDER BY p.[Fecha], p.[Pedido]';

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);
    $items = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $items[] = $this->filaTrabajador($row);
      if (count($items) > self::LIMITE) {
        break;
      }
    }

    return $items;
  }

  /**
   * @param list<string> $where
   * @param array<string, mixed> $params
   * @param array<string, mixed> $query
   */
  private function filtrosComunes(array &$where, array &$params, array $query): void
  {
    ListadosFiltrosSql::filtroEmpresaTienda($where, $params, (string) ($query['tienda'] ?? ''), 'p');
    $this->rangoFecha($where, $params, $query);
    $this->rangoEntero($where, $params, 'p.[Pedido]', $query, 'pedidoDesde', 'pedidoHasta', 'ped');
    ListadosFiltrosSql::filtroRangoTexto(
      $where,
      $params,
      'p.[Cliente]',
      $query,
      'clienteDesde',
      'clienteHasta',
      null,
      'cli'
    );
  }

  /**
   * @param list<string> $where
   * @param array<string, mixed> $params
   * @param array<string, mixed> $query
   */
  private function rangoFecha(array &$where, array &$params, array $query): void
  {
    $desde = ListadosFiltrosSql::fechaDiaInput($query['fechaDesde'] ?? null);
    $hasta = ListadosFiltrosSql::fechaDiaInput($query['fechaHasta'] ?? null);
    if ($desde !== null && $hasta !== null && $desde > $hasta) {
      throw new \InvalidArgumentException('fechaDesde no puede ser posterior a fechaHasta');
    }
    $col = 'CONVERT(date, p.[Fecha])';
    if ($desde !== null) {
      $where[] = "{$col} >= CONVERT(date, :fecha_d, 120)";
      $params['fecha_d'] = $desde;
    }
    if ($hasta !== null) {
      $where[] = "{$col} <= CONVERT(date, :fecha_h, 120)";
      $params['fecha_h'] = $hasta;
    }
  }

  /**
   * @param list<string> $where
   * @param array<string, mixed> $params
   * @param array<string, mixed> $query
   */
  private function rangoEntero(
    array &$where,
    array &$params,
    string $columna,
    array $query,
    string $desdeKey,
    string $hastaKey,
    string $pfx
  ): void {
    $desde = (int) ($query[$desdeKey] ?? 0);
    $hasta = (int) ($query[$hastaKey] ?? 0);
    if ($desde <= 0 && $hasta <= 0) {
      return;
    }
    if ($desde > 0 && $hasta > 0 && $desde > $hasta) {
      throw new \InvalidArgumentException("{$desdeKey} no puede ser mayor que {$hastaKey}");
    }
    if ($desde > 0) {
      $where[] = "{$columna} >= :{$pfx}_d";
      $params["{$pfx}_d"] = $desde;
    }
    if ($hasta > 0) {
      $where[] = "{$columna} <= :{$pfx}_h";
      $params["{$pfx}_h"] = $hasta;
    }
  }

  /** @param array<string, mixed> $row */
  private function filaProveedor(array $row): array
  {
    return [
      'empresa' => trim((string) ($row['empresa'] ?? '')),
      'pedido' => (int) ($row['pedido'] ?? 0),
      'fecha' => (string) ($row['fecha'] ?? ''),
      'cliente' => trim((string) ($row['cliente'] ?? '')),
      'clienteNombre' => trim((string) ($row['clienteNombre'] ?? '')),
      'proveedor' => trim((string) ($row['proveedor'] ?? '')),
      'proveedorNombre' => trim((string) ($row['proveedorNombre'] ?? '')),
      'importePedido' => round((float) ($row['importePedido'] ?? 0), 2),
      'comision' => round((float) ($row['comision'] ?? 0), 2),
    ];
  }

  /** @param array<string, mixed> $row */
  private function filaTrabajador(array $row): array
  {
    $importe = round((float) ($row['importePedido'] ?? 0), 2);
    $pje = round((float) ($row['pje'] ?? 0), 4);
    return [
      'empresa' => trim((string) ($row['empresa'] ?? '')),
      'pedido' => (int) ($row['pedido'] ?? 0),
      'fecha' => (string) ($row['fecha'] ?? ''),
      'cliente' => trim((string) ($row['cliente'] ?? '')),
      'clienteNombre' => trim((string) ($row['clienteNombre'] ?? '')),
      'trabajador' => trim((string) ($row['trabajador'] ?? '')),
      'trabajadorNombre' => trim((string) ($row['trabajadorNombre'] ?? '')),
      'importePedido' => $importe,
      'pje' => $pje,
      'comision' => round($importe * $pje / 100, 2),
    ];
  }
}
