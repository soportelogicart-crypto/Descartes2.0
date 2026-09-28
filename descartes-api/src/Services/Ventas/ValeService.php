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
                   v.Liquidado, v.FechaLiquidacion, v.TipoLiquidacion,
                   RTRIM(ISNULL(v.TipoVale, 'REGALO')) AS TipoVale,
                   ISNULL(v.ImporteOriginal, v.Importe) AS ImporteOriginal,
                   ISNULL(v.SaldoPendiente, CASE WHEN v.Liquidado = 1 THEN 0 ELSE v.Importe END) AS SaldoPendiente
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
    $tipoVale = strtoupper(trim((string) ($body['tipoVale'] ?? 'REGALO')));
    if (!in_array($tipoVale, ['REGALO', 'FIDELIZACION'], true)) {
      throw new \InvalidArgumentException('tipoVale debe ser REGALO o FIDELIZACION');
    }

    $sql = 'INSERT INTO Vales (
              Empresa, Codigo, Liquidado, Fecha, Importe, Cliente, FormaPago,
              FechaCaducidad, TrasModem, TipoVale, ImporteOriginal, SaldoPendiente
            )
            VALUES (
              :empresa, :codigo, 0, GETDATE(), :importe, :cliente, :formaPago,
              CONVERT(datetime, :fechaCad, 120), 0, :tipoVale, :importeOriginal, :saldoPendiente
            )';
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute([
      'empresa' => $empresa,
      'codigo' => $codigo,
      'importe' => $importe,
      'cliente' => $cliente,
      'formaPago' => $formaPago,
      'fechaCad' => $fechaCad,
      'tipoVale' => $tipoVale,
      'importeOriginal' => $importe,
      'saldoPendiente' => $importe,
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
      'UPDATE Vales SET Liquidado = 1, SaldoPendiente = 0,
              FechaLiquidacion = CONVERT(datetime, :fecha, 120), TipoLiquidacion = :tipo
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

  /**
   * Vales de fidelización utilizables, por caducidad y antigüedad.
   *
   * @return array{cliente: string, saldo: float, vales: list<array<string, mixed>>}
   */
  public function fidelizacionDisponible(string $empresa, string $cliente, bool $bloquear = false): array
  {
    $empresa = trim($empresa);
    $cliente = trim($cliente);
    if ($empresa === '' || $cliente === '') {
      throw new \InvalidArgumentException('empresa y cliente son obligatorios');
    }
    $lock = $bloquear ? ' WITH (UPDLOCK, HOLDLOCK)' : '';
    $st = $this->pdo->prepare(
      "SELECT RTRIM(Empresa) AS Empresa, Codigo,
              ISNULL(SaldoPendiente, Importe) AS SaldoPendiente, FechaCaducidad
       FROM Vales{$lock}
       WHERE RTRIM(Cliente) = :c
         AND RTRIM(ISNULL(TipoVale, 'REGALO')) = 'FIDELIZACION'
         AND ISNULL(Liquidado, 0) = 0
         AND ISNULL(SaldoPendiente, Importe) > 0
         AND (FechaCaducidad IS NULL OR CONVERT(date, FechaCaducidad) >= CONVERT(date, GETDATE()))
       ORDER BY CASE WHEN FechaCaducidad IS NULL THEN 1 ELSE 0 END, FechaCaducidad, Fecha, Empresa, Codigo"
    );
    $st->execute(['c' => $cliente]);
    $vales = [];
    $saldo = 0.0;
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $importe = round((float) ($row['SaldoPendiente'] ?? 0), 2);
      $saldo += $importe;
      $vales[] = [
        'empresa' => trim((string) ($row['Empresa'] ?? '')),
        'codigo' => (int) ($row['Codigo'] ?? 0),
        'saldo' => $importe,
        'fechaCaducidad' => !empty($row['FechaCaducidad'])
          ? date('Y-m-d', strtotime((string) $row['FechaCaducidad']))
          : null,
      ];
    }
    return ['cliente' => $cliente, 'saldo' => round($saldo, 2), 'vales' => $vales];
  }

  /**
   * Consume saldo FIFO. Debe ejecutarse dentro de la transacción de cierre.
   *
   * @return array{aplicado: float, saldoRestante: float, consumos: list<array<string, mixed>>}
   */
  public function consumirFidelizacion(
    string $empresa,
    string $cliente,
    float $importe,
    string $tipoVenta,
    int $albaran
  ): array {
    if (!$this->pdo->inTransaction()) {
      throw new \LogicException('El consumo del vale requiere una transacción activa');
    }
    $disponible = $this->fidelizacionDisponible($empresa, $cliente, true);
    $pendiente = min(round(max(0, $importe), 2), $disponible['saldo']);
    $consumos = [];
    foreach ($disponible['vales'] as $vale) {
      if ($pendiente < 0.005) {
        break;
      }
      $usado = round(min($pendiente, (float) $vale['saldo']), 2);
      if ($usado <= 0) {
        continue;
      }
      $nuevoSaldo = round((float) $vale['saldo'] - $usado, 2);
      $liquidado = $nuevoSaldo < 0.005;
      $empresaVale = trim((string) ($vale['empresa'] ?? '')) ?: $empresa;
      $upd = $this->pdo->prepare(
        'UPDATE Vales SET SaldoPendiente = :saldo, Liquidado = :liquidado,
             FechaLiquidacion = CASE WHEN :liquidado2 = 1 THEN GETDATE() ELSE NULL END,
             TipoLiquidacion = CASE WHEN :liquidado3 = 1 THEN \'F\' ELSE TipoLiquidacion END
         WHERE RTRIM(Empresa) = :e AND Codigo = :codigo AND ISNULL(Liquidado, 0) = 0'
      );
      $upd->execute([
        'saldo' => $liquidado ? 0 : $nuevoSaldo,
        'liquidado' => $liquidado ? 1 : 0,
        'liquidado2' => $liquidado ? 1 : 0,
        'liquidado3' => $liquidado ? 1 : 0,
        'e' => $empresaVale,
        'codigo' => (int) $vale['codigo'],
      ]);
      if ($upd->rowCount() !== 1) {
        throw new \RuntimeException('El saldo del vale cambió durante el cobro', 409);
      }
      $ins = $this->pdo->prepare(
        'INSERT INTO ValeConsumos
           (Empresa, ValeCodigo, EmpresaVenta, TipoVenta, Albaran, Importe, Fecha)
         VALUES (:e, :vale, :ev, :tv, :a, :importe, GETDATE())'
      );
      $ins->execute([
        'e' => $empresaVale,
        'vale' => (int) $vale['codigo'],
        'ev' => $empresa,
        'tv' => $tipoVenta,
        'a' => $albaran,
        'importe' => $usado,
      ]);
      $consumos[] = [
        'vale' => (int) $vale['codigo'],
        'importe' => $usado,
        'saldo' => $liquidado ? 0.0 : $nuevoSaldo,
      ];
      $pendiente = round($pendiente - $usado, 2);
    }
    $aplicado = round(min($importe, $disponible['saldo']) - $pendiente, 2);
    return [
      'aplicado' => $aplicado,
      'saldoRestante' => round($disponible['saldo'] - $aplicado, 2),
      'consumos' => $consumos,
    ];
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
      'SELECT Empresa, Codigo, Cliente, Importe, Fecha, FechaCaducidad, Liquidado, FechaLiquidacion, TipoLiquidacion,
              RTRIM(ISNULL(TipoVale, \'REGALO\')) AS TipoVale,
              ISNULL(ImporteOriginal, Importe) AS ImporteOriginal,
              ISNULL(SaldoPendiente, CASE WHEN Liquidado = 1 THEN 0 ELSE Importe END) AS SaldoPendiente
       FROM Vales WHERE Empresa = :empresa AND Codigo = :codigo'
    );
    $stmt->execute(['empresa' => $empresa, 'codigo' => $codigo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  }

  private function nextCodigo(string $empresa): int
  {
    $stmt = $this->pdo->prepare(
      'SELECT ISNULL(MAX(Codigo), 0) + 1 FROM Vales WITH (UPDLOCK, HOLDLOCK) WHERE Empresa = :empresa'
    );
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
      'importeOriginal' => (float) ($row['ImporteOriginal'] ?? $row['Importe'] ?? 0),
      'saldoPendiente' => (float) ($row['SaldoPendiente'] ?? $row['Importe'] ?? 0),
      'tipoVale' => trim((string) ($row['TipoVale'] ?? 'REGALO')),
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
