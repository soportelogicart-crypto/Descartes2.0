<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Facturacion;

use PDO;

/**
 * Retroceso de facturas → factura rectificativa (abono).
 *
 * No borra la factura original (correlatividad / normativa).
 * Crea FacturaTipo=A con importes invertidos (contador UltAbono).
 * Los albaranes siguen ligados a la F original (no se liberan).
 */
final class RetrocesoFacturaService
{
  private PDO $pdo;
  private RecibosFacturaService $recibos;

  public function __construct(PDO $pdo, RecibosFacturaService $recibos)
  {
    $this->pdo = $pdo;
    $this->recibos = $recibos;
  }

  /**
   * @param array<string, mixed> $query
   * @return array<string, mixed>
   */
  public function preview(array $query): array
  {
    $empresa = trim((string) ($query['empresa'] ?? ''));
    $facturaTipo = strtoupper(trim((string) ($query['facturaTipo'] ?? 'F')));
    $factura = (int) ($query['factura'] ?? 0);
    $albaran = (int) ($query['albaran'] ?? 0);

    if ($empresa === '') {
      throw new \InvalidArgumentException('empresa obligatoria');
    }
    if ($facturaTipo !== 'F') {
      throw new \InvalidArgumentException('Solo se pueden rectificar facturas (tipo F), no abonos');
    }

    // Compat: si no hay factura pero sí albarán, o el nº no existe como factura → resolver por albarán.
    if ($factura <= 0 && $albaran > 0) {
      $factura = $this->facturaDesdeAlbaran($empresa, $albaran);
    } elseif ($factura > 0 && !$this->existeFactura($empresa, 'F', $factura)) {
      // El usuario pudo teclear el nº de albarán en el campo factura.
      $resuelta = $this->facturaDesdeAlbaran($empresa, $factura);
      if ($resuelta > 0) {
        $albaran = $factura;
        $factura = $resuelta;
      }
    }

    if ($factura <= 0) {
      throw new \InvalidArgumentException('Indique un nº de factura (F) o un albarán ya facturado');
    }

    if ($this->fiscalBloqueaRetroceso()) {
      throw new \RuntimeException(
        'No se puede emitir rectificativa automática con TicketBAI / Veri*Factu activo (pendiente de integración fiscal)',
        409
      );
    }

    $fac = $this->cargarFactura($empresa, $facturaTipo, $factura);
    $estado = strtoupper(trim((string) ($fac['Estado'] ?? '')));
    $yaRectificada = $this->buscarAbonoRectificativo($empresa, $factura);
    $bloqueada = $yaRectificada !== null;
    $albaranes = $this->cargarAlbaranes($empresa, $facturaTipo, $factura);

    return [
      'empresa' => $empresa,
      'facturaTipo' => $facturaTipo,
      'factura' => $factura,
      'fecha' => $this->fmtFecha($fac['Fecha'] ?? null),
      'cliente' => trim((string) ($fac['Cliente'] ?? '')),
      'razonSocial' => trim((string) ($fac['RazonSocial'] ?? '')),
      'nif' => trim((string) ($fac['NIF'] ?? '')),
      'importe' => (float) ($fac['Importe'] ?? 0),
      'estado' => $estado,
      'trasCtb' => !empty($fac['TrasCtb']),
      'bloqueada' => $bloqueada,
      'puedeRetroceder' => !$bloqueada,
      'modo' => 'rectificativa',
      'resueltoDesdeAlbaran' => $albaran > 0 ? $albaran : null,
      'mensajeBloqueo' => $bloqueada
        ? 'Esta factura ya tiene abono rectificativo A-' . ($yaRectificada['factura'] ?? '')
        : null,
      'avisoCtb' => !empty($fac['TrasCtb'])
        ? 'La factura está marcada como traspasada a contabilidad. El abono no deshace asientos CTB automáticamente.'
        : null,
      'avisoRectificativa' =>
        'Se creará un abono (FacturaTipo A) con importes invertidos. La factura original se conserva. Los albaranes no se liberan.',
      'abonoExistente' => $yaRectificada,
      'albaranes' => $albaranes,
      'totales' => [
        'albaranes' => count($albaranes),
        'importeAlbaranes' => round(array_sum(array_map(
          static fn ($a) => (float) ($a['importe'] ?? 0),
          $albaranes
        )), 2),
      ],
    ];
  }

