<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Compras;

use Descartes\Api\Database\SqlPagination;
use PDO;

/**
 * Consulta de facturas de proveedor (004 US5 / T035).
 * Tabla: FacturasCompras (solo lectura v1). PK: Factura.
 * Nota SQL: vencimiento 3 = columna ImporteVto31.
 */
final class FacturaCompraConsultaService
{
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
    $pageSize = min(500, max(1, (int) ($query['pageSize'] ?? 25)));
    $offset = ($page - 1) * $pageSize;

    $where = ['1=1'];
    $params = [];

    if ($this->fechaIsoValida($query['fechaDesde'] ?? null)) {
      $where[] = 'f.Fecha >= CONVERT(datetime, :fechaDesde, 120)';
      $params['fechaDesde'] = substr((string) $query['fechaDesde'], 0, 10) . ' 00:00:00';
    }
    if ($this->fechaIsoValida($query['fechaHasta'] ?? null)) {
      $where[] = 'f.Fecha <= CONVERT(datetime, :fechaHasta, 120)';
      $params['fechaHasta'] = substr((string) $query['fechaHasta'], 0, 10) . ' 23:59:59';
    }
    if (!empty($query['proveedor'])) {
      $where[] = '(f.Proveedor LIKE :proveedor OR p.RazonSocial LIKE :proveedorNombre)';
      $params['proveedor'] = '%' . trim((string) $query['proveedor']) . '%';
      $params['proveedorNombre'] = '%' . trim((string) $query['proveedor']) . '%';
    }
    if (isset($query['factura']) && $query['factura'] !== '' && $query['factura'] !== null) {
      $where[] = 'f.Factura = :factura';
      $params['factura'] = (int) $query['factura'];
    }
    if (!empty($query['suFactura'])) {
      $where[] = 'f.SuFactura LIKE :suFactura';
      $params['suFactura'] = '%' . trim((string) $query['suFactura']) . '%';
    }
    if (!empty($query['estado'])) {
      $where[] = 'f.Estado = :estado';
      $params['estado'] = trim((string) $query['estado']);
    }

    $sqlWhere = implode(' AND ', $where);
    $from = 'FacturasCompras f
             LEFT JOIN Proveedores p ON RTRIM(p.Codigo) = RTRIM(f.Proveedor)';

    $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$from} WHERE {$sqlWhere}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $innerSql = "SELECT f.Factura, f.SuFactura, f.Fecha, f.Proveedor,
                   p.RazonSocial AS ProveedorNombre,
                   f.Estado, f.FPago,
                   f.BaseImp1, f.BaseImp2, f.BaseImp3,
                   f.PjeIVA1, f.PjeIVA2, f.PjeIVA3
            FROM {$from}
            WHERE {$sqlWhere}";
    $sql = SqlPagination::wrap($innerSql, 'f.Fecha DESC, f.Factura DESC', $offset, $pageSize);
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
  public function obtener(int $factura): ?array
  {
    $stmt = $this->pdo->prepare(
      'SELECT f.Factura, f.SuFactura, f.Fecha, f.Proveedor,
              p.RazonSocial AS ProveedorNombre,
              f.Estado, f.FPago,
              f.FechaVto1, f.FechaVto2, f.FechaVto3, f.FechaVto4, f.FechaVto5, f.FechaVto6,
              f.ImporteVto1, f.ImporteVto2, f.ImporteVto31, f.ImporteVto4, f.ImporteVto5, f.ImporteVto6,
              f.EstadoVto1, f.EstadoVto2, f.EstadoVto3, f.EstadoVto4, f.EstadoVto5, f.EstadoVto6,
              f.BaseImp1, f.BaseImp2, f.BaseImp3,
              f.PjeIVA1, f.PjeIVA2, f.PjeIVA3,
              f.PjeRec1, f.PjeRec2, f.PjeRec3
       FROM FacturasCompras f
       LEFT JOIN Proveedores p ON RTRIM(p.Codigo) = RTRIM(f.Proveedor)
       WHERE f.Factura = :factura'
    );
    $stmt->execute(['factura' => $factura]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
      return null;
    }

    $detalle = $this->mapResumen($row);
    $detalle['fechaVto1'] = $this->fmtDate($row['FechaVto1'] ?? null);
    $detalle['fechaVto2'] = $this->fmtDate($row['FechaVto2'] ?? null);
    $detalle['fechaVto3'] = $this->fmtDate($row['FechaVto3'] ?? null);
    $detalle['fechaVto4'] = $this->fmtDate($row['FechaVto4'] ?? null);
    $detalle['fechaVto5'] = $this->fmtDate($row['FechaVto5'] ?? null);
    $detalle['fechaVto6'] = $this->fmtDate($row['FechaVto6'] ?? null);
    $detalle['importeVto1'] = (float) ($row['ImporteVto1'] ?? 0);
    $detalle['importeVto2'] = (float) ($row['ImporteVto2'] ?? 0);
    $detalle['importeVto3'] = (float) ($row['ImporteVto31'] ?? 0);
    $detalle['importeVto4'] = (float) ($row['ImporteVto4'] ?? 0);
    $detalle['importeVto5'] = (float) ($row['ImporteVto5'] ?? 0);
    $detalle['importeVto6'] = (float) ($row['ImporteVto6'] ?? 0);
    $detalle['estadoVto1'] = $this->trimOrNull($row['EstadoVto1'] ?? null);
    $detalle['estadoVto2'] = $this->trimOrNull($row['EstadoVto2'] ?? null);
    $detalle['estadoVto3'] = $this->trimOrNull($row['EstadoVto3'] ?? null);
    $detalle['estadoVto4'] = $this->trimOrNull($row['EstadoVto4'] ?? null);
    $detalle['estadoVto5'] = $this->trimOrNull($row['EstadoVto5'] ?? null);
    $detalle['estadoVto6'] = $this->trimOrNull($row['EstadoVto6'] ?? null);
    $detalle['pjeRec1'] = (float) ($row['PjeRec1'] ?? 0);
    $detalle['pjeRec2'] = (float) ($row['PjeRec2'] ?? 0);
    $detalle['pjeRec3'] = (float) ($row['PjeRec3'] ?? 0);
    $detalle['editable'] = false;

    return $detalle;
  }

  /**
   * @param array<string, mixed> $row
   * @return array<string, mixed>
   */
  private function mapResumen(array $row): array
  {
    return [
      'factura' => (int) ($row['Factura'] ?? 0),
      'suFactura' => $this->trimOrNull($row['SuFactura'] ?? null),
      'fecha' => $this->fmtDate($row['Fecha'] ?? null),
      'proveedor' => $this->trimOrNull($row['Proveedor'] ?? null),
      'razonSocial' => $this->trimOrNull($row['ProveedorNombre'] ?? null),
      'estado' => $this->trimOrNull($row['Estado'] ?? null),
      'fpago' => $this->trimOrNull($row['FPago'] ?? null),
      'baseImp1' => (float) ($row['BaseImp1'] ?? 0),
      'baseImp2' => (float) ($row['BaseImp2'] ?? 0),
      'baseImp3' => (float) ($row['BaseImp3'] ?? 0),
      'pjeIva1' => (float) ($row['PjeIVA1'] ?? 0),
      'pjeIva2' => (float) ($row['PjeIVA2'] ?? 0),
      'pjeIva3' => (float) ($row['PjeIVA3'] ?? 0),
    ];
  }

  private function trimOrNull($value): ?string
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
}
