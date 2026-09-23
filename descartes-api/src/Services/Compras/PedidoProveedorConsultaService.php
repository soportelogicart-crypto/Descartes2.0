<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Compras;

use Descartes\Api\Database\SqlPagination;
use PDO;

/**
 * Consulta de pedidos a proveedor (004 US3 / T026).
 * Tablas: PedidosCab + PedidosLin.
 *
 * Situacion SQL (smallint): 0 pendiente, 1 parcial, 2 servido.
 * situacionLabel se deriva siempre de Σ CantidadSer / Σ CantidadPed.
 */
final class PedidoProveedorConsultaService
{
  public const SIT_PENDIENTE = 0;
  public const SIT_PARCIAL = 1;
  public const SIT_SERVIDO = 2;

  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  /**
   * @param array<string, mixed> $query
   * @return array{items: list<array<string, mixed>>, total: int, page: int, pageSize: int}
   */
  public function listar(array $query): array
  {
    $page = max(1, (int) ($query['page'] ?? 1));
    $pageSize = min(5000, max(1, (int) ($query['pageSize'] ?? 25)));
    $offset = ($page - 1) * $pageSize;

    $where = ['1=1'];
    $params = [];

    if ($this->fechaIsoValida($query['fechaDesde'] ?? null)) {
      $where[] = 'c.FechaPedido >= CONVERT(datetime, :fechaDesde, 120)';
      $params['fechaDesde'] = substr((string) $query['fechaDesde'], 0, 10) . ' 00:00:00';
    }
    if ($this->fechaIsoValida($query['fechaHasta'] ?? null)) {
      $where[] = 'c.FechaPedido <= CONVERT(datetime, :fechaHasta, 120)';
      $params['fechaHasta'] = substr((string) $query['fechaHasta'], 0, 10) . ' 23:59:59';
    }
    if (!empty($query['empresa'])) {
      $where[] = 'c.Empresa = :empresa';
      $params['empresa'] = trim((string) $query['empresa']);
    }
    if (!empty($query['proveedor'])) {
      $where[] = '(c.Proveedor LIKE :proveedor OR p.RazonSocial LIKE :proveedorNombre)';
      $params['proveedor'] = '%' . trim((string) $query['proveedor']) . '%';
      $params['proveedorNombre'] = '%' . trim((string) $query['proveedor']) . '%';
    }
    if (isset($query['almacen']) && $query['almacen'] !== '' && $query['almacen'] !== null) {
      $where[] = 'c.Almacen = :almacen';
      $params['almacen'] = (int) $query['almacen'];
    }
    if (isset($query['pedido']) && $query['pedido'] !== '' && $query['pedido'] !== null) {
      $where[] = 'c.Pedido = :pedido';
      $params['pedido'] = (int) $query['pedido'];
    }
    if (isset($query['situacion']) && $query['situacion'] !== '' && $query['situacion'] !== null) {
      $where[] = 'c.Situacion = :situacion';
      $params['situacion'] = (int) $query['situacion'];
    }
    // El filtro de columna del grid es parcial: "152" debe encontrar 1523.
    $pedidoTexto = $this->soloDigitos($query['pedidoTexto'] ?? null);
    if ($pedidoTexto !== '') {
      $where[] = 'CAST(c.Pedido AS varchar(20)) LIKE :pedidoTexto';
      $params['pedidoTexto'] = '%' . $pedidoTexto . '%';
    }

    $sqlWhere = implode(' AND ', $where);
    $from = 'PedidosCab c
             LEFT JOIN Proveedores p ON RTRIM(p.Codigo) = RTRIM(c.Proveedor)';

    $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$from} WHERE {$sqlWhere}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $innerSql = "SELECT c.Empresa, c.Pedido, c.FechaPedido, c.Proveedor,
                   p.RazonSocial AS ProveedorNombre,
                   c.Importe, c.Situacion, c.FechaMaxRecepcion, c.Vendedor, c.Almacen
            FROM {$from}
            WHERE {$sqlWhere}";
    $sql = SqlPagination::wrap($innerSql, 'c.FechaPedido DESC, c.Pedido DESC, c.Empresa ASC', $offset, $pageSize);
    $stmt = $this->pdo->prepare($sql);
    foreach ($params as $k => $v) {
      $stmt->bindValue(':' . $k, $v);
    }
    SqlPagination::bind($stmt, $offset, $pageSize);
    $stmt->execute();