  /**
   * @param array<string, mixed> $body
   * @return array<string, mixed>
   */
  public function ejecutar(array $body): array
  {
    $empresa = trim((string) ($body['empresa'] ?? ''));
    $facturaTipo = strtoupper(trim((string) ($body['facturaTipo'] ?? 'F')));
    $factura = (int) ($body['factura'] ?? 0);

    if ($empresa === '' || $factura <= 0) {
      throw new \InvalidArgumentException('empresa y factura obligatorios');
    }
    if ($facturaTipo !== 'F') {
      throw new \InvalidArgumentException('Solo se pueden rectificar facturas (tipo F)');
    }

    if ($this->fiscalBloqueaRetroceso()) {
      throw new \RuntimeException(
        'No se puede emitir rectificativa automática con TicketBAI / Veri*Factu activo',
        409
      );
    }

    $preview = $this->preview([
      'empresa' => $empresa,
      'facturaTipo' => $facturaTipo,
      'factura' => $factura,
    ]);
    if (!empty($preview['bloqueada'])) {
      throw new \RuntimeException((string) $preview['mensajeBloqueo'], 409);
    }

    // Puede haberse resuelto desde nº de albarán.
    $factura = (int) $preview['factura'];
    $fac = $this->cargarFacturaCompleta($empresa, $facturaTipo, $factura);

    $this->pdo->beginTransaction();
    try {
      $abonoNum = $this->nextNumeroAbono($empresa);
      $ref = $this->refRectificativa($facturaTipo, $factura);
      $hoy = date('Y-m-d');

      $this->insertarAbonoRectificativo($fac, $abonoNum, $hoy, $ref);
      $recibos = $this->recibos->generar($empresa, 'A', $abonoNum);

      $this->pdo->commit();
    } catch (\Throwable $e) {
      if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      throw $e;
    }

    return [
      'ok' => true,
      'modo' => 'rectificativa',
      'empresa' => $empresa,
      'facturaTipo' => $facturaTipo,
      'factura' => $factura,
      'abono' => [
        'empresa' => $empresa,
        'facturaTipo' => 'A',
        'factura' => $abonoNum,
        'importe' => round(-1 * (float) ($fac['Importe'] ?? 0), 2),
        'recibos' => $recibos,
      ],
      'albaranesLiberados' => 0,
      'mensaje' => "Creado abono rectificativo A-{$abonoNum} de la factura F-{$factura}. La factura original se conserva.",
    ];
  }

  private function existeFactura(string $empresa, string $facturaTipo, int $factura): bool
  {
    $st = $this->pdo->prepare(
      'SELECT 1 FROM Facturas WHERE Empresa = :e AND FacturaTipo = :ft AND Factura = :f'
    );
    $st->execute(['e' => $empresa, 'ft' => $facturaTipo, 'f' => $factura]);
    return $st->fetchColumn() !== false;
  }

  /** Resuelve el nº de factura F a partir de un albarán ya facturado. */
  private function facturaDesdeAlbaran(string $empresa, int $albaran): int
  {
    try {
      $st = $this->pdo->prepare(
        "SELECT TOP 1 a.Factura
         FROM AlbaranesVentasCab a
         WHERE a.Albaran = :a
           AND a.FacturaTipo = 'F'
           AND ISNULL(a.Factura, 0) > 0
           AND (a.EmpresaFacturacion = :e OR (ISNULL(a.EmpresaFacturacion, '') = '' AND a.Empresa = :e2))
         ORDER BY a.Factura DESC"
      );
      $st->execute(['a' => $albaran, 'e' => $empresa, 'e2' => $empresa]);
    } catch (\Throwable $e) {
      $st = $this->pdo->prepare(
        "SELECT TOP 1 a.Factura
         FROM AlbaranesVentasCab a
         WHERE a.Empresa = :e AND a.Albaran = :a
           AND a.FacturaTipo = 'F' AND ISNULL(a.Factura, 0) > 0
         ORDER BY a.Factura DESC"
      );
      $st->execute(['e' => $empresa, 'a' => $albaran]);
    }
    $n = (int) ($st->fetchColumn() ?: 0);
    if ($n <= 0) {
      throw new \RuntimeException(
        "No hay factura F para el albarán {$albaran} en tienda {$empresa}. Use el nº de factura (no el de albarán) si aún no está facturado.",
        404
      );
    }
    return $n;
  }

