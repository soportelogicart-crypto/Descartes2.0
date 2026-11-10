<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Ventas;

use Descartes\Api\Services\Instalacion\MejorasGestionSchema;
use PDO;

/**
 * Pagos a cuenta de un albarán a crédito. Cada uno entra en la caja abierta
 * en ese momento. El total queda en AlbaranesVentasCab.PagoaCuenta.
 */
final class PagoACuentaAlbaranService
{
  private PDO $pdo;
  private ArqueoService $arqueo;

  public function __construct(PDO $pdo, ArqueoService $arqueo)
  {
    $this->pdo = $pdo;
    $this->arqueo = $arqueo;
    try {
      MejorasGestionSchema::asegurar($pdo);
    } catch (\Throwable $e) {
      // La operación comprueba la tabla y explica el fallo.
    }
  }

  /** @return array<string, mixed> */
  public function listar(string $empresa, string $tipo, int $albaran): array
  {
    $cab = $this->cabecera($empresa, $tipo, $albaran);
    return $this->respuesta($empresa, $tipo, $albaran, $cab);
  }

  /** @param array<string, mixed> $body */
  public function crear(string $empresa, string $tipo, int $albaran, array $body): array
  {
    return $this->transaccion(function () use ($empresa, $tipo, $albaran, $body) {
      $cab = $this->cabecera($empresa, $tipo, $albaran);
      $this->exigirEditable($cab);
      $importe = round((float) ($body['importe'] ?? 0), 2);
      if ($importe <= 0) {
        throw new \InvalidArgumentException('Indique un importe mayor que cero');
      }
      $forma = $this->exigirEfectivo((string) ($body['formaPago'] ?? ''));
      $puesto = $this->puestoDe($body, $cab);
      $caja = $this->exigirCajaAbierta($puesto, $empresa);

      $nro = $this->siguienteNro($empresa, $tipo, $albaran);
      $this->insertarMovimiento(
        $empresa,
        $tipo,
        $albaran,
        $nro,
        1,
        $importe,
        $forma,
        $caja,
        date('Y-m-d H:i:s')
      );
      $this->arqueo->addArq($caja['empresa'], $caja['puesto'], $caja['sesion'], $forma, $importe);
      $this->recalcularCabecera($empresa, $tipo, $albaran);

      return $this->respuesta($empresa, $tipo, $albaran, $cab);
    });
  }

  /** @param array<string, mixed> $body */
  public function actualizar(
    string $empresa,
    string $tipo,
    int $albaran,
    int $nro,
    array $body
  ): array {
    return $this->transaccion(function () use ($empresa, $tipo, $albaran, $nro, $body) {
      $cab = $this->cabecera($empresa, $tipo, $albaran);
      $this->exigirEditable($cab);
      $importe = round((float) ($body['importe'] ?? 0), 2);
      if ($importe <= 0) {
        throw new \InvalidArgumentException('Indique un importe mayor que cero');
      }
      $forma = $this->exigirEfectivo((string) ($body['formaPago'] ?? ''));
      $this->aplicarImporte($empresa, $tipo, $albaran, $nro, $importe, $forma, $body, $cab);
      $this->recalcularCabecera($empresa, $tipo, $albaran);

      return $this->respuesta($empresa, $tipo, $albaran, $cab);
    });
  }

  /** @param array<string, mixed> $body */
  public function eliminar(string $empresa, string $tipo, int $albaran, int $nro, array $body = []): array
  {
    return $this->transaccion(function () use ($empresa, $tipo, $albaran, $nro, $body) {
      $cab = $this->cabecera($empresa, $tipo, $albaran);
      $this->exigirEditable($cab);
      $this->aplicarImporte($empresa, $tipo, $albaran, $nro, 0.0, '', $body, $cab);
      $this->recalcularCabecera($empresa, $tipo, $albaran);

      return $this->respuesta($empresa, $tipo, $albaran, $cab);
    });
  }

  /** @param array<string, mixed> $cab */
  private function exigirEditable(array $cab): void
  {
    $motivo = $this->motivoBloqueo($cab);
    if ($motivo !== '') {
      throw new \RuntimeException($motivo, 409);
    }
  }

