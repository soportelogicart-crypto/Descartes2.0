<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Compras;

use Descartes\Api\Database\SqlPagination;
use PDO;

/**
 * Consulta de albaranes de compra (004 US1 / T012).
 * Tablas: AlbaranesCompraCab + AlbaranesComprasLin.
 */
final class AlbaranCompraConsultaService
{
  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  /**
   * Listado paginado con filtros.
   *
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
      $where[] = 'c.FechaAlbaran >= CONVERT(datetime, :fechaDesde, 120)';
      $params['fechaDesde'] = substr((string) $query['fechaDesde'], 0, 10) . ' 00:00:00';
    }
    if ($this->fechaIsoValida($query['fechaHasta'] ?? null)) {
      $where[] = 'c.FechaAlbaran <= CONVERT(datetime, :fechaHasta, 120)';
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
    if (isset($query['albaran']) && $query['albaran'] !== '' && $query['albaran'] !== null) {
      $where[] = 'c.Albaran = :albaran';
      $params['albaran'] = (int) $query['albaran'];
    }
    if (!empty($query['suAlbaran'])) {
      $where[] = 'c.SuAlbaran LIKE :suAlbaran';
      $params['suAlbaran'] = '%' . trim((string) $query['suAlbaran']) . '%';
    }

    $sqlWhere = implode(' AND ', $where);
    $from = 'AlbaranesCompraCab c
             LEFT JOIN Proveedores p ON RTRIM(p.Codigo) = RTRIM(c.Proveedor)';

    $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$from} WHERE {$sqlWhere}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $innerSql = "SELECT c.Empresa, c.Albaran, c.SuAlbaran, c.FechaAlbaran, c.Proveedor,
                   p.RazonSocial AS ProveedorNombre,
                   c.FPago, c.ImporteAlb, c.ImporteDtos, c.ImporteIVA, c.ImporteRec,
                   c.Actualizado, c.AlbaranDevolucion, c.TrasCtb, c.Almacen, c.Estado
            FROM {$from}
            WHERE {$sqlWhere}";
    $sql = SqlPagination::wrap($innerSql, 'c.FechaAlbaran DESC, c.Albaran DESC, c.Empresa ASC', $offset, $pageSize);
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
   * Detalle cabecera + líneas. null si no existe.
   *
   * @return array<string, mixed>|null
   */
  public function obtener(string $empresa, int $albaran): ?array
  {
    $stmt = $this->pdo->prepare(
      'SELECT c.Empresa, c.Albaran, c.SuAlbaran, c.FechaAlbaran, c.Proveedor,
              p.RazonSocial AS ProveedorNombre,
              c.FPago, c.ImporteAlb, c.ImporteDtos, c.ImporteIVA, c.ImporteRec,
              c.Observaciones, c.Actualizado, c.AlbaranDevolucion, c.AlbaranDevolucionEstado,
              c.TrasModem, c.TrasCtb, c.Almacen, c.Serie, c.Seleccion, c.LUpdate,
              c.Cliente, c.Proyecto, c.ImporteTransporte, c.CoeficienteTransporte,
              c.BrutoConTransporte, c.Estado
       FROM AlbaranesCompraCab c
       LEFT JOIN Proveedores p ON RTRIM(p.Codigo) = RTRIM(c.Proveedor)
       WHERE c.Empresa = :empresa AND c.Albaran = :albaran'
    );
    $stmt->execute(['empresa' => $empresa, 'albaran' => $albaran]);
    $cab = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cab) {
      return null;
    }

    $linStmt = $this->pdo->prepare(
      'SELECT l.NroLin, l.Articulo, l.Descripcion, l.Cantidad, l.Precio, l.PjeDto,
              l.Dto1, l.Dto2, l.Dto3, l.Pedido, l.Lote, l.Almacen, l.ArticuloOriginal,
              ISNULL(a.PrecioVen1, 0) AS PrecioVen1
       FROM AlbaranesComprasLin l
       LEFT JOIN Articulos a ON RTRIM(a.Codigo) = RTRIM(l.Articulo)
       WHERE l.Empresa = :empresa AND l.Albaran = :albaran
       ORDER BY l.NroLin'
    );
    $linStmt->execute(['empresa' => $empresa, 'albaran' => $albaran]);

    $lineas = [];
    while ($lin = $linStmt->fetch(PDO::FETCH_ASSOC)) {
      $lineas[] = $this->mapLinea($lin);
    }