  /**
   * @return array{empresa: string, facturaTipo: string, factura: int}|null
   */
  private function buscarAbonoRectificativo(string $empresa, int $facturaOrigen): ?array
  {
    $ref = $this->refRectificativa('F', $facturaOrigen);
    try {
      $st = $this->pdo->prepare(
        "SELECT TOP 1 Empresa, FacturaTipo, Factura
         FROM Facturas
         WHERE Empresa = :e AND FacturaTipo = 'A' AND FirmaFacturaAnterior = :ref
         ORDER BY Factura DESC"
      );
      $st->execute(['e' => $empresa, 'ref' => $ref]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if ($row === false) {
        return null;
      }
      return [
        'empresa' => trim((string) $row['Empresa']),
        'facturaTipo' => trim((string) $row['FacturaTipo']),
        'factura' => (int) $row['Factura'],
      ];
    } catch (\Throwable $e) {
      return null;
    }
  }

  private function refRectificativa(string $facturaTipo, int $factura): string
  {
    return 'RECT:' . strtoupper($facturaTipo) . '/' . $factura;
  }

  /**
   * @param array<string, mixed> $fac
   */
  private function insertarAbonoRectificativo(array $fac, int $abonoNum, string $fecha, string $ref): void
  {
    $neg = static fn ($v): float => round(-1 * (float) ($v ?? 0), 2);
    $estadoOrigen = strtoupper(trim((string) ($fac['Estado'] ?? 'G')));
    // Abono de crédito/diferida sigue en G; contado en F.
    $estado = $estadoOrigen === 'F' ? 'F' : 'G';

    $sql = 'INSERT INTO Facturas (
        Empresa, FacturaTipo, Factura, Cliente, Fecha,
        ImporteBase1, ImporteBase2, ImporteBase3, ImporteBase4, ImporteBase5, ImporteBase6,
        PjeIva1, PjeIva2, PjeIva3, PjeIva4, PjeIva5, PjeIva6,
        ImporteIva1, ImporteIva2, ImporteIva3, ImporteIva4, ImporteIva5, ImporteIva6,
        PjeRec1, PjeRec2, PjeRec3, PjeRec4, PjeRec5, PjeRec6,
        ImporteRec1, ImporteRec2, ImporteRec3, ImporteRec4, ImporteRec5, ImporteRec6,
        PjeDto, ImporteDtos, Importe, Fpago, Estado,
        TrasCtb, TrasModem, Impresa, ImporteLiquidado, FacturaContadoDiferida,
        PjeRetIrpf, BasRetIrpf, ImpRetIrpf, PagoACuenta, CobroEnTienda, SujetoPasivo,
        TrasformacionTicketFactura, AlbaranTicketTransformado, FirmaFacturaAnterior
      ) VALUES (
        :empresa, \'A\', :factura, :cliente, CONVERT(datetime, :fecha, 120),
        :b1, :b2, :b3, :b4, :b5, :b6,
        :pi1, :pi2, :pi3, :pi4, :pi5, :pi6,
        :ii1, :ii2, :ii3, :ii4, :ii5, :ii6,
        :pr1, :pr2, :pr3, :pr4, :pr5, :pr6,
        :ir1, :ir2, :ir3, :ir4, :ir5, :ir6,
        :pjeDto, :importeDtos, :importe, :fpago, :estado,
        0, 0, 0, 0, :contadoDiferida,
        :pjeIrpf, :basIrpf, :impIrpf, :pagoACuenta, 0, :sujetoPasivo,
        0, 0, :ref
      )';

    $this->pdo->prepare($sql)->execute([
      'empresa' => trim((string) $fac['Empresa']),
      'factura' => $abonoNum,
      'cliente' => $fac['Cliente'],
      'fecha' => $fecha . ' 00:00:00',
      'b1' => $neg($fac['ImporteBase1'] ?? 0),
      'b2' => $neg($fac['ImporteBase2'] ?? 0),
      'b3' => $neg($fac['ImporteBase3'] ?? 0),
      'b4' => $neg($fac['ImporteBase4'] ?? 0),
      'b5' => $neg($fac['ImporteBase5'] ?? 0),
      'b6' => $neg($fac['ImporteBase6'] ?? 0),
      'pi1' => (float) ($fac['PjeIva1'] ?? 0),
      'pi2' => (float) ($fac['PjeIva2'] ?? 0),
      'pi3' => (float) ($fac['PjeIva3'] ?? 0),
      'pi4' => (float) ($fac['PjeIva4'] ?? 0),
      'pi5' => (float) ($fac['PjeIva5'] ?? 0),
      'pi6' => (float) ($fac['PjeIva6'] ?? 0),
      'ii1' => $neg($fac['ImporteIva1'] ?? 0),
      'ii2' => $neg($fac['ImporteIva2'] ?? 0),
      'ii3' => $neg($fac['ImporteIva3'] ?? 0),
      'ii4' => $neg($fac['ImporteIva4'] ?? 0),
      'ii5' => $neg($fac['ImporteIva5'] ?? 0),
      'ii6' => $neg($fac['ImporteIva6'] ?? 0),
      'pr1' => (float) ($fac['PjeRec1'] ?? 0),
      'pr2' => (float) ($fac['PjeRec2'] ?? 0),
      'pr3' => (float) ($fac['PjeRec3'] ?? 0),
      'pr4' => (float) ($fac['PjeRec4'] ?? 0),
      'pr5' => (float) ($fac['PjeRec5'] ?? 0),
      'pr6' => (float) ($fac['PjeRec6'] ?? 0),
      'ir1' => $neg($fac['ImporteRec1'] ?? 0),
      'ir2' => $neg($fac['ImporteRec2'] ?? 0),
      'ir3' => $neg($fac['ImporteRec3'] ?? 0),
      'ir4' => $neg($fac['ImporteRec4'] ?? 0),
      'ir5' => $neg($fac['ImporteRec5'] ?? 0),
      'ir6' => $neg($fac['ImporteRec6'] ?? 0),
      'pjeDto' => (float) ($fac['PjeDto'] ?? 0),
      'importeDtos' => $neg($fac['ImporteDtos'] ?? 0),
      'importe' => $neg($fac['Importe'] ?? 0),
      'fpago' => $fac['Fpago'] !== null && $fac['Fpago'] !== '' ? $fac['Fpago'] : null,
      'estado' => $estado,
      'contadoDiferida' => !empty($fac['FacturaContadoDiferida']) ? 1 : 0,
      'pjeIrpf' => (float) ($fac['PjeRetIrpf'] ?? 0),
      'basIrpf' => $neg($fac['BasRetIrpf'] ?? 0),
      'impIrpf' => $neg($fac['ImpRetIrpf'] ?? 0),
      'pagoACuenta' => $neg($fac['PagoACuenta'] ?? 0),
      'sujetoPasivo' => !empty($fac['SujetoPasivo']) ? 1 : 0,
      'ref' => $ref,
    ]);
  }

