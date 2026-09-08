<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Facturacion;

use DateTimeImmutable;
use PDO;

/**
 * Genera la cartera local ([Recibos]) al emitir una factura a crédito.
 *
 * Replica las reglas legacy:
 * - Estado G genera al menos un recibo, aunque NumVtos sea 0.
 * - Los plazos salen de FormasPago.
 * - DiaPago1/DiaPago2 desplazan cada vencimiento al siguiente día admitido.
 * - También refleja los seis vencimientos en la cabecera de Facturas.
 */
final class RecibosFacturaService
{
  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  /**
   * @return list<array{recibo: int, importe: float, vencimiento: string}>
   */
  public function generar(string $empresa, string $facturaTipo, int $factura): array
  {
    $stmt = $this->pdo->prepare(
      "SELECT f.Empresa, f.FacturaTipo, f.Factura, f.Fecha, f.Cliente,
              f.Importe, f.ImpRetIrpf, f.PagoACuenta, f.Estado, f.Fpago,
              ISNULL(fp.NumVtos, 0) AS NumVtos,
              ISNULL(fp.Dias1erVto, 0) AS Dias1erVto,
              ISNULL(fp.DiasEntreVtos, 0) AS DiasEntreVtos,
              ISNULL(fp.CobroEnTienda, 0) AS CobroEnTienda,
              ISNULL(c.DiaPago1, 0) AS DiaPago1,
              ISNULL(c.DiaPago2, 0) AS DiaPago2
       FROM Facturas f
       LEFT JOIN FormasPago fp ON fp.Codigo = f.Fpago
       LEFT JOIN Clientes c ON c.Codigo = f.Cliente
       WHERE f.Empresa = :empresa
         AND f.FacturaTipo = :facturaTipo
         AND f.Factura = :factura"
    );
    $stmt->execute([
      'empresa' => $empresa,
      'facturaTipo' => $facturaTipo,
      'factura' => $factura,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      throw new \RuntimeException('Factura no encontrada al generar recibos', 404);
    }

    if (strtoupper(trim((string) ($row['Estado'] ?? ''))) !== 'G') {
      return [];
    }

    if (!empty($row['CobroEnTienda'])) {
      $this->pdo->prepare(
        'UPDATE Facturas SET FacturaContadoDiferida=1, CobroEnTienda=1,
           ImporteLiquidado=ISNULL(PagoACuenta, 0)
         WHERE Empresa=:empresa AND FacturaTipo=:facturaTipo AND Factura=:factura'
      )->execute([
        'empresa' => $empresa,
        'facturaTipo' => $facturaTipo,
        'factura' => $factura,
      ]);
      return [];
    }

    $existentes = $this->cargarExistentes($empresa, $facturaTipo, $factura);
    if ($existentes !== []) {
      return $existentes;
    }

    $numVtos = max(1, (int) ($row['NumVtos'] ?? 0));
    if ($numVtos > 6) {
      throw new \RuntimeException('La forma de pago supera el máximo de 6 vencimientos', 409);
    }
    $diasPrimero = (int) ($row['Dias1erVto'] ?? 0);
    $diasEntre = (int) ($row['DiasEntreVtos'] ?? 0);
    $diaPago1 = (int) ($row['DiaPago1'] ?? 0);
    $diaPago2 = (int) ($row['DiaPago2'] ?? 0);
    $fecha = new DateTimeImmutable((string) $row['Fecha']);
    $importeTotal = round(
      (float) ($row['Importe'] ?? 0)
      - (float) ($row['ImpRetIrpf'] ?? 0)
      - (float) ($row['PagoACuenta'] ?? 0),
      2
    );
    $importeBase = round($importeTotal / $numVtos, 2);
    $importeAcumulado = 0.0;
    $generados = [];

    $insert = $this->pdo->prepare(
      'INSERT INTO Recibos (
         Empresa, FacturaTipo, Factura, Recibo, Importe, Vencimiento,
         Liquidado, ImporteLetras, Remesado, Seleccion, TrasCtb, TrasModem, Filler
       ) VALUES (
         :empresa, :facturaTipo, :factura, :recibo, :importe,
         CONVERT(datetime, :vencimiento, 120),
         0, NULL, 0, 0, 0, 0, 0
       )'
    );

    for ($i = 1; $i <= $numVtos; $i++) {
      $importe = $i === $numVtos
        ? round($importeTotal - $importeAcumulado, 2)
        : $importeBase;
      $importeAcumulado = round($importeAcumulado + $importe, 2);

      $vencimientoBase = $i === 1
        ? $this->sumarPlazo($fecha, $diasPrimero)
        : $this->sumarPlazo($vencimientoBase, $diasEntre);
      $vencimiento = $this->ajustarDiaPago($vencimientoBase, $diaPago1, $diaPago2);
      $vencimientoSql = $vencimiento->format('Y-m-d') . ' 00:00:00';

      $insert->execute([
        'empresa' => $empresa,
        'facturaTipo' => $facturaTipo,
        'factura' => $factura,
        'recibo' => $i,
        'importe' => $importe,
        'vencimiento' => $vencimientoSql,
      ]);
      $generados[] = [
        'recibo' => $i,
        'importe' => $importe,
        'vencimiento' => $vencimiento->format('Y-m-d'),
      ];
    }

    $this->actualizarVencimientosFactura($empresa, $facturaTipo, $factura, $generados);
    return $generados;
  }

