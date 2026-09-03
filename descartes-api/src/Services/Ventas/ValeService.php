<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Ventas;

use Descartes\Api\Database\SqlPagination;
use PDO;

final class ValeService
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
    $estado = strtolower((string) ($query['estado'] ?? 'todos'));

    $where = ['1=1'];
    $params = [];
    if ($estado === 'pendientes') {
      $where[] = 'v.Liquidado = 0';
    } elseif ($estado === 'liquidados') {
      $where[] = 'v.Liquidado = 1';
    }
    if (!empty($query['cliente'])) {
      $where[] = 'v.Cliente LIKE :cliente';
      $params['cliente'] = '%' . $query['cliente'] . '%';
    }
    if (!empty($query['empresa'])) {
      $where[] = 'v.Empresa = :empresa';
      $params['empresa'] = $query['empresa'];
    }

    $sqlWhere = implode(' AND ', $where);
    $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM Vales v WHERE {$sqlWhere}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $innerSql = "SELECT v.Empresa, v.Codigo, v.Cliente, v.Importe, v.Fecha, v.FechaCaducidad,
                   v.Liquidado, v.FechaLiquidacion, v.TipoLiquidacion
            FROM Vales v
            WHERE {$sqlWhere}";
    $sql = SqlPagination::wrap($innerSql, 'v.Fecha DESC, v.Codigo DESC', $offset, $pageSize);
    $stmt = $this->pdo->prepare($sql);
    foreach ($params as $k => $v) {
      $stmt->bindValue(':' . $k, $v);
    }
    SqlPagination::bind($stmt, $offset, $pageSize);
    $stmt->execute();

    $hoy = date('Y-m-d');
    $items = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $items[] = $this->mapVale($row, $hoy);
    }

    return [
      'items' => $items,
      'total' => $total,
      'page' => $page,
      'pageSize' => $pageSize,
    ];
  }

  /** @param array<string, mixed> $body */
  public function emitir(array $body): array
  {
    $empresa = trim((string) ($body['empresa'] ?? ''));
    $cliente = trim((string) ($body['cliente'] ?? ''));
    $importe = (float) ($body['importe'] ?? 0);
    if ($empresa === '' || $cliente === '') {
      throw new \InvalidArgumentException('Empresa y cliente son obligatorios');
    }
    if ($importe <= 0) {
      throw new \InvalidArgumentException('El importe debe ser mayor que cero');
    }

    $codigo = $this->nextCodigo($empresa);
    $fechaCad = !empty($body['fechaCaducidad']) ? $body['fechaCaducidad'] : null;
    $formaPago = isset($body['formaPago']) ? substr(trim((string) $body['formaPago']), 0, 2) : null;

    $sql = 'INSERT INTO Vales (Empresa, Codigo, Liquidado, Fecha, Importe, Cliente, FormaPago, FechaCaducidad, TrasModem)
            VALUES (:empresa, :codigo, 0, GETDATE(), :importe, :cliente, :formaPago, :fechaCad, 0)';
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute([
      'empresa' => $empresa,
      'codigo' => $codigo,
      'importe' => $importe,
      'cliente' => $cliente,
      'formaPago' => $formaPago,
      'fechaCad' => $fechaCad,
    ]);

    return $this->obtener($empresa, $codigo);
  }

  /** @param array<string, mixed> $body */
  public function liquidar(string $empresa, int $codigo, array $body): array
  {
    $vale = $this->obtenerRaw($empresa, $codigo);
    if ($vale === null) {
      throw new \RuntimeException('Vale no encontrado', 404);
    }
    if ((bool) $vale['Liquidado']) {
      throw new \RuntimeException('El vale ya esta liquidado', 409);
    }

    $fechaLiq = !empty($body['fechaLiquidacion'])
      ? (string) $body['fechaLiquidacion']
      : date('Y-m-d');
    $tipoLiq = isset($body['tipoLiquidacion'])
      ? substr(trim((string) $body['tipoLiquidacion']), 0, 1)
      : '';

    if ($tipoLiq === '') {
      throw new \InvalidArgumentException('tipoLiquidacion es obligatorio');
    }

    $caducidad = $vale['FechaCaducidad'] ?? null;
    if ($caducidad !== null && $caducidad !== '') {
      $cadDay = date('Y-m-d', strtotime((string) $caducidad));
      if ($fechaLiq > $cadDay) {
        throw new \RuntimeException('El vale esta caducado y no se puede liquidar', 409);
      }
    }

    $upd = $this->pdo->prepare(
      'UPDATE Vales SET Liquidado = 1, FechaLiquidacion = :fecha, TipoLiquidacion = :tipo
       WHERE Empresa = :empresa AND Codigo = :codigo AND Liquidado = 0'
    );
    $upd->execute([
      'fecha' => $fechaLiq,
      'tipo' => $tipoLiq,
      'empresa' => $empresa,
      'codigo' => $codigo,
    ]);
    if ($upd->rowCount() === 0) {
      throw new \RuntimeException('No se pudo liquidar el vale', 409);
    }

    return $this->obtener($empresa, $codigo);
  }

  public function obtener(string $empresa, int $codigo): array
  {
    $row = $this->obtenerRaw($empresa, $codigo);
    if ($row === null) {
      throw new \RuntimeException('Vale no encontrado', 404);
    }
    return $this->mapVale($row, date('Y-m-d'));
  }

  private function obtenerRaw(string $empresa, int $codigo): ?array
  {
    $stmt = $this->pdo->prepare(
      'SELECT Empresa, Codigo, Cliente, Importe, Fecha, FechaCaducidad, Liquidado, FechaLiquidacion, TipoLiquidacion
       FROM Vales WHERE Empresa = :empresa AND Codigo = :codigo'
    );
    $stmt->execute(['empresa' => $empresa, 'codigo' => $codigo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  }

  private function nextCodigo(string $empresa): int
  {
    $stmt = $this->pdo->prepare('SELECT ISNULL(MAX(Codigo), 0) + 1 FROM Vales WHERE Empresa = :empresa');
    $stmt->execute(['empresa' => $empresa]);
    return (int) $stmt->fetchColumn();
  }

  /** @param array<string, mixed> $row */
  private function mapVale(array $row, string $hoy): array
  {
    $liquidado = (bool) $row['Liquidado'];
    $caducidad = $row['FechaCaducidad'] ?? null;
    $caducado = false;
    if (!$liquidado && $caducidad !== null && $caducidad !== '') {
      $cadDay = date('Y-m-d', strtotime((string) $caducidad));
      $caducado = $hoy > $cadDay;
    }

    return [
      'empresa' => (string) $row['Empresa'],
      'codigo' => (int) $row['Codigo'],
      'cliente' => $row['Cliente'],
      'importe' => (float) ($row['Importe'] ?? 0),
      'fecha' => isset($row['Fecha']) && $row['Fecha'] ? date('c', strtotime((string) $row['Fecha'])) : null,
      'fechaCaducidad' => $caducidad ? date('Y-m-d', strtotime((string) $caducidad)) : null,
      'liquidado' => $liquidado,
      'fechaLiquidacion' => isset($row['FechaLiquidacion']) && $row['FechaLiquidacion']
        ? date('Y-m-d', strtotime((string) $row['FechaLiquidacion']))
        : null,
      'tipoLiquidacion' => $row['TipoLiquidacion'] !== null ? trim((string) $row['TipoLiquidacion']) : null,
      'caducado' => $caducado,
    ];
  }
}
