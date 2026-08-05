<?php

declare(strict_types=1);

namespace Descartes\Api\Repositories;

use PDO;

final class OfertasClientesRepository
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
      $where[] = '(o.[Articulo] LIKE :q OR o.[Cliente] LIKE :q OR a.[Descripcion] LIKE :q OR c.[RazonSocial] LIKE :q)';
      $params['q'] = '%' . trim((string) $query['q']) . '%';
    }
    $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

    $countSql = "SELECT COUNT(*) FROM [OfertasClientes] o
      LEFT JOIN [Articulos] a ON RTRIM(a.[Codigo]) = RTRIM(o.[Articulo])
      LEFT JOIN [Clientes] c ON RTRIM(c.[Codigo]) = RTRIM(o.[Cliente])
      {$whereSql}";
    $countStmt = $this->pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $sql = "SELECT o.[Articulo], o.[Cliente], o.[Precio], o.[LUpdate], o.[RappelPorDto],
      o.[Cantidad1], o.[Cantidad2], o.[Cantidad3], o.[Cantidad4],
      o.[Cantidad5], o.[Cantidad6], o.[Cantidad7], o.[Cantidad8],
      o.[PrecioEsp1], o.[PrecioEsp2], o.[PrecioEsp3], o.[PrecioEsp4],
      o.[PrecioEsp5], o.[PrecioEsp6], o.[PrecioEsp7], o.[PrecioEsp8],
      o.[BloquearOfeEmpresa1], o.[BloquearOfeEmpresa2], o.[BloquearOfeEmpresa3], o.[BloquearOfeEmpresa4],
      o.[BloquearOfeEmpresa5], o.[BloquearOfeEmpresa6], o.[BloquearOfeEmpresa7], o.[BloquearOfeEmpresa8],
      o.[BloquearOfeEmpresa9], o.[BloquearOfeEmpresa10],
      a.[Descripcion] AS ArticuloDescripcion,
      c.[RazonSocial] AS ClienteNombre
      FROM [OfertasClientes] o
      LEFT JOIN [Articulos] a ON RTRIM(a.[Codigo]) = RTRIM(o.[Articulo])
      LEFT JOIN [Clientes] c ON RTRIM(c.[Codigo]) = RTRIM(o.[Cliente])
      {$whereSql}
      ORDER BY o.[Articulo], o.[Cliente]
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

  public function findOne(string $articulo, string $cliente): ?array
  {
    $stmt = $this->pdo->prepare(
      'SELECT o.[Articulo], o.[Cliente], o.[Precio], o.[LUpdate], o.[RappelPorDto],
        o.[Cantidad1], o.[Cantidad2], o.[Cantidad3], o.[Cantidad4],
        o.[Cantidad5], o.[Cantidad6], o.[Cantidad7], o.[Cantidad8],
        o.[PrecioEsp1], o.[PrecioEsp2], o.[PrecioEsp3], o.[PrecioEsp4],
        o.[PrecioEsp5], o.[PrecioEsp6], o.[PrecioEsp7], o.[PrecioEsp8],
        o.[BloquearOfeEmpresa1], o.[BloquearOfeEmpresa2], o.[BloquearOfeEmpresa3], o.[BloquearOfeEmpresa4],
        o.[BloquearOfeEmpresa5], o.[BloquearOfeEmpresa6], o.[BloquearOfeEmpresa7], o.[BloquearOfeEmpresa8],
        o.[BloquearOfeEmpresa9], o.[BloquearOfeEmpresa10],
        a.[Descripcion] AS ArticuloDescripcion,
        c.[RazonSocial] AS ClienteNombre
       FROM [OfertasClientes] o
       LEFT JOIN [Articulos] a ON RTRIM(a.[Codigo]) = RTRIM(o.[Articulo])
       LEFT JOIN [Clientes] c ON RTRIM(c.[Codigo]) = RTRIM(o.[Cliente])
       WHERE RTRIM(o.[Articulo]) = :articulo AND RTRIM(o.[Cliente]) = :cliente'
    );
    $stmt->execute([
      'articulo' => trim($articulo),
      'cliente' => trim($cliente),
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $this->mapRow($row) : null;
  }

  /** @param array<string, mixed> $data */
  public function create(array $data): array
  {
    $articulo = $this->str($data['articulo'] ?? null, 18);
    $cliente = $this->str($data['cliente'] ?? null, 9);
    if ($articulo === null || $cliente === null) {
      throw new \InvalidArgumentException('Articulo y cliente son obligatorios');
    }
    if ($this->findOne($articulo, $cliente) !== null) {
      throw new \InvalidArgumentException('Ya existe una oferta para ese articulo y cliente');
    }

    $lUpdate = date('Y-m-d H:i:s');
    $stmt = $this->pdo->prepare(
      'INSERT INTO [OfertasClientes] (
        [Articulo], [Cliente], [Precio], [LUpdate], [RappelPorDto],
        [Cantidad1], [Cantidad2], [Cantidad3], [Cantidad4], [Cantidad5], [Cantidad6], [Cantidad7], [Cantidad8],
        [PrecioEsp1], [PrecioEsp2], [PrecioEsp3], [PrecioEsp4], [PrecioEsp5], [PrecioEsp6], [PrecioEsp7], [PrecioEsp8],
        [BloquearOfeEmpresa1], [BloquearOfeEmpresa2], [BloquearOfeEmpresa3], [BloquearOfeEmpresa4], [BloquearOfeEmpresa5],
        [BloquearOfeEmpresa6], [BloquearOfeEmpresa7], [BloquearOfeEmpresa8], [BloquearOfeEmpresa9], [BloquearOfeEmpresa10]
      ) VALUES (
        :articulo, :cliente, :precio, CONVERT(datetime, :lUpdate, 120), :rappelPorDto,
        :cantidad1, :cantidad2, :cantidad3, :cantidad4, :cantidad5, :cantidad6, :cantidad7, :cantidad8,
        :precioEsp1, :precioEsp2, :precioEsp3, :precioEsp4, :precioEsp5, :precioEsp6, :precioEsp7, :precioEsp8,
        :emp1, :emp2, :emp3, :emp4, :emp5, :emp6, :emp7, :emp8, :emp9, :emp10
      )'
    );
    $stmt->execute($this->bindPayload($articulo, $cliente, $data, $lUpdate));

    $created = $this->findOne($articulo, $cliente);
    if ($created === null) {
      throw new \RuntimeException('No se pudo leer la oferta creada');
    }
    return $created;
  }

  /** @param array<string, mixed> $data */
  public function update(string $articulo, string $cliente, array $data): ?array
  {
    $articulo = trim($articulo);
    $cliente = trim($cliente);
    if ($this->findOne($articulo, $cliente) === null) {
      return null;
    }

    $lUpdate = date('Y-m-d H:i:s');
    $stmt = $this->pdo->prepare(
      'UPDATE [OfertasClientes] SET
        [Precio] = :precio,
        [LUpdate] = CONVERT(datetime, :lUpdate, 120),
        [RappelPorDto] = :rappelPorDto,
        [Cantidad1] = :cantidad1, [Cantidad2] = :cantidad2, [Cantidad3] = :cantidad3, [Cantidad4] = :cantidad4,
        [Cantidad5] = :cantidad5, [Cantidad6] = :cantidad6, [Cantidad7] = :cantidad7, [Cantidad8] = :cantidad8,
        [PrecioEsp1] = :precioEsp1, [PrecioEsp2] = :precioEsp2, [PrecioEsp3] = :precioEsp3, [PrecioEsp4] = :precioEsp4,
        [PrecioEsp5] = :precioEsp5, [PrecioEsp6] = :precioEsp6, [PrecioEsp7] = :precioEsp7, [PrecioEsp8] = :precioEsp8,
        [BloquearOfeEmpresa1] = :emp1, [BloquearOfeEmpresa2] = :emp2, [BloquearOfeEmpresa3] = :emp3,
        [BloquearOfeEmpresa4] = :emp4, [BloquearOfeEmpresa5] = :emp5, [BloquearOfeEmpresa6] = :emp6,
        [BloquearOfeEmpresa7] = :emp7, [BloquearOfeEmpresa8] = :emp8, [BloquearOfeEmpresa9] = :emp9,
        [BloquearOfeEmpresa10] = :emp10
       WHERE RTRIM([Articulo]) = :articulo AND RTRIM([Cliente]) = :cliente'
    );
    $stmt->execute($this->bindPayload($articulo, $cliente, $data, $lUpdate));

    return $this->findOne($articulo, $cliente);
  }

  public function delete(string $articulo, string $cliente): bool
  {
    $stmt = $this->pdo->prepare(
      'DELETE FROM [OfertasClientes]
       WHERE RTRIM([Articulo]) = :articulo AND RTRIM([Cliente]) = :cliente'
    );
    $stmt->execute([
      'articulo' => trim($articulo),
      'cliente' => trim($cliente),
    ]);
    return $stmt->rowCount() > 0;
  }

  /**
   * @param array<string, mixed> $data
   * @return array<string, mixed>
   */
  private function bindPayload(string $articulo, string $cliente, array $data, string $lUpdate): array
  {
    $payload = [
      'articulo' => $articulo,
      'cliente' => $cliente,
      'precio' => $this->float($data['precio'] ?? null),
      'lUpdate' => $lUpdate,
      'rappelPorDto' => !empty($data['rappelPorDto']) ? 1 : 0,
    ];
    for ($i = 1; $i <= 8; $i++) {
      $payload['cantidad' . $i] = $this->float($data['cantidad' . $i] ?? null) ?? 0.0;
      $payload['precioEsp' . $i] = $this->float($data['precioEsp' . $i] ?? null) ?? 0.0;
    }
    for ($i = 1; $i <= 10; $i++) {
      $payload['emp' . $i] = $this->str($data['bloquearOfeEmpresa' . $i] ?? null, 3);
    }
    return $payload;
  }

  /** @param array<string, mixed> $row */
  private function mapRow(array $row): array
  {
    $lUpdate = $row['LUpdate'] ?? null;
    if ($lUpdate instanceof \DateTimeInterface) {
      $lUpdate = $lUpdate->format('Y-m-d H:i:s');
    } elseif (is_string($lUpdate) && $lUpdate !== '') {
      $lUpdate = preg_replace('/\.\d+$/', '', str_replace('T', ' ', $lUpdate));
    } else {
      $lUpdate = null;
    }

    $mapped = [
      'articulo' => rtrim((string) $row['Articulo']),
      'cliente' => rtrim((string) $row['Cliente']),
      'precio' => $row['Precio'] === null ? null : (float) $row['Precio'],
      'lUpdate' => $lUpdate,
      'rappelPorDto' => (bool) ($row['RappelPorDto'] ?? false),
      'articuloDescripcion' => $row['ArticuloDescripcion'] ?? null,
      'clienteNombre' => $row['ClienteNombre'] ?? null,
    ];
    for ($i = 1; $i <= 8; $i++) {
      $mapped['cantidad' . $i] = $row['Cantidad' . $i] === null ? 0.0 : (float) $row['Cantidad' . $i];
      $mapped['precioEsp' . $i] = $row['PrecioEsp' . $i] === null ? 0.0 : (float) $row['PrecioEsp' . $i];
    }
    for ($i = 1; $i <= 10; $i++) {
      $val = $row['BloquearOfeEmpresa' . $i] ?? null;
      $mapped['bloquearOfeEmpresa' . $i] = $val === null || trim((string) $val) === ''
        ? null
        : rtrim((string) $val);
    }
    return $mapped;
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
