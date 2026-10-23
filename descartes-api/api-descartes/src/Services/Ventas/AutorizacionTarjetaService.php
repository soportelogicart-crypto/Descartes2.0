<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Ventas;

use PDO;

/**
 * Cobros con tarjeta en TPV (tabla legacy Autorizaciones).
 * Necesaria para devoluciones Redsys (pedido / RTS / autorización del cobro original).
 */
final class AutorizacionTarjetaService
{
  private PDO $pdo;
  private RedsysLogTarjetaService $logTarjeta;

  public function __construct(PDO $pdo, ?RedsysLogTarjetaService $logTarjeta = null)
  {
    $this->pdo = $pdo;
    $this->logTarjeta = $logTarjeta ?? new RedsysLogTarjetaService();
  }

  /**
   * @return array<string, mixed>|null
   */
  public function buscarPorAlbaran(string $empresa, int $albaran): ?array
  {
    if ($albaran <= 0) {
      return null;
    }
    $empresas = $this->variantesEmpresa($empresa);
    $placeholders = implode(',', array_fill(0, count($empresas), '?'));
    $st = $this->pdo->prepare(
      "SELECT TOP 1 Empresa, Puesto, Sesion, Albaran, FormaPago, Tarjeta, Importe,
              Autorizacion, NumRefTP, identificadorRTS, Operacion, TipoOperacion,
              Comercio, TPV, Fecha, AID, LBL, ARC, marcaTarjeta
       FROM Autorizaciones
       WHERE Empresa IN ({$placeholders}) AND Albaran = ?
       ORDER BY Fecha DESC"
    );
    $st->execute([...$empresas, $albaran]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      return null;
    }

    return $this->mapRow($row);
  }

  /**
   * Igual que el TPV legacy (AUT + CLR): localiza Operacion/RTS en Autorizaciones.
   *
   * @return array<string, mixed>|null
   */
  public function resolverParaDevolucion(
    string $empresa,
    string $autorizacion,
    string $clr,
    ?float $importe = null,
    ?int $albaranOrigen = null
  ): ?array {
    if ($albaranOrigen !== null && $albaranOrigen > 0) {
      $porAlbaran = $this->buscarPorAlbaran($empresa, $albaranOrigen);
      if ($porAlbaran !== null && trim((string) ($porAlbaran['operacion'] ?? '')) !== '') {
        return $porAlbaran;
      }
    }

    $porAut = $this->buscarPorAutClr($empresa, $autorizacion, $clr, $importe);
    if ($porAut !== null && trim((string) ($porAut['identificadorRts'] ?? '')) !== '') {
      return $porAut;
    }

    $log = $this->logTarjeta->buscarCobro($autorizacion, $clr, $importe);
    if ($log !== null) {
      return $log;
    }

    return $porAut;
  }

  /**
   * @return array<string, mixed>|null
   */
  public function buscarPorAutClr(
    string $empresa,
    string $autorizacion,
    string $clr,
    ?float $importe = null
  ): ?array {
    $autCandidatos = $this->candidatosAutorizacion($autorizacion);
    if ($autCandidatos === []) {
      return null;
    }
    $empresas = $this->variantesEmpresa($empresa);
    $autPh = implode(',', array_fill(0, count($autCandidatos), '?'));
    $empPh = implode(',', array_fill(0, count($empresas), '?'));
    $sql = "SELECT Empresa, Puesto, Sesion, Albaran, FormaPago, Tarjeta, Importe,
              Autorizacion, NumRefTP, identificadorRTS, Operacion, TipoOperacion,
              Comercio, TPV, Fecha, AID, LBL, ARC, marcaTarjeta
       FROM Autorizaciones
       WHERE Empresa IN ({$empPh}) AND LTRIM(RTRIM(Autorizacion)) IN ({$autPh})";
    $params = [...$empresas, ...$autCandidatos];
    $ref = trim($clr);
    if ($ref !== '') {
      $sql .= ' AND LTRIM(RTRIM(NumRefTP)) = ?';
      $params[] = mb_substr($ref, 0, 10);
    }
    if ($importe !== null && abs($importe) > 0.0001) {
      $sql .= ' AND ABS(Importe - ?) < 0.02';
      $params[] = abs($importe);
    }
    $sql .= ' ORDER BY Fecha DESC';
    $st = $this->pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if ($rows === []) {
      return null;
    }

    return $this->mapRow($rows[0]);
  }