    $detalle = $this->mapResumen($cab);
    $detalle['observaciones'] = $this->textoNtext($cab['Observaciones'] ?? null);
    $detalle['albaranDevolucionEstado'] = isset($cab['AlbaranDevolucionEstado'])
      ? (int) $cab['AlbaranDevolucionEstado']
      : null;
    $detalle['trasModem'] = $this->asBool($cab['TrasModem'] ?? false);
    $detalle['serie'] = $this->trimOrNull($cab['Serie'] ?? null);
    $detalle['seleccion'] = $this->asBool($cab['Seleccion'] ?? false);
    $detalle['cliente'] = $this->trimOrNull($cab['Cliente'] ?? null);
    $detalle['proyecto'] = $this->trimOrNull($cab['Proyecto'] ?? null);
    $detalle['importeTransporte'] = (float) ($cab['ImporteTransporte'] ?? 0);
    $detalle['coeficienteTransporte'] = (float) ($cab['CoeficienteTransporte'] ?? 0);
    $detalle['brutoConTransporte'] = (float) ($cab['BrutoConTransporte'] ?? 0);
    $detalle['lUpdate'] = $this->fmtDate($cab['LUpdate'] ?? null);
    $detalle['editable'] = !$this->asBool($cab['TrasCtb'] ?? false)
      && !$this->asBool($cab['Actualizado'] ?? false);
    $detalle['lineas'] = $lineas;

    return $detalle;
  }

  /**
   * @param array<string, mixed> $row
   * @return array<string, mixed>
   */
  private function mapResumen(array $row): array
  {
    return [
      'empresa' => trim((string) ($row['Empresa'] ?? '')),
      'albaran' => (int) ($row['Albaran'] ?? 0),
      'suAlbaran' => $this->trimOrNull($row['SuAlbaran'] ?? null),
      'fechaAlbaran' => $this->fmtDate($row['FechaAlbaran'] ?? null),
      'proveedor' => $this->trimOrNull($row['Proveedor'] ?? null),
      'razonSocial' => $this->trimOrNull($row['ProveedorNombre'] ?? null),
      'fpago' => $this->trimOrNull($row['FPago'] ?? null),
      'importeAlb' => (float) ($row['ImporteAlb'] ?? 0),
      'importeDtos' => (float) ($row['ImporteDtos'] ?? 0),
      'importeIva' => (float) ($row['ImporteIVA'] ?? 0),
      'importeRec' => (float) ($row['ImporteRec'] ?? 0),
      'actualizado' => $this->asBool($row['Actualizado'] ?? false),
      'albaranDevolucion' => $this->asBool($row['AlbaranDevolucion'] ?? false),
      'trasCtb' => $this->asBool($row['TrasCtb'] ?? false),
      'almacen' => isset($row['Almacen']) && $row['Almacen'] !== null ? (int) $row['Almacen'] : null,
      'estado' => $this->trimOrNull($row['Estado'] ?? null),
    ];
  }

  /**
   * @param array<string, mixed> $lin
   * @return array<string, mixed>
   */
  private function mapLinea(array $lin): array
  {
    $pedido = isset($lin['Pedido']) ? (int) $lin['Pedido'] : 0;

    return [
      'nroLin' => (int) ($lin['NroLin'] ?? 0),
      'articulo' => $this->trimOrNull($lin['Articulo'] ?? null),
      'descripcion' => $this->trimOrNull($lin['Descripcion'] ?? null),
      'cantidad' => (float) ($lin['Cantidad'] ?? 0),
      'precio' => (float) ($lin['Precio'] ?? 0),
      'pjeDto' => (float) ($lin['PjeDto'] ?? 0),
      'dto1' => (float) ($lin['Dto1'] ?? 0),
      'dto2' => (float) ($lin['Dto2'] ?? 0),
      'dto3' => (float) ($lin['Dto3'] ?? 0),
      'pedido' => $pedido > 0 ? $pedido : null,
      'lote' => $this->trimOrNull($lin['Lote'] ?? null),
      'almacen' => isset($lin['Almacen']) && $lin['Almacen'] !== null ? (int) $lin['Almacen'] : null,
      'articuloOriginal' => $this->trimOrNull($lin['ArticuloOriginal'] ?? null),
      'precioVenta' => (float) ($lin['PrecioVen1'] ?? 0),
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
}
