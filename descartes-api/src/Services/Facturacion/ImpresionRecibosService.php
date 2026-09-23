<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Facturacion;

use Descartes\Api\Support\SimplePdf;
use PDO;

final class ImpresionRecibosService
{
  public function __construct(private PDO $pdo)
  {
  }

  /**
   * @param array<string, mixed> $query
   * @return array{items: list<array<string, mixed>>, totales: array{recibos: int, importe: float}}
   */
  public function listar(array $query): array
  {
    [$where, $params] = $this->buildWhere($query);
    $stmt = $this->pdo->prepare(
      // El grid filtra por columna sobre lo devuelto: conviene no recortar de más.
      "SELECT TOP 5000
          r.Empresa, r.FacturaTipo, r.Factura, r.Recibo, r.Vencimiento, r.Importe,
          ISNULL(r.Liquidado, 0) AS Liquidado, ISNULL(r.Remesado, 0) AS Remesado,
          f.Fecha, f.Cliente, f.Fpago,
          c.RazonSocial, c.NIF,
          fp.Descripcion AS FpagoDescripcion
       FROM Recibos r
       INNER JOIN Facturas f
         ON f.Empresa=r.Empresa AND f.FacturaTipo=r.FacturaTipo AND f.Factura=r.Factura
       LEFT JOIN Clientes c ON c.Codigo=f.Cliente
       LEFT JOIN FormasPago fp ON fp.Codigo=f.Fpago
       WHERE {$where}
       ORDER BY r.Vencimiento, r.Empresa, r.FacturaTipo, r.Factura, r.Recibo"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $items = [];
    $total = 0.0;
    foreach ($rows as $row) {
      $importe = (float) ($row['Importe'] ?? 0);
      $total += $importe;
      $items[] = [
        'empresa' => trim((string) ($row['Empresa'] ?? '')),
        'facturaTipo' => trim((string) ($row['FacturaTipo'] ?? '')),
        'factura' => (int) ($row['Factura'] ?? 0),
        'recibo' => (int) ($row['Recibo'] ?? 0),
        'fechaFactura' => $this->fmtFecha($row['Fecha'] ?? null),
        'vencimiento' => $this->fmtFecha($row['Vencimiento'] ?? null),
        'cliente' => trim((string) ($row['Cliente'] ?? '')),
        'razonSocial' => trim((string) ($row['RazonSocial'] ?? '')),
        'nif' => trim((string) ($row['NIF'] ?? '')),
        'formaPago' => trim((string) ($row['Fpago'] ?? '')),
        'formaPagoDescripcion' => trim((string) ($row['FpagoDescripcion'] ?? '')),
        'importe' => $importe,
        'liquidado' => !empty($row['Liquidado']),
        'remesado' => !empty($row['Remesado']),
      ];
    }

    return [
      'items' => $items,
      'totales' => ['recibos' => count($items), 'importe' => round($total, 2)],
    ];
  }

  /** @param array<string, mixed> $body */
  public function informePdf(array $body): string
  {
    $seleccion = $body['recibos'] ?? [];
    if (!is_array($seleccion) || $seleccion === []) {
      throw new \InvalidArgumentException('Seleccione al menos un recibo');
    }

    $pdf = new SimplePdf();
    $impresos = 0;
    foreach ($seleccion as $key) {
      if (!is_array($key)) {
        continue;
      }
      $recibo = $this->cargar(
        trim((string) ($key['empresa'] ?? '')),
        strtoupper(trim((string) ($key['facturaTipo'] ?? 'F'))),
        (int) ($key['factura'] ?? 0),
        (int) ($key['recibo'] ?? 0)
      );
      if ($recibo === null) {
        continue;
      }
      if ($impresos > 0) {
        $pdf->pageBreak();
      }
      $this->render($pdf, $recibo);
      $impresos++;
    }

    if ($impresos === 0) {
      throw new \RuntimeException('No se encontró ningún recibo para imprimir', 404);
    }
    return $pdf->build();
  }

  /** @return array{0: string, 1: array<string, mixed>} */
  private function buildWhere(array $query): array
  {
    $empresa = trim((string) ($query['empresa'] ?? ''));
    if ($empresa === '') {
      throw new \InvalidArgumentException('Indique tienda (empresa)');
    }
    $where = ['r.Empresa = :empresa'];
    $params = ['empresa' => $empresa];

    $estado = strtolower(trim((string) ($query['estado'] ?? 'pendientes')));
    if ($estado === 'pendientes') {
      $where[] = 'ISNULL(r.Liquidado, 0) = 0';
    } elseif ($estado === 'liquidados') {
      $where[] = 'ISNULL(r.Liquidado, 0) <> 0';
    }

    foreach ([
      ['vencimientoDesde', 'r.Vencimiento >= CONVERT(datetime, :vencimientoDesde, 120)', ' 00:00:00'],
      ['vencimientoHasta', 'r.Vencimiento <= CONVERT(datetime, :vencimientoHasta, 120)', ' 23:59:59'],
    ] as [$key, $sql, $hora]) {
      $valor = trim((string) ($query[$key] ?? ''));
      if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
        $where[] = $sql;
        $params[$key] = $valor . $hora;
      }
    }

    $cliente = trim((string) ($query['cliente'] ?? ''));
    if ($cliente !== '') {
      $where[] = 'f.Cliente = :cliente';
      $params['cliente'] = $cliente;
    }
    $factura = (int) ($query['factura'] ?? 0);
    if ($factura > 0) {
      $where[] = 'r.Factura = :factura';
      $params['factura'] = $factura;
    }
    return [implode(' AND ', $where), $params];
  }

