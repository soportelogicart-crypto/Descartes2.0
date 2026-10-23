<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Listados;

use Descartes\Api\Repositories\ClientesRiesgoRepository;
use InvalidArgumentException;
use PDO;

/**
 * Movimientos de un cliente en un periodo (facturas, albaranes sin facturar, cobros en albarán).
 * Saldo acumulado incluye saldo anterior aproximado antes del periodo.
 */
final class ExtractoClientesListadoService
{
  private const LIMITE_FILAS = 10000;

  private PDO $pdo;
  private ClientesRiesgoRepository $riesgo;

  public function __construct(PDO $pdo, ClientesRiesgoRepository $riesgo)
  {
    $this->pdo = $pdo;
    $this->riesgo = $riesgo;
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

    $cliente = trim((string) ($query['cliente'] ?? ''));
    if ($cliente === '') {
      throw new InvalidArgumentException('Indique el cliente');
    }

    $empresa = $this->normalizarEmpresa((string) ($query['empresa'] ?? ''));

    $cab = $this->cargarCliente($cliente);
    if ($cab === null) {
      throw new InvalidArgumentException('Cliente no encontrado');
    }

    $saldoAnterior = round(
      $this->sumFacturas($cliente, $empresa, null, $fechaDesde)
      + $this->sumAlbaranesPendientes($cliente, $empresa, null, $fechaDesde)
      - $this->sumCobros($cliente, $empresa, null, $fechaDesde),
      2
    );

    $movimientos = array_merge(
      $this->facturasEnRango($cliente, $empresa, $fechaDesde, $fechaHasta),
      $this->albaranesPendientesEnRango($cliente, $empresa, $fechaDesde, $fechaHasta),
      $this->cobrosEnRango($cliente, $empresa, $fechaDesde, $fechaHasta)
    );

    usort($movimientos, static function (array $a, array $b): int {
      $cmp = strcmp((string) $a['fecha'], (string) $b['fecha']);
      if ($cmp !== 0) {
        return $cmp;
      }
      return strcmp((string) $a['orden'], (string) $b['orden']);
    });

    $truncado = count($movimientos) > self::LIMITE_FILAS;
    if ($truncado) {
      $movimientos = array_slice($movimientos, 0, self::LIMITE_FILAS);
    }

    $saldo = $saldoAnterior;
    $totalDebe = 0.0;
    $totalHaber = 0.0;
    $items = [];
    foreach ($movimientos as $m) {
      $debe = (float) ($m['debe'] ?? 0);
      $haber = (float) ($m['haber'] ?? 0);
      $totalDebe += $debe;
      $totalHaber += $haber;
      $saldo = round($saldo + $debe - $haber, 2);
      $items[] = [
        'fecha' => $m['fecha'],
        'tipo' => $m['tipo'],
        'documento' => $m['documento'],
        'empresa' => $m['empresa'],
        'concepto' => $m['concepto'],
        'debe' => round($debe, 2),
        'haber' => round($haber, 2),
        'saldo' => $saldo,
      ];
    }

    $riesgo = $this->riesgo->calcular($cliente, (float) $cab['limiteCredito']);

    return [
      'fechaDesde' => $fechaDesde,
      'fechaHasta' => $fechaHasta,
      'cliente' => $cliente,
      'razonSocial' => $cab['razonSocial'],
      'empresa' => $empresa,
      'saldoAnterior' => $saldoAnterior,
      'saldoFinal' => $saldo,
      'riesgoAcumulado' => $riesgo['riesgoAcumulado'],
      'items' => $items,
      'totales' => [
        'movimientos' => count($items),
        'debe' => round($totalDebe, 2),
        'haber' => round($totalHaber, 2),
        'neto' => round($totalDebe - $totalHaber, 2),
      ],
      'truncado' => $truncado,
      'limite' => self::LIMITE_FILAS,
    ];
  }

