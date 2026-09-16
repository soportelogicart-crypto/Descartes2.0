<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Listados;

use InvalidArgumentException;
use PDO;

/**
 * Diario de tickets (cabeceras AlbaranesVentasCab con FacturaTipo T).
 */
final class InformeTicketsListadoService
{
  private const LIMITE_FILAS = 10000;

  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  /** @param array<string, mixed> $query */
  public function generar(array $query): array
  {
    $fechaDesde = $this->fechaDia($query['fechaDesde'] ?? null);
    $fechaHasta = $this->fechaDia($query['fechaHasta'] ?? null);
    if ($fechaDesde === null || $fechaHasta === null) {
      throw new InvalidArgumentException('fechaDesde y fechaHasta son obligatorias (YYYY-MM-DD)');
    }
    if ($fechaDesde > $fechaHasta) {
      throw new InvalidArgumentException('La fecha desde no puede ser posterior a la fecha hasta');
    }

    $empresa = $this->normalizarEmpresa((string) ($query['empresa'] ?? ''));
    $puesto = trim((string) ($query['puesto'] ?? ''));
    $vendedor = trim((string) ($query['vendedor'] ?? ''));

    $soloNumerados = filter_var($query['soloNumerados'] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    $soloNumerados = $soloNumerados !== false;

    $where = [
      'c.[Fecha] >= CONVERT(datetime, :fechaDesde, 120)',
      'c.[Fecha] <= CONVERT(datetime, :fechaHasta, 120)',
      "RTRIM(c.[FacturaTipo]) = 'T'",
      'ISNULL(c.[Anulado], 0) = 0',
      "RTRIM(ISNULL(c.[Estado], '')) <> 'B'",
    ];
    $params = [
      'fechaDesde' => $fechaDesde . ' 00:00:00',
      'fechaHasta' => $fechaHasta . ' 23:59:59',
    ];

    if ($empresa !== '') {
      $where[] = 'RTRIM(c.[Empresa]) = :empresa';
      $params['empresa'] = $empresa;
    }
    if ($puesto !== '') {
      $where[] = 'RTRIM(c.[Puesto]) = :puesto';
      $params['puesto'] = $puesto;
    }
    if ($vendedor !== '') {
      $where[] = 'RTRIM(c.[Vendedor]) = :vendedor';
      $params['vendedor'] = $vendedor;
    }
    if ($soloNumerados) {
      $where[] = 'ISNULL(c.[Factura], 0) > 0';
    }

    $sql = 'SELECT TOP ' . (self::LIMITE_FILAS + 1) . "
              RTRIM(c.[Empresa]) AS empresa,
              c.[Albaran] AS albaran,
              CONVERT(varchar(10), c.[Fecha], 23) AS fecha,
              c.[Factura] AS numeroTicket,
              RTRIM(ISNULL(c.[Puesto], '')) AS puesto,
              RTRIM(ISNULL(c.[Vendedor], '')) AS vendedor,
              RTRIM(ISNULL(c.[Cliente], '')) AS cliente,
              RTRIM(ISNULL(c.[RazonSocial], '')) AS razonSocial,
              ISNULL(c.[Importe], 0) AS importe,
              c.[Sesion] AS sesion,
              RTRIM(ISNULL(c.[Estado], '')) AS estado,
              RTRIM(ISNULL(c.[Fpago1], '')) AS formaPago
            FROM [AlbaranesVentasCab] c
            WHERE " . implode(' AND ', $where) . '
            ORDER BY c.[Fecha] DESC, c.[Albaran] DESC';

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);

    $items = [];
    $totalImporte = 0.0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $importe = round((float) ($row['importe'] ?? 0), 2);
      $totalImporte += $importe;
      $items[] = [
        'empresa' => (string) ($row['empresa'] ?? ''),
        'albaran' => (int) ($row['albaran'] ?? 0),
        'fecha' => (string) ($row['fecha'] ?? ''),
        'numeroTicket' => $row['numeroTicket'] !== null ? (int) $row['numeroTicket'] : null,
        'puesto' => (string) ($row['puesto'] ?? ''),
        'vendedor' => (string) ($row['vendedor'] ?? ''),
        'cliente' => (string) ($row['cliente'] ?? ''),
        'razonSocial' => (string) ($row['razonSocial'] ?? ''),
        'importe' => $importe,
        'sesion' => $row['sesion'] !== null ? (int) $row['sesion'] : null,
        'estado' => (string) ($row['estado'] ?? ''),
        'formaPago' => (string) ($row['formaPago'] ?? ''),
      ];
    }

    $truncado = count($items) > self::LIMITE_FILAS;
    if ($truncado) {
      $items = array_slice($items, 0, self::LIMITE_FILAS);
    }

    return [
      'fechaDesde' => $fechaDesde,
      'fechaHasta' => $fechaHasta,
      'empresa' => $empresa,
      'puesto' => $puesto,
      'vendedor' => $vendedor,
      'soloNumerados' => $soloNumerados,
      'items' => $items,
      'totales' => [
        'tickets' => count($items),
        'importe' => round($totalImporte, 2),
      ],
      'truncado' => $truncado,
      'limite' => self::LIMITE_FILAS,
    ];
  }

  private function fechaDia(mixed $value): ?string
  {
    if ($value === null || $value === '') {
      return null;
    }
    $s = trim((string) $value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
      return null;
    }

    return $s;
  }

  private function normalizarEmpresa(string $raw): string
  {
    $t = trim($raw);
    if ($t === '') {
      return '';
    }
    if (preg_match('/^\d+$/', $t)) {
      return (string) (int) $t;
    }

    return strtoupper($t);
  }
}