  /**
   * @return list<string>
   */
  private function variantesEmpresa(string $empresa): array
  {
    $e = trim($empresa);
    $out = [$e];
    $sinCeros = ltrim($e, '0');
    if ($sinCeros !== '' && $sinCeros !== $e) {
      $out[] = $sinCeros;
    }
    if ($e !== '' && strlen($e) < 3) {
      $out[] = str_pad($e, 3, '0', STR_PAD_LEFT);
    }
    if ($sinCeros !== '' && strlen($sinCeros) < 3) {
      $out[] = str_pad($sinCeros, 3, '0', STR_PAD_LEFT);
    }

    return array_values(array_unique($out));
  }

  /**
   * @return list<string>
   */
  private function candidatosAutorizacion(string $autorizacion): array
  {
    $aut = trim($autorizacion);
    if ($aut === '') {
      return [];
    }
    $cands = [$aut, mb_substr($aut, 0, 15)];
    $digits = preg_replace('/\D/', '', $aut) ?? '';
    if ($digits !== '') {
      $cands[] = $digits;
      $cands[] = ltrim($digits, '0');
      $cands[] = str_pad(ltrim($digits, '0') ?: '0', 6, '0', STR_PAD_LEFT);
    }

    return array_values(array_unique(array_filter(array_map('strval', $cands))));
  }

  /**
   * @param array<string, mixed> $body
   */
  public function registrar(string $empresa, string $puesto, int $sesion, int $albaran, array $body): void
  {
    $empresa = trim($empresa);
    $puesto = trim($puesto);
    if ($empresa === '' || $puesto === '' || $albaran <= 0 || $sesion <= 0) {
      throw new \InvalidArgumentException('Faltan datos de venta para registrar la autorización');
    }

    $formaPago = max(1, (int) ($body['formaPago'] ?? 1));
    $tarjeta = trim((string) ($body['tarjeta'] ?? ''));
    $autorizacion = trim((string) ($body['autorizacion'] ?? ''));
    $numRef = trim((string) ($body['numRefTp'] ?? $body['clr'] ?? ''));
    $rts = trim((string) ($body['identificadorRts'] ?? $body['identificadorRTS'] ?? ''));
    $pedidoRedsys = trim((string) ($body['pedidoRedsys'] ?? $body['pedido'] ?? ''));
    $importe = (float) ($body['importe'] ?? 0);
    $operacion = $pedidoRedsys !== '' ? $pedidoRedsys : trim((string) ($body['operacion'] ?? ''));

    $st = $this->pdo->prepare(
      'SELECT COUNT(*) FROM Autorizaciones
       WHERE Empresa = :e AND Puesto = :p AND Sesion = :s AND Albaran = :a AND FormaPago = :fp'
    );
    $st->execute([
      'e' => $empresa,
      'p' => $puesto,
      's' => $sesion,
      'a' => $albaran,
      'fp' => $formaPago,
    ]);
    $exists = (int) $st->fetchColumn() > 0;

    $params = [
      'tarjeta' => $tarjeta !== '' ? mb_substr($tarjeta, 0, 20) : null,
      'importe' => $importe,
      'autorizacion' => $autorizacion !== '' ? mb_substr($autorizacion, 0, 15) : null,
      'numRef' => $numRef !== '' ? mb_substr($numRef, 0, 10) : null,
      'rts' => $rts !== '' ? mb_substr($rts, 0, 30) : null,
      'operacion' => $operacion !== '' ? mb_substr($operacion, 0, 15) : null,
      'tipoOp' => mb_substr(trim((string) ($body['tipoOperacion'] ?? 'PAGO')), 0, 20),
      'comercio' => mb_substr(trim((string) ($body['comercio'] ?? '')), 0, 20) ?: null,
      'tpv' => mb_substr(trim((string) ($body['tpv'] ?? '')), 0, 20) ?: null,
      'aid' => mb_substr(trim((string) ($body['aid'] ?? '')), 0, 20) ?: null,
      'lbl' => mb_substr(trim((string) ($body['lbl'] ?? '')), 0, 20) ?: null,
      'arc' => mb_substr(trim((string) ($body['arc'] ?? '')), 0, 2) ?: null,
      'marca' => mb_substr(trim((string) ($body['marcaTarjeta'] ?? '')), 0, 3) ?: null,
    ];

    if ($exists) {
      $sql = 'UPDATE Autorizaciones SET
          Tarjeta = :tarjeta, Importe = :importe, Autorizacion = :autorizacion,
          NumRefTP = :numRef, identificadorRTS = :rts, Operacion = :operacion,
          TipoOperacion = :tipoOp, Comercio = :comercio, TPV = :tpv,
          Fecha = GETDATE(), AID = :aid, LBL = :lbl, ARC = :arc, marcaTarjeta = :marca
        WHERE Empresa = :e AND Puesto = :p AND Sesion = :s AND Albaran = :a AND FormaPago = :fp';
    } else {
      $sql = 'INSERT INTO Autorizaciones (
          Empresa, Puesto, Sesion, Albaran, FormaPago, Tarjeta, Importe,
          Autorizacion, NumRefTP, identificadorRTS, Operacion, TipoOperacion,
          Comercio, TPV, Fecha, TrasModem, Validado, AID, LBL, ARC, marcaTarjeta
        ) VALUES (
          :e, :p, :s, :a, :fp, :tarjeta, :importe,
          :autorizacion, :numRef, :rts, :operacion, :tipoOp,
          :comercio, :tpv, GETDATE(), 0, 1, :aid, :lbl, :arc, :marca
        )';
    }

