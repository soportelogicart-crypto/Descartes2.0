<?php

declare(strict_types=1);

namespace Descartes\Api\Repositories;

use PDO;

final class OfertasProveedorRepository
{
  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  /**
   * @return array{items: list<array<string, mixed>>, total: int, page: int, pageSize: int}
   */
  public function list(array $query): array
  {
    $page = max(1, (int) ($query['page'] ?? 1));
    $pageSize = min(500, max(1, (int) ($query['pageSize'] ?? 100)));
    $offset = ($page - 1) * $pageSize;

    $where = [];
    $params = [];
    if (isset($query['q']) && trim((string) $query['q']) !== '') {
      $where[] = '(o.[Articulo] LIKE :q OR o.[Proveedor] LIKE :q OR a.[Descripcion] LIKE :q OR p.[RazonSocial] LIKE :q)';
      $params['q'] = '%' . trim((string) $query['q']) . '%';
    }
    $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

    $countSql = "SELECT COUNT(*) FROM [OfertasProveedor] o
      LEFT JOIN [Articulos] a ON RTRIM(a.[Codigo]) = RTRIM(o.[Articulo])
      LEFT JOIN [Proveedores] p ON RTRIM(p.[Codigo]) = RTRIM(o.[Proveedor])
      {$whereSql}";
    $countStmt = $this->pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $sql = "SELECT o.[Articulo], o.[Proveedor], o.[FechaInicio], o.[FechaFin], o.[PrecioEsp], o.[LUpdate],
      o.[PjeDto], o.[PjeDto2], o.[PjeDto3],
      o.[Cantidad1], o.[Cantidad2], o.[Cantidad3], o.[Cantidad4],
      o.[Cantidad5], o.[Cantidad6], o.[Cantidad7], o.[Cantidad8],
      o.[PrecioEsp1], o.[PrecioEsp2], o.[PrecioEsp3], o.[PrecioEsp4],
      o.[PrecioEsp5], o.[PrecioEsp6], o.[PrecioEsp7], o.[PrecioEsp8],
      a.[Descripcion] AS ArticuloDescripcion,
      p.[RazonSocial] AS ProveedorNombre
      FROM [OfertasProveedor] o
      LEFT JOIN [Articulos] a ON RTRIM(a.[Codigo]) = RTRIM(o.[Articulo])
      LEFT JOIN [Proveedores] p ON RTRIM(p.[Codigo]) = RTRIM(o.[Proveedor])
      {$whereSql}
      ORDER BY o.[Articulo], o.[Proveedor]
      OFFSET {$offset} ROWS FETCH NEXT {$pageSize} ROWS ONLY";

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);
    $items = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $items[] = $this->mapRow($row);
    }

    return [
      'items' => $items,
      'total' => $total,
      'page' => $page,
      'pageSize' => $pageSize,
    ];
  }

  public function findOne(string $articulo, string $proveedor): ?array
  {
    $stmt = $this->pdo->prepare(
      'SELECT o.[Articulo], o.[Proveedor], o.[FechaInicio], o.[FechaFin], o.[PrecioEsp], o.[LUpdate],
        o.[PjeDto], o.[PjeDto2], o.[PjeDto3],
        o.[Cantidad1], o.[Cantidad2], o.[Cantidad3], o.[Cantidad4],
        o.[Cantidad5], o.[Cantidad6], o.[Cantidad7], o.[Cantidad8],
        o.[PrecioEsp1], o.[PrecioEsp2], o.[PrecioEsp3], o.[PrecioEsp4],
        o.[PrecioEsp5], o.[PrecioEsp6], o.[PrecioEsp7], o.[PrecioEsp8],
        a.[Descripcion] AS ArticuloDescripcion,
        p.[RazonSocial] AS ProveedorNombre
       FROM [OfertasProveedor] o
       LEFT JOIN [Articulos] a ON RTRIM(a.[Codigo]) = RTRIM(o.[Articulo])
       LEFT JOIN [Proveedores] p ON RTRIM(p.[Codigo]) = RTRIM(o.[Proveedor])
       WHERE RTRIM(o.[Articulo]) = :articulo AND RTRIM(o.[Proveedor]) = :proveedor'
    );
    $stmt->execute([
      'articulo' => trim($articulo),
      'proveedor' => trim($proveedor),
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $this->mapRow($row) : null;
  }

  /** @param array<string, mixed> $data */
  public function create(array $data): array
  {
    $articulo = $this->str($data['articulo'] ?? null, 18);
    $proveedor = $this->str($data['proveedor'] ?? null, 6);
    if ($articulo === null || $proveedor === null) {
      throw new \InvalidArgumentException('Articulo y proveedor son obligatorios');
    }
    if ($this->findOne($articulo, $proveedor) !== null) {
      throw new \InvalidArgumentException('Ya existe una oferta para ese articulo y proveedor');
    }

    $lUpdate = date('Y-m-d H:i:s');
    $stmt = $this->pdo->prepare(
      'INSERT INTO [OfertasProveedor] (
        [Articulo], [Proveedor], [FechaInicio], [FechaFin], [PrecioEsp], [LUpdate],
        [PjeDto], [PjeDto2], [PjeDto3],
        [Cantidad1], [Cantidad2], [Cantidad3], [Cantidad4], [Cantidad5], [Cantidad6], [Cantidad7], [Cantidad8],
        [PrecioEsp1], [PrecioEsp2], [PrecioEsp3], [PrecioEsp4], [PrecioEsp5], [PrecioEsp6], [PrecioEsp7], [PrecioEsp8]
      ) VALUES (
        :articulo, :proveedor,
        CONVERT(datetime, :fechaInicio, 120), CONVERT(datetime, :fechaFin, 120),
        :precioEsp, CONVERT(datetime, :lUpdate, 120),
        :pjeDto, :pjeDto2, :pjeDto3,
        :cantidad1, :cantidad2, :cantidad3, :cantidad4, :cantidad5, :cantidad6, :cantidad7, :cantidad8,
        :precioEsp1, :precioEsp2, :precioEsp3, :precioEsp4, :precioEsp5, :precioEsp6, :precioEsp7, :precioEsp8
      )'
    );
    $stmt->execute($this->bindPayload($articulo, $proveedor, $data, $lUpdate));

    $created = $this->findOne($articulo, $proveedor);
    if ($created === null) {
      throw new \RuntimeException('No se pudo leer la oferta creada');
    }
    return $created;
  }

  /** @param array<string, mixed> $data */
  public function update(string $articulo, string $proveedor, array $data): ?array
  {
    $articulo = trim($articulo);
    $proveedor = trim($proveedor);
    if ($this->findOne($articulo, $proveedor) === null) {
      return null;
    }

    $lUpdate = date('Y-m-d H:i:s');
    $stmt = $this->pdo->prepare(
      'UPDATE [OfertasProveedor] SET
        [FechaInicio] = CONVERT(datetime, :fechaInicio, 120),
        [FechaFin] = CONVERT(datetime, :fechaFin, 120),
        [PrecioEsp] = :precioEsp,
        [LUpdate] = CONVERT(datetime, :lUpdate, 120),
        [PjeDto] = :pjeDto, [PjeDto2] = :pjeDto2, [PjeDto3] = :pjeDto3,
        [Cantidad1] = :cantidad1, [Cantidad2] = :cantidad2, [Cantidad3] = :cantidad3, [Cantidad4] = :cantidad4,
        [Cantidad5] = :cantidad5, [Cantidad6] = :cantidad6, [Cantidad7] = :cantidad7, [Cantidad8] = :cantidad8,
        [PrecioEsp1] = :precioEsp1, [PrecioEsp2] = :precioEsp2, [PrecioEsp3] = :precioEsp3, [PrecioEsp4] = :precioEsp4,
        [PrecioEsp5] = :precioEsp5, [PrecioEsp6] = :precioEsp6, [PrecioEsp7] = :precioEsp7, [PrecioEsp8] = :precioEsp8
       WHERE RTRIM([Articulo]) = :articulo AND RTRIM([Proveedor]) = :proveedor'
    );
    $stmt->execute($this->bindPayload($articulo, $proveedor, $data, $lUpdate));

    return $this->findOne($articulo, $proveedor);
  }

  public function delete(string $articulo, string $proveedor): bool
  {
    $stmt = $this->pdo->prepare(
      'DELETE FROM [OfertasProveedor]
       WHERE RTRIM([Articulo]) = :articulo AND RTRIM([Proveedor]) = :proveedor'
    );
    $stmt->execute([
      'articulo' => trim($articulo),
      'proveedor' => trim($proveedor),
    ]);
    return $stmt->rowCount() > 0 || $this->findOne($articulo, $proveedor) === null;
  }

  /**
   * @param array<string, mixed> $data
   * @return array<string, mixed>
   */
  private function bindPayload(string $articulo, string $proveedor, array $data, string $lUpdate): array
  {
    $payload = [
      'articulo' => $articulo,
      'proveedor' => $proveedor,
      'fechaInicio' => $this->normalizeDate($data['fechaInicio'] ?? null),
      'fechaFin' => $this->normalizeDate($data['fechaFin'] ?? null),
      'precioEsp' => $this->float($data['precioEsp'] ?? null) ?? 0.0,
      'lUpdate' => $lUpdate,
      'pjeDto' => $this->float($data['pjeDto'] ?? null) ?? 0.0,
      'pjeDto2' => $this->float($data['pjeDto2'] ?? null) ?? 0.0,
      'pjeDto3' => $this->float($data['pjeDto3'] ?? null) ?? 0.0,
    ];
    for ($i = 1; $i <= 8; $i++) {
      $payload['cantidad' . $i] = $this->float($data['cantidad' . $i] ?? null) ?? 0.0;
      $payload['precioEsp' . $i] = $this->float($data['precioEsp' . $i] ?? null) ?? 0.0;
    }
    return $payload;
  }

  /** @param array<string, mixed> $row */
  private function mapRow(array $row): array
  {
    $mapped = [
      'articulo' => rtrim((string) $row['Articulo']),
      'proveedor' => rtrim((string) $row['Proveedor']),
      'fechaInicio' => $this->formatDateOut($row['FechaInicio'] ?? null),
      'fechaFin' => $this->formatDateOut($row['FechaFin'] ?? null),
      'precioEsp' => $row['PrecioEsp'] === null ? 0.0 : (float) $row['PrecioEsp'],
      'lUpdate' => $this->formatDateTimeOut($row['LUpdate'] ?? null),
      'pjeDto' => $row['PjeDto'] === null ? 0.0 : (float) $row['PjeDto'],
      'pjeDto2' => $row['PjeDto2'] === null ? 0.0 : (float) $row['PjeDto2'],
      'pjeDto3' => $row['PjeDto3'] === null ? 0.0 : (float) $row['PjeDto3'],
      'articuloDescripcion' => $row['ArticuloDescripcion'] ?? null,
      'proveedorNombre' => $row['ProveedorNombre'] ?? null,
    ];
    for ($i = 1; $i <= 8; $i++) {
      $mapped['cantidad' . $i] = $row['Cantidad' . $i] === null ? 0.0 : (float) $row['Cantidad' . $i];
      $mapped['precioEsp' . $i] = $row['PrecioEsp' . $i] === null ? 0.0 : (float) $row['PrecioEsp' . $i];
    }
    return $mapped;
  }

  private function normalizeDate(mixed $value): ?string
  {
    if ($value === null || $value === '') {
      return null;
    }
    $s = trim((string) $value);
    $s = str_replace('T', ' ', $s);
    $s = preg_replace('/\.\d+$/', '', $s) ?? $s;
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $s, $m)) {
      return $m[1] . ' 00:00:00';
    }
    return null;
  }

  private function formatDateOut(mixed $value): ?string
  {
    $full = $this->formatDateTimeOut($value);
    return $full === null ? null : substr($full, 0, 10);
  }

  private function formatDateTimeOut(mixed $value): ?string
  {
    if ($value instanceof \DateTimeInterface) {
      return $value->format('Y-m-d H:i:s');
    }
    if (!is_string($value) || trim($value) === '') {
      return null;
    }
    $s = preg_replace('/\.\d+$/', '', str_replace('T', ' ', trim($value))) ?? trim($value);
    return $s === '' ? null : $s;
  }

  private function float(mixed $value): ?float
  {
    if ($value === null || $value === '') {
      return null;
    }
    if (is_string($value)) {
      $value = str_replace(',', '.', $value);
    }
    return (float) $value;
  }

  private function str(mixed $value, int $maxLen): ?string
  {
    if ($value === null) {
      return null;
    }
    $s = trim((string) $value);
    if ($s === '') {
      return null;
    }
    return mb_substr($s, 0, $maxLen);
  }
}