    $items = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $items[] = $this->mapResumen($row);
    }

    // Enriquecer situacionLabel con agregados de líneas (más fiable que Situacion sola).
    $this->enrichSituacionFromLineas($items);

    return [
      'items' => $items,
      'total' => $total,
      'page' => $page,
      'pageSize' => $pageSize,
    ];
  }

  /**
   * @return array<string, mixed>|null
   */
  public function obtener(string $empresa, int $pedido): ?array
  {
    $stmt = $this->pdo->prepare(
      'SELECT c.Empresa, c.Pedido, c.FechaPedido, c.Proveedor,
              p.RazonSocial AS ProveedorNombre,
              c.Importe, c.ImporteRec, c.Situacion, c.FechaMaxRecepcion,
              c.Observaciones, c.ObservInternas, c.Vendedor, c.TrasModem,
              c.Almacen, c.PreciosActualizados, c.PrevisionC, c.PedidosWeb
       FROM PedidosCab c
       LEFT JOIN Proveedores p ON RTRIM(p.Codigo) = RTRIM(c.Proveedor)
       WHERE c.Empresa = :empresa AND c.Pedido = :pedido'
    );
    $stmt->execute(['empresa' => $empresa, 'pedido' => $pedido]);
    $cab = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cab) {
      return null;
    }

    $linStmt = $this->pdo->prepare(
      'SELECT NumLin, Articulo, Descripcion, CantidadPed, CantidadSer,
              PrecioPed, PrecioRec, PjeDto, Dto1, Dto2, Dto3, Importe, Almacen, Prevision
       FROM PedidosLin
       WHERE Empresa = :empresa AND Pedido = :pedido
       ORDER BY NumLin'
    );
    $linStmt->execute(['empresa' => $empresa, 'pedido' => $pedido]);

    $lineas = [];
    $sumPed = 0.0;
    $sumSer = 0.0;
    while ($lin = $linStmt->fetch(PDO::FETCH_ASSOC)) {
      $mapped = $this->mapLinea($lin);
      $lineas[] = $mapped;
      $sumPed += (float) $mapped['cantidadPed'];
      $sumSer += (float) $mapped['cantidadSer'];
    }

    $label = $this->labelFromCantidades($sumPed, $sumSer);
    $detalle = $this->mapResumen($cab);
    $detalle['situacionLabel'] = $label;
    $detalle['situacion'] = $this->codigoFromLabel($label);
    $detalle['observaciones'] = $this->textoNtext($cab['Observaciones'] ?? null);
    $detalle['observInternas'] = $this->textoNtext($cab['ObservInternas'] ?? null);
    $detalle['trasModem'] = $this->asBool($cab['TrasModem'] ?? false);
    $detalle['preciosActualizados'] = $this->asBool($cab['PreciosActualizados'] ?? false);
    $detalle['previsionC'] = $this->fmtDate($cab['PrevisionC'] ?? null);
    $detalle['importeRec'] = isset($cab['ImporteRec']) ? (int) $cab['ImporteRec'] : null;
    $detalle['pedidosWeb'] = $this->asBool($cab['PedidosWeb'] ?? false);
    $detalle['editable'] = $label !== 'servido';
    $detalle['lineas'] = $lineas;

    return $detalle;
  }

  /**
   * @param list<array<string, mixed>> $items
   */
  private function enrichSituacionFromLineas(array &$items): void
  {
    if ($items === []) {
      return;
    }

    // Agrupar por empresa para una query por tienda (listados típicos = 1 empresa).
    $byEmpresa = [];
    foreach ($items as $i => $it) {
      $e = (string) ($it['empresa'] ?? '');
      $byEmpresa[$e][] = ['idx' => $i, 'pedido' => (int) ($it['pedido'] ?? 0)];
    }

    foreach ($byEmpresa as $empresa => $refs) {
      if ($empresa === '' || $refs === []) {
        continue;
      }
      $pedidos = array_values(array_unique(array_map(static fn ($r) => $r['pedido'], $refs)));
      $pedidos = array_filter($pedidos, static fn ($p) => $p > 0);
      if ($pedidos === []) {
        continue;
      }

      $placeholders = [];
      $params = ['e' => $empresa];
      foreach (array_values($pedidos) as $i => $p) {
        $key = 'p' . $i;
        $placeholders[] = ':' . $key;
        $params[$key] = $p;
      }
      $in = implode(',', $placeholders);
      $sql = "SELECT Pedido,
                     ISNULL(SUM(CantidadPed), 0) AS SumPed,
                     ISNULL(SUM(CantidadSer), 0) AS SumSer
              FROM PedidosLin
              WHERE Empresa = :e AND Pedido IN ({$in})
              GROUP BY Pedido";
      $stmt = $this->pdo->prepare($sql);
      $stmt->execute($params);
      $map = [];
      while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $map[(int) $row['Pedido']] = [
          (float) $row['SumPed'],
          (float) $row['SumSer'],
        ];
      }
      foreach ($refs as $ref) {
        $idx = $ref['idx'];
        $pedido = $ref['pedido'];
        if (!isset($map[$pedido])) {
          $items[$idx]['situacionLabel'] = 'pendiente';
          continue;
        }
        [$ped, $ser] = $map[$pedido];
        $items[$idx]['situacionLabel'] = $this->labelFromCantidades($ped, $ser);
      }
    }
  }

  public function labelFromCantidades(float $sumPed, float $sumSer): string
  {
    $eps = 0.0000001;
    if ($sumSer <= $eps) {
      return 'pendiente';
    }
    if ($sumPed > $eps && $sumSer + $eps >= $sumPed) {
      return 'servido';
    }
    return 'parcial';
  }

  public function codigoFromLabel(string $label): int
  {
    return match ($label) {
      'parcial' => self::SIT_PARCIAL,
      'servido' => self::SIT_SERVIDO,
      default => self::SIT_PENDIENTE,
    };
  }

  /**
   * @param array<string, mixed> $row
   * @return array<string, mixed>
   */
  private function mapResumen(array $row): array
  {
    $sit = isset($row['Situacion']) && $row['Situacion'] !== null ? (int) $row['Situacion'] : null;
    $label = match ($sit) {
      self::SIT_PARCIAL => 'parcial',
      self::SIT_SERVIDO => 'servido',
      self::SIT_PENDIENTE => 'pendiente',
      default => $sit === null ? null : 'pendiente',
    };

    return [
      'empresa' => trim((string) ($row['Empresa'] ?? '')),
      'pedido' => (int) ($row['Pedido'] ?? 0),
      'fechaPedido' => $this->fmtDate($row['FechaPedido'] ?? null),
      'proveedor' => $this->trimOrNull($row['Proveedor'] ?? null),
      'razonSocial' => $this->trimOrNull($row['ProveedorNombre'] ?? null),
      'importe' => (float) ($row['Importe'] ?? 0),
      'situacion' => $sit,
      'situacionLabel' => $label,
      'fechaMaxRecepcion' => $this->fmtDate($row['FechaMaxRecepcion'] ?? null),
      'vendedor' => $this->trimOrNull($row['Vendedor'] ?? null),
      'almacen' => isset($row['Almacen']) && $row['Almacen'] !== null ? (int) $row['Almacen'] : null,
    ];
  }

  /**
   * @param array<string, mixed> $lin
   * @return array<string, mixed>
   */
  private function mapLinea(array $lin): array
  {
    return [
      'numLin' => (int) ($lin['NumLin'] ?? 0),
      'articulo' => $this->trimOrNull($lin['Articulo'] ?? null),
      'descripcion' => $this->trimOrNull($lin['Descripcion'] ?? null),
      'cantidadPed' => (float) ($lin['CantidadPed'] ?? 0),
      'cantidadSer' => (float) ($lin['CantidadSer'] ?? 0),
      'precioPed' => (float) ($lin['PrecioPed'] ?? 0),
      'precioRec' => (float) ($lin['PrecioRec'] ?? 0),
      'pjeDto' => (float) ($lin['PjeDto'] ?? 0),
      'dto1' => (float) ($lin['Dto1'] ?? 0),
      'dto2' => (float) ($lin['Dto2'] ?? 0),
      'dto3' => (float) ($lin['Dto3'] ?? 0),
      'importe' => (float) ($lin['Importe'] ?? 0),
      'almacen' => isset($lin['Almacen']) && $lin['Almacen'] !== null ? (int) $lin['Almacen'] : null,
      'prevision' => $this->fmtDate($lin['Prevision'] ?? null),
    ];
  }

  private function asBool($value): bool
  {
    if (is_bool($value)) {
      return $value;
    }
    return (int) $value === 1;
  }

  private function trimOrNull($value): ?string
  {
    if ($value === null) {
      return null;
    }
    $s = trim((string) $value);
    return $s === '' ? null : $s;
  }

  private function textoNtext($value): ?string
  {
    if ($value === null) {
      return null;
    }
    $s = trim((string) $value);
    return $s === '' ? null : $s;
  }

  private function fmtDate($value): ?string
  {
    if ($value === null || $value === '') {
      return null;
    }
    if ($value instanceof \DateTimeInterface) {
      return $value->format('c');
    }
    $ts = strtotime((string) $value);
    return $ts ? date('c', $ts) : (string) $value;
  }

  private function fechaIsoValida($value): bool
  {
    if ($value === null || $value === '') {
      return false;
    }
    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $value);
  }

  /** @param mixed $value */
  private function soloDigitos($value): string
  {
    if ($value === null) {
      return '';
    }
    return preg_replace('/\D+/', '', (string) $value) ?? '';
  }
}
