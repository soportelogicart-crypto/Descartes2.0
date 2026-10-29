<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Offline;

use Descartes\Api\Config\Database;
use Descartes\Api\Services\Ventas\VentaEscrituraService;
use PDO;

/**
 * Alta en el servidor de los tickets cobrados sin conexión.
 *
 * El UUID de la venta es la clave de idempotencia (tabla VentasOffline): si la
 * respuesta se pierde y el puesto reintenta, se devuelve el mismo ticket en vez
 * de crear otro. Si el proceso se corta a mitad, el reintento sigue desde el
 * paso en que se quedó (albarán reservado, cabecera creada o ticket ya cerrado).
 */
final class OfflineVentaService
{
  /** Un intento que lleva más de esto sin terminar se da por caído y se retoma. */
  private const SEGUNDOS_EN_PROCESO = 120;

  private PDO $pdo;
  private VentaEscrituraService $escritura;
  private bool $tablaLista = false;

  public function __construct(PDO $pdo, VentaEscrituraService $escritura)
  {
    $this->pdo = $pdo;
    $this->escritura = $escritura;
  }

  /**
   * @param array<string, mixed> $body { payload: string JSON firmado, hmac: string }
   * @return array<string, mixed>
   */
  public function registrar(array $body): array
  {
    $payloadJson = (string) ($body['payload'] ?? '');
    $firma = strtolower(trim((string) ($body['hmac'] ?? '')));
    $p = json_decode($payloadJson, true);
    if (!is_array($p)) {
      throw new \InvalidArgumentException('Venta sin conexión no válida');
    }

    $uuid = strtolower(trim((string) ($p['uuid'] ?? '')));
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid)) {
      throw new \InvalidArgumentException('Identificador de venta no válido');
    }
    $empresa = trim((string) ($p['empresa'] ?? ''));
    $puesto = trim((string) ($p['puesto'] ?? ''));
    $numeroOffline = substr(trim((string) ($p['numeroOffline'] ?? '')), 0, 30);
    if ($empresa === '' || $puesto === '' || $numeroOffline === '') {
      throw new \InvalidArgumentException('Faltan tienda, puesto o número de la venta');
    }

    $clave = hex2bin(OfflineEstadoService::clavePuesto($empresa, $puesto));
    $esperada = hash_hmac('sha256', $payloadJson, (string) $clave);
    if ($firma === '' || !hash_equals($esperada, $firma)) {
      throw new \InvalidArgumentException(
        'La firma de la venta no coincide: los datos se han modificado en el equipo o la clave del puesto ha cambiado'
      );
    }

    $this->asegurarTabla();
    $registro = $this->buscar($uuid);
    if ($registro !== null && (int) $registro['Ticket'] > 0) {
      return $this->respuesta($registro, true);
    }
    if ($registro === null) {
      $this->reclamar($uuid, $empresa, $puesto, $numeroOffline, (float) ($p['importe'] ?? 0));
      $registro = $this->buscar($uuid);
    } elseif (!$this->puedeRetomar($registro)) {
      throw new \RuntimeException('La venta se está registrando en otra petición', 409);
    } else {
      $this->pdo->prepare('UPDATE VentasOffline SET Estado = \'R\', Recibido = :r, Error = NULL WHERE Uuid = :u')
        ->execute(['r' => date('Y-m-d H:i:s'), 'u' => $uuid]);
    }

    try {
      return $this->procesar($uuid, $p, $registro ?? []);
    } catch (\Throwable $e) {
      $this->pdo->prepare('UPDATE VentasOffline SET Estado = \'E\', Error = :m WHERE Uuid = :u')
        ->execute(['m' => mb_substr($e->getMessage(), 0, 1000), 'u' => $uuid]);
      throw $e;
    }
  }

  /**
   * @param array<string, mixed> $p
   * @param array<string, mixed> $registro
   * @return array<string, mixed>
   */
  private function procesar(string $uuid, array $p, array $registro): array
  {
    $empresa = trim((string) $p['empresa']);
    $puesto = trim((string) $p['puesto']);
    $lineas = is_array($p['lineas'] ?? null) ? $p['lineas'] : [];
    $conArticulo = array_values(array_filter(
      $lineas,
      static fn ($l) => is_array($l) && trim((string) ($l['articulo'] ?? '')) !== ''
        && strcasecmp(trim((string) $l['articulo']), 'NO') !== 0
    ));
    if ($conArticulo === []) {
      throw new \InvalidArgumentException('La venta no tiene líneas');
    }
    $faltan = $this->articulosInexistentes($conArticulo);
    if ($faltan !== []) {
      throw new \InvalidArgumentException(
        'Artículos que ya no existen en el servidor: ' . implode(', ', $faltan) . '. Créelos y reintente.'
      );
    }

    $abandonado = $p['anularServidor'] ?? null;
    if (is_array($abandonado) && (int) ($abandonado['albaran'] ?? 0) > 0) {
      try {
        $this->escritura->eliminar(
          trim((string) ($abandonado['empresa'] ?? $empresa)),
          trim((string) ($abandonado['tipo'] ?? 'A')),
          (int) $abandonado['albaran']
        );
      } catch (\Throwable $e) {
        // Ya borrado o ya cobrado en el servidor: no impide registrar esta venta.
      }
    }

    $albaran = (int) ($registro['Albaran'] ?? 0);
    if ($albaran <= 0) {
      $reserva = $this->escritura->reservarAlbaran($empresa, $puesto);
      $albaran = (int) $reserva['albaran'];
      $this->pdo->prepare('UPDATE VentasOffline SET Albaran = :a WHERE Uuid = :u')
        ->execute(['a' => $albaran, 'u' => $uuid]);
    }

    $esAlbaran = strtoupper(trim((string) ($p['documentoTipo'] ?? ''))) === 'A';
    $cab = $this->cabecera($empresa, $albaran);
    if ($cab !== null && strtoupper(trim((string) ($cab['FacturaTipo'] ?? ''))) === 'T' && (int) $cab['Factura'] > 0) {
      $ticket = (int) $cab['Factura'];
    } elseif ($esAlbaran && $cab !== null && $this->albaranYaCerrado($cab)) {
      $ticket = 0;
    } else {
      if ($cab === null) {
        $this->escritura->crear($this->bodyCrear($p, $albaran));
      }
      $fpago = trim((string) ($p['fpago1'] ?? ''));
      if ($esAlbaran) {
        // La forma del cliente de crédito. EU lo convertiría en contado y el servidor lo rechazaría.
        $cuerpo = ['tipo' => 'A'];
        if ($fpago !== '') {
          $cuerpo['fpago1'] = $fpago;
        }
        $this->escritura->finalizar($empresa, 'A', $albaran, $cuerpo);
        $ticket = 0;
      } else {
        $cerrada = $this->escritura->finalizar($empresa, 'A', $albaran, [
          'tipo' => 'T',
          'fpago1' => $fpago !== '' ? $fpago : 'EU',
        ]);
        $ticket = (int) ($cerrada['factura'] ?? 0);
      }
    }

    $incidencias = $this->incidencias($p, $conArticulo);
    $this->pdo->prepare(
      'UPDATE VentasOffline SET Ticket = :t, Estado = \'F\', Procesado = :p, Incidencias = :i, Error = NULL
       WHERE Uuid = :u'
    )->execute([
      't' => $ticket,
      'p' => date('Y-m-d H:i:s'),
      'i' => $incidencias === [] ? null : mb_substr(implode("\n", $incidencias), 0, 2000),
      'u' => $uuid,
    ]);

    return $this->respuesta((array) $this->buscar($uuid), false);
  }

  /**
   * @param array<string, mixed> $p
   * @return array<string, mixed>
   */
  private function bodyCrear(array $p, int $albaran): array
  {
    $numero = trim((string) $p['numeroOffline']);
    $fecha = trim((string) ($p['fechaHora'] ?? ''));
    $lineas = [];
    foreach ((array) $p['lineas'] as $l) {
      if (!is_array($l)) {
        continue;
      }
      $lineas[] = [
        'articulo' => trim((string) ($l['articulo'] ?? '')),
        'descripcion' => (string) ($l['descripcion'] ?? ''),
        'cantidad' => (float) ($l['cantidad'] ?? 0),
        'precio' => (float) ($l['precio'] ?? 0),
        'pjeDto' => (float) ($l['pjeDto'] ?? 0),
        'importe' => (float) ($l['importe'] ?? 0),
        'pjeIva' => (float) ($l['pjeIva'] ?? 0),
      ];
    }

    return [
      'empresa' => trim((string) $p['empresa']),
      'albaran' => $albaran,
      'puesto' => trim((string) $p['puesto']),
      'cliente' => (string) ($p['cliente'] ?? ''),
      'razonSocial' => (string) ($p['razonSocial'] ?? ''),
      'razonSocial2' => (string) ($p['razonSocial2'] ?? ''),
      'nif' => (string) ($p['nif'] ?? ''),
      'telefono' => (string) ($p['telefono'] ?? ''),
      'email' => (string) ($p['email'] ?? ''),
      'fecha' => $fecha !== '' ? $fecha : null,
      'vendedor' => (string) ($p['vendedor'] ?? ''),
      'vendedorApertura' => (string) ($p['vendedor'] ?? ''),
      'almacen' => $p['almacen'] ?? null,
      'tarifa' => (int) ($p['tarifa'] ?? 1),
      'representante' => ' ',
      'transporte' => ' ',
      'agente' => ' ',
      'actividad' => 0,
      'fpago1' => (string) ($p['fpago1'] ?? ' '),
      'impFpago1' => (float) ($p['importe'] ?? 0),
      'facturaTipo' => 'R',
      'empresaFacturacion' => trim((string) $p['empresa']),
      'pjeIva1' => 21,
      'referencia1' => $numero,
      'observaciones' => ($this->esAlbaranCredito($p) ? 'Albarán sin conexión ' : 'Ticket sin conexión ') . $numero
        . ($fecha !== '' ? ' (' . $fecha . ')' : ''),
      'lineas' => $lineas,
    ];
  }

  /**
   * Precio cobrado distinto del actual o cliente dado de baja: se registra la
   * venta tal como se cobró y queda anotado.
   *
   * @param array<string, mixed> $p
   * @param list<array<string, mixed>> $lineas
   * @return list<string>
   */
  private function incidencias(array $p, array $lineas): array
  {
    $out = [];
    $tarifa = min(9, max(1, (int) ($p['tarifa'] ?? 1)));
    $col = 'PrecioVen' . $tarifa;
    $st = $this->pdo->prepare("SELECT {$col} AS Precio FROM Articulos WHERE RTRIM(Codigo) = :c");
    foreach ($lineas as $l) {
      if (!empty($l['precioManual']) || (float) ($l['pjeDto'] ?? 0) >= 100) {
        continue;
      }
      $codigo = trim((string) $l['articulo']);
      $st->execute(['c' => $codigo]);
      $actual = $st->fetchColumn();
      $cobrado = round((float) ($l['precio'] ?? 0), 2);
      if ($actual !== false && abs(round((float) $actual, 2) - $cobrado) > 0.004) {
        $out[] = sprintf(
          'Artículo %s: precio cobrado %s, precio actual %s',
          $codigo,
          number_format($cobrado, 2, ',', '.'),
          number_format((float) $actual, 2, ',', '.')
        );
      }
    }
    $cliente = trim((string) ($p['cliente'] ?? ''));
    if ($cliente !== '' && strcasecmp($cliente, 'ZZZZZZZZZ') !== 0) {
      $st = $this->pdo->prepare('SELECT COALESCE(Baja, 0) FROM Clientes WHERE RTRIM(Codigo) = :c');
      $st->execute(['c' => $cliente]);
      $baja = $st->fetchColumn();
      if ($baja === false) {
        $out[] = "El cliente {$cliente} ya no existe";
      } elseif ((int) $baja !== 0) {
        $out[] = "El cliente {$cliente} está dado de baja";
      }
    }

    return $out;
  }

  /**
   * @param list<array<string, mixed>> $lineas
   * @return list<string>
   */
  private function articulosInexistentes(array $lineas): array
  {
    $st = $this->pdo->prepare('SELECT COUNT(*) FROM Articulos WHERE RTRIM(Codigo) = :c');
    $faltan = [];
    foreach ($lineas as $l) {
      $codigo = trim((string) $l['articulo']);
      if (isset($faltan[$codigo])) {
        continue;
      }
      $st->execute(['c' => $codigo]);
      if ((int) $st->fetchColumn() === 0) {
        $faltan[$codigo] = true;
      }
    }

    return array_keys($faltan);
  }

  /** @return array<string, mixed>|null */
  private function cabecera(string $empresa, int $albaran): ?array
  {
    $st = $this->pdo->prepare(
      'SELECT FacturaTipo, Factura, RebajeStock, Sesion
       FROM AlbaranesVentasCab WHERE Empresa = :e AND Tipo = :t AND Albaran = :a'
    );
    $st->execute(['e' => $empresa, 't' => 'A', 'a' => $albaran]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row === false ? null : $row;
  }

  /** @param array<string, mixed> $p */
  private function esAlbaranCredito(array $p): bool
  {
    return strtoupper(trim((string) ($p['documentoTipo'] ?? ''))) === 'A';
  }

  /**
   * Albarán ya cerrado: sin ticket, con sesión y stock rebajado.
   * Un reintento no debe volver a finalizarlo.
   *
   * @param array<string, mixed> $cab
   */
  private function albaranYaCerrado(array $cab): bool
  {
    $ft = strtoupper(trim((string) ($cab['FacturaTipo'] ?? '')));
    if ($ft === 'T' || $ft === 'F' || $ft === 'R' || $ft === 'A') {
      return false;
    }

    return (int) ($cab['Factura'] ?? 0) === 0
      && (int) ($cab['RebajeStock'] ?? 0) === 1
      && (int) ($cab['Sesion'] ?? 0) > 0;
  }

  /** @return array<string, mixed>|null */
  private function buscar(string $uuid): ?array
  {
    $st = $this->pdo->prepare(
      'SELECT Uuid, Empresa, Puesto, NumeroOffline, Albaran, Ticket, Estado, Recibido, Incidencias, Error
       FROM VentasOffline WHERE Uuid = :u'
    );
    $st->execute(['u' => $uuid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row === false ? null : $row;
  }

  /** El INSERT con la clave primaria reserva el UUID: dos peticiones a la vez no crean dos tickets. */
  private function reclamar(string $uuid, string $empresa, string $puesto, string $numero, float $importe): void
  {
    try {
      $this->pdo->prepare(
        'INSERT INTO VentasOffline (Uuid, Empresa, Puesto, NumeroOffline, Albaran, Ticket, Estado, Importe, Recibido)
         VALUES (:u, :e, :p, :n, 0, 0, \'R\', :i, :r)'
      )->execute([
        'u' => $uuid,
        'e' => $empresa,
        'p' => $puesto,
        'n' => $numero,
        'i' => round($importe, 2),
        'r' => date('Y-m-d H:i:s'),
      ]);
    } catch (\PDOException $e) {
      throw new \RuntimeException('La venta se está registrando en otra petición', 409);
    }
  }

  /** @param array<string, mixed> $registro */
  private function puedeRetomar(array $registro): bool
  {
    if (strtoupper(trim((string) ($registro['Estado'] ?? ''))) === 'E') {
      return true;
    }
    $recibido = strtotime((string) ($registro['Recibido'] ?? ''));

    return $recibido === false || (time() - $recibido) > self::SEGUNDOS_EN_PROCESO;
  }

  /**
   * @param array<string, mixed> $registro
   * @return array<string, mixed>
   */
  private function respuesta(array $registro, bool $duplicado): array
  {
    $incidencias = trim((string) ($registro['Incidencias'] ?? ''));

    return [
      'uuid' => (string) $registro['Uuid'],
      'empresa' => trim((string) $registro['Empresa']),
      'tipo' => 'A',
      'albaran' => (int) $registro['Albaran'],
      'ticket' => (int) $registro['Ticket'],
      'numeroOffline' => trim((string) $registro['NumeroOffline']),
      'duplicado' => $duplicado,
      'incidencias' => $incidencias === '' ? [] : explode("\n", $incidencias),
    ];
  }

  private function asegurarTabla(): void
  {
    if ($this->tablaLista) {
      return;
    }
    $cfg = Database::resolveConfig();
    if (strtolower((string) ($cfg['motor'] ?? 'sqlsrv')) === 'mysql') {
      $this->pdo->exec(
        'CREATE TABLE IF NOT EXISTS `VentasOffline` (
          `Uuid` varchar(36) NOT NULL,
          `Empresa` varchar(3) NOT NULL,
          `Puesto` varchar(10) NOT NULL,
          `NumeroOffline` varchar(30) NOT NULL,
          `Albaran` int NOT NULL DEFAULT 0,
          `Ticket` int NOT NULL DEFAULT 0,
          `Estado` varchar(1) NOT NULL,
          `Importe` decimal(18,2) NOT NULL DEFAULT 0,
          `Recibido` datetime NOT NULL,
          `Procesado` datetime NULL,
          `Incidencias` varchar(2000) NULL,
          `Error` varchar(1000) NULL,
          PRIMARY KEY (`Uuid`),
          KEY `IX_VentasOffline_Puesto` (`Empresa`, `Puesto`, `NumeroOffline`)
        ) DEFAULT CHARSET=utf8mb4'
      );
    } else {
      $this->pdo->exec(
        "IF OBJECT_ID('dbo.VentasOffline', 'U') IS NULL
         BEGIN
           CREATE TABLE [dbo].[VentasOffline] (
             [Uuid] nvarchar(36) NOT NULL,
             [Empresa] nvarchar(3) NOT NULL,
             [Puesto] nvarchar(10) NOT NULL,
             [NumeroOffline] nvarchar(30) NOT NULL,
             [Albaran] int NOT NULL CONSTRAINT [DF_VentasOffline_Albaran] DEFAULT (0),
             [Ticket] int NOT NULL CONSTRAINT [DF_VentasOffline_Ticket] DEFAULT (0),
             [Estado] nvarchar(1) NOT NULL,
             [Importe] decimal(18,2) NOT NULL CONSTRAINT [DF_VentasOffline_Importe] DEFAULT (0),
             [Recibido] datetime NOT NULL,
             [Procesado] datetime NULL,
             [Incidencias] nvarchar(2000) NULL,
             [Error] nvarchar(1000) NULL,
             CONSTRAINT [PK_VentasOffline] PRIMARY KEY CLUSTERED ([Uuid] ASC)
           );
           CREATE INDEX [IX_VentasOffline_Puesto] ON [dbo].[VentasOffline]([Empresa], [Puesto], [NumeroOffline]);
         END"
      );
    }
    $this->tablaLista = true;
  }
}
