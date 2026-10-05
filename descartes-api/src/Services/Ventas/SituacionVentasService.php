<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Ventas;

use PDO;

/**
 * Situación de ventas del terminal: contadores de la sesión
 * (tickets, facturas, albaranes) y efectivo de caja.
 * Por fechas suma las sesiones cuyo inicio cae en el periodo.
 */
final class SituacionVentasService
{
  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  /**
   * @param array<string, mixed> $query
   * @return array<string, mixed>
   */
  public function consultar(array $query): array
  {
    $modo = strtolower(trim((string) ($query['modo'] ?? 'sesion')));
    if ($modo !== 'fechas') {
      $modo = 'sesion';
    }

    [$where, $params, $meta] = $this->filtros($modo, $query);
    $sqlWhere = implode(' AND ', $where);

    $st = $this->pdo->prepare(
      "SELECT
          COUNT(*) AS sesiones,
          SUM(ISNULL(s.Tickets, 0)) AS tickets,
          SUM(ISNULL(s.ImporteTickets, 0)) AS importeTickets,
          SUM(ISNULL(s.Facturas, 0)) AS facturas,
          SUM(ISNULL(s.ImporteFacturas, 0)) AS importeFacturas,
          SUM(ISNULL(s.Albaranes, 0)) AS albaranes,
          SUM(ISNULL(s.ImporteAlbaranes, 0)) AS importeAlbaranes
       FROM Sesiones s
       WHERE {$sqlWhere}"
    );
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $tickets = (int) ($row['tickets'] ?? 0);
    $facturas = (int) ($row['facturas'] ?? 0);
    $albaranes = (int) ($row['albaranes'] ?? 0);
    $impTickets = round((float) ($row['importeTickets'] ?? 0), 2);
    $impFacturas = round((float) ($row['importeFacturas'] ?? 0), 2);
    $impAlbaranes = round((float) ($row['importeAlbaranes'] ?? 0), 2);

    $detalle = [];
    $truncado = false;
    if ($modo === 'fechas' || $meta['puesto'] === null) {
      $det = $this->pdo->prepare(
        "SELECT TOP 300
            s.Empresa, s.Puesto, s.Sesion, s.FechaInicio, s.FechaFin,
            ISNULL(s.Tickets, 0) AS Tickets,
            ISNULL(s.ImporteTickets, 0) AS ImporteTickets,
            ISNULL(s.Facturas, 0) AS Facturas,
            ISNULL(s.ImporteFacturas, 0) AS ImporteFacturas,
            ISNULL(s.Albaranes, 0) AS Albaranes,
            ISNULL(s.ImporteAlbaranes, 0) AS ImporteAlbaranes
         FROM Sesiones s
         WHERE {$sqlWhere}
         ORDER BY s.FechaInicio DESC, s.Puesto, s.Sesion DESC"
      );
      $det->execute($params);
      foreach ($det as $linea) {
        $detalle[] = [
          'empresa' => trim((string) ($linea['Empresa'] ?? '')),
          'puesto' => trim((string) ($linea['Puesto'] ?? '')),
          'sesion' => (int) ($linea['Sesion'] ?? 0),
          'fechaInicio' => $this->fmtDate($linea['FechaInicio'] ?? null),
          'fechaFin' => $this->fmtDate($linea['FechaFin'] ?? null),
          'tickets' => (int) $linea['Tickets'],
          'importeTickets' => round((float) $linea['ImporteTickets'], 2),
          'facturas' => (int) $linea['Facturas'],
          'importeFacturas' => round((float) $linea['ImporteFacturas'], 2),
          'albaranes' => (int) $linea['Albaranes'],
          'importeAlbaranes' => round((float) $linea['ImporteAlbaranes'], 2),
        ];
      }
      $truncado = (int) ($row['sesiones'] ?? 0) > count($detalle);
    }

    return [
      'modo' => $modo,
      'puesto' => $meta['puesto'],
      'sesion' => $meta['sesion'],
      'fechaDesde' => $meta['fechaDesde'],
      'fechaHasta' => $meta['fechaHasta'],
      'sesiones' => (int) ($row['sesiones'] ?? 0),
      'tickets' => $tickets,
      'importeTickets' => $impTickets,
      'facturas' => $facturas,
      'importeFacturas' => $impFacturas,
      'albaranes' => $albaranes,
      'importeAlbaranes' => $impAlbaranes,
      'totalNumero' => $tickets + $facturas,
      'totalImporte' => round($impTickets + $impFacturas, 2),
      'totalConAlbaranesNumero' => $tickets + $facturas + $albaranes,
      'totalConAlbaranesImporte' => round($impTickets + $impFacturas + $impAlbaranes, 2),
      'efectivo' => $this->efectivo($sqlWhere, $params),
      'detalle' => $detalle,
      'truncado' => $truncado,
    ];
  }

