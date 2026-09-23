<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Facturacion;

use PDO;

/**
 * Traspasa facturas de Gestión a la contabilidad legacy:
 * libro de emitidas, asiento y efectos de cobro.
 */
final class TraspasoContableService
{
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
      $this->insertarLibroEmitidas($ctb, $serie, $numero, $factura, $cuentaCliente, $origen);
      $efectos = $this->insertarEfectos($ctb, $serie, $numero, $cuentaCliente, $origen);
      $this->insertarAsiento($ctb, $serie, $numero, $cuentaCliente, $origen);
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

  /** @return array<string, mixed> */
  private function cargarFactura(string $empresa, string $tipo, int $factura): array
  {
    $stmt = $this->gestion->prepare(
      "SELECT f.*, c.RazonSocial, c.NIF, c.Direccion, c.Poblacion, c.CodigoPostal,
              c.Provincia, c.Pais, c.Telefono1, c.Fax, c.FormaPago AS FormaPagoCliente,
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
    int $referencia,
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
         Serie, UltNum, ImpBase, CodIva, PjeIva, Iva, PjeRec, Rec
       ) VALUES (
         :serie, :numero, :base, :codigoIva, :pjeIva, :iva, :pjeRec, :rec
       )'
    );
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
      $insert->execute([
        'serie' => $serie,
        'numero' => $numero,
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
    foreach ($rows as $row) {
      $insert->execute([
        'serie' => $serie,
        'numero' => $numero,
        'efecto' => (int) $row['Recibo'],
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
    return count($rows);
  }

  /** @param array<string, mixed> $f */
  private function insertarAsiento(
    PDO $ctb,
    string $serie,
    int $numero,
    string $cuentaCliente,
    array $f
  ): void {
    $fecha = (string) $f['Fecha'];
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

    $nombre = mb_substr(trim((string) ($f['RazonSocial'] ?? '')), 0, 200);
    $descripcionCliente = 'Fra. ' . $f['Factura'] . ' ' . date('d/m/y', strtotime($fecha));
    $retencion = round((float) ($f['ImpRetIrpf'] ?? 0), 2);
    $lineas = [[
      'cuenta' => $cuentaCliente,
      'descripcion' => $descripcionCliente,
      'debe' => round((float) ($f['Importe'] ?? 0) - $retencion, 2),
      'haber' => 0.0,
    ]];

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
      $lineas[] = ['cuenta' => $cuenta, 'descripcion' => $nombre, 'debe' => 0.0, 'haber' => $importe];
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
      ];
    }

    foreach ($this->basesPorCuenta($f, round($baseTotal, 2)) as $cuenta => $importe) {
      $cuenta = (string) $cuenta;
      $this->validarCuenta($ctb, $cuenta);
      $lineas[] = ['cuenta' => $cuenta, 'descripcion' => $nombre, 'debe' => 0.0, 'haber' => $importe];
    }

    $debe = round(array_sum(array_column($lineas, 'debe')), 2);
    $haber = round(array_sum(array_column($lineas, 'haber')), 2);
    if (abs($debe - $haber) > 0.02) {
      throw new \RuntimeException(
        "Asiento descuadrado para {$f['FacturaTipo']}-{$f['Factura']}: debe {$debe}, haber {$haber}",
        409
      );
    }

    $insert = $ctb->prepare(
      'INSERT INTO AsientosLin (
         Serie, UltNum, Cuenta, DescripcionAsiento, ReferenciaAsiento,
         ImpDebe, ImpHaber, Casado, UltNumEfecto, Efecto
       ) VALUES (
         :serie, :numero, :cuenta, :descripcion, \'Fra.Dif\',
         :debe, :haber, 0, 0, 0
       )'
    );
    foreach ($lineas as $linea) {
      $insert->execute([
        'serie' => $serie,
        'numero' => $numero,
        'cuenta' => $linea['cuenta'],
        'descripcion' => mb_substr($linea['descripcion'], 0, 200),
        'debe' => $linea['debe'],
        'haber' => $linea['haber'],
      ]);
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
    $suma = 0.0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
      $cuenta = $this->codigoCuenta($row['Cuenta'] ?? null) ?: '700';
      $importe = (float) ($row['Importe'] ?? 0);
      $raw[$cuenta] = ($raw[$cuenta] ?? 0) + $importe;
      $suma += $importe;
    }
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

  private function validarCuenta(PDO $ctb, string $cuenta): void
  {
    $stmt = $ctb->prepare('SELECT 1 FROM Cuentas WHERE Codigo=:cuenta');
    $stmt->execute(['cuenta' => $cuenta]);
    if ($stmt->fetchColumn() === false) {
      throw new \RuntimeException("La cuenta {$cuenta} no existe en contabilidad", 409);
    }
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
