<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Listados;

use InvalidArgumentException;
use PDO;

/**
 * Ventas agrupadas por tipo de IVA en un periodo (líneas de albarán de venta).
 */
final class InformeIvaListadoService
{
  private const LIMITE_FILAS = 100;

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

    $empresa = strtoupper(trim((string) ($query['empresa'] ?? '')));

    $where = [
      'c.[Fecha] >= CONVERT(datetime, :fechaDesde, 120)',
      'c.[Fecha] <= CONVERT(datetime, :fechaHasta, 120)',
      'ISNULL(c.[Anulado], 0) = 0',
    ];
    $params = [
      'fechaDesde' => $fechaDesde . ' 00:00:00',
      'fechaHasta' => $fechaHasta . ' 23:59:59',
    ];
    if ($empresa !== '') {
      $where[] = 'RTRIM(c.[Empresa]) = :empresa';
      $params['empresa'] = $empresa;
    }

    $pje = 'COALESCE(NULLIF(l.[PjeIva], 0), ISNULL(i.[PjeIVA], 0), 0)';
    $importe = 'ISNULL(l.[Importe], 0)';
    $baseExpr = "({$importe} / NULLIF(1.0 + ({$pje}) / 100.0, 0))";
    $cuotaExpr = "({$importe} - ({$baseExpr}))";

    $sql = 'SELECT TOP ' . (self::LIMITE_FILAS + 1) . "
              RTRIM(ISNULL(i.[Codigo], '')) AS impuestoCodigo,
              RTRIM(ISNULL(i.[Descripcion], '')) AS impuestoNombre,
              {$pje} AS pjeIva,
              SUM({$baseExpr}) AS baseImponible,
              SUM({$cuotaExpr}) AS cuotaIva,
              SUM({$importe}) AS importeTotal,
              COUNT(*) AS numLineas
            FROM [AlbaranesVentasCab] c
            INNER JOIN [AlbaranesVentasLin] l
              ON c.[Empresa] = l.[Empresa] AND c.[Albaran] = l.[Albaran] AND c.[Tipo] = l.[Tipo]
            LEFT JOIN [Articulos] a ON RTRIM(a.[Codigo]) = RTRIM(l.[Articulo])
            LEFT JOIN [Impuestos] i ON i.[Codigo] = COALESCE(NULLIF(RTRIM(a.[Impuesto]), ''), 'NO')
            WHERE " . implode(' AND ', $where) . "
            GROUP BY i.[Codigo], i.[Descripcion], {$pje}
            ORDER BY {$pje}, i.[Codigo]";

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);

    $items = [];
    $totBase = 0.0;
    $totCuota = 0.0;
    $totImporte = 0.0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $base = round((float) ($row['baseImponible'] ?? 0), 2);
      $cuota = round((float) ($row['cuotaIva'] ?? 0), 2);
      $imp = round((float) ($row['importeTotal'] ?? 0), 2);
      $totBase += $base;
      $totCuota += $cuota;
      $totImporte += $imp;
      $items[] = [
        'impuestoCodigo' => (string) ($row['impuestoCodigo'] ?? ''),
        'impuestoNombre' => (string) ($row['impuestoNombre'] ?? ''),
        'pjeIva' => round((float) ($row['pjeIva'] ?? 0), 2),
        'baseImponible' => $base,
        'cuotaIva' => $cuota,
        'importeTotal' => $imp,
        'numLineas' => (int) ($row['numLineas'] ?? 0),
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
      'items' => $items,
      'totales' => [
        'baseImponible' => round($totBase, 2),
        'cuotaIva' => round($totCuota, 2),
        'importeTotal' => round($totImporte, 2),
        'filas' => count($items),
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
}
