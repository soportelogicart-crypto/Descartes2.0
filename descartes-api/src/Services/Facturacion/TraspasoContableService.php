<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Facturacion;

use Descartes\Api\Services\Instalacion\MejorasGestionSchema;
use PDO;

/**
 * Traspasa facturas de Gestión a la contabilidad legacy:
 * libro de emitidas, asiento y efectos de cobro.
 */
final class TraspasoContableService
{
  private static ?bool $asientosLinTieneCentroCoste = null;

  private PDO $gestion;
  private ConexionContableService $conexion;

  public function __construct(PDO $gestion, ConexionContableService $conexion)
  {
    $this->gestion = $gestion;
    $this->conexion = $conexion;
  }

  /** @param array<string, mixed> $query */
  public function listarPendientes(array $query): array
  {
    $where = ['ISNULL(f.TrasCtb, 0) = 0', "f.FacturaTipo IN ('F', 'A')"];
    $params = [];
    foreach (['empresa', 'facturaTipo'] as $campo) {
      $valor = trim((string) ($query[$campo] ?? ''));
      if ($valor !== '') {
        $columna = $campo === 'empresa' ? 'f.Empresa' : 'f.FacturaTipo';
        $where[] = "{$columna} = :{$campo}";
        $params[$campo] = strtoupper($valor);
      }
    }
    foreach (['fechaDesde' => '>=', 'fechaHasta' => '<='] as $campo => $op) {
      $valor = trim((string) ($query[$campo] ?? ''));
      if ($valor !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
        $where[] = 'f.Fecha ' . $op . " CONVERT(datetime, :{$campo}, 120)";
        $params[$campo] = $valor . ($campo === 'fechaHasta' ? ' 23:59:59' : ' 00:00:00');
      }
    }

    // El grid filtra por columna sobre lo devuelto: conviene no recortar de más.
    $sql = "SELECT TOP 5000 f.Empresa, f.FacturaTipo, f.Factura, f.Fecha, f.Cliente,
                   c.RazonSocial, c.NIF, f.Importe, f.Fpago, f.Estado,
                   (SELECT COUNT(*) FROM Recibos r
                    WHERE r.Empresa=f.Empresa AND r.FacturaTipo=f.FacturaTipo
                      AND r.Factura=f.Factura) AS NumEfectos
            FROM Facturas f
            LEFT JOIN Clientes c ON c.Codigo=f.Cliente
            WHERE " . implode(' AND ', $where) . '
            ORDER BY f.Fecha, f.Empresa, f.FacturaTipo, f.Factura';
    $stmt = $this->gestion->prepare($sql);
    $stmt->execute($params);

    $items = [];
    $importe = 0.0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
      $valor = round((float) ($row['Importe'] ?? 0), 2);
      $importe += $valor;
      $items[] = [
        'empresa' => trim((string) $row['Empresa']),
        'facturaTipo' => trim((string) $row['FacturaTipo']),
        'factura' => (int) $row['Factura'],
        'fecha' => $this->fechaIso($row['Fecha'] ?? null),
        'cliente' => trim((string) ($row['Cliente'] ?? '')),
        'razonSocial' => trim((string) ($row['RazonSocial'] ?? '')),
        'nif' => trim((string) ($row['NIF'] ?? '')),
        'importe' => $valor,
        'formaPago' => trim((string) ($row['Fpago'] ?? '')),
        'estado' => trim((string) ($row['Estado'] ?? '')),
        'numEfectos' => (int) ($row['NumEfectos'] ?? 0),
      ];
    }
    return [
      'items' => $items,
      'totales' => ['facturas' => count($items), 'importe' => round($importe, 2)],
    ];
  }

  /** @param array<string, mixed> $body */
  public function traspasar(array $body): array
  {
    $seleccion = $body['facturas'] ?? null;
    if (!is_array($seleccion) || $seleccion === []) {
      throw new \InvalidArgumentException('Seleccione al menos una factura');
    }

    $resultados = [];
    foreach ($seleccion as $item) {
      if (!is_array($item)) {
        continue;
      }
      $empresa = trim((string) ($item['empresa'] ?? ''));
      $tipo = strtoupper(trim((string) ($item['facturaTipo'] ?? 'F')));
      $factura = (int) ($item['factura'] ?? 0);
      if ($empresa === '' || !in_array($tipo, ['F', 'A'], true) || $factura <= 0) {
        throw new \InvalidArgumentException('Selección de facturas no válida');
      }
      $resultados[] = $this->traspasarFactura($empresa, $tipo, $factura);
    }

    return [
      'items' => $resultados,
      'totales' => [
        'facturas' => count($resultados),
        'asientos' => count(array_filter($resultados, static fn ($r) => !empty($r['asiento']))),
        'efectos' => array_sum(array_column($resultados, 'efectos')),
      ],
    ];
  }

  /** @return array<string, mixed> */
  private function traspasarFactura(string $empresa, string $tipo, int $factura): array
  {
    $this->gestion->beginTransaction();
    try {
      $origen = $this->cargarFactura($empresa, $tipo, $factura);
      $config = $this->conexion->paraEmpresa($empresa);
      /** @var PDO $ctb */
      $ctb = $config['pdo'];
      $serie = $this->serieFactura($origen, $config);

      $existente = $this->buscarExistente($ctb, $serie, $factura);
      if ($existente !== null) {
        $this->marcarTraspasada($empresa, $tipo, $factura, $serie, $existente);
        $this->gestion->commit();
        return [
          'empresa' => $empresa,
          'facturaTipo' => $tipo,
          'factura' => $factura,
          'serie' => $serie,
          'numeroContable' => $existente,
          'asiento' => $this->existeAsiento($ctb, $serie, $existente),
          'efectos' => $this->contarEfectos($ctb, $serie, $existente),
          'recuperado' => true,
        ];
      }

      $numero = $this->siguienteNumero($ctb, $serie);
      $cuentaCliente = $this->cuentaCliente($origen);
      $this->sincronizarCliente($ctb, $cuentaCliente, $origen);
      $this->insertarLibroEmitidas($ctb, $serie, $numero, (string) $factura, $cuentaCliente, $origen);
      $efectos = $this->insertarEfectos($ctb, $serie, $numero, $cuentaCliente, $origen);
      $this->insertarAsiento($ctb, $serie, $numero, $cuentaCliente, $origen);
      $this->insertarCobroSiContado($ctb, $serie, $cuentaCliente, $origen);
      $this->marcarTraspasada($empresa, $tipo, $factura, $serie, $numero);
      $this->gestion->commit();
    } catch (\Throwable $e) {
      if ($this->gestion->inTransaction()) {
        $this->gestion->rollBack();
      }
      throw $e;
    }

    return [
      'empresa' => $empresa,
      'facturaTipo' => $tipo,
      'factura' => $factura,
      'serie' => $serie,
      'numeroContable' => $numero,
      'asiento' => true,
      'efectos' => $efectos,
      'recuperado' => false,
    ];
  }

  /**
   * Un asiento con la suma de los tickets de la sesión. No abre transacción:
   * el cierre de caja ya la tiene abierta y contabilidad comparte la conexión.
   * Los tickets siguen siendo tickets. No se crean efectos de cobro.
   *
   * @return array{serie: ?string, numero: ?int, tickets: int, importe: float, recuperado: bool}
   */
  public function traspasarTicketsSesion(string $empresa, string $puesto, int $sesion): array
  {
    $respuesta = $this->contabilizarTicketsSesion($empresa, $puesto, $sesion);
    $this->traspasarPagosACuentaSesion($empresa, $puesto, $sesion);
    return $respuesta;
  }

  /** @return array{serie: ?string, numero: ?int, numeroCobro: ?int, tickets: int, importe: float, recuperado: bool} */
  private function contabilizarTicketsSesion(string $empresa, string $puesto, int $sesion): array
  {
    $empresa = trim($empresa);
    $puesto = trim($puesto);
    $tickets = $this->ticketsDeSesion($empresa, $puesto, $sesion);
    $cantidad = count($tickets);
    if ($cantidad === 0) {
      return $this->respuestaTickets(null, null, 0, 0.0, false);
    }

    $importe = 0.0;
    /** @var array<string, array{pjeIva: float, pjeRec: float, base: float, iva: float, rec: float}> $grupos */
    $grupos = [];
    foreach ($tickets as $ticket) {
      $importe += (float) ($ticket['Importe'] ?? 0);
      for ($i = 1; $i <= 6; $i++) {
        $base = (float) ($ticket["ImporteBase{$i}"] ?? 0);
        $iva = (float) ($ticket["ImporteIva{$i}"] ?? 0);
        $rec = (float) ($ticket["ImporteRec{$i}"] ?? 0);
        if (abs($base) < 0.0005 && abs($iva) < 0.0005 && abs($rec) < 0.0005) {
          continue;
        }
        $pjeIva = round((float) ($ticket["PjeIva{$i}"] ?? 0), 2);
        $pjeRec = round((float) ($ticket["PjeRec{$i}"] ?? 0), 2);
        $clave = $pjeIva . '|' . $pjeRec;
        if (!isset($grupos[$clave])) {
          $grupos[$clave] = [
            'pjeIva' => $pjeIva,
            'pjeRec' => $pjeRec,
            'base' => 0.0,
            'iva' => 0.0,
            'rec' => 0.0,
          ];
        }
        $grupos[$clave]['base'] += $base;
        $grupos[$clave]['iva'] += $iva;
        $grupos[$clave]['rec'] += $rec;
      }
    }
    $importe = round($importe, 2);
    foreach ($grupos as $clave => $grupo) {
      $grupos[$clave]['base'] = round($grupo['base'], 2);
      $grupos[$clave]['iva'] = round($grupo['iva'], 2);
      $grupos[$clave]['rec'] = round($grupo['rec'], 2);
    }

    if (abs($importe) < 0.005) {
      $this->marcarTicketsSesion($empresa, $puesto, $sesion);
      return $this->respuestaTickets(null, null, $cantidad, 0.0, false);
    }

    if (count($grupos) > 6) {
      throw new \RuntimeException(
        'La sesión tiene más de 6 tipos de IVA y no cabe en un solo libro de emitidas',
        409
      );
    }

    $baseTotal = 0.0;
    $cuota = 0.0;
    foreach ($grupos as $grupo) {
      $baseTotal += $grupo['base'];
      $cuota += $grupo['iva'] + $grupo['rec'];
    }
    $baseTotal = round($baseTotal, 2);
    $cuota = round($cuota, 2);
    if ($grupos === []) {
      $grupos['0|0'] = [
        'pjeIva' => 0.0,
        'pjeRec' => 0.0,
        'base' => $importe,
        'iva' => 0.0,
        'rec' => 0.0,
      ];
      $baseTotal = $importe;
      $cuota = 0.0;
    }
    // La base de venta no se toca. Si el total cobrado no cuadra con base + IVA,
    // la diferencia se lleva a la cuota más grande para que el asiento cierre.
    $descuadre = round($importe - $baseTotal - $cuota, 2);
    if (abs($descuadre) >= 0.005 && $grupos !== []) {
      $claveMax = array_key_first($grupos);
      $absMax = -1.0;
      foreach ($grupos as $clave => $grupo) {
        $peso = abs($grupo['iva']) >= 0.005 ? abs($grupo['iva']) : abs($grupo['base']);
        if ($peso > $absMax) {
          $absMax = $peso;
          $claveMax = $clave;
        }
      }
      $grupos[$claveMax]['iva'] = round($grupos[$claveMax]['iva'] + $descuadre, 2);
    }

    $texto = mb_substr('Tickets sesion ' . $puesto . '/' . $sesion, 0, 40);
    $referencia = mb_substr('S-' . $puesto . '-' . $sesion, 0, 20);
    $config = $this->conexion->paraEmpresa($empresa);
    /** @var PDO $ctb */
    $ctb = $config['pdo'];
    $serie = (string) $config['serie'];

    $existente = $this->buscarPorReferencia($ctb, $serie, $referencia);
    if ($existente !== null) {
      $this->marcarTicketsSesion($empresa, $puesto, $sesion);
      $this->tocarComunicacion($empresa);
      return $this->respuestaTickets($serie, $existente, $cantidad, $importe, true);
    }

    $numero = $this->siguienteNumero($ctb, $serie);
    $reparto = $this->repartoTicketsSesion($ctb, $empresa, $puesto, $sesion, $importe);
    $cuentasCliente = $reparto['clientes'];
    $cobros = $reparto['cobros'];
    $claveLibro = $this->claveMayor($cuentasCliente);
    $cuentaLibro = (string) $claveLibro;

    $origen = $reparto['fichas'][$claveLibro];
    $origen['RazonSocial'] = $texto;
    $origen['Fecha'] = date('Y-m-d H:i:s');
    $origen['Importe'] = $importe;
    $origen['ImpRetIrpf'] = 0;
    $origen['FacturaTipo'] = 'T';
    $origen['Factura'] = 0;
    $origen['Empresa'] = $empresa;
    $origen['Fpago'] = $this->formaCobroPrincipal($empresa, $puesto, $sesion);
    $origen['SujetoPasivo'] = 0;
    $origen['FacturaContadoDiferida'] = 0;
    $slot = 1;
    foreach ($grupos as $grupo) {
      $origen["ImporteBase{$slot}"] = $grupo['base'];
      $origen["PjeIva{$slot}"] = $grupo['pjeIva'];
      $origen["ImporteIva{$slot}"] = $grupo['iva'];
      $origen["PjeRec{$slot}"] = $grupo['pjeRec'];
      $origen["ImporteRec{$slot}"] = $grupo['rec'];
      $slot++;
    }

    $this->insertarLibroEmitidas($ctb, $serie, $numero, $referencia, $cuentaLibro, $origen);
    $bases = $this->basesTicketsSesion($empresa, $puesto, $sesion);
    if ($bases === []) {
      $bases = ['700' => $baseTotal];
    }
    $bases = $this->sustituirCuentasAusentes($ctb, $bases, '700');
    $this->insertarAsiento($ctb, $serie, $numero, $cuentaLibro, $origen, $bases, $texto, $cuentasCliente);
    $numeroCobro = $this->siguienteNumero($ctb, $serie);
    $this->insertarAsientoCobro(
      $ctb,
      $serie,
      $numeroCobro,
      (string) $origen['Fecha'],
      'Cobro ' . $texto,
      $cobros,
      $cuentasCliente
    );
    $this->marcarTicketsSesion($empresa, $puesto, $sesion);
    $this->tocarComunicacion($empresa);

    return $this->respuestaTickets($serie, $numero, $cantidad, $importe, false, $numeroCobro);
  }

  /** @return array{serie: ?string, numero: ?int, numeroCobro: ?int, tickets: int, importe: float, recuperado: bool} */
  private function respuestaTickets(
    ?string $serie,
    ?int $numero,
    int $tickets,
    float $importe,
    bool $recuperado,
    ?int $numeroCobro = null
  ): array {
    return [
      'serie' => $serie,
      'numero' => $numero,
      'numeroCobro' => $numeroCobro,
      'tickets' => $tickets,
      'importe' => round($importe, 2),
      'recuperado' => $recuperado,
    ];
  }

  /** @return list<array<string, mixed>> */
  private function ticketsDeSesion(string $empresa, string $puesto, int $sesion): array
  {
    $stmt = $this->gestion->prepare(
      "SELECT Importe,
              ImporteBase1, ImporteBase2, ImporteBase3, ImporteBase4, ImporteBase5, ImporteBase6,
              PjeIva1, PjeIva2, PjeIva3, PjeIva4, PjeIva5, PjeIva6,
              ImporteIva1, ImporteIva2, ImporteIva3, ImporteIva4, ImporteIva5, ImporteIva6,
              PjeRec1, PjeRec2, PjeRec3, PjeRec4, PjeRec5, PjeRec6,
              ImporteRec1, ImporteRec2, ImporteRec3, ImporteRec4, ImporteRec5, ImporteRec6
       FROM AlbaranesVentasCab WITH (UPDLOCK, ROWLOCK)
       WHERE Empresa = :empresa
         AND RTRIM(Puesto) = :puesto
         AND Sesion = :sesion
         AND FacturaTipo = 'T'
         AND ISNULL(TrasCtb, 0) = 0"
    );
    $stmt->execute([
      'empresa' => $empresa,
      'puesto' => $puesto,
      'sesion' => $sesion,
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }

  /** @return array<string, mixed> */
  private function clienteContado(): array
  {
    $stmt = $this->gestion->prepare(
      "SELECT RazonSocial, NIF, Direccion, Poblacion, CodigoPostal, Actividad, Provincia, Pais,
              Telefono1, Fax, FormaPago AS FormaPagoCliente, DiaPago1, DiaPago2, Banco,
              CuentaBancaria, CuentaCtb, CuentaCtb2, Swift, IBAN, ReferenciaMandato,
              FechaFirmaMandato, EmailFacturacion, TratamientoFiscal, Codigo AS Cliente
       FROM Clientes
       WHERE Codigo = 'ZZZZZZZZZ'"
    );
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      throw new \RuntimeException('No existe el cliente de contado ZZZZZZZZZ', 409);
    }
    return $row;
  }

  /**
   * La base sin IVA de cada ticket se reparte entre las cuentas de sus líneas
   * de ese IVA. No se reescala al total de la sesión.
   *
   * @return array<string, float>
   */
  private function basesTicketsSesion(string $empresa, string $puesto, int $sesion): array
  {
    $filtro = "a.Empresa = :empresa
         AND RTRIM(a.Puesto) = :puesto
         AND a.Sesion = :sesion
         AND a.FacturaTipo = 'T'
         AND ISNULL(a.TrasCtb, 0) = 0";
    $params = [
      'empresa' => $empresa,
      'puesto' => $puesto,
      'sesion' => $sesion,
    ];

    $cab = $this->gestion->prepare(
      "SELECT a.Tipo, a.Albaran,
              a.ImporteBase1, a.ImporteBase2, a.ImporteBase3, a.ImporteBase4, a.ImporteBase5, a.ImporteBase6,
              a.PjeIva1, a.PjeIva2, a.PjeIva3, a.PjeIva4, a.PjeIva5, a.PjeIva6,
              a.PjeRec1, a.PjeRec2, a.PjeRec3, a.PjeRec4, a.PjeRec5, a.PjeRec6
       FROM AlbaranesVentasCab a
       WHERE {$filtro}"
    );
    $cab->execute($params);
    $ticketsCab = $cab->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $cab->closeCursor();

    $lin = $this->gestion->prepare(
      "SELECT a.Tipo, a.Albaran, l.PjeIva, l.PjeRec, ISNULL(l.Importe, 0) AS Importe,
              COALESCE(NULLIF(sf.CuentaCtb, 0), NULLIF(fa.CuentaCtb, 0), 700) AS Cuenta
       FROM AlbaranesVentasCab a
       INNER JOIN AlbaranesVentasLin l
         ON l.Empresa=a.Empresa AND l.Tipo=a.Tipo AND l.Albaran=a.Albaran
       LEFT JOIN Articulos ar ON ar.Codigo=l.Articulo
       LEFT JOIN Subfamilias sf ON sf.Familia=ar.Familia AND sf.Subfamilia=ar.Subfamilia
       LEFT JOIN Familias fa ON fa.Codigo=ar.Familia
       WHERE {$filtro}
         AND LTRIM(RTRIM(ISNULL(l.Articulo, ''))) <> 'NO'"
    );
    $lin->execute($params);
    $filasLin = $lin->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $lin->closeCursor();

    /** @var array<string, array<string, array<string, float>>> $pesos */
    $pesos = [];
    foreach ($filasLin as $row) {
      $doc = $this->claveDocumento($row);
      $iva = $this->claveIva((float) ($row['PjeIva'] ?? 0), (float) ($row['PjeRec'] ?? 0));
      $cuenta = $this->codigoCuenta($row['Cuenta'] ?? null) ?: '700';
      $pesos[$doc][$iva][$cuenta] = ($pesos[$doc][$iva][$cuenta] ?? 0) + (float) ($row['Importe'] ?? 0);
    }

    $raw = [];
    foreach ($ticketsCab as $ticket) {
      $doc = $this->claveDocumento($ticket);
      $delTicket = $pesos[$doc] ?? [];
      for ($i = 1; $i <= 6; $i++) {
        $base = round((float) ($ticket["ImporteBase{$i}"] ?? 0), 2);
        if (abs($base) < 0.0005) {
          continue;
        }
        $iva = $this->claveIva(
          (float) ($ticket["PjeIva{$i}"] ?? 0),
          (float) ($ticket["PjeRec{$i}"] ?? 0)
        );
        $grupo = $delTicket[$iva] ?? [];
        if ($grupo === [] && count($delTicket) === 1) {
          $grupo = $delTicket[array_key_first($delTicket)];
        }
        foreach ($this->repartirEntreCuentas($grupo, $base) as $cuenta => $importe) {
          $raw[$cuenta] = round(($raw[$cuenta] ?? 0) + $importe, 2);
        }
      }
    }
    return $raw;
  }

  /** @param array<string, mixed> $row */
  private function claveDocumento(array $row): string
  {
    return trim((string) ($row['Tipo'] ?? '')) . '|' . (int) ($row['Albaran'] ?? 0);
  }

  private function claveIva(float $pjeIva, float $pjeRec): string
  {
    return round($pjeIva, 2) . '|' . round($pjeRec, 2);
  }

  /**
   * Reparte una base entre cuentas según el peso de cada línea.
   * La de más peso se queda el céntimo para que la suma sea exactamente la base.
   *
   * @param array<string, float> $pesos
   * @return array<string, float>
   */
  private function repartirEntreCuentas(array $pesos, float $total): array
  {
    $total = round($total, 2);
    $utiles = [];
    foreach ($pesos as $cuenta => $peso) {
      if (abs($peso) >= 0.0000001) {
        $utiles[(string) $cuenta] = $peso;
      }
    }
    if ($utiles === []) {
      return ['700' => $total];
    }
    $suma = array_sum($utiles);
    if (abs($suma) < 0.0000001) {
      $cuenta = (string) array_key_first($utiles);
      return [$cuenta => $total];
    }
    uasort($utiles, static fn (float $a, float $b): int => abs($b) <=> abs($a));
    $cuentas = array_keys($utiles);
    $mayor = (string) array_shift($cuentas);
    $resultado = [];
    $acumulado = 0.0;
    foreach ($cuentas as $cuenta) {
      $importe = round($total * ($utiles[$cuenta] / $suma), 2);
      $resultado[$cuenta] = $importe;
      $acumulado = round($acumulado + $importe, 2);
    }
    $resultado[$mayor] = round($total - $acumulado, 2);
    return $resultado;
  }

  private function marcarTicketsSesion(string $empresa, string $puesto, int $sesion): void
  {
    $this->gestion->prepare(
      "UPDATE AlbaranesVentasCab
       SET TrasCtb = 1
       WHERE Empresa = :empresa
         AND RTRIM(Puesto) = :puesto
         AND Sesion = :sesion
         AND FacturaTipo = 'T'
         AND ISNULL(TrasCtb, 0) = 0"
    )->execute([
      'empresa' => $empresa,
      'puesto' => $puesto,
      'sesion' => $sesion,
    ]);
  }

  private function tocarComunicacion(string $empresa): void
  {
    $this->gestion->prepare(
      'UPDATE Empresas_Ges SET UltimaComunicacionCTB=GETDATE() WHERE Codigo=:empresa'
    )->execute(['empresa' => $empresa]);
  }

  private function buscarPorReferencia(PDO $ctb, string $serie, string $referencia): ?int
  {
    $stmt = $ctb->prepare(
      'SELECT TOP 1 UltNum FROM FactEmitidasCab WHERE Serie=:serie AND Referencia=:referencia'
    );
    $stmt->execute(['serie' => $serie, 'referencia' => $referencia]);
    $valor = $stmt->fetchColumn();
    return $valor === false ? null : (int) $valor;
  }

  /** @return array<string, mixed> */
  private function cargarFactura(string $empresa, string $tipo, int $factura): array
  {
    $stmt = $this->gestion->prepare(
      "SELECT f.*, c.RazonSocial, c.NIF, c.Direccion, c.Poblacion, c.CodigoPostal,
              c.Actividad, c.Provincia, c.Pais, c.Telefono1, c.Fax, c.FormaPago AS FormaPagoCliente,
              c.DiaPago1, c.DiaPago2, c.Banco, c.CuentaBancaria, c.CuentaCtb,
              c.CuentaCtb2, c.CuentaCtbIta, c.Swift, c.IBAN, c.ReferenciaMandato,
              c.FechaFirmaMandato, c.EmailFacturacion, c.TratamientoFiscal,
              e.CtbRetIrpf
       FROM Facturas f WITH (UPDLOCK, ROWLOCK)
       LEFT JOIN Clientes c ON c.Codigo=f.Cliente
       LEFT JOIN Empresas_Ges e ON e.Codigo=f.Empresa
       WHERE f.Empresa=:empresa AND f.FacturaTipo=:tipo AND f.Factura=:factura"
    );
    $stmt->execute(['empresa' => $empresa, 'tipo' => $tipo, 'factura' => $factura]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      throw new \RuntimeException("Factura {$tipo}-{$factura} no encontrada", 404);
    }
    if (!empty($row['TrasCtb'])) {
      throw new \RuntimeException("Factura {$tipo}-{$factura} ya traspasada", 409);
    }
    return $row;
  }

  /** @param array<string, mixed> $factura @param array<string, mixed> $config */
  private function serieFactura(array $factura, array $config): string
  {
    $abono = strtoupper(trim((string) $factura['FacturaTipo'])) === 'A';
    $diferida = !empty($factura['FacturaContadoDiferida']);
    if ($abono) {
      return $diferida ? (string) $config['serieAbonoDiferida'] : (string) $config['serieAbono'];
    }
    return $diferida ? (string) $config['serieDiferida'] : (string) $config['serie'];
  }

  private function buscarExistente(PDO $ctb, string $serie, int $referencia): ?int
  {
    $stmt = $ctb->prepare(
      'SELECT TOP 1 UltNum FROM FactEmitidasCab WHERE Serie=:serie AND Referencia=:referencia'
    );
    $stmt->execute(['serie' => $serie, 'referencia' => (string) $referencia]);
    $valor = $stmt->fetchColumn();
    return $valor === false ? null : (int) $valor;
  }

  private function siguienteNumero(PDO $ctb, string $serie): int
  {
    $stmt = $ctb->prepare('SELECT UltNum FROM Series WITH (UPDLOCK, ROWLOCK) WHERE Codigo=:serie');
    $stmt->execute(['serie' => $serie]);
    $actual = $stmt->fetchColumn();
    if ($actual === false) {
      throw new \RuntimeException("La serie {$serie} no existe en contabilidad", 409);
    }
    $numero = (int) $actual + 1;
    $ctb->prepare('UPDATE Series SET UltNum=:numero WHERE Codigo=:serie')
      ->execute(['numero' => $numero, 'serie' => $serie]);
    return $numero;
  }

  /** @param array<string, mixed> $factura */
  private function cuentaCliente(array $factura): string
  {
    $cuenta = $this->codigoCuenta($factura['CuentaCtb2'] ?? null);
    if ($cuenta === '') {
      $cuenta = trim((string) ($factura['CuentaCtb'] ?? ''));
    }
    if ($cuenta === '') {
      $codigo = preg_replace('/\D+/', '', trim((string) ($factura['Cliente'] ?? ''))) ?: '';
      $cuenta = '430' . str_pad(substr($codigo, -6), 6, '0', STR_PAD_LEFT);
    }
    if (strlen($cuenta) > 10) {
      throw new \RuntimeException("Cuenta contable de cliente {$cuenta} demasiado larga", 409);
    }
    return $cuenta;
  }

  /** @param array<string, mixed> $f */
  private function sincronizarCliente(PDO $ctb, string $cuenta, array $f): void
  {
    $datos = [
      'codigo' => $cuenta,
      'nivel' => substr($cuenta, 0, 4),
      'descripcion' => mb_substr(trim((string) ($f['RazonSocial'] ?? '')), 0, 40),
      'nif' => mb_substr(trim((string) ($f['NIF'] ?? '')), 0, 16),
      'direccion' => mb_substr(trim((string) ($f['Direccion'] ?? '')), 0, 40),
      'poblacion' => mb_substr(trim((string) ($f['Poblacion'] ?? '')), 0, 40),
      'cp' => mb_substr(trim((string) ($f['CodigoPostal'] ?? '')), 0, 6),
      'provincia' => mb_substr(trim((string) ($f['Provincia'] ?? '')), 0, 20),
      'pais' => mb_substr(trim((string) ($f['Pais'] ?? '')), 0, 40),
      'telefono' => mb_substr(trim((string) ($f['Telefono1'] ?? '')), 0, 12),
      'fax' => mb_substr(trim((string) ($f['Fax'] ?? '')), 0, 12),
      'formaPago' => mb_substr(trim((string) ($f['Fpago'] ?? $f['FormaPagoCliente'] ?? '')), 0, 3),
      'dia1' => (int) ($f['DiaPago1'] ?? 0),
      'dia2' => (int) ($f['DiaPago2'] ?? 0),
      'banco' => mb_substr(trim((string) ($f['Banco'] ?? '')), 0, 30),
      'ctaBancaria' => mb_substr(trim((string) ($f['CuentaBancaria'] ?? '')), 0, 20),
      'swift' => mb_substr(trim((string) ($f['Swift'] ?? '')), 0, 20),
      'iban' => mb_substr(trim((string) ($f['IBAN'] ?? '')), 0, 34),
      'mandato' => mb_substr(trim((string) ($f['ReferenciaMandato'] ?? '')), 0, 35),
      'fechaMandato' => $this->fechaSql($f['FechaFirmaMandato'] ?? null),
      'email' => mb_substr(trim((string) ($f['EmailFacturacion'] ?? '')), 0, 200),
      'tratamiento' => mb_substr(trim((string) ($f['TratamientoFiscal'] ?? '')), 0, 1),
    ];

    $existe = $ctb->prepare('SELECT 1 FROM Cuentas WHERE Codigo=:codigo');
    $existe->execute(['codigo' => $cuenta]);
    if ($existe->fetchColumn() === false) {
      $ctb->prepare(
        'INSERT INTO Cuentas (
           Codigo, NivelAnterior, Descripcion, UltNivel, DesgloseAnalitica, CentroCoste,
           TipoGestion, Nif, Direccion, Poblacion, CodPostal, Provincia, Pais, Telefono,
           Fax, Modem, RecargoCli, Observaciones, FormaPago, DiaPago1, DiaPago2, Banco,
           CtaBancaria, Tipo, Calculo, CuentaDestino, TratamientoFiscal, Swift, IBAN,
           ReferenciaMandato, FechaFirmaMandato, EmailFacturacion
         ) VALUES (
           :codigo, :nivel, :descripcion, 1, 0, \'\', \'P\', :nif, :direccion, :poblacion,
           :cp, :provincia, :pais, :telefono, :fax, \'\', 0, \'\', :formaPago, :dia1,
           :dia2, :banco, :ctaBancaria, \'R\', 0, :codigoDestino, :tratamiento, :swift,
           :iban, :mandato, CONVERT(datetime, :fechaMandato, 120), :email
         )'
      )->execute($datos + ['codigoDestino' => $cuenta]);
      return;
    }

    $updateDatos = $datos;
    unset($updateDatos['nivel']);
    $ctb->prepare(
      'UPDATE Cuentas SET Descripcion=:descripcion, Nif=:nif, Direccion=:direccion,
         Poblacion=:poblacion, CodPostal=:cp, Provincia=:provincia, Pais=:pais,
         Telefono=:telefono, Fax=:fax, FormaPago=:formaPago, DiaPago1=:dia1,
         DiaPago2=:dia2, Banco=:banco, CtaBancaria=:ctaBancaria,
         TratamientoFiscal=:tratamiento, Swift=:swift, IBAN=:iban,
         ReferenciaMandato=:mandato, FechaFirmaMandato=CONVERT(datetime, :fechaMandato, 120),
         EmailFacturacion=:email
       WHERE Codigo=:codigo'
    )->execute($updateDatos);
  }

  /** @param array<string, mixed> $f */
  private function insertarLibroEmitidas(
    PDO $ctb,
    string $serie,
    int $numero,
    string $referencia,
    string $cuentaCliente,
    array $f
  ): void {
    $fecha = (string) $f['Fecha'];
    $ctb->prepare(
      'INSERT INTO FactEmitidasCab (
         Serie, UltNum, Fecha, Cliente, NombreCli, NifCli, Periodo, Ejercicio,
         TotalFact, Traspasado, Referencia, ImporteRetencion,
         FacturaContadoDiferida, FormasCobro, TrasSII, Observaciones, SujetoPasivo
       ) VALUES (
         :serie, :numero, CONVERT(datetime, :fecha, 120), :cliente, :nombre, :nif, :periodo, :ejercicio,
         :total, 1, :referencia, :retencion, :diferida, :formaPago, 0, NULL, :sujetoPasivo
       )'
    )->execute([
      'serie' => $serie,
      'numero' => $numero,
      'fecha' => $this->fechaSql($fecha),
      'cliente' => $cuentaCliente,
      'nombre' => mb_substr(trim((string) ($f['RazonSocial'] ?? '')), 0, 40),
      'nif' => mb_substr(trim((string) ($f['NIF'] ?? '')), 0, 16),
      'periodo' => (int) date('n', strtotime($fecha)),
      'ejercicio' => (int) date('Y', strtotime($fecha)),
      'total' => round((float) ($f['Importe'] ?? 0), 2),
      'referencia' => (string) $referencia,
      'retencion' => round((float) ($f['ImpRetIrpf'] ?? 0), 2),
      'diferida' => !empty($f['FacturaContadoDiferida']) ? 1 : 0,
      'formaPago' => mb_substr(trim((string) ($f['Fpago'] ?? '')), 0, 3),
      'sujetoPasivo' => !empty($f['SujetoPasivo']) ? 1 : 0,
    ]);

    $insert = $ctb->prepare(
      'INSERT INTO FactEmitidasLin (
         Serie, UltNum, NumLin, ImpBase, CodIva, PjeIva, Iva, PjeRec, Rec
       ) VALUES (
         :serie, :numero, :numLin, :base, :codigoIva, :pjeIva, :iva, :pjeRec, :rec
       )'
    );
    $numLin = 0;
    for ($i = 1; $i <= 6; $i++) {
      $base = round((float) ($f["ImporteBase{$i}"] ?? 0), 2);
      $iva = round((float) ($f["ImporteIva{$i}"] ?? 0), 2);
      $rec = round((float) ($f["ImporteRec{$i}"] ?? 0), 2);
      if (abs($base) < 0.005 && abs($iva) < 0.005 && abs($rec) < 0.005) {
        continue;
      }
      $pjeIva = (float) ($f["PjeIva{$i}"] ?? 0);
      $pjeRec = (float) ($f["PjeRec{$i}"] ?? 0);
      $impuesto = $this->impuesto($pjeIva, $pjeRec);
      $numLin++;
      $insert->execute([
        'serie' => $serie,
        'numero' => $numero,
        'numLin' => $numLin,
        'base' => $base,
        'codigoIva' => $impuesto['codigo'],
        'pjeIva' => $pjeIva,
        'iva' => $iva,
        'pjeRec' => $pjeRec,
        'rec' => $rec,
      ]);
    }
  }

  /** @param array<string, mixed> $f */
  private function insertarEfectos(
    PDO $ctb,
    string $serie,
    int $numero,
    string $cuentaCliente,
    array $f
  ): int {
    $stmt = $this->gestion->prepare(
      'SELECT Recibo, Importe, Vencimiento
       FROM Recibos
       WHERE Empresa=:empresa AND FacturaTipo=:tipo AND Factura=:factura
       ORDER BY Recibo'
    );
    $stmt->execute([
      'empresa' => $f['Empresa'],
      'tipo' => $f['FacturaTipo'],
      'factura' => $f['Factura'],
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (strtoupper(trim((string) ($f['Estado'] ?? ''))) === 'G'
      && empty($f['CobroEnTienda'])
      && $rows === []
    ) {
      throw new \RuntimeException(
        "La factura {$f['FacturaTipo']}-{$f['Factura']} no tiene efectos de cobro",
        409
      );
    }

    $insert = $ctb->prepare(
      // GastosDev, Impagado y RepercutirGastosDev son NOT NULL sin default: el efecto
      // nace al corriente (sin gastos de devolucion) y los gestiona la contabilidad.
      'INSERT INTO EfectosCobro (
         Serie, UltNum, Efecto, Cliente, FormasCobro, FechaEmision, FechaVctos,
         Importe, DireccBanco, EfectoPrevision, CtaBancaria, Estado, Banco,
         Remesa, Gastos, Cuenta, Tipo, SelecEfecto, SelecRemesa, Pagare,
         ImporteLetras, UltNumDestino, EfectoDestino, Swift, IBAN, ControlPagare,
         GastosDev, Impagado, RepercutirGastosDev
       ) VALUES (
         :serie, :numero, :efecto, :cliente, :formaPago,
         CONVERT(datetime, :fecha, 120), CONVERT(datetime, :vencimiento, 120),
         :importe, :direccionBanco, 0, :ctaBancaria, 0, \'\',
         0, 0, \'\', \'R\', 0, 0, \'\', NULL, 0, 0, :swift, :iban, 0,
         0, 0, 0
       )'
    );
    $siguienteEfectoLibre = 1;
    $efectosUsados = [];
    foreach ($rows as $row) {
      $recibo = (int) $row['Recibo'];
      if ($recibo > 0 && !isset($efectosUsados[$recibo])) {
        $efecto = $recibo;
      } else {
        while (isset($efectosUsados[$siguienteEfectoLibre])) {
          $siguienteEfectoLibre++;
        }
        $efecto = $siguienteEfectoLibre;
        $siguienteEfectoLibre++;
      }
      $efectosUsados[$efecto] = true;

      if ($this->existeEfectoCobro($ctb, $serie, $numero, $efecto)) {
        continue;
      }

      $insert->execute([
        'serie' => $serie,
        'numero' => $numero,
        'efecto' => $efecto,
        'cliente' => $cuentaCliente,
        'formaPago' => mb_substr(trim((string) ($f['Fpago'] ?? '')), 0, 3),
        'fecha' => date('Y-m-d H:i:s', strtotime((string) $f['Fecha'])),
        'vencimiento' => date('Y-m-d H:i:s', strtotime((string) $row['Vencimiento'])),
        'importe' => round((float) $row['Importe'], 2),
        'direccionBanco' => mb_substr(trim((string) ($f['Banco'] ?? '')), 0, 40),
        'ctaBancaria' => mb_substr(trim((string) ($f['CuentaBancaria'] ?? '')), 0, 20),
        'swift' => mb_substr(trim((string) ($f['Swift'] ?? '')), 0, 20),
        'iban' => mb_substr(trim((string) ($f['IBAN'] ?? '')), 0, 34),
      ]);
    }
    return count($efectosUsados);
  }

  /**
   * Ventas al debe de cada cliente (también el de contado) y cobro por forma de pago.
   *
   * @return array{clientes: array<string, float>, fichas: array<string, array<string, mixed>>, cobros: array<string, float>}
   */
  private function repartoTicketsSesion(
    PDO $ctb,
    string $empresa,
    string $puesto,
    int $sesion,
    float $importe
  ): array {
    $stmt = $this->gestion->prepare(
      "SELECT RTRIM(a.Cliente) AS Cliente, ISNULL(a.Importe, 0) AS Importe,
              RTRIM(a.Fpago1) AS Fpago1, ISNULL(a.ImpFpago1, 0) AS Imp1,
              RTRIM(a.Fpago2) AS Fpago2, ISNULL(a.ImpFpago2, 0) AS Imp2,
              c.RazonSocial, c.NIF, c.Direccion, c.Poblacion, c.CodigoPostal,
              c.Provincia, c.Pais, c.Telefono1, c.Fax, c.FormaPago AS FormaPagoCliente,
              c.DiaPago1, c.DiaPago2, c.Banco, c.CuentaBancaria, c.CuentaCtb, c.CuentaCtb2,
              c.Swift, c.IBAN, c.ReferenciaMandato, c.FechaFirmaMandato,
              c.EmailFacturacion, c.TratamientoFiscal
       FROM AlbaranesVentasCab a
       LEFT JOIN Clientes c ON c.Codigo = a.Cliente
       WHERE a.Empresa = :empresa
         AND RTRIM(a.Puesto) = :puesto
         AND a.Sesion = :sesion
         AND a.FacturaTipo = 'T'
         AND ISNULL(a.TrasCtb, 0) = 0"
    );
    $stmt->execute([
      'empresa' => $empresa,
      'puesto' => $puesto,
      'sesion' => $sesion,
    ]);
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $stmt->closeCursor();

    $contado = $this->clienteContado();
    $clientes = [];
    $fichas = [];
    $cobros = [];
    foreach ($filas as $row) {
      $codigo = trim((string) ($row['Cliente'] ?? ''));
      $ficha = $codigo !== '' && $row['RazonSocial'] !== null ? $row : $contado;
      if ($codigo === '' || $row['RazonSocial'] === null) {
        $ficha['Cliente'] = $contado['Cliente'];
      } else {
        $ficha['Cliente'] = $codigo;
      }
      $cuenta = $this->cuentaCliente($ficha);
      $clientes[$cuenta] = round(($clientes[$cuenta] ?? 0) + (float) ($row['Importe'] ?? 0), 2);
      $fichas[$cuenta] = $ficha;
      $this->sumarCobro($cobros, (string) ($row['Fpago1'] ?? ''), (float) ($row['Imp1'] ?? 0));
      $this->sumarCobro($cobros, (string) ($row['Fpago2'] ?? ''), (float) ($row['Imp2'] ?? 0));
    }
    if ($clientes === []) {
      throw new \RuntimeException('Los tickets de la sesión no tienen cliente', 409);
    }
    if ($cobros === []) {
      throw new \RuntimeException('Los tickets de la sesión no tienen forma de cobro', 409);
    }
    $clientes = $this->ajustarAlTotal($clientes, $importe);
    $cobros = $this->ajustarAlTotal($cobros, $importe);
    foreach ($fichas as $cuenta => $ficha) {
      $this->sincronizarCliente($ctb, (string) $cuenta, $ficha);
    }
    return [
      'clientes' => $clientes,
      'fichas' => $fichas,
      'cobros' => $cobros,
    ];
  }

  /**
   * @param array<string|int, float> $cuentas
   * @return array<string|int, float>
   */
  private function ajustarAlTotal(array $cuentas, float $objetivo): array
  {
    $diferencia = round($objetivo - array_sum($cuentas), 2);
    if (abs($diferencia) < 0.005 || $cuentas === []) {
      return $cuentas;
    }
    $mayor = $this->claveMayor($cuentas);
    $cuentas[$mayor] = round($cuentas[$mayor] + $diferencia, 2);
    return $cuentas;
  }

  /** @param array<string|int, float> $cuentas */
  private function claveMayor(array $cuentas): string|int
  {
    $mayor = array_key_first($cuentas);
    $absMax = -1.0;
    foreach ($cuentas as $cuenta => $importe) {
      if (abs($importe) > $absMax) {
        $absMax = abs($importe);
        $mayor = $cuenta;
      }
    }
    return $mayor;
  }

  /** @param array<string, mixed> $f */
  private function insertarCobroSiContado(PDO $ctb, string $serie, string $cuentaCliente, array $f): void
  {
    if (!empty($f['FacturaContadoDiferida'])) {
      return;
    }
    $fpago = trim((string) ($f['Fpago'] ?? ''));
    if ($fpago === '') {
      $fpago = trim((string) ($f['FormaPagoCliente'] ?? ''));
    }
    if ($fpago === '' || !$this->formaEsContado($fpago)) {
      return;
    }
    $importe = round((float) ($f['Importe'] ?? 0) - (float) ($f['ImpRetIrpf'] ?? 0), 2);
    if (abs($importe) < 0.005) {
      return;
    }
    $cuentaPago = $this->cuentaFormaPago($fpago);
    $numero = $this->siguienteNumero($ctb, $serie);
    $this->insertarAsientoCobro(
      $ctb,
      $serie,
      $numero,
      (string) ($f['Fecha'] ?? date('Y-m-d H:i:s')),
      'Cobro fra. ' . $f['FacturaTipo'] . '-' . $f['Factura'],
      [$cuentaPago => $importe],
      [$cuentaCliente => $importe]
    );
  }

  private function formaEsContado(string $codigo): bool
  {
    $stmt = $this->gestion->prepare(
      'SELECT CobroDeArqueo FROM FormasPago WHERE RTRIM(Codigo) = :codigo'
    );
    $stmt->execute(['codigo' => trim($codigo)]);
    $valor = $stmt->fetchColumn();
    $stmt->closeCursor();
    return $valor !== false && (int) $valor !== 0;
  }

  /** @param array<string, float> $porCuenta */
  private function sumarCobro(array &$porCuenta, string $forma, float $importe): void
  {
    if (abs($importe) < 0.0005 || trim($forma) === '') {
      return;
    }
    $cuenta = $this->cuentaFormaPago($forma);
    $porCuenta[$cuenta] = round(($porCuenta[$cuenta] ?? 0) + $importe, 2);
  }

  private function cuentaFormaPago(string $codigo): string
  {
    $stmt = $this->gestion->prepare(
      'SELECT CuentaCtb FROM FormasPago WHERE RTRIM(Codigo) = :codigo'
    );
    $stmt->execute(['codigo' => trim($codigo)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    if ($row === false) {
      throw new \RuntimeException("No existe la forma de cobro {$codigo}", 409);
    }
    $cuenta = $this->codigoCuenta($row['CuentaCtb'] ?? null);
    if ($cuenta === '') {
      throw new \RuntimeException("La forma de cobro {$codigo} no tiene cuenta contable", 409);
    }
    return $cuenta;
  }

  private function formaCobroPrincipal(string $empresa, string $puesto, int $sesion): string
  {
    $stmt = $this->gestion->prepare(
      "SELECT TOP 1 RTRIM(Fpago1) AS Fpago
       FROM AlbaranesVentasCab
       WHERE Empresa = :empresa
         AND RTRIM(Puesto) = :puesto
         AND Sesion = :sesion
         AND FacturaTipo = 'T'
         AND ISNULL(TrasCtb, 0) = 0
         AND RTRIM(ISNULL(Fpago1, '')) <> ''
       GROUP BY RTRIM(Fpago1)
       ORDER BY SUM(ABS(ISNULL(ImpFpago1, 0))) DESC"
    );
    $stmt->execute([
      'empresa' => $empresa,
      'puesto' => $puesto,
      'sesion' => $sesion,
    ]);
    $forma = $stmt->fetchColumn();
    $stmt->closeCursor();
    return $forma === false ? '' : trim((string) $forma);
  }

  /**
   * Cobro de los pagos a cuenta anotados en esta caja: debe la cuenta de la forma
   * de pago y haber la cuenta del cliente. Va aparte del cobro de los tickets.
   */
  private function traspasarPagosACuentaSesion(string $empresa, string $puesto, int $sesion): void
  {
    $empresa = trim($empresa);
    $puesto = trim($puesto);
    $movs = $this->leerPagosACuentaSesion($empresa, $puesto, $sesion);
    if ($movs === []) {
      return;
    }

    /** @var array<string, float> $debes */
    $debes = [];
    /** @var array<string, float> $haberes */
    $haberes = [];
    /** @var array<string, array<string, mixed>> $fichas */
    $fichas = [];
    /** @var array<string, string> $clientesAlbaran */
    $clientesAlbaran = [];
    /** @var array<string, array<string, mixed>> $fichasCliente */
    $fichasCliente = [];

    foreach ($movs as $mov) {
      $importe = round((float) ($mov['Importe'] ?? 0), 2);
      if (abs($importe) < 0.005) {
        continue;
      }
      $forma = trim((string) ($mov['FormaPago'] ?? ''));
      $cuentaForma = $this->cuentaFormaPago($forma);
      $claveAlb = trim((string) $mov['Empresa']) . '|' . trim((string) $mov['Tipo']) . '|' . (int) $mov['Albaran'];
      if (!isset($clientesAlbaran[$claveAlb])) {
        $clientesAlbaran[$claveAlb] = $this->clienteDeAlbaran(
          (string) $mov['Empresa'],
          (string) $mov['Tipo'],
          (int) $mov['Albaran']
        );
      }
      $codigoCliente = $clientesAlbaran[$claveAlb];
      if (!isset($fichasCliente[$codigoCliente])) {
        $fichasCliente[$codigoCliente] = $this->fichaClienteContable($codigoCliente);
      }
      $ficha = $fichasCliente[$codigoCliente];
      $cuentaCliente = $this->cuentaCliente($ficha);
      $fichas[$cuentaCliente] = $ficha;
      if ($importe > 0) {
        $debes[$cuentaForma] = round(($debes[$cuentaForma] ?? 0) + $importe, 2);
        $haberes[$cuentaCliente] = round(($haberes[$cuentaCliente] ?? 0) + $importe, 2);
      } else {
        $absoluto = round(abs($importe), 2);
        $haberes[$cuentaForma] = round(($haberes[$cuentaForma] ?? 0) + $absoluto, 2);
        $debes[$cuentaCliente] = round(($debes[$cuentaCliente] ?? 0) + $absoluto, 2);
      }
    }

    [$debes, $haberes] = $this->netearCuentas($debes, $haberes);
    $texto = mb_substr('Cobro a cuenta sesion ' . $puesto . '/' . $sesion, 0, 200);
    $config = $this->conexion->paraEmpresa($empresa);
    /** @var PDO $ctb */
    $ctb = $config['pdo'];
    $serie = (string) $config['serie'];
    if ($debes === [] || $this->asientoPorDescripcion($ctb, $texto)) {
      $this->marcarPagosACuenta($empresa, $puesto, $sesion);
      return;
    }

    foreach ($fichas as $cuenta => $ficha) {
      if (isset($debes[$cuenta]) || isset($haberes[$cuenta])) {
        $this->sincronizarCliente($ctb, (string) $cuenta, $ficha);
      }
    }
    $numero = $this->siguienteNumero($ctb, $serie);
    $this->insertarAsientoCobro($ctb, $serie, $numero, date('Y-m-d H:i:s'), $texto, $debes, $haberes);
    $this->marcarPagosACuenta($empresa, $puesto, $sesion);
    $this->tocarComunicacion($empresa);
  }

  /** @return list<array<string, mixed>> */
  private function leerPagosACuentaSesion(string $empresa, string $puesto, int $sesion): array
  {
    try {
      return $this->consultarPagosACuentaSesion($empresa, $puesto, $sesion);
    } catch (\Throwable $e) {
      if (!$this->esTablaPagosAusente($e)) {
        throw $e;
      }
    }
    try {
      MejorasGestionSchema::asegurar($this->gestion);
      return $this->consultarPagosACuentaSesion($empresa, $puesto, $sesion);
    } catch (\Throwable $e) {
      if ($this->esTablaPagosAusente($e)) {
        return [];
      }
      throw $e;
    }
  }

  /** @return list<array<string, mixed>> */
  private function consultarPagosACuentaSesion(string $empresa, string $puesto, int $sesion): array
  {
    $stmt = $this->gestion->prepare(
      'SELECT Empresa, Tipo, Albaran, Importe, FormaPago
       FROM PagosACuentaAlbaran
       WHERE RTRIM(EmpresaArqueo) = :e AND RTRIM(Puesto) = :p AND Sesion = :s
         AND ISNULL(Contabilizado, 0) = 0'
    );
    $stmt->execute(['e' => $empresa, 'p' => $puesto, 's' => $sesion]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    return $rows;
  }

  private function esTablaPagosAusente(\Throwable $e): bool
  {
    return str_contains(strtolower($e->getMessage()), 'pagosacuentaalbaran');
  }

  private function clienteDeAlbaran(string $empresa, string $tipo, int $albaran): string
  {
    $stmt = $this->gestion->prepare(
      'SELECT Cliente FROM AlbaranesVentasCab WHERE Empresa = :e AND Tipo = :t AND Albaran = :a'
    );
    $stmt->execute(['e' => trim($empresa), 't' => trim($tipo), 'a' => $albaran]);
    $cliente = trim((string) ($stmt->fetchColumn() ?: ''));
    $stmt->closeCursor();
    if ($cliente === '') {
      throw new \RuntimeException("El albarán {$albaran} no tiene cliente para el pago a cuenta", 409);
    }
    return $cliente;
  }

  /** @return array<string, mixed> */
  private function fichaClienteContable(string $codigo): array
  {
    try {
      $stmt = $this->gestion->prepare(
        'SELECT Codigo AS Cliente, RazonSocial, NIF, Direccion, Poblacion, CodigoPostal,
                Provincia, Pais, Telefono1, Fax, FormaPago AS FormaPagoCliente,
                CuentaCtb, CuentaCtb2
         FROM Clientes WHERE Codigo = :c'
      );
      $stmt->execute(['c' => $codigo]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      $stmt->closeCursor();
    } catch (\Throwable $e) {
      $row = false;
    }
    if ($row === false) {
      $stmt = $this->gestion->prepare(
        'SELECT Codigo AS Cliente, RazonSocial, CuentaCtb, CuentaCtb2 FROM Clientes WHERE Codigo = :c'
      );
      $stmt->execute(['c' => $codigo]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      $stmt->closeCursor();
    }
    if ($row === false) {
      throw new \RuntimeException("El cliente {$codigo} no existe para el pago a cuenta", 409);
    }
    return $row;
  }

  /**
   * @param array<string, float> $debes
   * @param array<string, float> $haberes
   * @return array{0: array<string, float>, 1: array<string, float>}
   */
  private function netearCuentas(array $debes, array $haberes): array
  {
    $cuentas = array_unique(array_merge(array_keys($debes), array_keys($haberes)));
    $debeNeto = [];
    $haberNeto = [];
    foreach ($cuentas as $cuenta) {
      $neto = round(($debes[$cuenta] ?? 0) - ($haberes[$cuenta] ?? 0), 2);
      if ($neto > 0.004) {
        $debeNeto[$cuenta] = $neto;
      } elseif ($neto < -0.004) {
        $haberNeto[$cuenta] = round(-$neto, 2);
      }
    }
    return [$debeNeto, $haberNeto];
  }

  private function asientoPorDescripcion(PDO $ctb, string $texto): bool
  {
    $stmt = $ctb->prepare('SELECT TOP 1 1 FROM AsientosLin WHERE DescripcionAsiento = :d');
    $stmt->execute(['d' => $texto]);
    $existe = $stmt->fetchColumn() !== false;
    $stmt->closeCursor();
    return $existe;
  }

  private function marcarPagosACuenta(string $empresa, string $puesto, int $sesion): void
  {
    $this->gestion->prepare(
      'UPDATE PagosACuentaAlbaran SET Contabilizado = 1
       WHERE RTRIM(EmpresaArqueo) = :e AND RTRIM(Puesto) = :p AND Sesion = :s
         AND ISNULL(Contabilizado, 0) = 0'
    )->execute(['e' => $empresa, 'p' => $puesto, 's' => $sesion]);
  }

  /**
   * Asiento de cobro: debe las formas de pago, haber las cuentas de cliente.
   *
   * @param array<string|int, float> $debes
   * @param array<string|int, float> $haberes
   */
  private function insertarAsientoCobro(
    PDO $ctb,
    string $serie,
    int $numero,
    string $fecha,
    string $descripcion,
    array $debes,
    array $haberes
  ): void {
    if (!$this->existeAsiento($ctb, $serie, $numero)) {
      $ctb->prepare(
        "INSERT INTO AsientosCab (Serie, UltNum, Fecha, Periodo, Ejercicio, Tipo)
         VALUES (:serie, :numero, CONVERT(datetime, :fecha, 120), :periodo, :ejercicio, 'N')"
      )->execute([
        'serie' => $serie,
        'numero' => $numero,
        'fecha' => date('Y-m-d', strtotime($fecha)) . ' 00:00:00',
        'periodo' => (int) date('n', strtotime($fecha)),
        'ejercicio' => (int) date('Y', strtotime($fecha)),
      ]);
    }
    $texto = mb_substr($descripcion, 0, 200);
    $lineas = [];
    foreach ($debes as $cuenta => $importe) {
      $cuenta = (string) $cuenta;
      $this->validarCuenta($ctb, $cuenta);
      $lineas[] = [
        'cuenta' => $cuenta,
        'descripcion' => $texto,
        'debe' => round((float) $importe, 2),
        'haber' => 0.0,
        'centroCoste' => '',
      ];
    }
    foreach ($haberes as $cuenta => $importe) {
      $cuenta = (string) $cuenta;
      $this->validarCuenta($ctb, $cuenta);
      $lineas[] = [
        'cuenta' => $cuenta,
        'descripcion' => $texto,
        'debe' => 0.0,
        'haber' => round((float) $importe, 2),
        'centroCoste' => '',
      ];
    }
    $debe = round(array_sum(array_column($lineas, 'debe')), 2);
    $haber = round(array_sum(array_column($lineas, 'haber')), 2);
    if (abs($debe - $haber) > 0.02) {
      throw new \RuntimeException(
        "Asiento de cobro descuadrado: debe {$debe}, haber {$haber}",
        409
      );
    }
    $this->escribirLineasAsiento($ctb, $serie, $numero, $lineas);
  }

  /**
   * @param array<string, mixed> $f
   * @param array<string, float>|null $bases Cuentas de venta ya repartidas. Si es null, se calculan de la factura.
   * @param array<string|int, float>|null $debes Importes al debe por cuenta. Null = la cuenta del cliente por el total.
   */
  private function insertarAsiento(
    PDO $ctb,
    string $serie,
    int $numero,
    string $cuentaCliente,
    array $f,
    ?array $bases = null,
    ?string $descripcion = null,
    ?array $debes = null
  ): void {
    $fecha = (string) $f['Fecha'];
    if (!$this->existeAsiento($ctb, $serie, $numero)) {
      $ctb->prepare(
        "INSERT INTO AsientosCab (Serie, UltNum, Fecha, Periodo, Ejercicio, Tipo)
         VALUES (:serie, :numero, CONVERT(datetime, :fecha, 120), :periodo, :ejercicio, 'N')"
      )->execute([
        'serie' => $serie,
        'numero' => $numero,
        'fecha' => date('Y-m-d', strtotime($fecha)) . ' 00:00:00',
        'periodo' => (int) date('n', strtotime($fecha)),
        'ejercicio' => (int) date('Y', strtotime($fecha)),
      ]);
    }

    $nombre = mb_substr($descripcion ?? trim((string) ($f['RazonSocial'] ?? '')), 0, 200);
    $descripcionCliente = $descripcion
      ?? ('Fra. ' . $f['Factura'] . ' ' . date('d/m/y', strtotime($fecha)));
    $retencion = round((float) ($f['ImpRetIrpf'] ?? 0), 2);
    $centroCosteActividad = $this->centroCosteActividadCliente($f);
    /** @var array<string, bool> */
    $cacheDesgloseAnalitica = [];
    if ($debes === null) {
      $debes = [
        $cuentaCliente => round((float) ($f['Importe'] ?? 0) - $retencion, 2),
      ];
    }
    $lineas = [];
    foreach ($debes as $cuenta => $importeCobro) {
      $cuenta = (string) $cuenta;
      $this->validarCuenta($ctb, $cuenta);
      $lineas[] = [
        'cuenta' => $cuenta,
        'descripcion' => $descripcionCliente,
        'debe' => round((float) $importeCobro, 2),
        'haber' => 0.0,
        'centroCoste' => '',
      ];
    }

    $impuestosPorCuenta = [];
    $baseTotal = 0.0;
    for ($i = 1; $i <= 6; $i++) {
      $baseTotal += (float) ($f["ImporteBase{$i}"] ?? 0);
      $iva = round(
        (float) ($f["ImporteIva{$i}"] ?? 0) + (float) ($f["ImporteRec{$i}"] ?? 0),
        2
      );
      if (abs($iva) < 0.005) {
        continue;
      }
      $impuesto = $this->impuesto(
        (float) ($f["PjeIva{$i}"] ?? 0),
        (float) ($f["PjeRec{$i}"] ?? 0)
      );
      $cuentaIva = $impuesto['cuenta'];
      if ($cuentaIva === '') {
        throw new \RuntimeException("El impuesto {$impuesto['codigo']} no tiene CuentaCtb", 409);
      }
      $impuestosPorCuenta[$cuentaIva] = round(
        ($impuestosPorCuenta[$cuentaIva] ?? 0) + $iva,
        2
      );
    }
    // PHP convierte a int las claves numericas del array: la cuenta vuelve a texto.
    foreach ($impuestosPorCuenta as $cuenta => $importe) {
      $cuenta = (string) $cuenta;
      $this->validarCuenta($ctb, $cuenta);
      $lineas[] = [
        'cuenta' => $cuenta,
        'descripcion' => $nombre,
        'debe' => 0.0,
        'haber' => $importe,
        'centroCoste' => '',
      ];
    }

    if (abs($retencion) >= 0.005) {
      $cuentaRetencion = $this->codigoCuenta($f['CtbRetIrpf'] ?? null);
      if ($cuentaRetencion === '') {
        throw new \RuntimeException('La tienda no tiene configurada CtbRetIrpf', 409);
      }
      $this->validarCuenta($ctb, $cuentaRetencion);
      $lineas[] = [
        'cuenta' => $cuentaRetencion,
        'descripcion' => $nombre,
        'debe' => $retencion,
        'haber' => 0.0,
        'centroCoste' => '',
      ];
    }

    $cuentasVenta = $bases ?? $this->basesPorCuenta($f, round($baseTotal, 2));
    foreach ($cuentasVenta as $cuenta => $importe) {
      $cuenta = (string) $cuenta;
      $this->validarCuenta($ctb, $cuenta);
      $centroCosteLinea = '';
      if ($centroCosteActividad !== ''
        && $this->cuentaLlevaCentroCoste($ctb, $cuenta, $cacheDesgloseAnalitica)
      ) {
        $centroCosteLinea = $centroCosteActividad;
      }
      $lineas[] = [
        'cuenta' => $cuenta,
        'descripcion' => $nombre,
        'debe' => 0.0,
        'haber' => $importe,
        'centroCoste' => $centroCosteLinea,
      ];
    }

    $debe = round(array_sum(array_column($lineas, 'debe')), 2);
    $haber = round(array_sum(array_column($lineas, 'haber')), 2);
    if (abs($debe - $haber) > 0.02) {
      throw new \RuntimeException(
        "Asiento descuadrado para {$f['FacturaTipo']}-{$f['Factura']}: debe {$debe}, haber {$haber}",
        409
      );
    }

    $this->escribirLineasAsiento($ctb, $serie, $numero, $lineas);
  }

  /** @param list<array{cuenta: string, descripcion: string, debe: float, haber: float, centroCoste: string}> $lineas */
  private function escribirLineasAsiento(PDO $ctb, string $serie, int $numero, array $lineas): void
  {
    $numLin = $this->maxNumLinAsiento($ctb, $serie, $numero);
    if ($numLin > 0) {
      return;
    }

    $conCentroCoste = $this->asientosLinTieneCentroCoste($ctb);
    if ($conCentroCoste) {
      $insert = $ctb->prepare(
        'INSERT INTO AsientosLin (
           Serie, UltNum, NumLin, Cuenta, DescripcionAsiento, ReferenciaAsiento,
           ImpDebe, ImpHaber, Casado, UltNumEfecto, Efecto, CentroCoste
         ) VALUES (
           :serie, :numero, :numLin, :cuenta, :descripcion, \'Fra.Dif\',
           :debe, :haber, 0, 0, 0, :centroCoste
         )'
      );
    } else {
      $insert = $ctb->prepare(
        'INSERT INTO AsientosLin (
           Serie, UltNum, NumLin, Cuenta, DescripcionAsiento, ReferenciaAsiento,
           ImpDebe, ImpHaber, Casado, UltNumEfecto, Efecto
         ) VALUES (
           :serie, :numero, :numLin, :cuenta, :descripcion, \'Fra.Dif\',
           :debe, :haber, 0, 0, 0
         )'
      );
    }
    foreach ($lineas as $linea) {
      $numLin++;
      $params = [
        'serie' => $serie,
        'numero' => $numero,
        'numLin' => $numLin,
        'cuenta' => $linea['cuenta'],
        'descripcion' => mb_substr($linea['descripcion'], 0, 200),
        'debe' => $linea['debe'],
        'haber' => $linea['haber'],
      ];
      if ($conCentroCoste) {
        $params['centroCoste'] = mb_substr(trim((string) ($linea['centroCoste'] ?? '')), 0, 10);
      }
      $insert->execute($params);
    }
  }

  /** @param array<string, mixed> $f @return array<string, float> */
  private function basesPorCuenta(array $f, float $baseTotal): array
  {
    $tipoDocumento = (string) $f['FacturaTipo'];
    $numeroDocumento = (int) $f['Factura'];
    if ($tipoDocumento === 'A'
      && preg_match('/^RECT:([FA])\/(\d+)$/', trim((string) ($f['FirmaFacturaAnterior'] ?? '')), $m)
    ) {
      $tipoDocumento = $m[1];
      $numeroDocumento = (int) $m[2];
    }

    $stmt = $this->gestion->prepare(
      "SELECT COALESCE(NULLIF(sf.CuentaCtb, 0), NULLIF(fa.CuentaCtb, 0), 700) AS Cuenta,
              SUM(ISNULL(l.Importe, 0)) AS Importe
       FROM AlbaranesVentasCab a
       INNER JOIN AlbaranesVentasLin l
         ON l.Empresa=a.Empresa AND l.Tipo=a.Tipo AND l.Albaran=a.Albaran
       LEFT JOIN Articulos ar ON ar.Codigo=l.Articulo
       LEFT JOIN Subfamilias sf ON sf.Familia=ar.Familia AND sf.Subfamilia=ar.Subfamilia
       LEFT JOIN Familias fa ON fa.Codigo=ar.Familia
       WHERE (a.EmpresaFacturacion=:empresa
              OR (ISNULL(a.EmpresaFacturacion, '')='' AND a.Empresa=:empresa2))
         AND a.FacturaTipo=:tipo AND a.Factura=:factura
         AND LTRIM(RTRIM(ISNULL(l.Articulo, ''))) <> 'NO'
       GROUP BY COALESCE(NULLIF(sf.CuentaCtb, 0), NULLIF(fa.CuentaCtb, 0), 700)"
    );
    $stmt->execute([
      'empresa' => $f['Empresa'],
      'empresa2' => $f['Empresa'],
      'tipo' => $tipoDocumento,
      'factura' => $numeroDocumento,
    ]);
    $raw = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
      $cuenta = $this->codigoCuenta($row['Cuenta'] ?? null) ?: '700';
      $raw[$cuenta] = ($raw[$cuenta] ?? 0) + (float) ($row['Importe'] ?? 0);
    }
    return $this->repartirBase($raw, $baseTotal);
  }

  /** @param array<string, float> $raw @return array<string, float> */
  private function repartirBase(array $raw, float $baseTotal): array
  {
    $suma = array_sum($raw);
    if ($raw === [] || abs($suma) < 0.005) {
      return ['700' => $baseTotal];
    }

    $resultado = [];
    $acumulado = 0.0;
    $cuentas = array_keys($raw);
    foreach ($cuentas as $indice => $cuenta) {
      $importe = $indice === count($cuentas) - 1
        ? round($baseTotal - $acumulado, 2)
        : round($baseTotal * ($raw[$cuenta] / $suma), 2);
      $resultado[$cuenta] = $importe;
      $acumulado = round($acumulado + $importe, 2);
    }
    return $resultado;
  }

  /** @return array{codigo: string, cuenta: string} */
  private function impuesto(float $pjeIva, float $pjeRec): array
  {
    $stmt = $this->gestion->prepare(
      'SELECT TOP 1 Codigo, CuentaCtb
       FROM Impuestos
       WHERE ABS(ISNULL(PjeIVA, 0)-:iva)<0.01
         AND (:sinRecargo=1 OR ABS(ISNULL(PjeRec, 0)-:rec)<0.01)
       ORDER BY Baja, ABS(ISNULL(PjeRec, 0)-:recOrden), Codigo'
    );
    $stmt->execute([
      'iva' => $pjeIva,
      'sinRecargo' => abs($pjeRec) < 0.01 ? 1 : 0,
      'rec' => $pjeRec,
      'recOrden' => $pjeRec,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      throw new \RuntimeException("No existe impuesto para IVA {$pjeIva} y recargo {$pjeRec}", 409);
    }
    return [
      'codigo' => trim((string) $row['Codigo']),
      'cuenta' => $this->codigoCuenta($row['CuentaCtb'] ?? null),
    ];
  }

  /**
   * La familia puede tener una cuenta de ventas que este plan contable no tiene.
   * Ese importe pasa a la 700, que es la cuenta por defecto del traspaso.
   *
   * @param array<string, float> $bases
   * @return array<string, float>
   */
  private function sustituirCuentasAusentes(PDO $ctb, array $bases, string $defecto): array
  {
    if (!$this->cuentaExiste($ctb, $defecto)) {
      return $bases;
    }
    $resultado = [];
    foreach ($bases as $cuenta => $importe) {
      $cuenta = (string) $cuenta;
      if ($cuenta !== $defecto && !$this->cuentaExiste($ctb, $cuenta)) {
        $resultado[$defecto] = round(($resultado[$defecto] ?? 0) + $importe, 2);
        continue;
      }
      $resultado[$cuenta] = round(($resultado[$cuenta] ?? 0) + $importe, 2);
    }
    return $resultado;
  }

  private function cuentaExiste(PDO $ctb, string $cuenta): bool
  {
    $stmt = $ctb->prepare('SELECT 1 FROM Cuentas WHERE Codigo=:cuenta');
    $stmt->execute(['cuenta' => $cuenta]);
    return $stmt->fetchColumn() !== false;
  }

  private function validarCuenta(PDO $ctb, string $cuenta): void
  {
    $stmt = $ctb->prepare('SELECT 1 FROM Cuentas WHERE Codigo=:cuenta');
    $stmt->execute(['cuenta' => $cuenta]);
    if ($stmt->fetchColumn() === false) {
      throw new \RuntimeException("La cuenta {$cuenta} no existe en contabilidad", 409);
    }
  }

  /** @param array<string, mixed> $factura */
  private function centroCosteActividadCliente(array $factura): string
  {
    if (!array_key_exists('Actividad', $factura)) {
      return '';
    }
    $codigoActividad = (int) ($factura['Actividad'] ?? 0);
    $stmt = $this->gestion->prepare(
      'SELECT CodigoCentroCoste FROM Actividades WHERE Codigo = :codigo'
    );
    $stmt->execute(['codigo' => $codigoActividad]);
    $valor = $stmt->fetchColumn();
    if ($valor === false) {
      return '';
    }

    return mb_substr(trim((string) $valor), 0, 10);
  }

  /** @param array<string, bool> $cache */
  private function cuentaLlevaCentroCoste(PDO $ctb, string $cuenta, array &$cache): bool
  {
    if (isset($cache[$cuenta])) {
      return $cache[$cuenta];
    }
    $stmt = $ctb->prepare('SELECT DesgloseAnalitica FROM Cuentas WHERE Codigo = :cuenta');
    $stmt->execute(['cuenta' => $cuenta]);
    $valor = $stmt->fetchColumn();
    $cache[$cuenta] = $valor !== false && !empty($valor);

    return $cache[$cuenta];
  }

  private function asientosLinTieneCentroCoste(PDO $ctb): bool
  {
    if (self::$asientosLinTieneCentroCoste !== null) {
      return self::$asientosLinTieneCentroCoste;
    }
    if ($ctb->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
      $stmt = $ctb->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = \'AsientosLin\'
           AND COLUMN_NAME = \'CentroCoste\''
      );
      $stmt->execute();
      self::$asientosLinTieneCentroCoste = (bool) $stmt->fetchColumn();
    } else {
      $stmt = $ctb->query(
        "SELECT CASE WHEN COL_LENGTH('dbo.AsientosLin', 'CentroCoste') IS NOT NULL THEN 1 ELSE 0 END"
      );
      self::$asientosLinTieneCentroCoste = (bool) ($stmt ? $stmt->fetchColumn() : 0);
    }

    return self::$asientosLinTieneCentroCoste;
  }

  private function marcarTraspasada(
    string $empresa,
    string $tipo,
    int $factura,
    string $serie,
    int $numero
  ): void
  {
    $stmt = $this->gestion->prepare(
      'UPDATE Facturas SET TrasCtb=1, SerieCtb=:serie, UltNum=:numero
       WHERE Empresa=:empresa AND FacturaTipo=:tipo AND Factura=:factura'
    );
    $stmt->execute([
      'serie' => $serie,
      'numero' => $numero,
      'empresa' => $empresa,
      'tipo' => $tipo,
      'factura' => $factura,
    ]);
    $this->gestion->prepare(
      'UPDATE Empresas_Ges SET UltimaComunicacionCTB=GETDATE() WHERE Codigo=:empresa'
    )->execute(['empresa' => $empresa]);
  }

  private function existeAsiento(PDO $ctb, string $serie, int $numero): bool
  {
    $stmt = $ctb->prepare('SELECT 1 FROM AsientosCab WHERE Serie=:serie AND UltNum=:numero');
    $stmt->execute(['serie' => $serie, 'numero' => $numero]);
    return $stmt->fetchColumn() !== false;
  }

  private function contarEfectos(PDO $ctb, string $serie, int $numero): int
  {
    $stmt = $ctb->prepare('SELECT COUNT(*) FROM EfectosCobro WHERE Serie=:serie AND UltNum=:numero');
    $stmt->execute(['serie' => $serie, 'numero' => $numero]);
    return (int) $stmt->fetchColumn();
  }

  private function existeEfectoCobro(PDO $ctb, string $serie, int $numero, int $efecto): bool
  {
    $stmt = $ctb->prepare(
      'SELECT 1 FROM EfectosCobro WHERE Serie=:serie AND UltNum=:numero AND Efecto=:efecto'
    );
    $stmt->execute(['serie' => $serie, 'numero' => $numero, 'efecto' => $efecto]);
    return $stmt->fetchColumn() !== false;
  }

  private function maxNumLinAsiento(PDO $ctb, string $serie, int $numero): int
  {
    $stmt = $ctb->prepare(
      'SELECT COALESCE(MAX(NumLin), 0) FROM AsientosLin WHERE Serie=:serie AND UltNum=:numero'
    );
    $stmt->execute(['serie' => $serie, 'numero' => $numero]);
    return (int) $stmt->fetchColumn();
  }

  private function codigoCuenta(mixed $valor): string
  {
    $texto = trim((string) ($valor ?? ''));
    if ($texto === '' || (float) $texto == 0.0) {
      return '';
    }
    return preg_match('/^-?\d+(?:\.0+)?$/', $texto)
      ? (string) (int) round((float) $texto)
      : $texto;
  }

  /** Normaliza a 'Y-m-d H:i:s' para usar siempre CONVERT(datetime, ..., 120). */
  private function fechaSql(mixed $valor): ?string
  {
    if ($valor === null || $valor === '') {
      return null;
    }
    $ts = strtotime((string) $valor);
    return $ts === false ? null : date('Y-m-d H:i:s', $ts);
  }

  private function fechaIso(mixed $valor): ?string
  {
    if ($valor === null || $valor === '') {
      return null;
    }
    $ts = strtotime((string) $valor);
    return $ts === false ? null : date('Y-m-d', $ts);
  }
}
