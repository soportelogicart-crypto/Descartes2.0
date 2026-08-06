<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Facturacion;

use Descartes\Api\Support\SimplePdf;
use PDO;

/**
 * Impresión de facturas (legacy FacturaStd.rpt / Prg_ImpFacturas).
 * MVP: listar + PDF SimplePdf + marcar Impresa=1.
 */
final class ImpresionFacturasService
{
  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  /**
   * @param array<string, mixed> $query
   * @return array{items: list<array<string, mixed>>, totales: array{facturas: int, importe: float}}
   */
  public function listar(array $query): array
  {
    [$where, $params] = $this->buildWhere($query);

    $orden = strtolower(trim((string) ($query['ordenFecha'] ?? 'desc')));
    if ($orden === 'asc') {
      $orderSql = 'ORDER BY f.Fecha ASC, f.FacturaTipo ASC, f.Factura ASC';
    } else {
      $orderSql = 'ORDER BY f.Fecha DESC, f.FacturaTipo DESC, f.Factura DESC';
    }

    $sql = "SELECT TOP 500
        f.Empresa, f.FacturaTipo, f.Factura, f.Fecha, f.Cliente, f.Importe, f.Fpago,
        ISNULL(f.Impresa, 0) AS Impresa, f.Estado,
        ISNULL(f.FacturaContadoDiferida, 0) AS FacturaContadoDiferida,
        c.RazonSocial, c.NIF
      FROM Facturas f
      INNER JOIN Clientes c ON c.Codigo = f.Cliente
      WHERE {$where}
      {$orderSql}";

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $items = [];
    $importe = 0.0;
    foreach ($rows as $r) {
      $imp = (float) ($r['Importe'] ?? 0);
      $importe += $imp;
      $estado = strtoupper(trim((string) ($r['Estado'] ?? '')));
      $contadoDif = !empty($r['FacturaContadoDiferida']);
      // Crédito/generación (G) o contado diferido → diferida; resto contado.
      $tipoCobro = ($estado === 'G' || $contadoDif) ? 'diferida' : 'contado';
      $items[] = [
        'empresa' => trim((string) ($r['Empresa'] ?? '')),
        'facturaTipo' => trim((string) ($r['FacturaTipo'] ?? '')),
        'factura' => (int) ($r['Factura'] ?? 0),
        'fecha' => $this->fmtFecha($r['Fecha'] ?? null),
        'cliente' => trim((string) ($r['Cliente'] ?? '')),
        'razonSocial' => trim((string) ($r['RazonSocial'] ?? '')),
        'nif' => trim((string) ($r['NIF'] ?? '')),
        'importe' => $imp,
        'fpago' => trim((string) ($r['Fpago'] ?? '')),
        'impresa' => !empty($r['Impresa']),
        'estado' => $estado,
        'facturaContadoDiferida' => $contadoDif,
        'tipoCobro' => $tipoCobro,
      ];
    }

    return [
      'items' => $items,
      'totales' => [
        'facturas' => count($items),
        'importe' => round($importe, 2),
      ],
    ];
  }

  /**
   * @param array<string, mixed> $body
   * @return string PDF bytes
   */
  public function informePdf(array $body): string
  {
    $keys = $this->resolveKeys($body);
    if ($keys === []) {
      throw new \InvalidArgumentException('Indique facturas a imprimir o filtros de búsqueda');
    }

    // Por defecto no marcar: el preview PDF no debe alterar Impresa.
    $marcar = array_key_exists('marcarImpresa', $body) && !empty($body['marcarImpresa']);
    $pdf = new SimplePdf();
    $primera = true;

    foreach ($keys as $key) {
      $doc = $this->cargarFactura($key['empresa'], $key['facturaTipo'], $key['factura']);
      if ($doc === null) {
        continue;
      }
      if (!$primera) {
        $pdf->pageBreak();
      }
      $primera = false;
      $this->renderFactura($pdf, $doc);
      if ($marcar) {
        $this->marcarImpresa($key['empresa'], $key['facturaTipo'], $key['factura']);
      }
    }

    if ($primera) {
      throw new \RuntimeException('Ninguna factura encontrada para imprimir', 404);
    }

    return $pdf->build();
  }

