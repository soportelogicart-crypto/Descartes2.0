<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Ventas;

use PDO;

final class VentaConsultaService
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
    if (!empty($query['vendedor'])) {
      $where[] = 'c.Vendedor = :vendedor';
      $params['vendedor'] = $query['vendedor'];
    }
    if (!empty($query['cliente'])) {
      $where[] = '(c.Cliente LIKE :cliente OR c.RazonSocial LIKE :clienteNombre)';
      $params['cliente'] = '%' . $query['cliente'] . '%';
      $params['clienteNombre'] = '%' . $query['cliente'] . '%';
    }
    if (!empty($query['estado'])) {
      $where[] = 'c.Estado = :estado';
      $params['estado'] = $query['estado'];
    }
    if (!empty($query['empresa'])) {
      $where[] = 'c.Empresa = :empresa';
      $params['empresa'] = $query['empresa'];
    }

    $sqlWhere = implode(' AND ', $where);

    $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM AlbaranesVentasCab c WHERE {$sqlWhere}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $sql = "SELECT c.Empresa, c.Tipo, c.Albaran, c.Fecha, c.Cliente, c.RazonSocial, c.Puesto,
                   c.Vendedor, c.Estado, c.Importe, c.Factura, c.FacturaTipo, c.Sesion,
                   c.AlbaranOrigenAbono
            FROM AlbaranesVentasCab c
            WHERE {$sqlWhere}
            ORDER BY c.Albaran DESC, c.Fecha DESC, c.Empresa ASC
            OFFSET :offset ROWS FETCH NEXT :pageSize ROWS ONLY";
    $stmt = $this->pdo->prepare($sql);
    foreach ($params as $k => $v) {
      $stmt->bindValue(':' . $k, $v);
    }
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':pageSize', $pageSize, PDO::PARAM_INT);
    $stmt->execute();

    $items = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $items[] = $this->mapResumen($row);
    }

    return [
      'items' => $items,
      'total' => $total,
      'page' => $page,
      'pageSize' => $pageSize,
    ];
  }

  public function obtener(string $empresa, string $tipo, int $albaran): ?array
  {
    return $this->obtenerFicha($empresa, $tipo, $albaran);
  }

  /** Detalle completo para ficha de Gestion (cabecera + lineas). */
  public function obtenerFicha(string $empresa, string $tipo, int $albaran): ?array
  {
    $stmt = $this->pdo->prepare(
      'SELECT Empresa, Tipo, Albaran, Fecha, Cliente, RazonSocial, RazonSocial2, NIF, Puesto, Vendedor,
              Representante, Transporte, DireccionEnvio, PoblacionEnvio, CodigoPostalEnvio,
              ProvinciaEnvio, PaisEnvio, Telefono, Telefono2, Fax, Email, Almacen, Pedido,
              Referencia1, Referencia2, NumeroDeSerie, SujetoPasivo, Portes, Estado, Impreso,
              Importe, Factura, FacturaTipo, Sesion, FechaEntrega, ImporteDtos, PjeDto,
              Fpago1, Fpago2, Fpago3, ImpFpago1, ImpFpago2,
              ImporteBase1, ImporteBase2, ImporteBase3, ImporteBase4, ImporteBase5, ImporteBase6,
              PjeIva1, PjeIva2, PjeIva3, PjeIva4, PjeIva5, PjeIva6,
              ImporteIva1, ImporteIva2, ImporteIva3, ImporteIva4, ImporteIva5, ImporteIva6,
              AlbaranOrigenAbono
       FROM AlbaranesVentasCab
       WHERE Empresa = :empresa AND Tipo = :tipo AND Albaran = :albaran'
    );
    $stmt->execute(['empresa' => $empresa, 'tipo' => $tipo, 'albaran' => $albaran]);
    $cab = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cab) {
      return null;
    }

    $linStmt = $this->pdo->prepare(
      'SELECT NroLin, Articulo, Descripcion, LoteVenta, Cantidad, Precio, PjeDto, Importe, PjeIva
       FROM AlbaranesVentasLin
       WHERE Empresa = :empresa AND Tipo = :tipo AND Albaran = :albaran
       ORDER BY NroLin'
    );
    $linStmt->execute(['empresa' => $empresa, 'tipo' => $tipo, 'albaran' => $albaran]);

    $lineas = [];
    while ($lin = $linStmt->fetch(PDO::FETCH_ASSOC)) {
      $lineas[] = [
        'nroLin' => (int) $lin['NroLin'],
        'articulo' => $lin['Articulo'],
        'descripcion' => $lin['Descripcion'],
        'loteVenta' => $lin['LoteVenta'],
        'cantidad' => (float) ($lin['Cantidad'] ?? 0),
        'precio' => (float) ($lin['Precio'] ?? 0),
        'pjeDto' => (float) ($lin['PjeDto'] ?? 0),
        'importe' => (float) ($lin['Importe'] ?? 0),
        'pjeIva' => (float) ($lin['PjeIva'] ?? 0),
      ];
    }

    $detalle = $this->mapResumen($cab);
    $detalle['razonSocial2'] = $cab['RazonSocial2'] ?? null;
    $detalle['nif'] = $cab['NIF'] ?? null;
    $detalle['representante'] = $cab['Representante'] ?? null;
    $detalle['transporte'] = $cab['Transporte'] ?? null;
    $detalle['direccionEnvio'] = $cab['DireccionEnvio'] ?? null;
    $detalle['poblacionEnvio'] = $cab['PoblacionEnvio'] ?? null;
    $detalle['codigoPostalEnvio'] = $cab['CodigoPostalEnvio'] ?? null;
    $detalle['provinciaEnvio'] = $cab['ProvinciaEnvio'] ?? null;
    $detalle['paisEnvio'] = $cab['PaisEnvio'] ?? null;
    $detalle['telefono'] = $cab['Telefono'] ?? null;
    $detalle['telefono2'] = $cab['Telefono2'] ?? null;
    $detalle['fax'] = $cab['Fax'] ?? null;
    $detalle['email'] = $cab['Email'] ?? null;
    $detalle['almacen'] = isset($cab['Almacen']) && $cab['Almacen'] !== null ? (int) $cab['Almacen'] : null;
    $detalle['pedido'] = isset($cab['Pedido']) && $cab['Pedido'] !== null && (int) $cab['Pedido'] > 0
      ? (int) $cab['Pedido'] : null;
    $detalle['referencia1'] = $cab['Referencia1'] ?? null;
    $detalle['referencia2'] = $cab['Referencia2'] ?? null;
    $detalle['numeroDeSerie'] = $cab['NumeroDeSerie'] !== null ? trim((string) $cab['NumeroDeSerie']) : null;
    $detalle['sujetoPasivo'] = !empty($cab['SujetoPasivo']);
    $detalle['portes'] = $cab['Portes'] ?? null;
    $detalle['fechaEntrega'] = $this->fmtDate($cab['FechaEntrega'] ?? null);
    $detalle['bruto'] = (float) ($cab['ImporteBase1'] ?? 0);
    $detalle['descuento'] = (float) ($cab['ImporteDtos'] ?? 0);
    $detalle['iva'] = (float) ($cab['ImporteIva1'] ?? 0);
    $detalle['pjeIva1'] = (float) ($cab['PjeIva1'] ?? 0);
    $detalle['pjeDto'] = (float) ($cab['PjeDto'] ?? 0);
    $detalle['formasPago'] = $this->mapFormasPago($cab);
    $detalle['importesIva'] = $this->mapImportesIva($cab);
    $detalle['impreso'] = !empty($cab['Impreso']);
    $detalle['lineas'] = $lineas;
    $this->enriquecerFacturaAbono($detalle);

    return $detalle;
  }

  /**
   * Datos de Facturas para saber si el documento admite abono parcial por líneas
   * (ticket / factura contado / albarán cerrado). Crédito (Estado G) → rectificativa.
   *
   * @param array<string, mixed> $detalle
   */
  private function enriquecerFacturaAbono(array &$detalle): void
  {
    $ft = strtoupper(trim((string) ($detalle['facturaTipo'] ?? '')));
    $factura = (int) ($detalle['factura'] ?? 0);
    $sesion = (int) ($detalle['sesion'] ?? 0);
    $esAbonoAlb = ((int) ($detalle['albaranOrigenAbono'] ?? 0) > 0)
      || ((float) ($detalle['importe'] ?? 0) < 0);

    $detalle['facturaEstado'] = null;
    $detalle['facturaContadoDiferida'] = false;

    if ($ft === 'F' && $factura > 0) {
      $empresa = (string) ($detalle['empresa'] ?? '');
      try {
        $st = $this->pdo->prepare(
          'SELECT TOP 1 Estado, FacturaContadoDiferida
           FROM Facturas
           WHERE Empresa = :e AND FacturaTipo = :ft AND Factura = :f'
        );
        $st->execute(['e' => $empresa, 'ft' => $ft, 'f' => $factura]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
          $detalle['facturaEstado'] = $row['Estado'] !== null ? trim((string) $row['Estado']) : null;
          $detalle['facturaContadoDiferida'] = !empty($row['FacturaContadoDiferida']);
        }
      } catch (\Throwable $e) {
        // Sin bloqueo de ficha si falla el lookup.
      }
    }

    $detalle['permiteAbonoParcial'] = $this->calcularPermiteAbonoParcial(
      $ft,
      $factura,
      $sesion,
      $esAbonoAlb,
      $detalle['facturaEstado'] ?? null,
      !empty($detalle['facturaContadoDiferida'])
    );

    $detalle['origenDocumento'] = null;
    $origenAlb = (int) ($detalle['albaranOrigenAbono'] ?? 0);
    if ($origenAlb > 0) {
      $detalle['origenDocumento'] = $this->resolverOrigenDocumentoAbono(
        (string) ($detalle['empresa'] ?? ''),
        $origenAlb
      );
    }
  }

  /**
   * @return array{albaran: int, tipo: string|null, facturaTipo: string|null, factura: int|null, etiqueta: string}|null
   */
  private function resolverOrigenDocumentoAbono(string $empresa, int $albaranOrigen): ?array
  {
    if ($empresa === '' || $albaranOrigen <= 0) {
      return null;
    }
    try {
      $st = $this->pdo->prepare(
        'SELECT TOP 1 Tipo, FacturaTipo, Factura
         FROM AlbaranesVentasCab
         WHERE Empresa = :e AND Albaran = :a
         ORDER BY CASE WHEN Tipo = \'A\' THEN 0 ELSE 1 END'
      );
      $st->execute(['e' => $empresa, 'a' => $albaranOrigen]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if ($row === false) {
        return [
          'albaran' => $albaranOrigen,
          'tipo' => null,
          'facturaTipo' => null,
          'factura' => null,
          'etiqueta' => "Albarán {$albaranOrigen}",
        ];
      }
      $tipo = $row['Tipo'] !== null ? trim((string) $row['Tipo']) : null;
      $ft = strtoupper(trim((string) ($row['FacturaTipo'] ?? '')));
      $factura = isset($row['Factura']) && (int) $row['Factura'] > 0 ? (int) $row['Factura'] : null;
      $etiqueta = "Albarán {$albaranOrigen}";
      if ($ft === 'T' && $factura !== null) {
        $etiqueta = "Ticket T-{$factura} (albarán {$albaranOrigen})";
      } elseif ($ft === 'F' && $factura !== null) {
        $etiqueta = "Factura F-{$factura} (albarán {$albaranOrigen})";
      } elseif ($ft === 'A' && $factura !== null) {
        $etiqueta = "Abono A-{$factura} (albarán {$albaranOrigen})";
      }
      return [
        'albaran' => $albaranOrigen,
        'tipo' => $tipo,
        'facturaTipo' => $ft !== '' ? $ft : null,
        'factura' => $factura,
        'etiqueta' => $etiqueta,
      ];
    } catch (\Throwable $e) {
      return [
        'albaran' => $albaranOrigen,
        'tipo' => null,
        'facturaTipo' => null,
        'factura' => null,
        'etiqueta' => "Albarán {$albaranOrigen}",
      ];
    }
  }

  private function calcularPermiteAbonoParcial(
    string $ft,
    int $factura,
    int $sesion,
    bool $esAbonoAlb,
    ?string $facturaEstado,
    bool $contadoDiferida
  ): bool {
    if ($esAbonoAlb) {
      return false;
    }
    // Albarán cerrado (crédito pendiente de facturar).
    if (($ft === '' || $ft === 'Z') && $factura <= 0 && $sesion > 0) {
      return true;
    }
    // Ticket tipificado.
    if ($ft === 'T' && $factura > 0) {
      return true;
    }
    // Factura de contado (Estado F). Crédito/diferida (G) no.
    if ($ft === 'F' && $factura > 0) {
      $fe = strtoupper(trim((string) $facturaEstado));
      if ($contadoDiferida || $fe === 'G') {
        return false;
      }
      return $fe === 'F';
    }
    return false;
  }

  /** @param array<string, mixed> $row */
  private function mapResumen(array $row): array
  {
    $factura = $row['Factura'] !== null && $row['Factura'] !== '' ? (int) $row['Factura'] : null;

    $tipo = (string) $row['Tipo'];
    $facturaTipo = $row['FacturaTipo'] !== null ? trim((string) $row['FacturaTipo']) : null;
    $ft = strtoupper((string) ($facturaTipo ?? ''));
    $esTicket = $ft === 'T' && $factura !== null && $factura > 0;
    $facturada = $factura !== null && $factura > 0;
    // Solo factura/abono bloquean del todo; el ticket puede pasar a factura.
    $bloqueado = $ft === 'F' || $ft === 'A';

    return [
      'empresa' => (string) $row['Empresa'],
      'tipo' => $tipo,
      'albaran' => (int) $row['Albaran'],
      'fecha' => $this->fmtDate($row['Fecha'] ?? null),
      'cliente' => $row['Cliente'],
      'razonSocial' => $row['RazonSocial'],
      'puesto' => $row['Puesto'],
      'vendedor' => $row['Vendedor'],
      'estado' => $row['Estado'],
      'importe' => (float) ($row['Importe'] ?? 0),
      'facturada' => $facturada,
      'bloqueado' => $bloqueado,
      'esTicket' => $esTicket,
      'factura' => $factura,
      'facturaTipo' => $row['FacturaTipo'] ?? null,
      'sesion' => isset($row['Sesion']) && $row['Sesion'] !== null ? (int) $row['Sesion'] : null,
      'albaranOrigenAbono' => isset($row['AlbaranOrigenAbono']) && (int) $row['AlbaranOrigenAbono'] > 0
        ? (int) $row['AlbaranOrigenAbono']
        : null,
    ];
  }

  /** @param array<string, mixed> $cab */
  private function mapFormasPago(array $cab): array
  {
    $out = [];
    $slots = [
      ['Fpago1', 'ImpFpago1'],
      ['Fpago2', 'ImpFpago2'],
    ];
    foreach ($slots as [$fp, $imp]) {
      $codigo = trim((string) ($cab[$fp] ?? ''));
      if ($codigo === '') {
        continue;
      }
      $out[] = [
        'codigo' => $codigo,
        'importe' => (float) ($cab[$imp] ?? 0),
      ];
    }
    $fp3 = trim((string) ($cab['Fpago3'] ?? ''));
    if ($fp3 !== '') {
      $resto = (float) ($cab['Importe'] ?? 0)
        - (float) ($cab['ImpFpago1'] ?? 0)
        - (float) ($cab['ImpFpago2'] ?? 0);
      $out[] = ['codigo' => $fp3, 'importe' => max(0.0, $resto)];
    }
    return $out;
  }

  /** @param array<string, mixed> $cab */
  private function mapImportesIva(array $cab): array
  {
    $out = [];
    for ($i = 1; $i <= 6; $i++) {
      $base = (float) ($cab["ImporteBase{$i}"] ?? 0);
      $iva = (float) ($cab["ImporteIva{$i}"] ?? 0);
      $pje = (float) ($cab["PjeIva{$i}"] ?? 0);
      if ($base == 0.0 && $iva == 0.0 && $pje == 0.0) {
        continue;
      }
      $out[] = ['pjeIva' => $pje, 'base' => $base, 'iva' => $iva];
    }
    return $out;
  }

  private function fmtDate($value): ?string
  {
    if ($value === null || $value === '') {
      return null;
    }
    if ($value instanceof \DateTimeInterface) {
      return $value->format('c');
    }
    $ts = strtotime((string) $value);
    return $ts ? date('c', $ts) : (string) $value;
  }

  private function fechaIsoValida($value): bool
  {
    if ($value === null || $value === '') {
      return false;
    }
    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $value);
  }
}