  private function nextNumeroAbono(string $empresa): int
  {
    $stmt = $this->pdo->prepare(
      'SELECT UltAbono FROM Empresas WITH (UPDLOCK, ROWLOCK) WHERE Codigo = :e'
    );
    $stmt->execute(['e' => $empresa]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      throw new \RuntimeException('Tienda no encontrada', 404);
    }
    $n = (int) ($row['UltAbono'] ?? 0) + 1;
    $this->pdo->prepare('UPDATE Empresas SET UltAbono = :n WHERE Codigo = :e')
      ->execute(['n' => $n, 'e' => $empresa]);

    if ($this->contadorFacturasAnioMesActivo()) {
      $n = (int) (date('ym') . str_pad((string) $n, 5, '0', STR_PAD_LEFT));
    }
    return $n;
  }

  private function contadorFacturasAnioMesActivo(): bool
  {
    $paths = [
      'C:\\DesOra\\DesParametros.ini',
      '\\DesOra\\DesParametros.ini',
      dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'DesParametros.ini',
    ];
    foreach ($paths as $path) {
      if (!is_readable($path)) {
        continue;
      }
      $txt = (string) @file_get_contents($path);
      if (preg_match('/ContadorFacturasA[nñ]oMes\s*=\s*S/iu', $txt)) {
        return true;
      }
    }
    return false;
  }

