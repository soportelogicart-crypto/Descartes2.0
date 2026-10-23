<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Ventas;

use Descartes\Api\Database\SqlPagination;
use Descartes\Api\Services\Listados\ListadosFiltrosSql;
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
    $estado = strtolower((string) ($query['estado'] ?? 'pendientes'));
    $alcance = strtolower(trim((string) ($query['alcance'] ?? 'liquidacion')));

    $where = ['1=1'];
    $params = [];
    if ($estado === 'pendientes') {
      $where[] = 'v.Liquidado = 0';
    } elseif ($estado === 'liquidados') {
      $where[] = 'v.Liquidado = 1';
    }
    if ($alcance === 'liquidacion') {
      $where[] = ValesTipoLegacy::sqlAlcanceLiquidacion('v');
    } elseif ($alcance === 'caja') {
      $where[] = ValesTipoLegacy::sqlAlcanceCaja('v');
    }
    if (!empty($query['tipo'])) {
      $tipoF = strtoupper(substr(trim((string) $query['tipo']), 0, 1));
      $where[] = 'RTRIM(ISNULL(v.Tipo, \'\')) = :tipoFiltro';
      $params['tipoFiltro'] = $tipoF;
    }
    if (!empty($query['cliente'])) {
      $where[] = 'v.Cliente LIKE :cliente';
      $params['cliente'] = '%' . $query['cliente'] . '%';
    }
    if (!empty($query['empresa'])) {
      $where[] = 'v.Empresa = :empresa';
      $params['empresa'] = trim((string) $query['empresa']);
    }
    if (!empty($query['formaPago'])) {
      $where[] = 'LTRIM(RTRIM(ISNULL(v.FormaPago, \'\'))) = :formaPago';
      $params['formaPago'] = trim((string) $query['formaPago']);
    }
    ListadosFiltrosSql::filtroRangoFecha($where, $params, 'v.Fecha', $query, 'fechaDesde', 'fechaHasta', 'vale_f');

    $sqlWhere = implode(' AND ', $where);
    $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM Vales v WHERE {$sqlWhere}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $innerSql = "SELECT v.Empresa, v.Codigo, v.Cliente, v.Importe, v.Fecha, v.FechaCaducidad,
                   v.Liquidado, v.FechaLiquidacion, v.TipoLiquidacion, v.FormaPago,
                   RTRIM(ISNULL(v.Tipo, '')) AS Tipo,
                   RTRIM(ISNULL(v.TipoVale, 'REGALO')) AS TipoVale,
                   ISNULL(v.ImporteOriginal, v.Importe) AS ImporteOriginal,
                   ISNULL(v.SaldoPendiente, CASE WHEN v.Liquidado = 1 THEN 0 ELSE v.Importe END) AS SaldoPendiente,
                   ISNULL(v.Factura, 0) AS Factura,
                   RTRIM(ISNULL(v.FacturaTipo, '')) AS FacturaTipo,
                   ISNULL(v.Albaran, 0) AS Albaran,
                   RTRIM(ISNULL(v.MotivoOperador, '')) AS MotivoOperador
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
    if (!in_array($tipoVale, ['REGALO', 'FIDELIZACION', 'DEVOLUCION'], true)) {
      throw new \InvalidArgumentException('tipoVale debe ser REGALO, FIDELIZACION o DEVOLUCION');
    }
    $tipoLegacy = strtoupper(substr(trim((string) ($body['tipo'] ?? '')), 0, 1));
    if ($tipoLegacy === '') {
      $tipoLegacy = ValesTipoLegacy::desdeTipoVale($tipoVale);
    }

    $texto = static function ($v, int $max): ?string {
      $s = trim((string) ($v ?? ''));
      return $s === '' ? null : mb_substr($s, 0, $max);
    };
    $sesion = (int) ($body['sesion'] ?? 0);

    $sql = 'INSERT INTO Vales (
              Empresa, Codigo, Tipo, Numero, Liquidado, Fecha, Importe, Cliente, FormaPago,
              FechaCaducidad, TrasModem, TipoVale, ImporteOriginal, SaldoPendiente,
              Puesto, Sesion, Cajero, Motivo, EmpresaOrigen
            )
            VALUES (
              :empresa, :codigo, :tipo, :numero, 0, GETDATE(), :importe, :cliente, :formaPago,
              CONVERT(datetime, :fechaCad, 120), 0, :tipoVale, :importeOriginal, :saldoPendiente,
              :puesto, :sesion, :cajero, :motivo, :empresaOrigen
            )';
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute([
      'empresa' => $empresa,
      'codigo' => $codigo,
      'tipo' => $tipoLegacy,
      'numero' => $codigo,
      'importe' => $importe,
      'cliente' => $cliente,
      'formaPago' => $formaPago,
      'fechaCad' => $fechaCad,
      'tipoVale' => $tipoVale,
      'importeOriginal' => $importe,
      'saldoPendiente' => $importe,
      'puesto' => $texto($body['puesto'] ?? null, 2),
      'sesion' => $sesion > 0 ? $sesion : null,
      'cajero' => $texto($body['cajero'] ?? null, 4),
      'motivo' => $texto($body['motivo'] ?? null, 30),
      'empresaOrigen' => $texto($body['empresaOrigen'] ?? null, 3),
    ]);

    return $this->obtener($empresa, $codigo);
  }

  /** @param array<string, mixed> $body */
  public function liquidar(string $empresa, int $codigo, array $body): array
  {
    return $this->liquidarInterno($empresa, $codigo, $body);
  }

  /**
   * @param array<int, array{empresa: string, codigo: int|string}> $items
   * @param array<string, mixed> $body
   *
   * @return array{liquidados: list<array<string, mixed>>, errores: list<array{empresa: string, codigo: int, message: string}>}
   */
  public function liquidarLote(array $body): array
  {
    $items = $body['items'] ?? [];
    if (!is_array($items) || $items === []) {
      throw new \InvalidArgumentException('Debe indicar al menos un vale');
    }
    $motivo = trim((string) ($body['motivoOperador'] ?? ''));
    if ($motivo === '') {
      throw new \InvalidArgumentException('El motivo del operador es obligatorio');
    }
    $motivo = mb_substr($motivo, 0, 30);

    $liquidados = [];
    $errores = [];
    $this->pdo->beginTransaction();
    try {
      foreach ($items as $item) {
        if (!is_array($item)) {
          continue;
        }
        $empresa = trim((string) ($item['empresa'] ?? ''));
        $codigo = (int) ($item['codigo'] ?? 0);
        if ($empresa === '' || $codigo <= 0) {
          continue;
        }
        try {
          $liquidados[] = $this->liquidarInterno($empresa, $codigo, array_merge($body, [
            'motivoOperador' => $motivo,
            'tipoLiquidacion' => $body['tipoLiquidacion'] ?? 'C',
          ]));
        } catch (\Throwable $e) {
          $errores[] = [
            'empresa' => $empresa,
            'codigo' => $codigo,
            'message' => $e->getMessage(),
          ];
        }
      }
      if ($liquidados === [] && $errores !== []) {
        $this->pdo->rollBack();
        throw new \RuntimeException($errores[0]['message'], 409);
      }
      $this->pdo->commit();
    } catch (\Throwable $e) {
      if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      throw $e;
    }

    return ['liquidados' => $liquidados, 'errores' => $errores];
  }

  /** @param array<string, mixed> $body */
  private function liquidarInterno(string $empresa, int $codigo, array $body): array
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
      : 'C';
    if ($tipoLiq === '') {
      $tipoLiq = 'C';
    }
    $motivoOp = isset($body['motivoOperador'])
      ? mb_substr(trim((string) $body['motivoOperador']), 0, 30)
      : null;

    $caducidad = $vale['FechaCaducidad'] ?? null;
    if ($caducidad !== null && $caducidad !== '') {
      $cadDay = date('Y-m-d', strtotime((string) $caducidad));
      if ($fechaLiq > $cadDay) {
        throw new \RuntimeException('El vale esta caducado y no se puede liquidar', 409);
      }
    }

    $upd = $this->pdo->prepare(
      'UPDATE Vales SET Liquidado = 1, SaldoPendiente = 0,
              FechaLiquidacion = CONVERT(datetime, :fecha, 120), TipoLiquidacion = :tipo,
              MotivoOperador = COALESCE(:motivo, MotivoOperador)
       WHERE Empresa = :empresa AND Codigo = :codigo AND Liquidado = 0'
    );
    $upd->execute([
      'fecha' => $fechaLiq,
      'tipo' => $tipoLiq,
      'motivo' => $motivoOp,
      'empresa' => $empresa,
      'codigo' => $codigo,
    ]);
    if ($upd->rowCount() === 0) {
      throw new \RuntimeException('No se pudo liquidar el vale', 409);
    }

    return $this->obtener($empresa, $codigo);
  }

  public function vincularValeDocumento(
    string $empresaVale,
    int $codigoVale,
    string $empresaVenta,
    string $facturaTipo,
    int $factura,
    int $albaran
  ): void {
    if ($codigoVale <= 0 || $factura <= 0) {
      return;
    }
    $ft = substr(trim($facturaTipo), 0, 3);
    $this->pdo->prepare(
      'UPDATE Vales SET Factura = :factura, FacturaTipo = :ft, Albaran = :albaran, EmpresaF = :ef
       WHERE RTRIM(Empresa) = :e AND Codigo = :codigo'
    )->execute([
      'factura' => $factura,
      'ft' => $ft,
      'albaran' => $albaran,
      'ef' => substr(trim($empresaVenta), 0, 3),
      'e' => trim($empresaVale),
      'codigo' => $codigoVale,
    ]);
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
    $m = ValesTipoLegacy::FIDELIZACION;
    $lock = $bloquear ? ' WITH (UPDLOCK, HOLDLOCK)' : '';
    $st = $this->pdo->prepare(
      "SELECT RTRIM(Empresa) AS Empresa, Codigo,
              ISNULL(SaldoPendiente, Importe) AS SaldoPendiente, FechaCaducidad
       FROM Vales{$lock}
       WHERE RTRIM(Cliente) = :c
         AND (RTRIM(ISNULL(Tipo, '')) = '{$m}' OR RTRIM(ISNULL(TipoVale, 'REGALO')) = 'FIDELIZACION')
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

  public function importeFidelizacionConsumido(string $empresa, int $albaran): float
  {
    if ($albaran <= 0) {
      return 0.0;
    }
    $m = ValesTipoLegacy::FIDELIZACION;
    try {
      $st = $this->pdo->prepare(
        "SELECT ISNULL(SUM(c.Importe), 0)
         FROM ValeConsumos c
         INNER JOIN Vales v ON v.Codigo = c.ValeCodigo AND RTRIM(v.Empresa) = RTRIM(c.Empresa)
         WHERE RTRIM(c.EmpresaVenta) = :e
           AND c.Albaran = :a
           AND (RTRIM(ISNULL(v.Tipo, '')) = '{$m}' OR RTRIM(ISNULL(v.TipoVale, '')) = 'FIDELIZACION')"
      );
      $st->execute(['e' => trim($empresa), 'a' => $albaran]);

      return round((float) $st->fetchColumn(), 2);
    } catch (\Throwable $e) {
      return 0.0;
    }
  }

  public function formaEsVale(string $codigo): bool
  {
    $codigo = substr(trim($codigo), 0, 2);
    if ($codigo === '') {
      return false;
    }
    try {
      $st = $this->pdo->prepare('SELECT Vales FROM FormasPago WHERE Codigo = :c');
      $st->execute(['c' => $codigo]);
      $row = $st->fetch(PDO::FETCH_ASSOC);

      return $row !== false && !empty($row['Vales']);
    } catch (\Throwable $e) {
      return false;
    }
  }

  /** @return array{empresa: string, codigo: int, cliente: string, saldo: float, tipo: string, tipoVale: string} */
  public function consultarParaCobro(string $empresa, int $codigo): array
  {
    $row = $this->filaCanjePendiente($empresa, $codigo, false);
    if ($row === null) {
      throw new \RuntimeException('Vale no encontrado, caducado o ya usado', 404);
    }

    return $this->resumenCanje($row);
  }

  /**
   * @param array{puesto?: string, sesion?: int, cajero?: string, motivo?: string, empresaOrigen?: string, fechaCaducidad?: string|null} $origen
   *
   * @return array{
   *   aplicado: float,
   *   codigo: int,
   *   empresaVale: string,
   *   valeResto: ?array{empresa: string, codigo: int, importe: float, cliente: string}
   * }
   */
  public function aplicarPorCodigo(
    string $empresaVenta,
    int $codigo,
    float $importeVenta,
    string $tipoVenta,
    int $albaran,
    array $origen = []
  ): array {
    if (!$this->pdo->inTransaction()) {
      throw new \LogicException('El uso del vale requiere una transacción activa');
    }
    $row = $this->filaCanjePendiente($empresaVenta, $codigo, true);
    if ($row === null) {
      throw new \InvalidArgumentException('Vale no encontrado, caducado o ya usado');
    }
    $saldo = round((float) ($row['Saldo'] ?? 0), 2);
    $aplicado = round(min($saldo, max(0, $importeVenta)), 2);
    if ($aplicado <= 0) {
      throw new \InvalidArgumentException('El importe de la venta no admite este vale');
    }
    $resto = round($saldo - $aplicado, 2);
    $empresaVale = trim((string) ($row['Empresa'] ?? '')) ?: $empresaVenta;
    $cliente = trim((string) ($row['Cliente'] ?? ''));
    if ($cliente === '') {
      $cliente = 'ZZZZZZZZZ';
    }
    $tipoLegacy = strtoupper(trim((string) ($row['Tipo'] ?? '')));
    if ($tipoLegacy === '') {
      $tipoLegacy = ValesTipoLegacy::desdeTipoVale(trim((string) ($row['TipoVale'] ?? 'REGALO')));
    }

    $upd = $this->pdo->prepare(
      'UPDATE Vales SET SaldoPendiente = 0, Liquidado = 1,
           FechaLiquidacion = GETDATE(), TipoLiquidacion = :tipo
       WHERE RTRIM(Empresa) = :e AND Codigo = :codigo AND ISNULL(Liquidado, 0) = 0'
    );
    $upd->execute([
      'tipo' => 'V',
      'e' => $empresaVale,
      'codigo' => (int) $row['Codigo'],
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
      'vale' => (int) $row['Codigo'],
      'ev' => $empresaVenta,
      'tv' => $tipoVenta,
      'a' => $albaran,
      'importe' => $aplicado,
    ]);

    $valeResto = null;
    if ($resto >= 0.005) {
      $tipoValeResto = ValesTipoLegacy::tipoValeDesdeLegacy($tipoLegacy);
      $nuevo = $this->emitir(array_merge($origen, [
        'empresa' => $empresaVale,
        'cliente' => $cliente,
        'importe' => $resto,
        'tipo' => $tipoLegacy,
        'tipoVale' => $tipoValeResto,
        'fechaCaducidad' => $row['FechaCaducidad'] ?? ($origen['fechaCaducidad'] ?? null),
      ]));
      $valeResto = [
        'empresa' => (string) ($nuevo['empresa'] ?? $empresaVale),
        'codigo' => (int) ($nuevo['codigo'] ?? 0),
        'importe' => round((float) ($nuevo['importe'] ?? $resto), 2),
        'cliente' => (string) ($nuevo['cliente'] ?? $cliente),
      ];
    }

    return [
      'aplicado' => $aplicado,
      'codigo' => (int) $row['Codigo'],
      'empresaVale' => $empresaVale,
      'valeResto' => $valeResto,
    ];
  }

  /** @return array<string, mixed>|null */
  private function filaCanjePendiente(string $empresa, int $codigo, bool $bloquear): ?array
  {
    if ($codigo <= 0) {
      return null;
    }
    $lock = $bloquear ? ' WITH (UPDLOCK, HOLDLOCK)' : '';
    $canjeSql = ValesTipoLegacy::sqlCanjePorCodigo();
    $st = $this->pdo->prepare(
      "SELECT TOP 1 RTRIM(Empresa) AS Empresa, Codigo, RTRIM(Cliente) AS Cliente,
              ISNULL(SaldoPendiente, Importe) AS Saldo, FechaCaducidad,
              RTRIM(ISNULL(Tipo, '')) AS Tipo,
              RTRIM(ISNULL(TipoVale, 'REGALO')) AS TipoVale
       FROM Vales{$lock}
       WHERE Codigo = :c
         AND ISNULL(Liquidado, 0) = 0
         AND ISNULL(SaldoPendiente, Importe) > 0
         AND {$canjeSql}
       ORDER BY CASE WHEN RTRIM(Empresa) = :e THEN 0 ELSE 1 END, Empresa"
    );
    $st->execute(['c' => $codigo, 'e' => trim($empresa)]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      return null;
    }
    $caducidad = $row['FechaCaducidad'] ?? null;
    if ($caducidad !== null && $caducidad !== '') {
      $cadDay = date('Y-m-d', strtotime((string) $caducidad));
      if (date('Y-m-d') > $cadDay) {
        return null;
      }
    }

    return $row;
  }

  /** @param array<string, mixed> $row */
  private function resumenCanje(array $row): array
  {
    $tipo = strtoupper(trim((string) ($row['Tipo'] ?? '')));
    if ($tipo === '') {
      $tipo = ValesTipoLegacy::desdeTipoVale(trim((string) ($row['TipoVale'] ?? 'REGALO')));
    }

    return [
      'empresa' => trim((string) ($row['Empresa'] ?? '')),
      'codigo' => (int) ($row['Codigo'] ?? 0),
      'cliente' => trim((string) ($row['Cliente'] ?? '')),
      'saldo' => round((float) ($row['Saldo'] ?? 0), 2),
      'tipo' => $tipo,
      'tipoVale' => trim((string) ($row['TipoVale'] ?? ValesTipoLegacy::tipoValeDesdeLegacy($tipo))),
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
              RTRIM(ISNULL(Tipo, \'\')) AS Tipo,
              RTRIM(ISNULL(TipoVale, \'REGALO\')) AS TipoVale,
              ISNULL(ImporteOriginal, Importe) AS ImporteOriginal,
              ISNULL(SaldoPendiente, CASE WHEN Liquidado = 1 THEN 0 ELSE Importe END) AS SaldoPendiente,
              ISNULL(Factura, 0) AS Factura,
              RTRIM(ISNULL(FacturaTipo, \'\')) AS FacturaTipo,
              ISNULL(Albaran, 0) AS Albaran,
              RTRIM(ISNULL(MotivoOperador, \'\')) AS MotivoOperador,
              RTRIM(ISNULL(FormaPago, \'\')) AS FormaPago
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
    $tipo = strtoupper(trim((string) ($row['Tipo'] ?? '')));
    if ($tipo === '') {
      $tipo = ValesTipoLegacy::desdeTipoVale(trim((string) ($row['TipoVale'] ?? 'REGALO')));
    }

    return [
      'empresa' => (string) $row['Empresa'],
      'codigo' => (int) $row['Codigo'],
      'cliente' => $row['Cliente'],
      'importe' => (float) ($row['Importe'] ?? 0),
      'importeOriginal' => (float) ($row['ImporteOriginal'] ?? $row['Importe'] ?? 0),
      'saldoPendiente' => (float) ($row['SaldoPendiente'] ?? $row['Importe'] ?? 0),
      'tipo' => $tipo,
      'tipoLabel' => ValesTipoLegacy::etiqueta($tipo),
      'tipoVale' => trim((string) ($row['TipoVale'] ?? ValesTipoLegacy::tipoValeDesdeLegacy($tipo))),
      'fecha' => isset($row['Fecha']) && $row['Fecha'] ? date('c', strtotime((string) $row['Fecha'])) : null,
      'fechaCaducidad' => $caducidad ? date('Y-m-d', strtotime((string) $caducidad)) : null,
      'liquidado' => $liquidado,
      'fechaLiquidacion' => isset($row['FechaLiquidacion']) && $row['FechaLiquidacion']
        ? date('Y-m-d', strtotime((string) $row['FechaLiquidacion']))
        : null,
      'tipoLiquidacion' => $row['TipoLiquidacion'] !== null ? trim((string) $row['TipoLiquidacion']) : null,
      'formaPago' => isset($row['FormaPago']) ? trim((string) $row['FormaPago']) : null,
      'motivoOperador' => isset($row['MotivoOperador']) ? trim((string) $row['MotivoOperador']) : null,
      'factura' => (int) ($row['Factura'] ?? 0),
      'facturaTipo' => isset($row['FacturaTipo']) ? trim((string) $row['FacturaTipo']) : null,
      'albaran' => (int) ($row['Albaran'] ?? 0),
      'caducado' => $caducado,
    ];
  }
}