  /**
   * @param array<string, mixed> $body
   * @return array{marcadas: int}
   */
  public function marcar(array $body): array
  {
    $keys = $this->resolveKeys($body);
    if ($keys === []) {
      throw new \InvalidArgumentException('Indique facturas a marcar');
    }
    $n = 0;
    foreach ($keys as $key) {
      if ($this->marcarImpresa($key['empresa'], $key['facturaTipo'], $key['factura'])) {
        $n++;
      }
    }
    return ['marcadas' => $n];
  }

  /**
   * @param array<string, mixed> $body
   * @return list<array{empresa: string, facturaTipo: string, factura: int}>
   */
  private function resolveKeys(array $body): array
  {
    $sel = $body['facturas'] ?? null;
    if (is_array($sel) && $sel !== []) {
      $keys = [];
      foreach ($sel as $row) {
        if (!is_array($row)) {
          continue;
        }
        $e = trim((string) ($row['empresa'] ?? ''));
        $ft = strtoupper(trim((string) ($row['facturaTipo'] ?? 'F')));
        $f = (int) ($row['factura'] ?? 0);
        if ($e === '' || $f <= 0) {
          continue;
        }
        if (!in_array($ft, ['F', 'A'], true)) {
          $ft = 'F';
        }
        $keys[$e . '|' . $ft . '|' . $f] = [
          'empresa' => $e,
          'facturaTipo' => $ft,
          'factura' => $f,
        ];
      }
      return array_values($keys);
    }

    // Sin selección explícita: todas las del filtro (máx 100).
    $list = $this->listar($body);
    $keys = [];
    foreach (array_slice($list['items'], 0, 100) as $item) {
      $keys[] = [
        'empresa' => $item['empresa'],
        'facturaTipo' => $item['facturaTipo'],
        'factura' => $item['factura'],
      ];
    }
    return $keys;
  }

