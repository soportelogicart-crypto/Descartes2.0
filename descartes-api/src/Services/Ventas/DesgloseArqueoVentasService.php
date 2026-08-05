<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Ventas;

use Descartes\Api\Support\SimplePdf;
use PDO;

/**
 * Informe legacy «Desglose de Arqueo» / «… Registradora».
 * Fuente: AlbaranesVentasCab (no Moneda01–20).
 */
final class DesgloseArqueoVentasService
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
    $formato = strtolower(trim((string) ($query['formato'] ?? 'desglosado')));
    if (!in_array($formato, ['desglosado', 'resumido'], true)) {
      $formato = 'desglosado';
    }
    $subtotal = strtoupper(trim((string) ($query['subtotal'] ?? 'F')));
    if (!in_array($subtotal, ['F', 'P', 'S', 'V'], true)) {
      $subtotal = 'F';
    }
    $reservas = strtolower(trim((string) ($query['reservas'] ?? 'excluidas')));
    $albaranesModo = strtolower(trim((string) ($query['albaranes'] ?? 'todos')));

    [$where, $params] = $this->buildWhere($query, $reservas, $albaranesModo);
    $order = $this->orderBy($subtotal);

    $sql = "SELECT TOP 5000
              c.Empresa, c.Tipo, c.Albaran, c.Fecha, c.Puesto, c.Sesion, c.Vendedor,
              c.Cliente, c.RazonSocial, c.Importe, c.Factura, c.FacturaTipo,
              c.Fpago1, c.Fpago2, c.ImpFpago1, c.ImpFpago2,
              f1.Agrupacion AS Agrup1, f2.Agrupacion AS Agrup2
            FROM AlbaranesVentasCab c
            LEFT JOIN FormasPago f1 ON f1.Codigo = c.Fpago1
            LEFT JOIN FormasPago f2 ON f2.Codigo = c.Fpago2
            WHERE {$where}
            ORDER BY {$order}";
    $st = $this->pdo->prepare($sql);
    $st->execute($params);

    $lineas = [];
    $grupos = [];
    $totales = $this->emptyTotales();
    $agrupacion = [
      'efectivo' => 0.0,
      'cheques' => 0.0,
      'tarjetas' => 0.0,
      'credito' => 0.0,
      'vales' => 0.0,
      'otros' => 0.0,
    ];

    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $mapped = $this->mapLinea($row);
      $clave = $this->claveGrupo($mapped, $subtotal);

      if (!isset($grupos[$clave])) {
        $grupos[$clave] = [
          'clave' => $clave,
          'etiqueta' => $this->etiquetaGrupo($mapped, $subtotal),
          'contado' => 0.0,
          'credito' => 0.0,
          'total' => 0.0,
          'lineas' => [],
        ];
      }

      $grupos[$clave]['contado'] = round($grupos[$clave]['contado'] + $mapped['importeContado'], 2);
      $grupos[$clave]['credito'] = round($grupos[$clave]['credito'] + $mapped['importeCredito'], 2);
      $grupos[$clave]['total'] = round($grupos[$clave]['total'] + $mapped['importe'], 2);
      if ($formato === 'desglosado') {
        $grupos[$clave]['lineas'][] = $mapped;
      }

      $totales['contado'] = round($totales['contado'] + $mapped['importeContado'], 2);
      $totales['credito'] = round($totales['credito'] + $mapped['importeCredito'], 2);
      $totales['total'] = round($totales['total'] + $mapped['importe'], 2);
      $totales['documentos']++;

      foreach ($mapped['desgloseAgrupacion'] as $k => $v) {
        $agrupacion[$k] = round($agrupacion[$k] + $v, 2);
      }

      if ($formato === 'desglosado') {
        $lineas[] = $mapped;
      }
    }

    return [
      'formato' => $formato,
      'subtotal' => $subtotal,
      'reservas' => $reservas,
      'albaranes' => $albaranesModo,
      'filtros' => [
        'empresaDesde' => (string) ($query['empresaDesde'] ?? $query['empresa'] ?? ''),
        'empresaHasta' => (string) ($query['empresaHasta'] ?? $query['empresa'] ?? ''),
        'puestoDesde' => (string) ($query['puestoDesde'] ?? $query['puesto'] ?? ''),
        'puestoHasta' => (string) ($query['puestoHasta'] ?? $query['puesto'] ?? ''),
        'sesionDesde' => (int) ($query['sesionDesde'] ?? $query['sesion'] ?? 0),
        'sesionHasta' => (int) ($query['sesionHasta'] ?? $query['sesion'] ?? 0),
        'fechaDesde' => (string) ($query['fechaDesde'] ?? ''),
        'fechaHasta' => (string) ($query['fechaHasta'] ?? ''),
        'vendedorDesde' => (string) ($query['vendedorDesde'] ?? ''),
        'vendedorHasta' => (string) ($query['vendedorHasta'] ?? ''),
        'clienteDesde' => (string) ($query['clienteDesde'] ?? ''),
        'clienteHasta' => (string) ($query['clienteHasta'] ?? ''),
        'albaranDesde' => (int) ($query['albaranDesde'] ?? 0),
        'albaranHasta' => (int) ($query['albaranHasta'] ?? 0),
        'fpagoDesde' => (string) ($query['fpagoDesde'] ?? ''),
        'fpagoHasta' => (string) ($query['fpagoHasta'] ?? ''),
      ],
      'grupos' => array_values($grupos),
      'lineas' => $lineas,
      'totales' => $totales,
      'porAgrupacion' => $agrupacion,
    ];
  }

  /** @param array<string, mixed> $query */
  public function informePdf(array $query): string
  {
    $data = $this->consultar($query);
    $pdf = new SimplePdf();
    $pdf->title('Desglose de arqueo');
    $pdf->text(
      'Formato ' . $data['formato'] . ' · Subtotal ' . $data['subtotal']
      . ' · Docs ' . (int) $data['totales']['documentos'],
      10
    );
    $f = $data['filtros'];
    $pdf->text(
      'Tienda ' . ($f['empresaDesde'] ?: '—') . '…' . ($f['empresaHasta'] ?: '—')
      . '  Puesto ' . ($f['puestoDesde'] ?: '—') . '…' . ($f['puestoHasta'] ?: '—')
      . '  Fecha ' . ($f['fechaDesde'] ?: '—') . '…' . ($f['fechaHasta'] ?: '—'),
      9
    );
    $pdf->spacer(8);

    foreach ($data['grupos'] as $g) {
      $pdf->text($g['etiqueta'], 11, true);
      if ($data['formato'] === 'desglosado') {
        $rows = [];
        foreach ($g['lineas'] as $l) {
          $rows[] = [
            (string) $l['albaran'],
            (string) $l['fechaCorta'],
            (string) $l['docTipo'],
            (string) ($l['fpago1'] ?: ''),
            number_format((float) $l['importeContado'], 2, '.', ''),
            number_format((float) $l['importeCredito'], 2, '.', ''),
            number_format((float) $l['importe'], 2, '.', ''),
          ];
        }
        if ($rows !== []) {
          $pdf->table(
            ['Alb', 'Fecha', 'Tipo', 'FP', 'Contado', 'Credito', 'Total'],
            $rows,
            [55, 55, 35, 30, 70, 70, 70]
          );
        }
      }
      $pdf->text(
        'Subtotal — Contado ' . number_format((float) $g['contado'], 2, '.', '')
        . '  Credito ' . number_format((float) $g['credito'], 2, '.', '')
        . '  Total ' . number_format((float) $g['total'], 2, '.', ''),
        10,
        true
      );
      $pdf->spacer(6);
    }

    $t = $data['totales'];
    $a = $data['porAgrupacion'];
    $pdf->spacer(4);
    $pdf->text(
      'TOTALES — Contado ' . number_format((float) $t['contado'], 2, '.', '')
      . '  Credito ' . number_format((float) $t['credito'], 2, '.', '')
      . '  Total ' . number_format((float) $t['total'], 2, '.', ''),
      11,
      true
    );
    $pdf->text(
      'Efectivo ' . number_format((float) $a['efectivo'], 2, '.', '')
      . '  Cheques ' . number_format((float) $a['cheques'], 2, '.', '')
      . '  Tarjetas ' . number_format((float) $a['tarjetas'], 2, '.', '')
      . '  Credito ' . number_format((float) $a['credito'], 2, '.', '')
      . '  Vales ' . number_format((float) $a['vales'], 2, '.', '')
      . '  Otros ' . number_format((float) $a['otros'], 2, '.', ''),
      9
    );

    return $pdf->build();
  }

  /** Texto plano para térmica (Registradora). */
  public function textoTermico(array $query): string
  {
    $data = $this->consultar($query);
    $out = [];
    $out[] = 'DESGLOSE DE ARQUEO';
    $out[] = 'Formato: ' . $data['formato'] . '  Subtotal: ' . $data['subtotal'];
    $out[] = str_repeat('-', 42);

    foreach ($data['grupos'] as $g) {
      $out[] = $g['etiqueta'];
      if ($data['formato'] === 'desglosado') {
        foreach ($g['lineas'] as $l) {
          $out[] = sprintf(
            '%s %s %s %s %s',
            str_pad((string) $l['albaran'], 8, ' ', STR_PAD_LEFT),
            $l['fechaCorta'],
            str_pad($l['docTipo'], 3),
            str_pad((string) ($l['fpago1'] ?: ''), 2),
            number_format((float) $l['importe'], 2, '.', '')
          );
        }
      }
      $out[] = sprintf(
        '  SUB C:%s R:%s T:%s',
        number_format((float) $g['contado'], 2, '.', ''),
        number_format((float) $g['credito'], 2, '.', ''),
        number_format((float) $g['total'], 2, '.', '')
      );
      $out[] = '';
    }

    $t = $data['totales'];
    $a = $data['porAgrupacion'];
    $out[] = str_repeat('=', 42);
    $out[] = sprintf(
      'TOTAL C:%s R:%s T:%s',
      number_format((float) $t['contado'], 2, '.', ''),
      number_format((float) $t['credito'], 2, '.', ''),
      number_format((float) $t['total'], 2, '.', '')
    );
    $out[] = sprintf(
      'Efec %s Cheq %s Tarj %s',
      number_format((float) $a['efectivo'], 2, '.', ''),
      number_format((float) $a['cheques'], 2, '.', ''),
      number_format((float) $a['tarjetas'], 2, '.', '')
    );
    $out[] = sprintf(
      'Cred %s Vale %s Otro %s',
      number_format((float) $a['credito'], 2, '.', ''),
      number_format((float) $a['vales'], 2, '.', ''),
      number_format((float) $a['otros'], 2, '.', '')
    );

    return implode("\n", $out);
  }

  /**
   * @param array<string, mixed> $query
   * @return array{0: string, 1: array<string, mixed>}
   */
  private function buildWhere(array $query, string $reservas, string $albaranesModo): array
  {
    $where = ['1=1'];
    $params = [];

    $this->addRange($where, $params, 'c.Empresa', $query, 'empresa', 'empresaDesde', 'empresaHasta', false);
    $this->addRange($where, $params, 'c.Puesto', $query, 'puesto', 'puestoDesde', 'puestoHasta', false);
    $this->addRange($where, $params, 'c.Vendedor', $query, 'vendedor', 'vendedorDesde', 'vendedorHasta', false);
    $this->addRange($where, $params, 'c.Cliente', $query, 'cliente', 'clienteDesde', 'clienteHasta', false);
    $this->addRangeInt($where, $params, 'c.Sesion', $query, 'sesion', 'sesionDesde', 'sesionHasta');
    $this->addRangeInt($where, $params, 'c.Albaran', $query, 'albaran', 'albaranDesde', 'albaranHasta');

    if ($this->fechaOk($query['fechaDesde'] ?? null)) {
      $where[] = 'c.Fecha >= CONVERT(datetime, :fechaDesde, 120)';
      $params['fechaDesde'] = substr((string) $query['fechaDesde'], 0, 10) . ' 00:00:00';
    }
    if ($this->fechaOk($query['fechaHasta'] ?? null)) {
      $where[] = 'c.Fecha <= CONVERT(datetime, :fechaHasta, 120)';
      $params['fechaHasta'] = substr((string) $query['fechaHasta'], 0, 10) . ' 23:59:59';
    }

    $fpDesde = trim((string) ($query['fpagoDesde'] ?? ''));
    $fpHasta = trim((string) ($query['fpagoHasta'] ?? ''));
    if ($fpDesde !== '' && $fpHasta !== '') {
      $where[] = '((c.Fpago1 >= :fpD AND c.Fpago1 <= :fpH) OR (c.Fpago2 >= :fpD2 AND c.Fpago2 <= :fpH2))';
      $params['fpD'] = $fpDesde;
      $params['fpH'] = $fpHasta;
      $params['fpD2'] = $fpDesde;
      $params['fpH2'] = $fpHasta;
    } elseif ($fpDesde !== '') {
      $where[] = '(c.Fpago1 = :fp1 OR c.Fpago2 = :fp2)';
      $params['fp1'] = $fpDesde;
      $params['fp2'] = $fpDesde;
    }

    if ($albaranesModo === 'excluir_facturados') {
      $where[] = '(c.Factura IS NULL OR c.Factura = 0)';
    } elseif ($albaranesModo === 'solo_tickets_facturas') {
      $where[] = "(c.FacturaTipo IN ('T','F','A') AND ISNULL(c.Factura, 0) <> 0)";
    } elseif ($albaranesModo === 'facturas_contado') {
      $where[] = "(c.FacturaTipo IN ('F','A') AND ISNULL(c.Factura, 0) <> 0 AND ISNULL(c.Fpago1, '') <> '')";
    }

    // Legacy: albarán de reserva = FacturaTipo 'Z' (Crystal DesgloseArqueo / Registradora).
    if ($reservas !== 'incluidas') {
      $where[] = "(c.FacturaTipo IS NULL OR LTRIM(RTRIM(c.FacturaTipo)) = '' OR c.FacturaTipo <> 'Z')";
    }

    return [implode(' AND ', $where), $params];
  }

  /** @param list<string> $where @param array<string, mixed> $params @param array<string, mixed> $query */
  private function addRange(
    array &$where,
    array &$params,
    string $col,
    array $query,
    string $single,
    string $desdeKey,
    string $hastaKey,
    bool $like
  ): void {
    $desde = trim((string) ($query[$desdeKey] ?? $query[$single] ?? ''));
    $hasta = trim((string) ($query[$hastaKey] ?? $query[$single] ?? ''));
    if ($desde === '' && $hasta === '') {
      return;
    }
    if ($desde !== '' && $hasta !== '' && $desde !== $hasta) {
      $kd = str_replace('.', '_', $col) . '_d';
      $kh = str_replace('.', '_', $col) . '_h';
      $where[] = "{$col} >= :{$kd} AND {$col} <= :{$kh}";
      $params[$kd] = $desde;
      $params[$kh] = $hasta;
      return;
    }
    $v = $desde !== '' ? $desde : $hasta;
    $k = str_replace('.', '_', $col) . '_eq';
    if ($like) {
      $where[] = "{$col} LIKE :{$k}";
      $params[$k] = '%' . $v . '%';
    } else {
      $where[] = "{$col} = :{$k}";
      $params[$k] = $v;
    }
  }

  /** @param list<string> $where @param array<string, mixed> $params @param array<string, mixed> $query */
  private function addRangeInt(
    array &$where,
    array &$params,
    string $col,
    array $query,
    string $single,
    string $desdeKey,
    string $hastaKey
  ): void {
    $desde = (int) ($query[$desdeKey] ?? $query[$single] ?? 0);
    $hasta = (int) ($query[$hastaKey] ?? $query[$single] ?? 0);
    if ($desde <= 0 && $hasta <= 0) {
      return;
    }
    if ($desde > 0 && $hasta > 0 && $desde !== $hasta) {
      $kd = str_replace('.', '_', $col) . '_d';
      $kh = str_replace('.', '_', $col) . '_h';
      $where[] = "{$col} >= :{$kd} AND {$col} <= :{$kh}";
      $params[$kd] = $desde;
      $params[$kh] = $hasta;
      return;
    }
    $v = $desde > 0 ? $desde : $hasta;
    $k = str_replace('.', '_', $col) . '_eq';
    $where[] = "{$col} = :{$k}";
    $params[$k] = $v;
  }

  private function orderBy(string $subtotal): string
  {
    return match ($subtotal) {
      'P' => 'c.Empresa, c.Puesto, c.Albaran',
      'S' => 'c.Empresa, c.Puesto, c.Sesion, c.Albaran',
      'V' => 'c.Empresa, c.Vendedor, c.Albaran',
      default => 'c.Empresa, c.Fecha, c.Albaran',
    };
  }

  /** @return array{contado: float, credito: float, total: float, documentos: int} */
  private function emptyTotales(): array
  {
    return ['contado' => 0.0, 'credito' => 0.0, 'total' => 0.0, 'documentos' => 0];
  }

  /** @param array<string, mixed> $row @return array<string, mixed> */
  private function mapLinea(array $row): array
  {
    $importe = round((float) ($row['Importe'] ?? 0), 2);
    $factTipo = trim((string) ($row['FacturaTipo'] ?? ''));
    $factura = (int) ($row['Factura'] ?? 0);
    $fp1 = trim((string) ($row['Fpago1'] ?? ''));
    $fp2 = trim((string) ($row['Fpago2'] ?? ''));
    $imp1 = round((float) ($row['ImpFpago1'] ?? 0), 2);
    $imp2 = round((float) ($row['ImpFpago2'] ?? 0), 2);

    $docTipo = $this->docTipo($factTipo, $factura, $fp1);
    $esCredito = $docTipo === 'ALB' || $docTipo === 'HAB';

    $importeContado = 0.0;
    $importeCredito = 0.0;
    $desglose = [
      'efectivo' => 0.0,
      'cheques' => 0.0,
      'tarjetas' => 0.0,
      'credito' => 0.0,
      'vales' => 0.0,
      'otros' => 0.0,
    ];

    if ($esCredito) {
      $importeCredito = $importe;
      $desglose['credito'] = $importe;
    } else {
      if ($imp1 == 0.0 && $imp2 == 0.0) {
        $imp1 = $importe;
      }
      $importeContado = round($imp1 + $imp2, 2);
      $this->sumAgrupacion($desglose, (int) ($row['Agrup1'] ?? -1), $imp1);
      if ($imp2 != 0.0) {
        $this->sumAgrupacion($desglose, (int) ($row['Agrup2'] ?? -1), $imp2);
      }
    }

    $fecha = $row['Fecha'] ?? null;
    $fechaIso = is_string($fecha) ? substr($fecha, 0, 19) : (string) $fecha;
    $fechaCorta = '';
    if ($fechaIso !== '') {
      try {
        $fechaCorta = (new \DateTimeImmutable($fechaIso))->format('d/m/y');
      } catch (\Throwable $e) {
        $fechaCorta = substr($fechaIso, 0, 10);
      }
    }

    return [
      'empresa' => trim((string) ($row['Empresa'] ?? '')),
      'tipo' => trim((string) ($row['Tipo'] ?? '')),
      'albaran' => (int) ($row['Albaran'] ?? 0),
      'fecha' => $fechaIso,
      'fechaCorta' => $fechaCorta,
      'puesto' => trim((string) ($row['Puesto'] ?? '')),
      'sesion' => (int) ($row['Sesion'] ?? 0),
      'vendedor' => trim((string) ($row['Vendedor'] ?? '')),
      'cliente' => trim((string) ($row['Cliente'] ?? '')),
      'razonSocial' => $row['RazonSocial'] ?? null,
      'docTipo' => $docTipo,
      'factura' => $factura,
      'fpago1' => $fp1 !== '' ? $fp1 : null,
      'fpago2' => $fp2 !== '' ? $fp2 : null,
      'impFpago1' => $imp1,
      'impFpago2' => $imp2,
      'importe' => $importe,
      'importeContado' => $importeContado,
      'importeCredito' => $importeCredito,
      'desgloseAgrupacion' => $desglose,
    ];
  }

  private function docTipo(string $factTipo, int $factura, string $fpago1): string
  {
    if ($factTipo === 'H' && $factura !== 0) {
      return 'HAB';
    }
    if ($fpago1 === '' || $factTipo === '' || $factTipo === 'H') {
      return 'ALB';
    }
    if ($factTipo === 'T') {
      return 'TIC';
    }
    if ($factTipo === 'F' || $factTipo === 'A') {
      return 'FAC';
    }
    return 'ALB';
  }

  /** @param array<string, float> $bag */
  private function sumAgrupacion(array &$bag, int $agrupacion, float $importe): void
  {
    $key = match ($agrupacion) {
      0 => 'efectivo',
      1 => 'cheques',
      2 => 'tarjetas',
      3 => 'credito',
      4 => 'vales',
      default => 'otros',
    };
    $bag[$key] = round($bag[$key] + $importe, 2);
  }

  /** @param array<string, mixed> $l */
  private function claveGrupo(array $l, string $subtotal): string
  {
    return match ($subtotal) {
      'P' => (string) $l['puesto'],
      'S' => $l['puesto'] . '-' . $l['sesion'],
      'V' => (string) $l['vendedor'],
      default => substr((string) $l['fecha'], 0, 10),
    };
  }

  /** @param array<string, mixed> $l */
  private function etiquetaGrupo(array $l, string $subtotal): string
  {
    return match ($subtotal) {
      'P' => 'Puesto ' . $l['puesto'],
      'S' => 'Puesto ' . $l['puesto'] . ' · Sesion ' . $l['sesion'],
      'V' => 'Vendedor ' . ($l['vendedor'] ?: '—'),
      default => 'Fecha ' . ($l['fechaCorta'] ?: substr((string) $l['fecha'], 0, 10)),
    };
  }

  private function fechaOk(mixed $v): bool
  {
    if (!is_string($v) || $v === '') {
      return false;
    }
    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}/', $v);
  }
}