  /** @return array{razonSocial: string, limiteCredito: float}|null */
  private function cargarCliente(string $codigo): ?array
  {
    $stmt = $this->pdo->prepare(
      'SELECT RTRIM(ISNULL([RazonSocial], \'\')) AS razonSocial,
              ISNULL([LimiteCredito], 0) AS limiteCredito
       FROM [Clientes] WHERE [Codigo] = :codigo'
    );
    $stmt->execute(['codigo' => $codigo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      return null;
    }

    return [
      'razonSocial' => (string) ($row['razonSocial'] ?? ''),
      'limiteCredito' => (float) ($row['limiteCredito'] ?? 0),
    ];
  }

  /**
   * @return list<array<string, mixed>>
   */
  private function facturasEnRango(string $cliente, string $empresa, string $desde, string $hasta): array
  {
    [$extraWhere, $params] = $this->filtroEmpresa('f.[Empresa]', $empresa);
    $params['cliente'] = $cliente;
    $params['fechaDesde'] = $desde . ' 00:00:00';
    $params['fechaHasta'] = $hasta . ' 23:59:59';

    $sql = "SELECT RTRIM(f.[Empresa]) AS empresa,
                   CONVERT(varchar(10), f.[Fecha], 23) AS fecha,
                   RTRIM(f.[FacturaTipo]) AS facturaTipo,
                   f.[Factura] AS factura,
                   ISNULL(f.[Importe], 0) AS importe
            FROM [Facturas] f
            WHERE f.[Cliente] = :cliente
              AND f.[Fecha] >= CONVERT(datetime, :fechaDesde, 120)
              AND f.[Fecha] <= CONVERT(datetime, :fechaHasta, 120)
              {$extraWhere}
            ORDER BY f.[Fecha], f.[FacturaTipo], f.[Factura]";

    return $this->mapFacturas($this->pdo->prepare($sql), $params, '1');
  }

  private function sumFacturas(string $cliente, string $empresa, ?string $desde, ?string $hastaExcl): float
  {
    [$extraWhere, $params] = $this->filtroEmpresa('f.[Empresa]', $empresa);
    $params['cliente'] = $cliente;
    $fechaSql = '';
    if ($desde !== null && $hastaExcl === null) {
      $fechaSql = ' AND f.[Fecha] < CONVERT(datetime, :fechaCorte, 120)';
      $params['fechaCorte'] = $desde . ' 00:00:00';
    } elseif ($desde !== null && $hastaExcl !== null) {
      $fechaSql = ' AND f.[Fecha] >= CONVERT(datetime, :fechaDesde, 120)
                    AND f.[Fecha] <= CONVERT(datetime, :fechaHasta, 120)';
      $params['fechaDesde'] = $desde . ' 00:00:00';
      $params['fechaHasta'] = $hastaExcl . ' 23:59:59';
    }

    $sql = "SELECT SUM(ISNULL(f.[Importe], 0)) FROM [Facturas] f
            WHERE f.[Cliente] = :cliente {$fechaSql} {$extraWhere}";
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);

    return (float) ($stmt->fetchColumn() ?: 0);
  }