  /**
   * Legacy trata los plazos comerciales de 30 días como meses naturales.
   * Así, 16/07 + 30 vence el 16/08, no el 15/08.
   */
  private function sumarPlazo(DateTimeImmutable $fecha, int $dias): DateTimeImmutable
  {
    if ($dias !== 0 && $dias % 30 === 0) {
      $meses = intdiv($dias, 30);
      $destino = $fecha
        ->modify('first day of this month')
        ->modify(($meses >= 0 ? '+' : '') . $meses . ' months');
      return $destino->setDate(
        (int) $destino->format('Y'),
        (int) $destino->format('m'),
        min((int) $fecha->format('d'), (int) $destino->format('t'))
      );
    }
    return $fecha->modify(($dias >= 0 ? '+' : '') . $dias . ' days');
  }

  /**
   * @return list<array{recibo: int, importe: float, vencimiento: string}>
   */
  private function cargarExistentes(string $empresa, string $facturaTipo, int $factura): array
  {
    $stmt = $this->pdo->prepare(
      'SELECT Recibo, Importe, CONVERT(varchar(10), Vencimiento, 23) AS Vencimiento
       FROM Recibos
       WHERE Empresa = :empresa AND FacturaTipo = :facturaTipo AND Factura = :factura
       ORDER BY Recibo'
    );
    $stmt->execute([
      'empresa' => $empresa,
      'facturaTipo' => $facturaTipo,
      'factura' => $factura,
    ]);

    $result = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
      $result[] = [
        'recibo' => (int) $row['Recibo'],
        'importe' => round((float) $row['Importe'], 2),
        'vencimiento' => (string) $row['Vencimiento'],
      ];
    }
    return $result;
  }

  private function ajustarDiaPago(DateTimeImmutable $fecha, int $dia1, int $dia2): DateTimeImmutable
  {
    $dias = array_values(array_unique(array_filter(
      [$dia1, $dia2],
      static fn (int $dia): bool => $dia >= 1 && $dia <= 31
    )));
    if ($dias === []) {
      return $fecha;
    }

    $candidatas = [];
    for ($mes = 0; $mes <= 1; $mes++) {
      $base = $fecha->modify("first day of +{$mes} month");
      $ultimoDia = (int) $base->format('t');
      foreach ($dias as $dia) {
        $candidata = $base->setDate(
          (int) $base->format('Y'),
          (int) $base->format('m'),
          min($dia, $ultimoDia)
        );
        if ($candidata >= $fecha) {
          $candidatas[] = $candidata;
        }
      }
      if ($candidatas !== []) {
        break;
      }
    }

    usort(
      $candidatas,
      static fn (DateTimeImmutable $a, DateTimeImmutable $b): int => $a <=> $b
    );
    return $candidatas[0] ?? $fecha;
  }

  /**
   * @param list<array{recibo: int, importe: float, vencimiento: string}> $recibos
   */
  private function actualizarVencimientosFactura(
    string $empresa,
    string $facturaTipo,
    int $factura,
    array $recibos
  ): void {
    $sets = [];
    $params = [
      'empresa' => $empresa,
      'facturaTipo' => $facturaTipo,
      'factura' => $factura,
    ];
    for ($i = 1; $i <= 6; $i++) {
      $recibo = $recibos[$i - 1] ?? null;
      $sets[] = "Vencimiento{$i} = "
        . ($recibo === null ? 'NULL' : "CONVERT(datetime, :vencimiento{$i}, 120)");
      $sets[] = "Importe{$i} = :importe{$i}";
      if ($recibo !== null) {
        $params["vencimiento{$i}"] = $recibo['vencimiento'] . ' 00:00:00';
      }
      $params["importe{$i}"] = $recibo['importe'] ?? 0;
    }

    $sql = 'UPDATE Facturas SET ' . implode(', ', $sets)
      . ' WHERE Empresa = :empresa AND FacturaTipo = :facturaTipo AND Factura = :factura';
    $this->pdo->prepare($sql)->execute($params);
  }
}