  /**
   * @param array<string, mixed> $query
   * @return array{0: string, 1: array<string, mixed>}
   */
  private function buildWhere(array $query): array
  {
    $where = ['1=1'];
    $params = [];

    // Tienda facturación: exacta o rango (legacy Desde/Hasta Tienda).
    $empresa = trim((string) ($query['empresa'] ?? ''));
    $empresaDesde = trim((string) ($query['empresaDesde'] ?? ''));
    $empresaHasta = trim((string) ($query['empresaHasta'] ?? ''));
    if ($empresaDesde !== '' || $empresaHasta !== '') {
      if ($empresaDesde !== '') {
        $where[] = 'f.Empresa >= :empresaDesde';
        $params['empresaDesde'] = $empresaDesde;
      }
      if ($empresaHasta !== '') {
        $where[] = 'f.Empresa <= :empresaHasta';
        $params['empresaHasta'] = $empresaHasta;
      }
    } elseif ($empresa !== '') {
      $where[] = 'f.Empresa = :empresa';
      $params['empresa'] = $empresa;
    } else {
      throw new \InvalidArgumentException('Indique tienda (empresa)');
    }

    // Estado impresión: pendientes / impresas / todas (legacy Combo Estado).
    $estadoImp = strtolower(trim((string) ($query['estadoImpresion'] ?? '')));
    if ($estadoImp === '') {
      // Compat: soloNoImpresas
      $soloNo = $query['soloNoImpresas'] ?? '1';
      if ($soloNo === true || $soloNo === 1 || $soloNo === '1' || $soloNo === 'true') {
        $estadoImp = 'pendientes';
      } elseif ($soloNo === false || $soloNo === 0 || $soloNo === '0' || $soloNo === 'false') {
        $estadoImp = 'todas';
      } else {
        $estadoImp = 'pendientes';
      }
    }
    if ($estadoImp === 'pendientes') {
      $where[] = 'ISNULL(f.Impresa, 0) = 0';
    } elseif ($estadoImp === 'impresas') {
      $where[] = 'ISNULL(f.Impresa, 0) <> 0';
    }

    // Tipo cobro (legacy Combo Tipo en Impresión):
    // - Diferidas: crédito/generación (Estado G) o contado diferido (FacturaContadoDiferida=1).
    // - Contado: Estado F (cobro inmediato). FacturaContadoDiferida NO es "crédito".
    $tipoCobro = strtolower(trim((string) ($query['tipoCobro'] ?? 'todas')));
    if ($tipoCobro === 'diferidas') {
      $where[] = "(RTRIM(ISNULL(f.Estado, '')) = 'G' OR ISNULL(f.FacturaContadoDiferida, 0) <> 0)";
    } elseif ($tipoCobro === 'contado') {
      $where[] = "(RTRIM(ISNULL(f.Estado, '')) = 'F' AND ISNULL(f.FacturaContadoDiferida, 0) = 0)";
    }

    // Estado documento legado (G/F) si se fuerza explícitamente.
    $estado = strtoupper(trim((string) ($query['estado'] ?? '')));
    if ($estado !== '' && $estado !== 'TODOS') {
      $where[] = 'f.Estado = :estado';
      $params['estado'] = $estado;
    }

    // Tipo factura F / A / todas.
    $tipo = strtoupper(trim((string) ($query['facturaTipo'] ?? '')));
    if ($tipo === 'F' || $tipo === 'A') {
      $where[] = 'f.FacturaTipo = :facturaTipo';
      $params['facturaTipo'] = $tipo;
    }

    $fechaDesde = trim((string) ($query['fechaDesde'] ?? ''));
    if ($fechaDesde !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaDesde)) {
      $where[] = 'f.Fecha >= CONVERT(datetime, :fechaDesde, 120)';
      $params['fechaDesde'] = $fechaDesde . ' 00:00:00';
    }
    $fechaHasta = trim((string) ($query['fechaHasta'] ?? ''));
    if ($fechaHasta !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaHasta)) {
      $where[] = 'f.Fecha <= CONVERT(datetime, :fechaHasta, 120)';
      $params['fechaHasta'] = $fechaHasta . ' 23:59:59';
    }

    $facturaDesde = (int) ($query['facturaDesde'] ?? 0);
    if ($facturaDesde > 0) {
      $where[] = 'f.Factura >= :facturaDesde';
      $params['facturaDesde'] = $facturaDesde;
    }
    $facturaHasta = (int) ($query['facturaHasta'] ?? 0);
    if ($facturaHasta > 0) {
      $where[] = 'f.Factura <= :facturaHasta';
      $params['facturaHasta'] = $facturaHasta;
    }

    $cliente = trim((string) ($query['cliente'] ?? ''));
    if ($cliente !== '') {
      $where[] = 'f.Cliente = :cliente';
      $params['cliente'] = $cliente;
    }
    $clienteDesde = trim((string) ($query['clienteDesde'] ?? ''));
    if ($clienteDesde !== '') {
      $where[] = 'f.Cliente >= :clienteDesde';
      $params['clienteDesde'] = $clienteDesde;
    }
    $clienteHasta = trim((string) ($query['clienteHasta'] ?? ''));
    if ($clienteHasta !== '') {
      $where[] = 'f.Cliente <= :clienteHasta';
      $params['clienteHasta'] = $clienteHasta;
    }

    // Actividad del cliente (legacy intervalo Actividad; Clientes.Actividad numérico).
    $actividadDesde = trim((string) ($query['actividadDesde'] ?? ''));
    if ($actividadDesde !== '' && is_numeric($actividadDesde)) {
      $where[] = 'ISNULL(c.Actividad, 0) >= :actividadDesde';
      $params['actividadDesde'] = (int) $actividadDesde;
    }
    $actividadHasta = trim((string) ($query['actividadHasta'] ?? ''));
    if ($actividadHasta !== '' && is_numeric($actividadHasta)) {
      $where[] = 'ISNULL(c.Actividad, 0) <= :actividadHasta';
      $params['actividadHasta'] = (int) $actividadHasta;
    }

    // Facturación: sujeto pasivo (legacy combo Facturación).
    $facturacion = strtolower(trim((string) ($query['facturacion'] ?? 'normal')));
    if ($facturacion === 'sujeto_pasivo') {
      $where[] = 'ISNULL(f.SujetoPasivo, 0) <> 0';
    } elseif ($facturacion === 'normal') {
      // Sin filtro estricto: incluye todos salvo si se pide solo sujeto pasivo.
    }

    // Canal impresión: email → pendientes de envío (legacy Combo Impresión).
    $canal = strtolower(trim((string) ($query['canalImpresion'] ?? 'impresora')));
    if ($canal === 'email') {
      $where[] = 'ISNULL(f.EnviadaPorEmail, 0) = 0';
    }

    return [implode(' AND ', $where), $params];
  }

  /**
   * @return array<string, mixed>|null
   */
  private function cargarFactura(string $empresa, string $facturaTipo, int $factura): ?array
  {
    $sql = "SELECT
        f.Empresa, f.FacturaTipo, f.Factura, f.Fecha, f.Cliente, f.Fpago, f.Estado,
        f.Importe, f.ImporteDtos, f.PjeDto, f.PagoACuenta,
        f.ImporteBase1, f.ImporteBase2, f.ImporteBase3, f.ImporteBase4, f.ImporteBase5, f.ImporteBase6,
        f.PjeIva1, f.PjeIva2, f.PjeIva3, f.PjeIva4, f.PjeIva5, f.PjeIva6,
        f.ImporteIva1, f.ImporteIva2, f.ImporteIva3, f.ImporteIva4, f.ImporteIva5, f.ImporteIva6,
        f.PjeRec1, f.PjeRec2, f.PjeRec3, f.PjeRec4, f.PjeRec5, f.PjeRec6,
        f.ImporteRec1, f.ImporteRec2, f.ImporteRec3, f.ImporteRec4, f.ImporteRec5, f.ImporteRec6,
        c.RazonSocial, c.RazonSocial2, c.NIF, c.Direccion, c.Poblacion, c.CodigoPostal, c.Provincia,
        e.Nombre AS EmpNombre, e.NombreFiscal AS EmpNombreFiscal, e.NIF AS EmpNif,
        e.Direccion AS EmpDireccion, e.Poblacion AS EmpPoblacion,
        e.CodigoPostal AS EmpCodigoPostal, e.Provincia AS EmpProvincia
      FROM Facturas f
      INNER JOIN Clientes c ON c.Codigo = f.Cliente
      INNER JOIN Empresas e ON e.Codigo = f.Empresa
      WHERE f.Empresa = :e AND f.FacturaTipo = :ft AND f.Factura = :f";

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute(['e' => $empresa, 'ft' => $facturaTipo, 'f' => $factura]);
    $cab = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($cab === false) {
      return null;
    }

    $lineas = $this->cargarLineas($empresa, $facturaTipo, $factura);
    $albaranes = $this->cargarAlbaranesResumen($empresa, $facturaTipo, $factura);

    return [
      'cab' => $cab,
      'lineas' => $lineas,
      'albaranes' => $albaranes,
    ];
  }

  /**
   * @return list<array<string, mixed>>
   */
  private function cargarLineas(string $empresa, string $facturaTipo, int $factura): array
  {
    try {
      $sql = "SELECT TOP 2000
          a.Albaran, a.Fecha AS FechaAlb,
          l.NroLin, l.Articulo, l.Descripcion, l.Cantidad, l.Precio, l.PjeDto, l.Importe
        FROM AlbaranesVentasCab a
        INNER JOIN AlbaranesVentasLin l
          ON l.Empresa = a.Empresa AND l.Tipo = a.Tipo AND l.Albaran = a.Albaran
        WHERE a.FacturaTipo = :ft AND a.Factura = :f
          AND (a.EmpresaFacturacion = :e OR (ISNULL(a.EmpresaFacturacion, '') = '' AND a.Empresa = :e2))
          AND (l.Articulo IS NULL OR l.Articulo <> 'NO')
        ORDER BY a.Albaran, l.NroLin";
      $stmt = $this->pdo->prepare($sql);
      $stmt->execute(['ft' => $facturaTipo, 'f' => $factura, 'e' => $empresa, 'e2' => $empresa]);
      return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $ex) {
      // EmpresaFacturacion puede faltar en esquemas antiguos.
      try {
        $sql = "SELECT TOP 2000
            a.Albaran, a.Fecha AS FechaAlb,
            l.NroLin, l.Articulo, l.Descripcion, l.Cantidad, l.Precio, l.PjeDto, l.Importe
          FROM AlbaranesVentasCab a
          INNER JOIN AlbaranesVentasLin l
            ON l.Empresa = a.Empresa AND l.Tipo = a.Tipo AND l.Albaran = a.Albaran
          WHERE a.Empresa = :e AND a.FacturaTipo = :ft AND a.Factura = :f
            AND (l.Articulo IS NULL OR l.Articulo <> 'NO')
          ORDER BY a.Albaran, l.NroLin";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['e' => $empresa, 'ft' => $facturaTipo, 'f' => $factura]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
      } catch (\Throwable $ex2) {
        return [];
      }
    }
  }

  /**
   * @return list<array<string, mixed>>
   */
  private function cargarAlbaranesResumen(string $empresa, string $facturaTipo, int $factura): array
  {
    try {
      $sql = "SELECT a.Empresa, a.Albaran, a.Fecha, a.Importe
        FROM AlbaranesVentasCab a
        WHERE a.FacturaTipo = :ft AND a.Factura = :f
          AND (a.EmpresaFacturacion = :e OR (ISNULL(a.EmpresaFacturacion, '') = '' AND a.Empresa = :e2))
        ORDER BY a.Albaran";
      $stmt = $this->pdo->prepare($sql);
      $stmt->execute(['ft' => $facturaTipo, 'f' => $factura, 'e' => $empresa, 'e2' => $empresa]);
      return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $ex) {
      try {
        $sql = "SELECT a.Empresa, a.Albaran, a.Fecha, a.Importe
          FROM AlbaranesVentasCab a
          WHERE a.Empresa = :e AND a.FacturaTipo = :ft AND a.Factura = :f
          ORDER BY a.Albaran";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['e' => $empresa, 'ft' => $facturaTipo, 'f' => $factura]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
      } catch (\Throwable $ex2) {
        return [];
      }
    }
  }

  /** @param array{cab: array<string, mixed>, lineas: list<array<string, mixed>>, albaranes: list<array<string, mixed>>} $doc */
  private function renderFactura(SimplePdf $pdf, array $doc): void
  {
    $c = $doc['cab'];
    $tipo = trim((string) ($c['FacturaTipo'] ?? 'F'));
    $num = (int) ($c['Factura'] ?? 0);
    $titulo = ($tipo === 'A' ? 'ABONO' : 'FACTURA') . ' ' . $tipo . '-' . $num;

    $pdf->title($titulo);
    $empNom = trim((string) ($c['EmpNombreFiscal'] ?: $c['EmpNombre'] ?: ''));
    $pdf->text($empNom, 11, true);
    $pdf->text(
      trim((string) ($c['EmpDireccion'] ?? ''))
      . '  ' . trim((string) ($c['EmpCodigoPostal'] ?? ''))
      . ' ' . trim((string) ($c['EmpPoblacion'] ?? ''))
      . '  NIF ' . trim((string) ($c['EmpNif'] ?? '')),
      9
    );
    $pdf->spacer(6);
    $pdf->text('Fecha: ' . $this->fmtFecha($c['Fecha'] ?? null), 10);
    $pdf->text(
      'Cliente: ' . trim((string) ($c['Cliente'] ?? ''))
      . '  ' . trim((string) ($c['RazonSocial'] ?? ''))
      . '  NIF ' . trim((string) ($c['NIF'] ?? '')),
      10
    );
    $dirCli = trim(
      trim((string) ($c['Direccion'] ?? ''))
      . '  ' . trim((string) ($c['CodigoPostal'] ?? ''))
      . ' ' . trim((string) ($c['Poblacion'] ?? ''))
    );
    if ($dirCli !== '') {
      $pdf->text($dirCli, 9);
    }
    $fp = trim((string) ($c['Fpago'] ?? ''));
    if ($fp !== '') {
      $pdf->text('Forma de pago: ' . $fp, 9);
    }
    $pdf->spacer(8);

    $lineas = $doc['lineas'];
    if ($lineas !== []) {
      $rows = [];
      foreach ($lineas as $l) {
        $rows[] = [
          (string) ($l['Albaran'] ?? ''),
          trim((string) ($l['Articulo'] ?? '')),
          $this->trunc(trim((string) ($l['Descripcion'] ?? '')), 28),
          $this->num((float) ($l['Cantidad'] ?? 0)),
          $this->num((float) ($l['Precio'] ?? 0)),
          $this->num((float) ($l['Importe'] ?? 0)),
        ];
      }
      $pdf->table(
        ['Alb', 'Art.', 'Descripcion', 'Cant', 'Precio', 'Importe'],
        $rows,
        [45, 55, 180, 45, 55, 60]
      );
    } elseif ($doc['albaranes'] !== []) {
      $rows = [];
      foreach ($doc['albaranes'] as $a) {
        $rows[] = [
          (string) ($a['Albaran'] ?? ''),
          $this->fmtFecha($a['Fecha'] ?? null),
          $this->num((float) ($a['Importe'] ?? 0)),
        ];
      }
      $pdf->table(['Albaran', 'Fecha', 'Importe'], $rows, [80, 100, 80]);
    } else {
      $pdf->text('(Sin lineas de detalle)', 9);
    }

    $pdf->spacer(10);
    $pdf->text('Desglose IVA', 10, true);
    $ivaRows = [];
    for ($i = 1; $i <= 6; $i++) {
      $base = (float) ($c["ImporteBase{$i}"] ?? 0);
      if (abs($base) < 0.0001) {
        continue;
      }
      $ivaRows[] = [
        $this->num((float) ($c["PjeIva{$i}"] ?? 0)) . '%',
        $this->num($base),
        $this->num((float) ($c["ImporteIva{$i}"] ?? 0)),
        $this->num((float) ($c["ImporteRec{$i}"] ?? 0)),
      ];
    }
    if ($ivaRows !== []) {
      $pdf->table(['% IVA', 'Base', 'IVA', 'Recargo'], $ivaRows, [60, 90, 90, 90]);
    }

    $dto = (float) ($c['ImporteDtos'] ?? 0);
    if (abs($dto) > 0.0001) {
      $pdf->text(
        'Descuento ' . $this->num((float) ($c['PjeDto'] ?? 0)) . '%: ' . $this->num($dto),
        10
      );
    }
    $pagoACuenta = (float) ($c['PagoACuenta'] ?? 0);
    if (abs($pagoACuenta) > 0.0001) {
      $pdf->text('A cuenta: ' . $this->num($pagoACuenta), 10);
    }
    $pdf->spacer(4);
    $pdf->text('TOTAL: ' . $this->num((float) ($c['Importe'] ?? 0)) . ' EUR', 14, true);
  }

  private function marcarImpresa(string $empresa, string $facturaTipo, int $factura): bool
  {
    $stmt = $this->pdo->prepare(
      'UPDATE Facturas SET Impresa = 1
       WHERE Empresa = :e AND FacturaTipo = :ft AND Factura = :f
         AND ISNULL(Impresa, 0) = 0'
    );
    $stmt->execute(['e' => $empresa, 'ft' => $facturaTipo, 'f' => $factura]);
    return $stmt->rowCount() > 0;
  }

  private function fmtFecha(mixed $v): string
  {
    if ($v === null || $v === '') {
      return '';
    }
    if ($v instanceof \DateTimeInterface) {
      return $v->format('d/m/Y');
    }
    $s = (string) $v;
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) {
      return $m[3] . '/' . $m[2] . '/' . $m[1];
    }
    return $s;
  }

  private function num(float $n): string
  {
    return number_format($n, 2, ',', '.');
  }

  private function trunc(string $text, int $max): string
  {
    if (mb_strlen($text, 'UTF-8') <= $max) {
      return $text;
    }
    return mb_substr($text, 0, max(1, $max - 1), 'UTF-8') . '…';
  }
}