  /**
   * @return array<string, mixed>
   */
  private function cargarFactura(string $empresa, string $facturaTipo, int $factura): array
  {
    $sql = "SELECT f.Empresa, f.FacturaTipo, f.Factura, f.Fecha, f.Cliente, f.Importe,
               f.Estado, f.TrasCtb, c.RazonSocial, c.NIF
            FROM Facturas f
            INNER JOIN Clientes c ON c.Codigo = f.Cliente
            WHERE f.Empresa = :e AND f.FacturaTipo = :ft AND f.Factura = :f";
    $st = $this->pdo->prepare($sql);
    $st->execute(['e' => $empresa, 'ft' => $facturaTipo, 'f' => $factura]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      throw new \RuntimeException('Factura no encontrada', 404);
    }
    return $row;
  }

  /**
   * @return array<string, mixed>
   */
  private function cargarFacturaCompleta(string $empresa, string $facturaTipo, int $factura): array
  {
    $sql = "SELECT *
            FROM Facturas
            WHERE Empresa = :e AND FacturaTipo = :ft AND Factura = :f";
    $st = $this->pdo->prepare($sql);
    $st->execute(['e' => $empresa, 'ft' => $facturaTipo, 'f' => $factura]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      throw new \RuntimeException('Factura no encontrada', 404);
    }
    return $row;
  }

  /**
   * @return list<array<string, mixed>>
   */
  private function cargarAlbaranes(string $empresa, string $facturaTipo, int $factura): array
  {
    try {
      $sql = "SELECT a.Empresa, a.Tipo, a.Albaran, a.Fecha, a.Cliente, a.Importe
              FROM AlbaranesVentasCab a
              WHERE a.FacturaTipo = :ft AND a.Factura = :f
                AND (a.EmpresaFacturacion = :e OR (ISNULL(a.EmpresaFacturacion, '') = '' AND a.Empresa = :e2))
              ORDER BY a.Albaran";
      $st = $this->pdo->prepare($sql);
      $st->execute(['ft' => $facturaTipo, 'f' => $factura, 'e' => $empresa, 'e2' => $empresa]);
    } catch (\Throwable $e) {
      $sql = "SELECT a.Empresa, a.Tipo, a.Albaran, a.Fecha, a.Cliente, a.Importe
              FROM AlbaranesVentasCab a
              WHERE a.Empresa = :e AND a.FacturaTipo = :ft AND a.Factura = :f
              ORDER BY a.Albaran";
      $st = $this->pdo->prepare($sql);
      $st->execute(['e' => $empresa, 'ft' => $facturaTipo, 'f' => $factura]);
    }
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $r) {
      $out[] = [
        'empresa' => trim((string) ($r['Empresa'] ?? '')),
        'tipo' => trim((string) ($r['Tipo'] ?? '')),
        'albaran' => (int) ($r['Albaran'] ?? 0),
        'fecha' => $this->fmtFecha($r['Fecha'] ?? null),
        'cliente' => trim((string) ($r['Cliente'] ?? '')),
        'importe' => (float) ($r['Importe'] ?? 0),
      ];
    }
    return $out;
  }

  private function fiscalBloqueaRetroceso(): bool
  {
    try {
      $st = $this->pdo->query(
        "SELECT TOP 1 ISNULL(TicketSI_Territorio, '') AS Territorio
         FROM Empresas
         WHERE ISNULL(Central, 0) <> 0"
      );
      $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
      if ($row !== false && trim((string) ($row['Territorio'] ?? '')) !== '') {
        return true;
      }
    } catch (\Throwable $e) {
      // ignore
    }
    try {
      $st = $this->pdo->query(
        'SELECT TOP 1 ISNULL(VerifactuEnvios, 0) AS Vf FROM TicketSI'
      );
      $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
      if ($row !== false && !empty($row['Vf'])) {
        return true;
      }
    } catch (\Throwable $e) {
      // ignore
    }
    return false;
  }

  private function fmtFecha($value): string
  {
    if ($value === null || $value === '') {
      return '';
    }
    if ($value instanceof \DateTimeInterface) {
      return $value->format('Y-m-d');
    }
    $ts = strtotime((string) $value);
    return $ts ? date('Y-m-d', $ts) : '';
  }
}
