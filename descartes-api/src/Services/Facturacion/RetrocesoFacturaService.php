<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Facturacion;

use PDO;

/**
 * Retroceso de facturas (legacy FrmRetrocesoFactura).
 * MVP: deshace Facturas/Recibos y libera albaranes. Sin CTB externo.
 */
final class RetrocesoFacturaService
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
  public function preview(array $query): array
  {
    $empresa = trim((string) ($query['empresa'] ?? ''));
    $facturaTipo = strtoupper(trim((string) ($query['facturaTipo'] ?? 'F')));
    $factura = (int) ($query['factura'] ?? 0);

    if ($empresa === '' || $factura <= 0) {
      throw new \InvalidArgumentException('empresa y factura obligatorios');
    }
    if (!in_array($facturaTipo, ['F', 'A'], true)) {
      $facturaTipo = 'F';
    }

    if ($this->fiscalBloqueaRetroceso()) {
      throw new \RuntimeException(
        'No se puede retroceder facturas con TicketBAI / Veri*Factu activo',
        409
      );
    }

    $fac = $this->cargarFactura($empresa, $facturaTipo, $factura);
    $estado = strtoupper(trim((string) ($fac['Estado'] ?? '')));
    $bloqueada = $estado === 'F';

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
      'mensajeBloqueo' => $bloqueada
        ? 'La factura está en estado F (cerrada/contabilizada) y no se puede retroceder'
        : null,
      'avisoCtb' => !empty($fac['TrasCtb'])
        ? 'La factura está marcada como traspasada a contabilidad. El retroceso en 2.0 no deshace asientos CTB.'
        : null,
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
    if (!in_array($facturaTipo, ['F', 'A'], true)) {
      $facturaTipo = 'F';
    }

    if ($this->fiscalBloqueaRetroceso()) {
      throw new \RuntimeException(
        'No se puede retroceder facturas con TicketBAI / Veri*Factu activo',
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

    $this->pdo->beginTransaction();
    try {
      // Recibos
      try {
        $this->pdo->prepare(
          'DELETE FROM Recibos WHERE FacturaTipo = :ft AND Empresa = :e AND Factura = :f'
        )->execute(['ft' => $facturaTipo, 'e' => $empresa, 'f' => $factura]);
      } catch (\Throwable $e) {
        // Tabla Recibos puede no existir en algunos esquemas.
      }

      // Liberar albaranes (preferir EmpresaFacturacion; fallback Empresa).
      $albs = 0;
      try {
        $st = $this->pdo->prepare(
          "UPDATE AlbaranesVentasCab
           SET FacturaTipo = NULL, Factura = 0, EmpresaFacturacion = NULL,
               FacturaRetroceso = :fr
           WHERE FacturaTipo = :ft AND Factura = :f
             AND (EmpresaFacturacion = :e OR (ISNULL(EmpresaFacturacion, '') = '' AND Empresa = :e2))"
        );
        $st->execute([
          'fr' => $factura,
          'ft' => $facturaTipo,
          'f' => $factura,
          'e' => $empresa,
          'e2' => $empresa,
        ]);
        $albs = $st->rowCount();
      } catch (\Throwable $e) {
        $st = $this->pdo->prepare(
          "UPDATE AlbaranesVentasCab
           SET FacturaTipo = NULL, Factura = 0, FacturaRetroceso = :fr
           WHERE Empresa = :e AND FacturaTipo = :ft AND Factura = :f"
        );
        $st->execute([
          'fr' => $factura,
          'e' => $empresa,
          'ft' => $facturaTipo,
          'f' => $factura,
        ]);
        $albs = $st->rowCount();
      }

      $del = $this->pdo->prepare(
        'DELETE FROM Facturas WHERE FacturaTipo = :ft AND Empresa = :e AND Factura = :f'
      );
      $del->execute(['ft' => $facturaTipo, 'e' => $empresa, 'f' => $factura]);
      if ($del->rowCount() === 0) {
        throw new \RuntimeException('Factura no encontrada al retroceder', 404);
      }

      $this->pdo->commit();
    } catch (\Throwable $e) {
      if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      throw $e;
    }

    return [
      'ok' => true,
      'empresa' => $empresa,
      'facturaTipo' => $facturaTipo,
      'factura' => $factura,
      'albaranesLiberados' => $albs,
      'mensaje' => 'Factura retrocedida correctamente',
    ];
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
