<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Ventas;

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

    $where = ['1=1'];
    $params = [];
    if ($this->fechaIsoValida($query['fechaDesde'] ?? null)) {
      $where[] = 'c.Fecha >= CONVERT(datetime, :fechaDesde, 120)';
      $params['fechaDesde'] = substr((string) $query['fechaDesde'], 0, 10) . ' 00:00:00';
    }
    if ($this->fechaIsoValida($query['fechaHasta'] ?? null)) {
      $where[] = 'c.Fecha <= CONVERT(datetime, :fechaHasta, 120)';
      $params['fechaHasta'] = substr((string) $query['fechaHasta'], 0, 10) . ' 23:59:59';
    }
    if (!empty($query['puesto'])) {
      $where[] = 'c.Puesto = :puesto';
      $params['puesto'] = $query['puesto'];
    }

    $sqlWhere = implode(' AND ', $where);
    $sql = "SELECT c.Empresa, c.Tipo, c.Albaran, c.Fecha, c.Puesto,
                   c.Fpago1, c.Fpago2, c.Fpago3, c.ImpFpago1, c.ImpFpago2, c.Importe,
                   f1.CobroPago AS CobroPago1, f2.CobroPago AS CobroPago2, f3.CobroPago AS CobroPago3
            FROM AlbaranesVentasCab c
            LEFT JOIN FormasPago f1 ON f1.Codigo = c.Fpago1
            LEFT JOIN FormasPago f2 ON f2.Codigo = c.Fpago2
            LEFT JOIN FormasPago f3 ON f3.Codigo = RTRIM(c.Fpago3)
            WHERE {$sqlWhere}
            ORDER BY c.Fecha DESC";
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);

    $formaPagoFiltro = isset($query['formaPago']) ? trim((string) $query['formaPago']) : '';
    $all = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $slots = [
        [trim((string) ($row['Fpago1'] ?? '')), (float) ($row['ImpFpago1'] ?? 0), $row['CobroPago1'] ?? null],
        [trim((string) ($row['Fpago2'] ?? '')), (float) ($row['ImpFpago2'] ?? 0), $row['CobroPago2'] ?? null],
      ];
      $fp3 = trim((string) ($row['Fpago3'] ?? ''));
      if ($fp3 !== '') {
        $imp3 = max(0.0, (float) ($row['Importe'] ?? 0) - (float) ($row['ImpFpago1'] ?? 0) - (float) ($row['ImpFpago2'] ?? 0));
        $slots[] = [$fp3, $imp3, $row['CobroPago3'] ?? null];
      }

      foreach ($slots as [$codigo, $importe, $cobroPago]) {
        if ($codigo === '') {
          continue;
        }
        if ($formaPagoFiltro !== '' && strcasecmp($codigo, $formaPagoFiltro) !== 0) {
          continue;
        }
        $tipo = $this->clasificar($cobroPago);
        if ($tipoFiltro === 'cobro' && $tipo !== 'cobro') {
          continue;
        }
        if ($tipoFiltro === 'pago' && $tipo !== 'pago') {
          continue;
        }
        $fecha = $row['Fecha'] ?? null;
        $all[] = [
          'tipo' => $tipo,
          'formaPago' => $codigo,
          'importe' => $importe,
          'fecha' => $fecha ? date('c', strtotime((string) $fecha)) : null,
          'puesto' => $row['Puesto'],
          'empresa' => (string) $row['Empresa'],
          'tipoAlbaran' => (string) $row['Tipo'],
          'albaran' => (int) $row['Albaran'],
        ];
      }
    }

    $total = count($all);
    $slice = array_slice($all, ($page - 1) * $pageSize, $pageSize);

    return [
      'items' => $slice,
      'total' => $total,
      'page' => $page,
      'pageSize' => $pageSize,
    ];
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