  /** @param array<string, mixed> $cab */
  private function motivoBloqueo(array $cab): string
  {
    $tipoDoc = strtoupper(trim((string) ($cab['FacturaTipo'] ?? '')));
    if ((int) ($cab['Factura'] ?? 0) > 0 || $tipoDoc === 'T' || $tipoDoc === 'F' || $tipoDoc === 'A') {
      return 'El albarán ya está facturado';
    }
    $cliente = trim((string) ($cab['Cliente'] ?? ''));
    if ($cliente === '') {
      return 'El albarán no tiene un cliente';
    }
    $stmt = $this->pdo->prepare('SELECT FormaPago FROM Clientes WHERE Codigo = :c');
    $stmt->execute(['c' => $cliente]);
    $formaCliente = $stmt->fetchColumn();
    $stmt->closeCursor();
    if ($formaCliente === false) {
      return 'El cliente no existe';
    }
    $formaCliente = trim((string) $formaCliente);
    if ($formaCliente === '') {
      return '';
    }
    $stmt = $this->pdo->prepare(
      'SELECT CobroDeArqueo FROM FormasPago WHERE RTRIM(Codigo) = :c'
    );
    $stmt->execute(['c' => $formaCliente]);
    $cobro = $stmt->fetchColumn();
    $stmt->closeCursor();
    if ($cobro !== false && (int) $cobro !== 0) {
      return 'El cliente es de contado y no puede llevar pagos a cuenta';
    }
    return '';
  }