    $this->pdo->prepare($sql)->execute(array_merge($params, [
      'e' => $empresa,
      'p' => $puesto,
      's' => $sesion,
      'a' => $albaran,
      'fp' => $formaPago,
    ]));
  }

  /**
   * @param array<string, mixed> $row
   * @return array<string, mixed>
   */
  private function mapRow(array $row): array
  {
    $tarjeta = trim((string) ($row['Tarjeta'] ?? ''));
    $numRef = trim((string) ($row['NumRefTP'] ?? ''));
    if ($numRef === '' && $tarjeta !== '') {
      $digits = preg_replace('/\D/', '', $tarjeta) ?? '';
      if (strlen($digits) >= 4) {
        $numRef = substr($digits, -4);
      }
    }

    return [
      'empresa' => (string) ($row['Empresa'] ?? ''),
      'puesto' => (string) ($row['Puesto'] ?? ''),
      'sesion' => (int) ($row['Sesion'] ?? 0),
      'albaran' => (int) ($row['Albaran'] ?? 0),
      'formaPago' => (int) ($row['FormaPago'] ?? 0),
      'tarjeta' => $tarjeta,
      'importe' => (float) ($row['Importe'] ?? 0),
      'autorizacion' => trim((string) ($row['Autorizacion'] ?? '')),
      'clr' => $numRef,
      'numRefTp' => $numRef,
      'identificadorRts' => trim((string) ($row['identificadorRTS'] ?? '')),
      'pedidoRedsys' => trim((string) ($row['Operacion'] ?? '')),
      'operacion' => trim((string) ($row['Operacion'] ?? '')),
      'tipoOperacion' => trim((string) ($row['TipoOperacion'] ?? '')),
      'comercio' => trim((string) ($row['Comercio'] ?? '')),
      'tpv' => trim((string) ($row['TPV'] ?? '')),
      'fecha' => $row['Fecha'] ?? null,
    ];
  }
}