  /**
   * @return list<array<string, mixed>>
   */
  private function albaranesPendientesEnRango(string $cliente, string $empresa, string $desde, string $hasta): array
  {
    [$extraWhere, $params] = $this->filtroEmpresa('c.[Empresa]', $empresa);
    $params['cliente'] = $cliente;
    $params['fechaDesde'] = $desde . ' 00:00:00';
    $params['fechaHasta'] = $hasta . ' 23:59:59';

    $sql = "SELECT RTRIM(c.[Empresa]) AS empresa,
                   CONVERT(varchar(10), c.[Fecha], 23) AS fecha,
                   RTRIM(c.[Tipo]) AS tipoAlb,
                   c.[Albaran] AS albaran,
                   ISNULL(c.[Importe], 0) AS importe
            FROM [AlbaranesVentasCab] c
            WHERE c.[Cliente] = :cliente
              AND ISNULL(c.[Anulado], 0) = 0
              AND ISNULL(c.[Factura], 0) = 0
              AND (c.[FacturaTipo] IS NULL OR LTRIM(RTRIM(ISNULL(c.[FacturaTipo], ''))) = '')
              AND c.[Fecha] >= CONVERT(datetime, :fechaDesde, 120)
              AND c.[Fecha] <= CONVERT(datetime, :fechaHasta, 120)
              {$extraWhere}";

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);
    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $importe = round((float) ($row['importe'] ?? 0), 2);
      $doc = trim((string) ($row['tipoAlb'] ?? '')) . '-' . (int) ($row['albaran'] ?? 0);
      [$debe, $haber] = $this->debeHaberDesdeImporte($importe);
      $out[] = [
        'fecha' => (string) ($row['fecha'] ?? ''),
        'tipo' => 'albaran',
        'documento' => $doc,
        'empresa' => (string) ($row['empresa'] ?? ''),
        'concepto' => 'Albarán sin facturar',
        'debe' => $debe,
        'haber' => $haber,
        'orden' => '2-' . $doc,
      ];
    }

    return $out;
  }

  private function sumAlbaranesPendientes(string $cliente, string $empresa, ?string $desde, ?string $hastaExcl): float
  {
    [$extraWhere, $params] = $this->filtroEmpresa('c.[Empresa]', $empresa);
    $params['cliente'] = $cliente;
    $fechaSql = '';
    if ($desde !== null && $hastaExcl === null) {
      $fechaSql = ' AND c.[Fecha] < CONVERT(datetime, :fechaCorte, 120)';
      $params['fechaCorte'] = $desde . ' 00:00:00';
    } elseif ($desde !== null && $hastaExcl !== null) {
      $fechaSql = ' AND c.[Fecha] >= CONVERT(datetime, :fechaDesde, 120)
                    AND c.[Fecha] <= CONVERT(datetime, :fechaHasta, 120)';
      $params['fechaDesde'] = $desde . ' 00:00:00';
      $params['fechaHasta'] = $hastaExcl . ' 23:59:59';
    }

    $sql = "SELECT SUM(ISNULL(c.[Importe], 0))
            FROM [AlbaranesVentasCab] c
            WHERE c.[Cliente] = :cliente
              AND ISNULL(c.[Anulado], 0) = 0
              AND ISNULL(c.[Factura], 0) = 0
              AND (c.[FacturaTipo] IS NULL OR LTRIM(RTRIM(ISNULL(c.[FacturaTipo], ''))) = '')
              {$fechaSql} {$extraWhere}";
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);

    return (float) ($stmt->fetchColumn() ?: 0);
  }

  /**
   * @return list<array<string, mixed>>
   */
  private function cobrosEnRango(string $cliente, string $empresa, string $desde, string $hasta): array
  {
    [$extraWhere, $params] = $this->filtroEmpresa('c.[Empresa]', $empresa);
    $params['cliente'] = $cliente;
    $params['fechaDesde'] = $desde . ' 00:00:00';
    $params['fechaHasta'] = $hasta . ' 23:59:59';

    $sql = "SELECT RTRIM(c.[Empresa]) AS empresa,
                   CONVERT(varchar(10), c.[FechaCobro], 23) AS fecha,
                   RTRIM(c.[Tipo]) AS tipoAlb,
                   c.[Albaran] AS albaran,
                   ISNULL(c.[Importe], 0) AS importe
            FROM [AlbaranesVentasCab] c
            WHERE c.[Cliente] = :cliente
              AND ISNULL(c.[Anulado], 0) = 0
              AND c.[FechaCobro] IS NOT NULL
              AND c.[FechaCobro] >= CONVERT(datetime, :fechaDesde, 120)
              AND c.[FechaCobro] <= CONVERT(datetime, :fechaHasta, 120)
              {$extraWhere}";

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);
    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $importe = round(abs((float) ($row['importe'] ?? 0)), 2);
      if ($importe <= 0) {
        continue;
      }
      $doc = trim((string) ($row['tipoAlb'] ?? '')) . '-' . (int) ($row['albaran'] ?? 0);
      $out[] = [
        'fecha' => (string) ($row['fecha'] ?? ''),
        'tipo' => 'cobro',
        'documento' => $doc,
        'empresa' => (string) ($row['empresa'] ?? ''),
        'concepto' => 'Cobro (fecha cobro albarán)',
        'debe' => 0.0,
        'haber' => $importe,
        'orden' => '3-' . $doc,
      ];
    }

    return $out;
  }

  private function sumCobros(string $cliente, string $empresa, ?string $desde, ?string $hastaExcl): float
  {
    [$extraWhere, $params] = $this->filtroEmpresa('c.[Empresa]', $empresa);
    $params['cliente'] = $cliente;
    $fechaSql = '';
    if ($desde !== null && $hastaExcl === null) {
      $fechaSql = ' AND c.[FechaCobro] < CONVERT(datetime, :fechaCorte, 120)';
      $params['fechaCorte'] = $desde . ' 00:00:00';
    } elseif ($desde !== null && $hastaExcl !== null) {
      $fechaSql = ' AND c.[FechaCobro] >= CONVERT(datetime, :fechaDesde, 120)
                    AND c.[FechaCobro] <= CONVERT(datetime, :fechaHasta, 120)';
      $params['fechaDesde'] = $desde . ' 00:00:00';
      $params['fechaHasta'] = $hastaExcl . ' 23:59:59';
    }

    $sql = "SELECT SUM(ABS(ISNULL(c.[Importe], 0)))
            FROM [AlbaranesVentasCab] c
            WHERE c.[Cliente] = :cliente
              AND ISNULL(c.[Anulado], 0) = 0
              AND c.[FechaCobro] IS NOT NULL
              {$fechaSql} {$extraWhere}";
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);

    return (float) ($stmt->fetchColumn() ?: 0);
  }

  /**
   * @param array<string, mixed> $params
   * @return list<array<string, mixed>>
   */
  private function mapFacturas(\PDOStatement $stmt, array $params, string $ordenPrefijo): array
  {
    $stmt->execute($params);
    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $importe = round((float) ($row['importe'] ?? 0), 2);
      $tipo = trim((string) ($row['facturaTipo'] ?? ''));
      $num = (int) ($row['factura'] ?? 0);
      $doc = $tipo . '-' . $num;
      [$debe, $haber] = $this->debeHaberDesdeImporte($importe);
      $out[] = [
        'fecha' => (string) ($row['fecha'] ?? ''),
        'tipo' => 'factura',
        'documento' => $doc,
        'empresa' => (string) ($row['empresa'] ?? ''),
        'concepto' => 'Factura',
        'debe' => $debe,
        'haber' => $haber,
        'orden' => $ordenPrefijo . '-' . $doc,
      ];
    }

    return $out;
  }

  /** @return array{0: float, 1: float} */
  private function debeHaberDesdeImporte(float $importe): array
  {
    if ($importe >= 0) {
      return [$importe, 0.0];
    }

    return [0.0, abs($importe)];
  }

  /** @return array{0: string, 1: array<string, mixed>} */
  private function filtroEmpresa(string $columna, string $empresa): array
  {
    if ($empresa === '') {
      return ['', []];
    }

    return [" AND RTRIM({$columna}) = :empresa", ['empresa' => $empresa]];
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