  /**
   * @param array<string, mixed> $query
   * @return array{0: list<string>, 1: array<string, mixed>, 2: array<string, mixed>}
   */
  private function filtros(string $modo, array $query): array
  {
    $puesto = trim((string) ($query['puesto'] ?? ''));
    $where = [];
    $params = [];
    $meta = [
      'puesto' => $puesto !== '' ? $puesto : null,
      'sesion' => null,
      'fechaDesde' => null,
      'fechaHasta' => null,
    ];

    if ($modo === 'sesion') {
      $sesion = (int) ($query['sesion'] ?? 0);
      if ($sesion <= 0) {
        throw new \InvalidArgumentException('Indique la sesión');
      }
      $where[] = 's.Sesion = :sesion';
      $params['sesion'] = $sesion;
      $meta['sesion'] = $sesion;
      if ($puesto !== '') {
        $where[] = 'RTRIM(s.Puesto) = :puesto';
        $params['puesto'] = $puesto;
      }
      return [$where, $params, $meta];
    }

    $desde = trim((string) ($query['fechaDesde'] ?? ''));
    $hasta = trim((string) ($query['fechaHasta'] ?? ''));
    if ($desde === '' || $hasta === '') {
      throw new \InvalidArgumentException('Indique la fecha desde y la fecha hasta');
    }
    if ($desde > $hasta) {
      throw new \InvalidArgumentException('La fecha desde no puede ser posterior a la fecha hasta');
    }
    $where[] = 's.FechaInicio >= :desde';
    $where[] = 's.FechaInicio < DATEADD(day, 1, :hasta)';
    $params['desde'] = $desde;
    $params['hasta'] = $hasta;
    $meta['fechaDesde'] = $desde;
    $meta['fechaHasta'] = $hasta;
    if ($puesto !== '') {
      $where[] = 'RTRIM(s.Puesto) = :puesto';
      $params['puesto'] = $puesto;
    }

    return [$where, $params, $meta];
  }

  /**
   * Acumulado de las formas de pago de efectivo (Agrupacion = 0) de esas sesiones.
   *
   * @param array<string, mixed> $params
   */
  private function efectivo(string $sqlWhere, array $params): float
  {
    try {
      $st = $this->pdo->prepare(
        "SELECT SUM(ISNULL(a.Acumulado, 0)) AS efectivo
         FROM Arqueo a
         INNER JOIN Sesiones s
           ON s.Empresa = a.Empresa AND s.Puesto = a.Puesto AND s.Sesion = a.Sesion
         INNER JOIN FormasPago f ON RTRIM(f.Codigo) = RTRIM(a.Codigo)
         WHERE ISNULL(f.Agrupacion, 0) = 0 AND {$sqlWhere}"
      );
      $st->execute($params);
      return round((float) $st->fetchColumn(), 2);
    } catch (\Throwable $e) {
      return 0.0;
    }
  }

  private function fmtDate(mixed $value): ?string
  {
    if ($value === null || $value === '') {
      return null;
    }
    if ($value instanceof \DateTimeInterface) {
      return $value->format('Y-m-d H:i:s');
    }
    $text = trim((string) $value);
    return $text !== '' ? $text : null;
  }
}