  private function exigirEfectivo(string $codigo): string
  {
    $codigo = substr(trim($codigo), 0, 5);
    if ($codigo === '') {
      throw new \InvalidArgumentException('Indique la forma de pago');
    }
    $stmt = $this->pdo->prepare(
      'SELECT CobroDeArqueo FROM FormasPago WHERE RTRIM(Codigo) = :c'
    );
    $stmt->execute(['c' => $codigo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    if ($row === false) {
      throw new \InvalidArgumentException('La forma de pago no existe');
    }
    if (empty($row['CobroDeArqueo'])) {
      throw new \InvalidArgumentException('La forma de pago tiene que tener cobro de arqueo');
    }
    return $codigo;
  }

  /**
   * @param array<string, mixed> $body
   * @param array<string, mixed> $cab
   */
  private function puestoDe(array $body, array $cab): string
  {
    $puesto = trim((string) ($body['puesto'] ?? ''));
    if ($puesto === '') {
      $puesto = trim((string) ($cab['Puesto'] ?? ''));
    }
    if ($puesto === '') {
      throw new \InvalidArgumentException('Indique el puesto de la caja');
    }
    return substr($puesto, 0, 10);
  }

  /** @return array{empresa: string, puesto: string, sesion: int} */
  private function exigirCajaAbierta(string $puesto, string $empresaVenta): array
  {
    $ctx = $this->arqueo->asegurarSesionPuesto($puesto, $empresaVenta);
    $empresa = $ctx['empresa'] !== '' ? $ctx['empresa'] : trim($empresaVenta);
    $sesion = (int) $ctx['sesion'];
    if ($empresa === '' || $puesto === '' || $sesion <= 0) {
      throw new \RuntimeException('No hay una caja abierta en este puesto', 409);
    }
    $stmt = $this->pdo->prepare(
      'SELECT Cerrada FROM Sesiones WHERE Empresa = :e AND Puesto = :p AND Sesion = :s'
    );
    $stmt->execute(['e' => $empresa, 'p' => $puesto, 's' => $sesion]);
    $cerrada = $stmt->fetchColumn();
    $stmt->closeCursor();
    if ($cerrada === false || (int) $cerrada !== 0) {
      throw new \RuntimeException('No hay una caja abierta en este puesto', 409);
    }
    return ['empresa' => $empresa, 'puesto' => $puesto, 'sesion' => $sesion];
  }

  /** @return array{empresa: string, puesto: string, sesion: int}|null */
  private function cajaAbierta(string $puesto, string $empresaVenta): ?array
  {
    $puesto = trim($puesto);
    if ($puesto === '') {
      return null;
    }
    $empresa = $this->arqueo->empresaArqueoPuesto($puesto, $empresaVenta);
    $stmt = $this->pdo->prepare('SELECT UltSesion FROM Puestos WHERE Puesto = :p');
    $stmt->execute(['p' => $puesto]);
    $sesion = (int) ($stmt->fetchColumn() ?: 0);
    $stmt->closeCursor();
    if ($empresa === '' || $sesion <= 0) {
      return null;
    }
    $stmt = $this->pdo->prepare(
      'SELECT Cerrada FROM Sesiones WHERE Empresa = :e AND Puesto = :p AND Sesion = :s'
    );
    $stmt->execute(['e' => $empresa, 'p' => $puesto, 's' => $sesion]);
    $cerrada = $stmt->fetchColumn();
    $stmt->closeCursor();
    if ($cerrada === false || (int) $cerrada !== 0) {
      return null;
    }
    return ['empresa' => $empresa, 'puesto' => $puesto, 'sesion' => $sesion];
  }

  /**
   * @param array<string, mixed> $body
   * @param array<string, mixed> $cab
   */
  private function aplicarImporte(
    string $empresa,
    string $tipo,
    int $albaran,
    int $nro,
    float $nuevoImporte,
    string $nuevaForma,
    array $body,
    array $cab
  ): void {
    $movs = $this->movimientos($empresa, $tipo, $albaran, $nro);
    if ($movs === []) {
      throw new \RuntimeException('El pago a cuenta no existe', 404);
    }
    $neto = 0.0;
    foreach ($movs as $mov) {
      $neto += (float) $mov['Importe'];
    }
    $neto = round($neto, 2);
    $formaActual = $this->formaNeta($movs);
    $primero = $movs[0];
    $mismaCaja = $this->sesionAbierta(
      (string) $primero['EmpresaArqueo'],
      (string) $primero['Puesto'],
      (int) $primero['Sesion']
    ) && $this->todosEnLaMismaSesion($movs, $primero);

    if ($mismaCaja) {
      if ($nuevoImporte <= 0) {
        foreach ($movs as $mov) {
          $this->arqueo->addArq(
            (string) $mov['EmpresaArqueo'],
            (string) $mov['Puesto'],
            (int) $mov['Sesion'],
            (string) $mov['FormaPago'],
            -1 * (float) $mov['Importe']
          );
        }
        $this->borrarMovimientos($empresa, $tipo, $albaran, $nro);
        return;
      }
      if (abs($nuevoImporte - $neto) < 0.005 && $nuevaForma === $formaActual && count($movs) === 1) {
        return;
      }
      foreach ($movs as $mov) {
        $this->arqueo->addArq(
          (string) $mov['EmpresaArqueo'],
          (string) $mov['Puesto'],
          (int) $mov['Sesion'],
          (string) $mov['FormaPago'],
          -1 * (float) $mov['Importe']
        );
      }
      $this->borrarMovimientos($empresa, $tipo, $albaran, $nro);
      $this->insertarMovimiento(
        $empresa,
        $tipo,
        $albaran,
        $nro,
        1,
        $nuevoImporte,
        $nuevaForma,
        [
          'empresa' => (string) $primero['EmpresaArqueo'],
          'puesto' => (string) $primero['Puesto'],
          'sesion' => (int) $primero['Sesion'],
        ],
        $this->fechaSql($primero['Fecha'] ?? null)
      );
      $this->arqueo->addArq(
        (string) $primero['EmpresaArqueo'],
        (string) $primero['Puesto'],
        (int) $primero['Sesion'],
        $nuevaForma,
        $nuevoImporte
      );
      return;
    }

    $puesto = $this->puestoDe($body !== [] ? $body : ['puesto' => $primero['Puesto']], $cab);
    $caja = $this->exigirCajaAbierta($puesto, $empresa);
    if ($nuevoImporte <= 0) {
      if (abs($neto) < 0.005) {
        return;
      }
      $this->anotarDelta($empresa, $tipo, $albaran, $nro, $formaActual, -1 * $neto, $caja);
      return;
    }
    if ($nuevaForma === $formaActual) {
      $delta = round($nuevoImporte - $neto, 2);
      if (abs($delta) < 0.005) {
        return;
      }
      $this->anotarDelta($empresa, $tipo, $albaran, $nro, $nuevaForma, $delta, $caja);
      return;
    }
    if (abs($neto) >= 0.005) {
      $this->anotarDelta($empresa, $tipo, $albaran, $nro, $formaActual, -1 * $neto, $caja);
    }
    $this->anotarDelta($empresa, $tipo, $albaran, $nro, $nuevaForma, $nuevoImporte, $caja);
  }

  /** @param array{empresa: string, puesto: string, sesion: int} $caja */
  private function anotarDelta(
    string $empresa,
    string $tipo,
    int $albaran,
    int $nro,
    string $forma,
    float $importe,
    array $caja
  ): void {
    if (abs($importe) < 0.005 || $forma === '') {
      return;
    }
    $nroMov = $this->siguienteNroMov($empresa, $tipo, $albaran, $nro);
    $this->insertarMovimiento(
      $empresa,
      $tipo,
      $albaran,
      $nro,
      $nroMov,
      round($importe, 2),
      $forma,
      $caja,
      date('Y-m-d H:i:s')
    );
    $this->arqueo->addArq($caja['empresa'], $caja['puesto'], $caja['sesion'], $forma, round($importe, 2));
  }

  /** @param array{empresa: string, puesto: string, sesion: int} $caja */
  private function insertarMovimiento(
    string $empresa,
    string $tipo,
    int $albaran,
    int $nro,
    int $nroMov,
    float $importe,
    string $forma,
    array $caja,
    string $fecha
  ): void {
    $this->pdo->prepare(
      'INSERT INTO PagosACuentaAlbaran (
         Empresa, Tipo, Albaran, Nro, NroMov, Fecha, Importe, FormaPago, Puesto, Sesion, EmpresaArqueo, Contabilizado
       ) VALUES (
         :e, :t, :a, :nro, :mov, CONVERT(datetime, :fecha, 120), :importe, :forma, :puesto, :sesion, :empresaArq, 0
       )'
    )->execute([
      'e' => $empresa,
      't' => $tipo,
      'a' => $albaran,
      'nro' => $nro,
      'mov' => $nroMov,
      'fecha' => $fecha,
      'importe' => round($importe, 2),
      'forma' => $forma,
      'puesto' => $caja['puesto'],
      'sesion' => $caja['sesion'],
      'empresaArq' => $caja['empresa'],
    ]);
  }

  private function siguienteNro(string $empresa, string $tipo, int $albaran): int
  {
    $stmt = $this->pdo->prepare(
      'SELECT ISNULL(MAX(Nro), 0) FROM PagosACuentaAlbaran WITH (UPDLOCK, HOLDLOCK)
       WHERE Empresa = :e AND Tipo = :t AND Albaran = :a'
    );
    $stmt->execute(['e' => $empresa, 't' => $tipo, 'a' => $albaran]);
    $nro = (int) $stmt->fetchColumn() + 1;
    $stmt->closeCursor();
    return $nro;
  }

  private function siguienteNroMov(string $empresa, string $tipo, int $albaran, int $nro): int
  {
    $stmt = $this->pdo->prepare(
      'SELECT ISNULL(MAX(NroMov), 0) FROM PagosACuentaAlbaran WITH (UPDLOCK, HOLDLOCK)
       WHERE Empresa = :e AND Tipo = :t AND Albaran = :a AND Nro = :nro'
    );
    $stmt->execute(['e' => $empresa, 't' => $tipo, 'a' => $albaran, 'nro' => $nro]);
    $mov = (int) $stmt->fetchColumn() + 1;
    $stmt->closeCursor();
    return $mov;
  }

  /** @return list<array<string, mixed>> */
  private function movimientos(string $empresa, string $tipo, int $albaran, int $nro): array
  {
    $stmt = $this->pdo->prepare(
      'SELECT Nro, NroMov, Fecha, Importe, FormaPago, Puesto, Sesion, EmpresaArqueo
       FROM PagosACuentaAlbaran WITH (UPDLOCK, HOLDLOCK)
       WHERE Empresa = :e AND Tipo = :t AND Albaran = :a AND Nro = :nro
       ORDER BY NroMov'
    );
    $stmt->execute(['e' => $empresa, 't' => $tipo, 'a' => $albaran, 'nro' => $nro]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    return $rows;
  }

  private function borrarMovimientos(string $empresa, string $tipo, int $albaran, int $nro): void
  {
    $this->pdo->prepare(
      'DELETE FROM PagosACuentaAlbaran
       WHERE Empresa = :e AND Tipo = :t AND Albaran = :a AND Nro = :nro'
    )->execute(['e' => $empresa, 't' => $tipo, 'a' => $albaran, 'nro' => $nro]);
  }

  /** @param list<array<string, mixed>> $movs */
  private function formaNeta(array $movs): string
  {
    $porForma = [];
    foreach ($movs as $mov) {
      $forma = trim((string) $mov['FormaPago']);
      $porForma[$forma] = round(($porForma[$forma] ?? 0) + (float) $mov['Importe'], 2);
    }
    $mejor = '';
    $max = -1.0;
    foreach ($porForma as $forma => $importe) {
      if ($importe > $max) {
        $max = $importe;
        $mejor = (string) $forma;
      }
    }
    if ($mejor !== '') {
      return $mejor;
    }
    return trim((string) ($movs[count($movs) - 1]['FormaPago'] ?? ''));
  }

  /** @param list<array<string, mixed>> $movs @param array<string, mixed> $primero */
  private function todosEnLaMismaSesion(array $movs, array $primero): bool
  {
    foreach ($movs as $mov) {
      if (
        trim((string) $mov['EmpresaArqueo']) !== trim((string) $primero['EmpresaArqueo'])
        || trim((string) $mov['Puesto']) !== trim((string) $primero['Puesto'])
        || (int) $mov['Sesion'] !== (int) $primero['Sesion']
      ) {
        return false;
      }
    }
    return true;
  }

  private function sesionAbierta(string $empresa, string $puesto, int $sesion): bool
  {
    $stmt = $this->pdo->prepare(
      'SELECT Cerrada FROM Sesiones WHERE Empresa = :e AND Puesto = :p AND Sesion = :s'
    );
    $stmt->execute(['e' => trim($empresa), 'p' => trim($puesto), 's' => $sesion]);
    $cerrada = $stmt->fetchColumn();
    $stmt->closeCursor();
    return $cerrada !== false && (int) $cerrada === 0;
  }

  private function recalcularCabecera(string $empresa, string $tipo, int $albaran): float
  {
    $stmt = $this->pdo->prepare(
      'SELECT ISNULL(SUM(Importe), 0) FROM PagosACuentaAlbaran
       WHERE Empresa = :e AND Tipo = :t AND Albaran = :a'
    );
    $stmt->execute(['e' => $empresa, 't' => $tipo, 'a' => $albaran]);
    $total = round((float) $stmt->fetchColumn(), 2);
    $stmt->closeCursor();
    $this->pdo->prepare(
      'UPDATE AlbaranesVentasCab SET PagoaCuenta = :pago
       WHERE Empresa = :e AND Tipo = :t AND Albaran = :a'
    )->execute(['pago' => $total, 'e' => $empresa, 't' => $tipo, 'a' => $albaran]);
    return $total;
  }

  /** @return array<string, mixed> */
  private function cabecera(string $empresa, string $tipo, int $albaran): array
  {
    $empresa = trim($empresa);
    $tipo = trim($tipo);
    if ($empresa === '' || $tipo === '' || $albaran <= 0) {
      throw new \InvalidArgumentException('Albarán no válido');
    }
    $this->exigirTabla();
    $stmt = $this->pdo->prepare(
      'SELECT Cliente, Factura, FacturaTipo, Puesto
       FROM AlbaranesVentasCab
       WHERE Empresa = :e AND Tipo = :t AND Albaran = :a'
    );
    $stmt->execute(['e' => $empresa, 't' => $tipo, 'a' => $albaran]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    if ($row === false) {
      throw new \RuntimeException('Albarán no encontrado', 404);
    }
    return $row;
  }

  private function exigirTabla(): void
  {
    try {
      MejorasGestionSchema::asegurar($this->pdo);
    } catch (\Throwable $e) {
      throw new \RuntimeException('No se pudo preparar la tabla de pagos a cuenta', 500);
    }
    $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
      $stmt = $this->pdo->prepare(
        'SELECT 1 FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
      );
    } else {
      $stmt = $this->pdo->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = :t'
      );
    }
    $stmt->execute(['t' => 'PagosACuentaAlbaran']);
    $existe = (bool) $stmt->fetchColumn();
    $stmt->closeCursor();
    if (!$existe) {
      throw new \RuntimeException('No se pudo preparar la tabla de pagos a cuenta', 500);
    }
  }

  /** @param array<string, mixed> $cab @return array<string, mixed> */
  private function respuesta(string $empresa, string $tipo, int $albaran, array $cab): array
  {
    $stmt = $this->pdo->prepare(
      'SELECT Nro, NroMov, Fecha, Importe, FormaPago, Puesto, Sesion, EmpresaArqueo
       FROM PagosACuentaAlbaran
       WHERE Empresa = :e AND Tipo = :t AND Albaran = :a
       ORDER BY Nro, NroMov'
    );
    $stmt->execute(['e' => trim($empresa), 't' => trim($tipo), 'a' => $albaran]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    $formas = $this->formasEfectivo();
    $nombres = [];
    foreach ($formas as $forma) {
      $nombres[$forma['codigo']] = $forma['descripcion'];
    }

    $cajas = [];
    $grupos = [];
    foreach ($rows as $row) {
      $nro = (int) $row['Nro'];
      if (!isset($grupos[$nro])) {
        $grupos[$nro] = [
          'nro' => $nro,
          'fecha' => $this->fechaTexto($row['Fecha'] ?? ''),
          'importe' => 0.0,
          'formaPago' => '',
          'formaPagoNombre' => '',
          'puesto' => trim((string) $row['Puesto']),
          'sesion' => (int) $row['Sesion'],
          'enCajaHoy' => false,
          'formas' => [],
          'nombres' => [],
        ];
      }
      $importe = round((float) $row['Importe'], 2);
      $grupos[$nro]['importe'] = round($grupos[$nro]['importe'] + $importe, 2);
      $forma = trim((string) $row['FormaPago']);
      $grupos[$nro]['formas'][$forma] = round(($grupos[$nro]['formas'][$forma] ?? 0) + $importe, 2);
      $nombre = $nombres[$forma] ?? '';
      if ($nombre !== '') {
        $grupos[$nro]['nombres'][$forma] = $nombre;
      }
      $puesto = trim((string) $row['Puesto']);
      if (!array_key_exists($puesto, $cajas)) {
        $cajas[$puesto] = $this->cajaAbierta($puesto, $empresa);
      }
      $caja = $cajas[$puesto];
      if (
        $caja !== null
        && (int) $row['Sesion'] === $caja['sesion']
        && trim((string) $row['EmpresaArqueo']) === $caja['empresa']
      ) {
        $grupos[$nro]['enCajaHoy'] = true;
        $grupos[$nro]['puesto'] = $puesto;
        $grupos[$nro]['sesion'] = (int) $row['Sesion'];
      }
    }

    $items = [];
    $total = 0.0;
    foreach ($grupos as $grupo) {
      if (abs($grupo['importe']) < 0.005) {
        continue;
      }
      $forma = '';
      $max = -1.0;
      foreach ($grupo['formas'] as $codigo => $importeForma) {
        if ($importeForma > $max) {
          $max = $importeForma;
          $forma = (string) $codigo;
        }
      }
      $total = round($total + $grupo['importe'], 2);
      $items[] = [
        'nro' => $grupo['nro'],
        'fecha' => $grupo['fecha'],
        'importe' => $grupo['importe'],
        'formaPago' => $forma,
        'formaPagoNombre' => (string) ($grupo['nombres'][$forma] ?? ''),
        'puesto' => $grupo['puesto'],
        'sesion' => $grupo['sesion'],
        'enCajaHoy' => $grupo['enCajaHoy'],
      ];
    }

    $motivo = $this->motivoBloqueo($cab);
    return [
      'total' => $total,
      'editable' => $motivo === '',
      'motivo' => $motivo,
      'formasEfectivo' => $formas,
      'items' => $items,
    ];
  }

  /** @return list<array{codigo: string, descripcion: string}> */
  private function formasEfectivo(): array
  {
    $stmt = $this->pdo->query(
      'SELECT Codigo, Descripcion FROM FormasPago
       WHERE CobroDeArqueo <> 0
       ORDER BY Codigo'
    );
    $out = [];
    foreach ($stmt as $row) {
      $codigo = trim((string) ($row['Codigo'] ?? ''));
      if ($codigo === '') {
        continue;
      }
      $out[] = [
        'codigo' => $codigo,
        'descripcion' => trim((string) ($row['Descripcion'] ?? $codigo)),
      ];
    }
    $stmt->closeCursor();
    return $out;
  }

  private function fechaSql(mixed $valor): string
  {
    if ($valor instanceof \DateTimeInterface) {
      return $valor->format('Y-m-d H:i:s');
    }
    $texto = trim((string) $valor);
    $ts = $texto === '' ? false : strtotime($texto);
    return $ts === false ? date('Y-m-d H:i:s') : date('Y-m-d H:i:s', $ts);
  }

  private function fechaTexto(mixed $valor): string
  {
    if ($valor instanceof \DateTimeInterface) {
      return $valor->format('d/m/Y H:i');
    }
    $texto = trim((string) $valor);
    if ($texto === '') {
      return '';
    }
    $ts = strtotime($texto);
    if ($ts === false) {
      return substr($texto, 0, 16);
    }
    return date('d/m/Y H:i', $ts);
  }

  /** @param callable(): array<string, mixed> $fn @return array<string, mixed> */
  private function transaccion(callable $fn): array
  {
    $propia = !$this->pdo->inTransaction();
    if ($propia) {
      $this->pdo->beginTransaction();
    }
    try {
      $res = $fn();
      if ($propia) {
        $this->pdo->commit();
      }
      return $res;
    } catch (\Throwable $e) {
      if ($propia && $this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      throw $e;
    }
  }
}
