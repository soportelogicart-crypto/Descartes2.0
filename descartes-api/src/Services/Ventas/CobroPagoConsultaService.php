<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Ventas;

use Descartes\Api\Database\SqlPagination;
use PDO;

final class CobroPagoConsultaService
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
    $tipoFiltro = strtolower((string) ($query['tipo'] ?? 'todos'));

    $documento = $this->filtrosDocumento($query);
    $formaPagoFiltro = isset($query['formaPago']) ? trim((string) $query['formaPago']) : '';
    $filtros = ['1=1'];
    $params = $documento['params'];
    if ($formaPagoFiltro !== '') {
      $filtros[] = 'UPPER(slots.FormaPago) = UPPER(:formaPago)';
      $params['formaPago'] = $formaPagoFiltro;
    }
    if ($tipoFiltro === 'pago') {
      $filtros[] = "UPPER(LTRIM(RTRIM(ISNULL(slots.CobroPago, '')))) = 'P'";
    } elseif ($tipoFiltro === 'cobro') {
      $filtros[] = "UPPER(LTRIM(RTRIM(ISNULL(slots.CobroPago, '')))) <> 'P'";
    }
    $sqlWhere = 'WHERE ' . implode(' AND ', $filtros);
    $from = 'FROM (' . $this->sqlSlots($documento['where']) . ') slots';

    $countStmt = $this->pdo->prepare("SELECT COUNT(*) AS total {$from} {$sqlWhere}");
    $countStmt->execute($params);
    $total = (int) ($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

    $offset = ($page - 1) * $pageSize;
    $sql = SqlPagination::wrap(
      "SELECT slots.Fecha, slots.Slot, slots.FormaPago, slots.ImpFpago1, slots.ImpFpago2,
              slots.ImporteDoc, slots.CobroPago, slots.Puesto, slots.Empresa, slots.Tipo, slots.Albaran
       {$from} {$sqlWhere}",
      'Fecha DESC, Albaran DESC, Slot ASC, Empresa, Tipo',
      $offset,
      $pageSize
    );
    $stmt = $this->pdo->prepare($sql);
    foreach ($params as $key => $value) {
      $stmt->bindValue(':' . $key, $value);
    }
    SqlPagination::bind($stmt, $offset, $pageSize);
    $stmt->execute();

    $items = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $slot = (int) $row['Slot'];
      $importe = $slot === 3
        ? max(0.0, (float) ($row['ImporteDoc'] ?? 0) - (float) ($row['ImpFpago1'] ?? 0) - (float) ($row['ImpFpago2'] ?? 0))
        : (float) ($slot === 2 ? $row['ImpFpago2'] : $row['ImpFpago1']);
      $fecha = $row['Fecha'] ?? null;
      $items[] = [
        'tipo' => $this->clasificar($row['CobroPago'] ?? null),
        'formaPago' => trim((string) $row['FormaPago']),
        'importe' => $importe,
        'fecha' => $fecha ? date('c', strtotime((string) $fecha)) : null,
        'puesto' => $row['Puesto'],
        'empresa' => (string) $row['Empresa'],
        'tipoAlbaran' => (string) $row['Tipo'],
        'albaran' => (int) $row['Albaran'],
      ];
    }

    return [
      'items' => $items,
      'total' => $total,
      'page' => $page,
      'pageSize' => $pageSize,
    ];
  }

  /**
   * Cada albarán sale como hasta tres filas, en el mismo orden que el listado anterior.
   *
   * @param array<int, string> $whereDocumento
   */
  private function sqlSlots(array $whereDocumento): string
  {
    $documento = $whereDocumento === [] ? '' : ' AND ' . implode(' AND ', $whereDocumento);
    $rama = static function (int $slot, string $join, string $where) use ($documento): string {
      $extra = str_replace(
        [':fechaDesde', ':fechaHasta', ':puesto'],
        [':fechaDesde' . $slot, ':fechaHasta' . $slot, ':puesto' . $slot],
        $documento
      );
      return "SELECT c.Fecha, {$slot} AS Slot, LTRIM(RTRIM(c.Fpago{$slot})) AS FormaPago,
              c.ImpFpago1, c.ImpFpago2, c.Importe AS ImporteDoc, {$join} AS CobroPago,
              c.Puesto, c.Empresa, c.Tipo, c.Albaran
       FROM AlbaranesVentasCab c
       {$where}{$extra}";
    };

    return $rama(1, 'f1.CobroPago', 'LEFT JOIN FormasPago f1 ON f1.Codigo = c.Fpago1
       WHERE LTRIM(RTRIM(ISNULL(c.Fpago1, \'\'))) <> \'\'')
      . ' UNION ALL '
      . $rama(2, 'f2.CobroPago', 'LEFT JOIN FormasPago f2 ON f2.Codigo = c.Fpago2
       WHERE LTRIM(RTRIM(ISNULL(c.Fpago2, \'\'))) <> \'\'')
      . ' UNION ALL '
      . $rama(3, 'f3.CobroPago', 'LEFT JOIN FormasPago f3 ON f3.Codigo = RTRIM(c.Fpago3)
       WHERE LTRIM(RTRIM(ISNULL(c.Fpago3, \'\'))) <> \'\'');
  }

  /**
   * @param array<string, mixed> $query
   * @return array{where: list<string>, params: array<string, string>}
   */
  private function filtrosDocumento(array $query): array
  {
    $where = [];
    $params = [];
    if ($this->fechaIsoValida($query['fechaDesde'] ?? null)) {
      $where[] = 'c.Fecha >= CONVERT(datetime, :fechaDesde, 120)';
      $desde = substr((string) $query['fechaDesde'], 0, 10) . ' 00:00:00';
      $params['fechaDesde1'] = $desde;
      $params['fechaDesde2'] = $desde;
      $params['fechaDesde3'] = $desde;
    }
    if ($this->fechaIsoValida($query['fechaHasta'] ?? null)) {
      $where[] = 'c.Fecha <= CONVERT(datetime, :fechaHasta, 120)';
      $hasta = substr((string) $query['fechaHasta'], 0, 10) . ' 23:59:59';
      $params['fechaHasta1'] = $hasta;
      $params['fechaHasta2'] = $hasta;
      $params['fechaHasta3'] = $hasta;
    }
    if (!empty($query['puesto'])) {
      $where[] = 'c.Puesto = :puesto';
      $puesto = (string) $query['puesto'];
      $params['puesto1'] = $puesto;
      $params['puesto2'] = $puesto;
      $params['puesto3'] = $puesto;
    }

    return ['where' => $where, 'params' => $params];
  }

  private function clasificar($cobroPago): string
  {
    $v = strtoupper(trim((string) $cobroPago));
    if ($v === 'P') {
      return 'pago';
    }
    return 'cobro';
  }

  private function fechaIsoValida($value): bool
  {
    if ($value === null || $value === '') {
      return false;
    }
    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $value);
  }
}