  /** @return array<string, mixed>|null */
  private function cargar(string $empresa, string $tipo, int $factura, int $recibo): ?array
  {
    if ($empresa === '' || $factura <= 0 || $recibo <= 0) {
      return null;
    }
    $stmt = $this->pdo->prepare(
      'SELECT r.*, f.Fecha, f.Cliente, f.Fpago,
              c.RazonSocial, c.NIF, c.Direccion, c.CodigoPostal, c.Poblacion, c.Provincia,
              fp.Descripcion AS FpagoDescripcion,
              e.Nombre AS EmpNombre, e.NombreFiscal AS EmpNombreFiscal, e.NIF AS EmpNif,
              e.Direccion AS EmpDireccion, e.CodigoPostal AS EmpCodigoPostal,
              e.Poblacion AS EmpPoblacion
       FROM Recibos r
       INNER JOIN Facturas f
         ON f.Empresa=r.Empresa AND f.FacturaTipo=r.FacturaTipo AND f.Factura=r.Factura
       LEFT JOIN Clientes c ON c.Codigo=f.Cliente
       LEFT JOIN FormasPago fp ON fp.Codigo=f.Fpago
       INNER JOIN Empresas_Ges e ON e.Codigo=r.Empresa
       WHERE r.Empresa=:empresa AND r.FacturaTipo=:tipo
         AND r.Factura=:factura AND r.Recibo=:recibo'
    );
    $stmt->execute([
      'empresa' => $empresa,
      'tipo' => $tipo,
      'factura' => $factura,
      'recibo' => $recibo,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row !== false ? $row : null;
  }

  /** @param array<string, mixed> $r */
  private function render(SimplePdf $pdf, array $r): void
  {
    $pdf->title('RECIBO ' . (int) $r['Recibo']);
    $empresa = trim((string) ($r['EmpNombreFiscal'] ?: $r['EmpNombre'] ?: ''));
    $pdf->text($empresa, 11, true);
    $pdf->text(
      trim((string) ($r['EmpDireccion'] ?? '')) . '  '
      . trim((string) ($r['EmpCodigoPostal'] ?? '')) . ' '
      . trim((string) ($r['EmpPoblacion'] ?? ''))
      . '  NIF ' . trim((string) ($r['EmpNif'] ?? '')),
      9
    );
    $pdf->spacer(8);
    $pdf->text(
      'Factura: ' . trim((string) $r['FacturaTipo']) . '-' . (int) $r['Factura']
      . '   Fecha: ' . $this->fmtFecha($r['Fecha'] ?? null),
      10
    );
    $pdf->text(
      'Cliente: ' . trim((string) ($r['Cliente'] ?? '')) . '  '
      . trim((string) ($r['RazonSocial'] ?? ''))
      . '  NIF ' . trim((string) ($r['NIF'] ?? '')),
      10
    );
    $pdf->text(
      trim((string) ($r['Direccion'] ?? '')) . '  '
      . trim((string) ($r['CodigoPostal'] ?? '')) . ' '
      . trim((string) ($r['Poblacion'] ?? '')) . ' '
      . trim((string) ($r['Provincia'] ?? '')),
      9
    );
    $forma = trim((string) ($r['Fpago'] ?? ''));
    $descripcion = trim((string) ($r['FpagoDescripcion'] ?? ''));
    $pdf->text('Forma de pago: ' . $forma . ($descripcion !== '' ? ' - ' . $descripcion : ''), 9);
    $pdf->spacer(14);
    $pdf->text('Vencimiento: ' . $this->fmtFecha($r['Vencimiento'] ?? null), 12, true);
    $pdf->text('IMPORTE: ' . $this->num((float) ($r['Importe'] ?? 0)) . ' EUR', 16, true);
  }

  private function fmtFecha(mixed $fecha): string
  {
    if ($fecha === null || $fecha === '') {
      return '';
    }
    if ($fecha instanceof \DateTimeInterface) {
      return $fecha->format('d/m/Y');
    }
    $valor = (string) $fecha;
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $valor, $m)) {
      return "{$m[3]}/{$m[2]}/{$m[1]}";
    }
    return $valor;
  }

  private function num(float $importe): string
  {
    return number_format($importe, 2, ',', '.');
  }
}
