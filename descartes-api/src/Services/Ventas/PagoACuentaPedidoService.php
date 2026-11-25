<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Ventas;

use Descartes\Api\Services\Instalacion\MejorasGestionSchema;
use PDO;

/**
 * Pagos a cuenta de un pedido de cliente. Cada uno entra en la caja abierta
 * en ese momento. Al generar el albarán se traspasan sin volver a tocar la caja.
 */
final class PagoACuentaPedidoService
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
  public function listar(string $empresa, int $pedido): array
  {
    $cab = $this->cabecera($empresa, $pedido);
    return $this->respuesta($empresa, $pedido, $cab);
  }

  /** @param array<string, mixed> $body */
  public function crear(string $empresa, int $pedido, array $body): array
  {
    return $this->transaccion(function () use ($empresa, $pedido, $body) {
      $cab = $this->cabecera($empresa, $pedido);
      $this->exigirEditable($cab);
      $importe = round((float) ($body['importe'] ?? 0), 2);
      if ($importe <= 0) {
        throw new \InvalidArgumentException('Indique un importe mayor que cero');
      }
      $forma = $this->exigirEfectivo((string) ($body['formaPago'] ?? ''));
      $puesto = $this->puestoDe($body, $cab);
      $caja = $this->exigirCajaAbierta($puesto, $empresa);

      $nro = $this->siguienteNro($empresa, $pedido);
      $this->insertarMovimiento($empresa, $pedido, $nro, 1, $importe, $forma, $caja, date('Y-m-d H:i:s'), 0);
      $this->arqueo->addArq($caja['empresa'], $caja['puesto'], $caja['sesion'], $forma, $importe);

      return $this->respuesta($empresa, $pedido, $cab);
    });
  }

  /** @param array<string, mixed> $body */
  public function actualizar(string $empresa, int $pedido, int $nro, array $body): array
  {
    return $this->transaccion(function () use ($empresa, $pedido, $nro, $body) {
      $cab = $this->cabecera($empresa, $pedido);
      $this->exigirEditable($cab);
      $importe = round((float) ($body['importe'] ?? 0), 2);
      if ($importe <= 0) {
        throw new \InvalidArgumentException('Indique un importe mayor que cero');
      }
      $forma = $this->exigirEfectivo((string) ($body['formaPago'] ?? ''));
      $this->aplicarImporte($empresa, $pedido, $nro, $importe, $forma, $body, $cab);

      return $this->respuesta($empresa, $pedido, $cab);
    });
  }

  /** @param array<string, mixed> $body */
  public function eliminar(string $empresa, int $pedido, int $nro, array $body = []): array
  {
    return $this->transaccion(function () use ($empresa, $pedido, $nro, $body) {
      $cab = $this->cabecera($empresa, $pedido);
      $this->exigirEditable($cab);
      $this->aplicarImporte($empresa, $pedido, $nro, 0.0, '', $body, $cab);

      return $this->respuesta($empresa, $pedido, $cab);
    });
  }

  /**
   * Mueve pagos del pedido a los albaranes, en orden, hasta cubrir el importe de cada uno.
   * No vuelve a sumar en el arqueo: el dinero ya entró al anotarlo en el pedido.
   *
   * @param list<array{tipo: string, albaran: int, importe: float}> $albaranes
   * @return array<string, float> totales a cuenta por "tipo|albaran"
   */
  public function traspasarAAlbaranes(string $empresa, int $pedido, array $albaranes): array
  {
    return $this->transaccion(function () use ($empresa, $pedido, $albaranes) {
      $this->exigirTabla();
      $empresa = trim($empresa);
      $grupos = $this->gruposNetos($empresa, $pedido);
      $plan = RepartoPagosPedido::repartir($grupos, $albaranes);
      $tocados = [];
      foreach ($plan['asignaciones'] as $asig) {
        $destino = $albaranes[$asig['indice']] ?? null;
        if ($destino === null) {
          continue;
        }
        $tipo = trim((string) ($destino['tipo'] ?? ''));
        $albaran = (int) ($destino['albaran'] ?? 0);
        if ($tipo === '' || $albaran <= 0) {
          continue;
        }
        $this->aplicarAsignacion($empresa, $pedido, $asig['nro'], (float) $asig['importe'], (bool) $asig['completo'], $tipo, $albaran);
        $tocados[$tipo . '|' . $albaran] = ['tipo' => $tipo, 'albaran' => $albaran];
      }
      $totales = [];
      foreach ($tocados as $clave => $dest) {
        $totales[$clave] = $this->recalcularCabeceraAlbaran($empresa, $dest['tipo'], $dest['albaran']);
      }
      return $totales;
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
    if (!empty($cab['Actualizado'])) {
      return 'El pedido está cerrado';
    }
    $cliente = trim((string) ($cab['Cliente'] ?? ''));
    if ($cliente === '') {
      return 'El pedido no tiene un cliente';
    }
    $stmt = $this->pdo->prepare('SELECT 1 FROM Clientes WHERE Codigo = :c');
    $stmt->execute(['c' => $cliente]);
    $existe = $stmt->fetchColumn();
    $stmt->closeCursor();
    if ($existe === false) {
      return 'El cliente no existe';
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
    int $pedido,
    int $nro,
    float $nuevoImporte,
    string $nuevaForma,
    array $body,
    array $cab
  ): void {
    $movs = $this->movimientos($empresa, $pedido, $nro);
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
        $this->borrarMovimientos($empresa, $pedido, $nro);
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
      $this->borrarMovimientos($empresa, $pedido, $nro);
      $this->insertarMovimiento(
        $empresa,
        $pedido,
        $nro,
        1,
        $nuevoImporte,
        $nuevaForma,
        [
          'empresa' => (string) $primero['EmpresaArqueo'],
          'puesto' => (string) $primero['Puesto'],
          'sesion' => (int) $primero['Sesion'],
        ],
        $this->fechaSql($primero['Fecha'] ?? null),
        (int) ($primero['Contabilizado'] ?? 0)
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
      $this->anotarDelta($empresa, $pedido, $nro, $formaActual, -1 * $neto, $caja);
      return;
    }
    if ($nuevaForma === $formaActual) {
      $delta = round($nuevoImporte - $neto, 2);
      if (abs($delta) < 0.005) {
        return;
      }
      $this->anotarDelta($empresa, $pedido, $nro, $nuevaForma, $delta, $caja);
      return;
    }
    if (abs($neto) >= 0.005) {
      $this->anotarDelta($empresa, $pedido, $nro, $formaActual, -1 * $neto, $caja);
    }
    $this->anotarDelta($empresa, $pedido, $nro, $nuevaForma, $nuevoImporte, $caja);
  }

  /** @param array{empresa: string, puesto: string, sesion: int} $caja */
  private function anotarDelta(
    string $empresa,
    int $pedido,
    int $nro,
    string $forma,
    float $importe,
    array $caja
  ): void {
    if (abs($importe) < 0.005 || $forma === '') {
      return;
    }
    $nroMov = $this->siguienteNroMov($empresa, $pedido, $nro);
    $this->insertarMovimiento(
      $empresa,
      $pedido,
      $nro,
      $nroMov,
      round($importe, 2),
      $forma,
      $caja,
      date('Y-m-d H:i:s'),
      0
    );
    $this->arqueo->addArq($caja['empresa'], $caja['puesto'], $caja['sesion'], $forma, round($importe, 2));
  }

  /** @param array{empresa: string, puesto: string, sesion: int} $caja */
  private function insertarMovimiento(
    string $empresa,
    int $pedido,
    int $nro,
    int $nroMov,
    float $importe,
    string $forma,
    array $caja,
    string $fecha,
    int $contabilizado
  ): void {
    $this->pdo->prepare(
      'INSERT INTO PagosACuentaPedido (
         Empresa, Pedido, Nro, NroMov, Fecha, Importe, FormaPago, Puesto, Sesion, EmpresaArqueo, Contabilizado
       ) VALUES (
         :e, :p, :nro, :mov, CONVERT(datetime, :fecha, 120), :importe, :forma, :puesto, :sesion, :empresaArq, :contab
       )'
    )->execute([
      'e' => $empresa,
      'p' => $pedido,
      'nro' => $nro,
      'mov' => $nroMov,
      'fecha' => $fecha,
      'importe' => round($importe, 2),
      'forma' => $forma,
      'puesto' => $caja['puesto'],
      'sesion' => $caja['sesion'],
      'empresaArq' => $caja['empresa'],
      'contab' => $contabilizado ? 1 : 0,
    ]);
  }

  private function aplicarAsignacion(
    string $empresa,
    int $pedido,
    int $nro,
    float $importe,
    bool $completo,
    string $tipo,
    int $albaran
  ): void {
    $movs = $this->movimientos($empresa, $pedido, $nro);
    if ($movs === []) {
      throw new \RuntimeException('El pago a cuenta del pedido ya no está', 409);
    }
    $importe = round($importe, 2);
    if ($completo) {
      $nroAlb = $this->siguienteNroAlbaran($empresa, $tipo, $albaran);
      $nroMov = 0;
      foreach ($movs as $mov) {
        $nroMov++;
        $this->insertarMovimientoAlbaran($empresa, $tipo, $albaran, $nroAlb, $nroMov, $mov);
      }
      $this->borrarMovimientos($empresa, $pedido, $nro);
      return;
    }
    $primero = $movs[0];
    $forma = $this->formaNeta($movs);
    $contab = (int) ($primero['Contabilizado'] ?? 0);
    $fecha = $this->fechaSql($primero['Fecha'] ?? null);
    $caja = [
      'empresa' => (string) $primero['EmpresaArqueo'],
      'puesto' => (string) $primero['Puesto'],
      'sesion' => (int) $primero['Sesion'],
    ];
    $nroAlb = $this->siguienteNroAlbaran($empresa, $tipo, $albaran);
    $this->insertarMovimientoAlbaran($empresa, $tipo, $albaran, $nroAlb, 1, [
      'Fecha' => $fecha,
      'Importe' => $importe,
      'FormaPago' => $forma,
      'Puesto' => $caja['puesto'],
      'Sesion' => $caja['sesion'],
      'EmpresaArqueo' => $caja['empresa'],
      'Contabilizado' => $contab,
    ]);
    $this->insertarMovimiento(
      $empresa,
      $pedido,
      $nro,
      $this->siguienteNroMov($empresa, $pedido, $nro),
      -1 * $importe,
      $forma,
      $caja,
      $fecha,
      $contab
    );
  }

  /** @param array<string, mixed> $mov */
  private function insertarMovimientoAlbaran(
    string $empresa,
    string $tipo,
    int $albaran,
    int $nro,
    int $nroMov,
    array $mov
  ): void {
    $this->pdo->prepare(
      'INSERT INTO PagosACuentaAlbaran (
         Empresa, Tipo, Albaran, Nro, NroMov, Fecha, Importe, FormaPago, Puesto, Sesion, EmpresaArqueo, Contabilizado
       ) VALUES (
         :e, :t, :a, :nro, :mov, CONVERT(datetime, :fecha, 120), :importe, :forma, :puesto, :sesion, :empresaArq, :contab
       )'
    )->execute([
      'e' => $empresa,
      't' => $tipo,
      'a' => $albaran,
      'nro' => $nro,
      'mov' => $nroMov,
      'fecha' => $this->fechaSql($mov['Fecha'] ?? null),
      'importe' => round((float) $mov['Importe'], 2),
      'forma' => substr(trim((string) ($mov['FormaPago'] ?? '')), 0, 5),
      'puesto' => substr(trim((string) ($mov['Puesto'] ?? '')), 0, 10),
      'sesion' => (int) ($mov['Sesion'] ?? 0),
      'empresaArq' => substr(trim((string) ($mov['EmpresaArqueo'] ?? '')), 0, 10),
      'contab' => !empty($mov['Contabilizado']) ? 1 : 0,
    ]);
  }

  private function siguienteNro(string $empresa, int $pedido): int
  {
    $stmt = $this->pdo->prepare(
      'SELECT ISNULL(MAX(Nro), 0) FROM PagosACuentaPedido WITH (UPDLOCK, HOLDLOCK)
       WHERE Empresa = :e AND Pedido = :p'
    );
    $stmt->execute(['e' => $empresa, 'p' => $pedido]);
    $nro = (int) $stmt->fetchColumn() + 1;
    $stmt->closeCursor();
    return $nro;
  }

  private function siguienteNroMov(string $empresa, int $pedido, int $nro): int
  {
    $stmt = $this->pdo->prepare(
      'SELECT ISNULL(MAX(NroMov), 0) FROM PagosACuentaPedido WITH (UPDLOCK, HOLDLOCK)
       WHERE Empresa = :e AND Pedido = :p AND Nro = :nro'
    );
    $stmt->execute(['e' => $empresa, 'p' => $pedido, 'nro' => $nro]);
    $mov = (int) $stmt->fetchColumn() + 1;
    $stmt->closeCursor();
    return $mov;
  }

  private function siguienteNroAlbaran(string $empresa, string $tipo, int $albaran): int
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

  /** @return list<array<string, mixed>> */
  private function movimientos(string $empresa, int $pedido, int $nro): array
  {
    $stmt = $this->pdo->prepare(
      'SELECT Nro, NroMov, Fecha, Importe, FormaPago, Puesto, Sesion, EmpresaArqueo, Contabilizado
       FROM PagosACuentaPedido WITH (UPDLOCK, HOLDLOCK)
       WHERE Empresa = :e AND Pedido = :p AND Nro = :nro
       ORDER BY NroMov'
    );
    $stmt->execute(['e' => $empresa, 'p' => $pedido, 'nro' => $nro]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    return $rows;
  }

  /** @return list<array{nro: int, importe: float}> */
  private function gruposNetos(string $empresa, int $pedido): array
  {
    $stmt = $this->pdo->prepare(
      'SELECT Nro, ISNULL(SUM(Importe), 0) AS Importe
       FROM PagosACuentaPedido
       WHERE Empresa = :e AND Pedido = :p
       GROUP BY Nro
       ORDER BY Nro'
    );
    $stmt->execute(['e' => $empresa, 'p' => $pedido]);
    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $importe = round((float) $row['Importe'], 2);
      if ($importe <= 0.004) {
        continue;
      }
      $out[] = ['nro' => (int) $row['Nro'], 'importe' => $importe];
    }
    $stmt->closeCursor();
    return $out;
  }

  private function borrarMovimientos(string $empresa, int $pedido, int $nro): void
  {
    $this->pdo->prepare(
      'DELETE FROM PagosACuentaPedido WHERE Empresa = :e AND Pedido = :p AND Nro = :nro'
    )->execute(['e' => $empresa, 'p' => $pedido, 'nro' => $nro]);
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

  private function recalcularCabeceraAlbaran(string $empresa, string $tipo, int $albaran): float
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
  private function cabecera(string $empresa, int $pedido): array
  {
    $empresa = trim($empresa);
    if ($empresa === '' || $pedido <= 0) {
      throw new \InvalidArgumentException('Pedido no válido');
    }
    $this->exigirTabla();
    $stmt = $this->pdo->prepare(
      'SELECT Cliente, Actualizado, Puesto FROM PedidosClientes WHERE Empresa = :e AND Pedido = :p'
    );
    $stmt->execute(['e' => $empresa, 'p' => $pedido]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    if ($row === false) {
      throw new \RuntimeException('Pedido no encontrado', 404);
    }
    $row['Empresa'] = $empresa;
    $row['Pedido'] = $pedido;
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
    $stmt->execute(['t' => 'PagosACuentaPedido']);
    $existe = (bool) $stmt->fetchColumn();
    $stmt->closeCursor();
    if (!$existe) {
      throw new \RuntimeException('No se pudo preparar la tabla de pagos a cuenta', 500);
    }
  }

  /** @param array<string, mixed> $cab @return array<string, mixed> */
  private function respuesta(string $empresa, int $pedido, array $cab): array
  {
    $stmt = $this->pdo->prepare(
      'SELECT Nro, NroMov, Fecha, Importe, FormaPago, Puesto, Sesion, EmpresaArqueo
       FROM PagosACuentaPedido
       WHERE Empresa = :e AND Pedido = :p
       ORDER BY Nro, NroMov'
    );
    $stmt->execute(['e' => trim($empresa), 'p' => $pedido]);
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
    if ($stmt === false) {
      return $out;
    }
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
