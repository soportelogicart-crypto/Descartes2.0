<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Ventas;

use PDO;

final class AnulacionConsultaService
{
  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  /** @param array<string, mixed> $query */
  public function listar(array $query): array
  {
    $page = max(1, (int) ($query['page'] ?? 1));
    $pageSize = min(500, max(1, (int) ($query['pageSize'] ?? 50)));
    $offset = ($page - 1) * $pageSize;

    $where = ['1=1'];
    $params = [];

    if ($this->fechaIsoValida($query['fechaDesde'] ?? null)) {
      $where[] = 'a.Fecha >= CONVERT(datetime, :fechaDesde, 120)';
      $params['fechaDesde'] = substr((string) $query['fechaDesde'], 0, 10) . ' 00:00:00';
    }
    if ($this->fechaIsoValida($query['fechaHasta'] ?? null)) {
      $where[] = 'a.Fecha <= CONVERT(datetime, :fechaHasta, 120)';
      $params['fechaHasta'] = substr((string) $query['fechaHasta'], 0, 10) . ' 23:59:59';
    }
    if (!empty($query['cajero'])) {
      $where[] = 'a.Cajero = :cajero';
      $params['cajero'] = $query['cajero'];
    }
    if (isset($query['motivo']) && $query['motivo'] !== '') {
      $where[] = '(CAST(a.Motivo AS NVARCHAR(20)) = :motivo OR a.MotivoOperador LIKE :motivoOp OR m.Descripcion LIKE :motivoDesc)';
      $params['motivo'] = $query['motivo'];
      $params['motivoOp'] = '%' . $query['motivo'] . '%';
      $params['motivoDesc'] = '%' . $query['motivo'] . '%';
    }

    $sqlWhere = implode(' AND ', $where);

    $countSql = "SELECT COUNT(*) FROM LogAnulaciones a
                 LEFT JOIN Motivos m ON m.Codigo = a.Motivo
                 WHERE {$sqlWhere}";
    $countStmt = $this->pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $sql = "SELECT a.Fecha, a.Articulo, a.Cantidad, a.ImporteLin, a.Cajero, a.Puesto, a.Mesa,
                   a.Sesion, a.Empresa, a.Motivo, a.MotivoOperador, m.Descripcion AS MotivoDesc
            FROM LogAnulaciones a
            LEFT JOIN Motivos m ON m.Codigo = a.Motivo
            WHERE {$sqlWhere}
            ORDER BY a.Fecha DESC
            OFFSET :offset ROWS FETCH NEXT :pageSize ROWS ONLY";
    $stmt = $this->pdo->prepare($sql);
    foreach ($params as $k => $v) {
      $stmt->bindValue(':' . $k, $v);
    }
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':pageSize', $pageSize, PDO::PARAM_INT);
    $stmt->execute();

    $items = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $motivo = trim((string) ($row['MotivoOperador'] ?? ''));
      if ($motivo === '') {
        $motivo = trim((string) ($row['MotivoDesc'] ?? ''));
      }
      if ($motivo === '' && $row['Motivo'] !== null && $row['Motivo'] !== '') {
        $motivo = (string) $row['Motivo'];
      }
      if ($motivo === '') {
        $motivo = null;
      }

      $fecha = $row['Fecha'] ?? null;
      $items[] = [
        'fecha' => $fecha ? date('c', strtotime((string) $fecha)) : null,
        'articulo' => $row['Articulo'],
        'cantidad' => (float) ($row['Cantidad'] ?? 0),
        'importeLin' => (float) ($row['ImporteLin'] ?? 0),
        'motivo' => $motivo,
        'cajero' => $row['Cajero'],
        'puesto' => $row['Puesto'],
        'mesa' => $row['Mesa'] !== null ? (int) $row['Mesa'] : null,
        'sesion' => $row['Sesion'] !== null ? (int) $row['Sesion'] : null,
        'empresa' => $row['Empresa'],
      ];
    }

    return [
      'items' => $items,
      'total' => $total,
      'page' => $page,
      'pageSize' => $pageSize,
    ];
  }

  private function fechaIsoValida($value): bool
  {
    if ($value === null || $value === '') {
      return false;
    }
    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $value);
  }
}
