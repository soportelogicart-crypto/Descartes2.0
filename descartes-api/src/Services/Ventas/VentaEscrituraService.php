<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Ventas;

use PDO;

/**
 * Alta/edicion de albaranes de venta desde Gestion.
 * Regla: si esta facturado (Factura>0) o Tipo=F, no se puede modificar.
 */
final class VentaEscrituraService
{
  private PDO $pdo;
  private VentaConsultaService $consulta;
  private ArqueoService $arqueo;

  public function __construct(PDO $pdo, VentaConsultaService $consulta, ?ArqueoService $arqueo = null)
  {
    $this->pdo = $pdo;
    $this->consulta = $consulta;
    $this->arqueo = $arqueo ?? new ArqueoService($pdo);
  }

  public function estaBloqueado(?array $cab): bool
  {
    if ($cab === null) {
      return false;
    }
    $facturaTipo = strtoupper(trim((string) ($cab['facturaTipo'] ?? $cab['FacturaTipo'] ?? '')));
    // Factura / abono: bloqueo total. Ticket cerrado (T+Factura>0) no se edita, pero puede pasar a factura.
    if ($facturaTipo === 'F' || $facturaTipo === 'A') {
      return true;
    }
    if ($facturaTipo === 'T' && (int) ($cab['factura'] ?? $cab['Factura'] ?? 0) > 0) {
      return true; // no editar lineas; finalizar T→F se permite aparte
    }
    return false;
  }

  /** Ticket tipificado (legacy Option3: TransformacionTicketaFactura). */
  public function esTicketCerrado(?array $cab): bool
  {
    if ($cab === null) {
      return false;
    }
    $facturaTipo = strtoupper(trim((string) ($cab['facturaTipo'] ?? $cab['FacturaTipo'] ?? '')));
    return $facturaTipo === 'T' && (int) ($cab['factura'] ?? $cab['Factura'] ?? 0) > 0;
  }

  /** @param array<string, mixed> $body */
  public function crear(array $body): array
  {
    $empresa = trim((string) ($body['empresa'] ?? ''));
    // Legacy FrmVenta PreGrabacion: Tipo siempre "A" en alta.
    $tipo = 'A';
    if ($empresa === '') {
      throw new \InvalidArgumentException('Empresa (tienda) obligatoria');
    }

    $albaran = isset($body['albaran']) && (int) $body['albaran'] > 0
      ? (int) $body['albaran']
      : $this->nextAlbaran($empresa, $tipo);

    $lineas = $body['lineas'] ?? [];
    if (!is_array($lineas)) {
      $lineas = [];
    }

    // Alta de cabecera: importes a 0 hasta tener lineas (como legacy antes de CalculoTotal).
    $totales = $this->totalesVacios();
    if (count(array_filter($lineas, static fn ($l) => trim((string) ($l['articulo'] ?? '')) !== '')) > 0) {
      $totales = $this->calcularTotales($lineas, $body);
    }

    $puesto = trim((string) ($body['puesto'] ?? ''));
    if ($puesto === '') {
      $puesto = '99';
    }
    $vendedor = $this->nullIfEmpty($body['vendedor'] ?? null);
    $fecha = $this->normalizeFecha($body['fecha'] ?? null);
    $representante = $this->codigoCharOEspacio($body['representante'] ?? null);
    $transporte = $this->spaceIfEmpty($body['transporte'] ?? null);
    $agente = $this->spaceIfEmpty($body['agente'] ?? null);

    $this->pdo->beginTransaction();
    try {
      $sql = 'INSERT INTO AlbaranesVentasCab (
          Empresa, Albaran, Tipo, Puesto, Cliente, RazonSocial, RazonSocial2, NIF, Fecha, Vendedor,
          Representante, Transporte, DireccionEnvio, PoblacionEnvio, CodigoPostalEnvio, ProvinciaEnvio, PaisEnvio,
          ImporteBase1, ImporteBase2, ImporteBase3, ImporteBase4,
          PjeIva1, PjeIva2, PjeIva3, PjeIva4,
          ImporteIva1, ImporteIva2, ImporteIva3, ImporteIva4,
          PjeRec1, PjeRec2, PjeRec3, PjeRec4,
          ImporteRec1, ImporteRec2, ImporteRec3, ImporteRec4,
          PjeDto, ImporteDtos, Importe,
          Fpago1, Fpago2, Divisa1, Divisa2,
          Estado, FechaCobro, FacturaTipo, Factura, Pedido,
          TrasCtb, TrasModem, RebajeStock, Impreso,
          Referencia1, Referencia2, Agente, Almacen, EmpresaFacturacion,
          Mesa, Cubiertos, ReImpresion, LineasImpresas,
          FechaEntrega, HoraEntrega, Telefono, Telefono2, Fax, Email,
          SuPedido, Habitacion, CentroProduccion, TraspasadoaHotel, Sesion,
          ImpFpago1, ImpFpago2, OrGen, PagoaCuenta, Idioma, Kilos, CostePortes, ImportePortes,
          Seleccion, Cambio, Impresion80, ReservaCentralizada,
          VendedorApertura, Perfil, FacturaRetroceso, AgenciaHotel, EntregaDomicilio, Tarjeta,
          FechaApertura, LineasConDescuentos, FechaPreparacion, GenAlbaranCompras, Actividad, Anulado,
          PuestoApertura, Portes, Tarifa, Plataforma, NumeroDeSerie, Observaciones
        ) VALUES (
          :empresa, :albaran, :tipo, :puesto, :cliente, :razonSocial, :razonSocial2, :nif,
          CONVERT(datetime, :fecha, 120), :vendedor,
          :representante, :transporte, :direccionEnvio, :poblacionEnvio, :codigoPostalEnvio, :provinciaEnvio, :paisEnvio,
          :importeBase1, :importeBase2, :importeBase3, :importeBase4,
          :pjeIva1, :pjeIva2, :pjeIva3, :pjeIva4,
          :importeIva1, :importeIva2, :importeIva3, :importeIva4,
          0, 0, 0, 0,
          0, 0, 0, 0,
          :pjeDto, :importeDtos, :importe,
          :fpago1, :fpago2, 0, 0,
          :estado, NULL, :facturaTipo, 0, :pedido,
          0, 0, 0, 0,
          :referencia1, :referencia2, :agente, :almacen, :empresaFacturacion,
          0, NULL, 0, NULL,
          NULL, NULL, :telefono, :telefono2, :fax, :email,
          :suPedido, :habitacion, NULL, 0, 0,
          0, 0, 0, 0, 0, 0, 0, 0,
          0, 0, 0, 0,
          :vendedorApertura, 0, 0, NULL, 0, NULL,
          NULL, :lineasConDescuentos, NULL, 0, 0, 0,
          NULL, :portes, :tarifa, :plataforma, :numeroDeSerie, :observaciones
        )';
      $stmt = $this->pdo->prepare($sql);
      $stmt->execute([
        'empresa' => $empresa,
        'albaran' => $albaran,
        'tipo' => $tipo,
        'puesto' => $puesto,
        'cliente' => $this->nullIfEmpty($body['cliente'] ?? null),
        'razonSocial' => $this->nullIfEmpty($body['razonSocial'] ?? null),
        'razonSocial2' => $this->blankIfEmpty($body['razonSocial2'] ?? null),
        'nif' => (string) ($body['nif'] ?? ''),
        'fecha' => $fecha,
        'vendedor' => $vendedor,
        'representante' => $representante,
        'transporte' => $transporte,
        'direccionEnvio' => $this->spaceIfEmpty($body['direccionEnvio'] ?? null),
        'poblacionEnvio' => $this->spaceIfEmpty($body['poblacionEnvio'] ?? null),
        'codigoPostalEnvio' => $this->spaceIfEmpty($body['codigoPostalEnvio'] ?? null),
        'provinciaEnvio' => $this->spaceIfEmpty($body['provinciaEnvio'] ?? null),
        'paisEnvio' => $this->spaceIfEmpty($body['paisEnvio'] ?? null),
        'importeBase1' => $totales['bases'][0],
        'importeBase2' => $totales['bases'][1],
        'importeBase3' => $totales['bases'][2],
        'importeBase4' => $totales['bases'][3],
        'pjeIva1' => $totales['pjes'][0],
        'pjeIva2' => $totales['pjes'][1],
        'pjeIva3' => $totales['pjes'][2],
        'pjeIva4' => $totales['pjes'][3],
        'importeIva1' => $totales['ivas'][0],
        'importeIva2' => $totales['ivas'][1],
        'importeIva3' => $totales['ivas'][2],
        'importeIva4' => $totales['ivas'][3],
        'pjeDto' => $totales['pjeDto'],
        'importeDtos' => $totales['descuento'],
        'importe' => $totales['importe'],
        'fpago1' => $this->spaceIfEmpty($body['fpago1'] ?? null),
        'fpago2' => (string) ($body['fpago2'] ?? ''),
        'estado' => 'B',
        'facturaTipo' => (string) ($body['facturaTipo'] ?? 'R'),
        'pedido' => isset($body['pedido']) && $body['pedido'] !== '' && $body['pedido'] !== null
          ? (int) $body['pedido'] : 0,
        'referencia1' => $this->blankIfEmpty($body['referencia1'] ?? null),
        'referencia2' => $this->blankIfEmpty($body['referencia2'] ?? null),
        'agente' => $agente,
        'almacen' => isset($body['almacen']) && $body['almacen'] !== '' && $body['almacen'] !== null
          ? (int) $body['almacen'] : null,
        'empresaFacturacion' => (string) ($body['empresaFacturacion'] ?? $empresa),
        'telefono' => $this->blankIfEmpty($body['telefono'] ?? null),
        'telefono2' => $this->blankIfEmpty($body['telefono2'] ?? null),
        'fax' => $this->blankIfEmpty($body['fax'] ?? null),
        'email' => $this->blankIfEmpty($body['email'] ?? null),
        'suPedido' => (string) ($body['suPedido'] ?? ''),
        'habitacion' => isset($body['habitacion']) && $body['habitacion'] !== '' && $body['habitacion'] !== null
          ? (float) $body['habitacion'] : null,
        // VendedorApertura = vendedor (trabajador). No usar usuario de login.
        'vendedorApertura' => $this->nullIfEmpty($body['vendedorApertura'] ?? $vendedor),
        'lineasConDescuentos' => !empty($totales['lineasConDescuentos']) ? 1 : 0,
        'portes' => $this->spaceIfEmpty($body['portes'] ?? null),
        'tarifa' => isset($body['tarifa']) && $body['tarifa'] !== '' && $body['tarifa'] !== null
          ? (int) $body['tarifa'] : 0,
        'plataforma' => $this->blankIfEmpty($body['plataforma'] ?? null),
        'numeroDeSerie' => $this->blankIfEmpty($body['numeroDeSerie'] ?? null),
        'observaciones' => $this->blankIfEmpty($body['observaciones'] ?? null),
      ]);

      $this->reemplazarLineas($empresa, $tipo, $albaran, $lineas);
      $this->pdo->commit();
    } catch (\Throwable $e) {
      $this->pdo->rollBack();
      throw $e;
    }

    $detalle = $this->consulta->obtenerFicha($empresa, $tipo, $albaran);
    if ($detalle === null) {
      throw new \RuntimeException('Venta creada pero no se pudo releer');
    }
    return $detalle;
  }

  /** @param array<string, mixed> $body */
  public function actualizar(string $empresa, string $tipo, int $albaran, array $body): array
  {
    $actual = $this->consulta->obtenerFicha($empresa, $tipo, $albaran);
    if ($actual === null) {
      throw new \RuntimeException('Venta no encontrada', 404);
    }
    if ($this->estaBloqueado($actual)) {
      throw new \RuntimeException('Documento facturado: no se puede modificar', 409);
    }

    $lineas = $body['lineas'] ?? $actual['lineas'] ?? [];
    if (!is_array($lineas)) {
      $lineas = [];
    }
    if (!isset($body['empresa']) || trim((string) $body['empresa']) === '') {
      $body['empresa'] = $empresa;
    }
    $totales = $this->calcularTotales($lineas, $body);

    $this->pdo->beginTransaction();
    try {
      $sql = 'UPDATE AlbaranesVentasCab SET
          Puesto = :puesto, Cliente = :cliente, RazonSocial = :razonSocial, RazonSocial2 = :razonSocial2,
          NIF = :nif, Fecha = CONVERT(datetime, :fecha, 120), Vendedor = :vendedor, Representante = :representante,
          Transporte = :transporte, DireccionEnvio = :direccionEnvio, PoblacionEnvio = :poblacionEnvio,
          CodigoPostalEnvio = :codigoPostalEnvio, ProvinciaEnvio = :provinciaEnvio, PaisEnvio = :paisEnvio,
          Telefono = :telefono, Telefono2 = :telefono2, Fax = :fax, Email = :email, Almacen = :almacen,
          Pedido = :pedido, Referencia1 = :referencia1, Referencia2 = :referencia2, NumeroDeSerie = :numeroDeSerie,
          Plataforma = :plataforma, Observaciones = :observaciones,
          SujetoPasivo = :sujetoPasivo, Portes = :portes,
          Fpago1 = :fpago1, Fpago2 = :fpago2, ImpFpago1 = :impFpago1, ImpFpago2 = :impFpago2,
          Importe = :importe, ImporteDtos = :importeDtos, PjeDto = :pjeDto,
          ImporteBase1 = :importeBase1, ImporteBase2 = :importeBase2, ImporteBase3 = :importeBase3, ImporteBase4 = :importeBase4,
          PjeIva1 = :pjeIva1, PjeIva2 = :pjeIva2, PjeIva3 = :pjeIva3, PjeIva4 = :pjeIva4,
          ImporteIva1 = :importeIva1, ImporteIva2 = :importeIva2, ImporteIva3 = :importeIva3, ImporteIva4 = :importeIva4,
          LineasConDescuentos = :lineasConDescuentos
        WHERE Empresa = :empresa AND Tipo = :tipo AND Albaran = :albaran';
      $stmt = $this->pdo->prepare($sql);
      $stmt->execute([
        'empresa' => $empresa,
        'tipo' => $tipo,
        'albaran' => $albaran,
        'puesto' => $this->nullIfEmpty($body['puesto'] ?? $actual['puesto'] ?? null),
        'cliente' => $this->nullIfEmpty($body['cliente'] ?? $actual['cliente'] ?? null),
        'razonSocial' => $this->nullIfEmpty($body['razonSocial'] ?? $actual['razonSocial'] ?? null),
        'razonSocial2' => $this->blankIfEmpty($body['razonSocial2'] ?? $actual['razonSocial2'] ?? null),
        'nif' => $this->blankIfEmpty($body['nif'] ?? $actual['nif'] ?? null),
        'fecha' => $this->normalizeFecha($body['fecha'] ?? $actual['fecha'] ?? null),
        'vendedor' => $this->nullIfEmpty($body['vendedor'] ?? $actual['vendedor'] ?? null),
        'representante' => $this->codigoCharOEspacio($body['representante'] ?? $actual['representante'] ?? null),
        'transporte' => $this->spaceIfEmpty($body['transporte'] ?? $actual['transporte'] ?? null),
        'direccionEnvio' => $this->spaceIfEmpty($body['direccionEnvio'] ?? $actual['direccionEnvio'] ?? null),
        'poblacionEnvio' => $this->spaceIfEmpty($body['poblacionEnvio'] ?? $actual['poblacionEnvio'] ?? null),
        'codigoPostalEnvio' => $this->spaceIfEmpty($body['codigoPostalEnvio'] ?? $actual['codigoPostalEnvio'] ?? null),
        'provinciaEnvio' => $this->spaceIfEmpty($body['provinciaEnvio'] ?? $actual['provinciaEnvio'] ?? null),
        'paisEnvio' => $this->spaceIfEmpty($body['paisEnvio'] ?? $actual['paisEnvio'] ?? null),
        'telefono' => $this->blankIfEmpty($body['telefono'] ?? $actual['telefono'] ?? null),
        'telefono2' => $this->blankIfEmpty($body['telefono2'] ?? $actual['telefono2'] ?? null),
        'fax' => $this->blankIfEmpty($body['fax'] ?? $actual['fax'] ?? null),
        'email' => $this->blankIfEmpty($body['email'] ?? $actual['email'] ?? null),
        'almacen' => isset($body['almacen']) ? (($body['almacen'] === '' || $body['almacen'] === null) ? null : (int) $body['almacen'])
          : ($actual['almacen'] ?? null),
        'pedido' => isset($body['pedido']) ? (($body['pedido'] === '' || $body['pedido'] === null) ? 0 : (int) $body['pedido'])
          : (int) ($actual['pedido'] ?? 0),
        'referencia1' => $this->blankIfEmpty($body['referencia1'] ?? $actual['referencia1'] ?? null),
        'referencia2' => $this->blankIfEmpty($body['referencia2'] ?? $actual['referencia2'] ?? null),
        'numeroDeSerie' => $this->blankIfEmpty($body['numeroDeSerie'] ?? $actual['numeroDeSerie'] ?? null),
        'plataforma' => $this->blankIfEmpty($body['plataforma'] ?? $actual['plataforma'] ?? null),
        'observaciones' => $this->blankIfEmpty($body['observaciones'] ?? $actual['observaciones'] ?? null),
        'sujetoPasivo' => !empty($body['sujetoPasivo'] ?? $actual['sujetoPasivo'] ?? false) ? 1 : 0,
        'portes' => $this->spaceIfEmpty($body['portes'] ?? $actual['portes'] ?? null),
        'fpago1' => $this->spaceIfEmpty($body['fpago1'] ?? $this->fpagoCodigo($actual, 0)),
        'fpago2' => (string) ($body['fpago2'] ?? $this->fpagoCodigo($actual, 1)),
        'impFpago1' => (float) ($body['impFpago1'] ?? $this->fpagoImporte($actual, 0)),
        'impFpago2' => (float) ($body['impFpago2'] ?? $this->fpagoImporte($actual, 1)),
        'importe' => $totales['importe'],
        'importeDtos' => $totales['descuento'],
        'pjeDto' => $totales['pjeDto'],
        'importeBase1' => $totales['bases'][0],
        'importeBase2' => $totales['bases'][1],
        'importeBase3' => $totales['bases'][2],
        'importeBase4' => $totales['bases'][3],
        'pjeIva1' => $totales['pjes'][0],
        'pjeIva2' => $totales['pjes'][1],
        'pjeIva3' => $totales['pjes'][2],
        'pjeIva4' => $totales['pjes'][3],
        'importeIva1' => $totales['ivas'][0],
        'importeIva2' => $totales['ivas'][1],
        'importeIva3' => $totales['ivas'][2],
        'importeIva4' => $totales['ivas'][3],
        'lineasConDescuentos' => !empty($totales['lineasConDescuentos']) ? 1 : 0,
      ]);

      $this->reemplazarLineas($empresa, $tipo, $albaran, $lineas);
      $this->pdo->commit();
    } catch (\Throwable $e) {
      $this->pdo->rollBack();
      throw $e;
    }

    $detalle = $this->consulta->obtenerFicha($empresa, $tipo, $albaran);
    if ($detalle === null) {
      throw new \RuntimeException('No se pudo releer la venta');
    }
    return $detalle;
  }

  /**
   * Al finalizar (legacy): el Tipo del albaran permanece "A".
   * Se tipifica con FacturaTipo (T ticket, A albaran cerrado, P presupuesto, F factura)
   * y contadores UltTicket / UltFactura.
   *
   * @param array<string, mixed> $body
   */
  public function finalizar(string $empresa, string $tipoActual, int $albaran, array $body): array
  {
    $actual = $this->consulta->obtenerFicha($empresa, $tipoActual, $albaran);
    if ($actual === null) {
      throw new \RuntimeException('Venta no encontrada', 404);
    }

    $opcion = strtoupper(substr(trim((string) ($body['tipo'] ?? '')), 0, 1));
    if (!in_array($opcion, ['T', 'A', 'P', 'F'], true)) {
      throw new \InvalidArgumentException('Tipo invalido. Use T, A, P o F');
    }

    $esTicketAFactura = $this->esTicketCerrado($actual) && $opcion === 'F';
    if ($this->estaBloqueado($actual) && !$esTicketAFactura) {
      throw new \RuntimeException('Documento facturado: no se puede modificar', 409);
    }
    if ($esTicketAFactura) {
      $cliente = trim((string) ($actual['cliente'] ?? ''));
      $nif = trim((string) ($actual['nif'] ?? ''));
      $razon = trim((string) ($actual['razonSocial'] ?? ''));
      if ($cliente === '' || $nif === '' || $razon === '') {
        throw new \InvalidArgumentException(
          'Para pasar ticket a factura hacen falta Cliente, NIF y Razon social (legacy TransformacionTicketaFactura).'
        );
      }
    }

    // Legacy FrmVenta: si FormaPago tiene CobroDeArqueo / FacturacionDirecta, no permite Albaran.
    if ($opcion === 'A' && $this->esFormaPagoContado($actual)) {
      throw new \InvalidArgumentException(
        'Cliente de contado: no se puede finalizar como albaran. Use Ticket o Factura.'
      );
    }

    $puesto = trim((string) ($actual['puesto'] ?? ''));
    $ctxSesion = $this->arqueo->asegurarSesionPuesto($puesto, $empresa);
    $sesion = $ctxSesion['sesion'];
    $ahora = date('Y-m-d H:i:s');
    $importe = (float) ($actual['importe'] ?? 0);
    // Contado/ticket: forma de pago elegida (legacy Frame1/DbList2: CobroDeArqueo).
    $fpagoBody = trim((string) ($body['fpago1'] ?? ''));
    $fpago1 = $fpagoBody !== '' ? $fpagoBody : trim((string) ($this->fpagoCodigo($actual, 0)));
    if ($fpago1 === '') {
      $fpago1 = 'EU';
    }

    $this->pdo->beginTransaction();
    try {
      if ($opcion === 'T') {
        $factura = $this->nextContadorEmpresa($empresa, 'UltTicket');
        $this->pdo->prepare(
          'UPDATE AlbaranesVentasCab SET
              FacturaTipo = \'T\', Factura = :factura, Estado = NULL,
              FechaCobro = CONVERT(datetime, :fechaCobro, 120),
              Fpago1 = :fpago1, ImpFpago1 = :impFpago1,
              Sesion = :sesion, RebajeStock = 1,
              EmpresaFacturacion = :empresaFacturacion
           WHERE Empresa = :e AND Tipo = :t AND Albaran = :a'
        )->execute([
          'factura' => $factura,
          'fechaCobro' => $ahora,
          'fpago1' => $fpago1,
          'impFpago1' => $importe,
          'sesion' => $sesion,
          'empresaFacturacion' => $empresa,
          'e' => $empresa,
          't' => $tipoActual,
          'a' => $albaran,
        ]);
      } elseif ($opcion === 'F') {
        // Legacy FacturaCliente / TransformacionTicketaFactura → CreaFactura.
        $albaranTicketOrigen = $esTicketAFactura ? $albaran : 0;
        $creada = $this->crearRegistroFactura(
          $empresa,
          $tipoActual,
          $albaran,
          $actual,
          $fpago1,
          $ahora,
          $albaranTicketOrigen
        );
        $factura = $creada['factura'];
        $facturaTipoDoc = $creada['facturaTipo'];
        // Trasformación ticket→factura: Estado F en albarán (legacy TransformacionTicketaFactura).
        $estadoAlb = $esTicketAFactura ? 'F' : null;
        if ($creada['estado'] === 'G') {
          $this->pdo->prepare(
            'UPDATE AlbaranesVentasCab SET
                FacturaTipo = :facturaTipo, Factura = :factura, Estado = :estado,
                FechaCobro = NULL, Fpago1 = NULL, ImpFpago1 = 0,
                Sesion = :sesion, RebajeStock = 1,
                EmpresaFacturacion = :empresaFacturacion
             WHERE Empresa = :e AND Tipo = :t AND Albaran = :a'
          )->execute([
            'facturaTipo' => $facturaTipoDoc,
            'factura' => $factura,
            'estado' => $estadoAlb,
            'sesion' => $sesion,
            'empresaFacturacion' => $empresa,
            'e' => $empresa,
            't' => $tipoActual,
            'a' => $albaran,
          ]);
        } else {
          $this->pdo->prepare(
            'UPDATE AlbaranesVentasCab SET
                FacturaTipo = :facturaTipo, Factura = :factura, Estado = :estado,
                FechaCobro = CONVERT(datetime, :fechaCobro, 120),
                Fpago1 = :fpago1, ImpFpago1 = :impFpago1,
                Sesion = :sesion, RebajeStock = 1,
                EmpresaFacturacion = :empresaFacturacion
             WHERE Empresa = :e AND Tipo = :t AND Albaran = :a'
          )->execute([
            'facturaTipo' => $facturaTipoDoc,
            'factura' => $factura,
            'estado' => $estadoAlb,
            'fechaCobro' => $ahora,
            'fpago1' => $fpago1,
            'impFpago1' => $importe,
            'sesion' => $sesion,
            'empresaFacturacion' => $empresa,
            'e' => $empresa,
            't' => $tipoActual,
            'a' => $albaran,
          ]);
        }
      } elseif ($opcion === 'P') {
        $this->pdo->prepare(
          'UPDATE AlbaranesVentasCab SET
              FacturaTipo = \'R\', Factura = 0, Estado = \'B\',
              Sesion = :sesion, RebajeStock = 0
           WHERE Empresa = :e AND Tipo = :t AND Albaran = :a'
        )->execute([
          'sesion' => $sesion,
          'e' => $empresa,
          't' => $tipoActual,
          'a' => $albaran,
        ]);
      } else {
        // Albaran cerrado (legacy): FacturaTipo NULL, Estado NULL, Sesion.
        $this->pdo->prepare(
          'UPDATE AlbaranesVentasCab SET
              FacturaTipo = NULL, Factura = 0, Estado = NULL,
              Sesion = :sesion, RebajeStock = 1
           WHERE Empresa = :e AND Tipo = :t AND Albaran = :a'
        )->execute([
          'sesion' => $sesion,
          'e' => $empresa,
          't' => $tipoActual,
          'a' => $albaran,
        ]);
      }

      // Legacy Add_Arq + contadores Sesiones (Fase 0 arqueo operativo).
      // Ticket→factura: el ticket ya acumuló; no volver a sumar.
      if ($opcion === 'T' || ($opcion === 'F' && !$esTicketAFactura)) {
        $this->acumularArqueoTrasFinalizar(
          $empresa,
          $puesto,
          $sesion,
          $opcion,
          $fpago1,
          $importe,
          $actual
        );
      }

      $this->pdo->commit();
    } catch (\Throwable $e) {
      $this->pdo->rollBack();
      throw $e;
    }

    $detalle = $this->consulta->obtenerFicha($empresa, $tipoActual, $albaran);
    if ($detalle === null) {
      throw new \RuntimeException('No se pudo releer tras finalizar');
    }
    return $detalle;
  }

  public function eliminar(string $empresa, string $tipo, int $albaran): void
  {
    $actual = $this->consulta->obtenerFicha($empresa, $tipo, $albaran);
    if ($actual === null) {
      throw new \RuntimeException('Venta no encontrada', 404);
    }
    if ($this->estaBloqueado($actual)) {
      throw new \RuntimeException('Documento facturado: no se puede borrar', 409);
    }

    $this->pdo->beginTransaction();
    try {
      $this->pdo->prepare(
        'DELETE FROM AlbaranesVentasLin WHERE Empresa = :e AND Tipo = :t AND Albaran = :a'
      )->execute(['e' => $empresa, 't' => $tipo, 'a' => $albaran]);
      $this->pdo->prepare(
        'DELETE FROM AlbaranesVentasCab WHERE Empresa = :e AND Tipo = :t AND Albaran = :a'
      )->execute(['e' => $empresa, 't' => $tipo, 'a' => $albaran]);
      $this->pdo->commit();
    } catch (\Throwable $e) {
      $this->pdo->rollBack();
      throw $e;
    }
  }

  /**
   * Reserva el siguiente albaran de venta desde Empresas.UltAlbaranVen (contador tienda)
   * e incrementa el contador en el mismo momento (como legacy al pulsar Intro).
   *
   * @return array{
   *   empresa: string,
   *   tipo: string,
   *   albaran: int,
   *   puesto: ?string,
   *   vendedor: ?string,
   *   almacen: ?int
   * }
   */
  public function reservarAlbaran(string $empresa, ?string $puesto = null): array
  {
    $empresa = trim($empresa);
    if ($empresa === '') {
      throw new \InvalidArgumentException('Empresa (tienda) obligatoria');
    }

    $this->pdo->beginTransaction();
    try {
      $stmt = $this->pdo->prepare(
        'SELECT UltAlbaranVen, Almacen FROM Empresas WITH (UPDLOCK, ROWLOCK) WHERE Codigo = :e'
      );
      $stmt->execute(['e' => $empresa]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      if ($row === false) {
        throw new \RuntimeException('Tienda no encontrada', 404);
      }

      $albaran = (int) ($row['UltAlbaranVen'] ?? 0) + 1;
      $this->pdo->prepare(
        'UPDATE Empresas SET UltAlbaranVen = :n WHERE Codigo = :e'
      )->execute(['n' => $albaran, 'e' => $empresa]);

      $vendedor = null;
      $puestoLimpio = $puesto !== null ? trim($puesto) : '';
      if ($puestoLimpio !== '') {
        try {
          $ps = $this->pdo->prepare(
            'SELECT Trabajador FROM Puestos WHERE Puesto = :p'
          );
          $ps->execute(['p' => $puestoLimpio]);
          $trab = $ps->fetchColumn();
          if ($trab !== false && trim((string) $trab) !== '') {
            $vendedor = trim((string) $trab);
          }
        } catch (\Throwable $e) {
          // Columna Trabajador puede no existir si no se aplico la migracion
          $vendedor = null;
        }
      }

      $almacenRaw = $row['Almacen'] ?? null;
      $almacen = ($almacenRaw === null || $almacenRaw === '') ? null : (int) $almacenRaw;

      $this->pdo->commit();
    } catch (\Throwable $e) {
      $this->pdo->rollBack();
      throw $e;
    }

    return [
      'empresa' => $empresa,
      'tipo' => 'A',
      'albaran' => $albaran,
      'puesto' => $puestoLimpio !== '' ? $puestoLimpio : null,
      'vendedor' => $vendedor,
      'almacen' => $almacen,
    ];
  }

  private function nextAlbaran(string $empresa, string $tipo): int
  {
    // Preferir contador de tienda (legacy). Si falla, fallback a MAX+1.
    try {
      $reserva = $this->reservarAlbaran($empresa, null);
      return (int) $reserva['albaran'];
    } catch (\Throwable $e) {
      $stmt = $this->pdo->prepare(
        'SELECT ISNULL(MAX(Albaran), 0) + 1 FROM AlbaranesVentasCab WHERE Empresa = :e AND Tipo = :t'
      );
      $stmt->execute(['e' => $empresa, 't' => $tipo]);
      return (int) $stmt->fetchColumn();
    }
  }

  /** @param list<array<string, mixed>> $lineas */
  private function reemplazarLineas(string $empresa, string $tipo, int $albaran, array $lineas): void
  {
    $this->pdo->prepare(
      'DELETE FROM AlbaranesVentasLin WHERE Empresa = :e AND Tipo = :t AND Albaran = :a'
    )->execute(['e' => $empresa, 't' => $tipo, 'a' => $albaran]);

    $this->insertarLineas($empresa, $tipo, $albaran, $lineas);
  }

  /** @param list<array<string, mixed>> $lineas */
  private function insertarLineas(string $empresa, string $tipo, int $albaran, array $lineas): void
  {
    $ins = $this->pdo->prepare(
      'INSERT INTO AlbaranesVentasLin (
         Empresa, Tipo, Albaran, Articulo, Descripcion, Cantidad, Precio, PjeDto, Importe, PjeIva, PjeRec,
         RebajeStock, PrecioMedio, Plato, Cocina, PrecioMenu, PrecioAlterado, PrecioHabitacion,
         PrecioTarifa, Ean, UnidadesPaquete, LoteVenta
       ) VALUES (
         :e, :t, :a, :articulo, :descripcion, :cantidad, :precio, :pjeDto, :importe, :pjeIva, 0,
         0, :precioMedio, 0, 0, 0, :precioAlterado, 0,
         :precioTarifa, :ean, :unidadesPaquete, :loteVenta
       )'
    );

    foreach ($lineas as $lin) {
      $articulo = trim((string) ($lin['articulo'] ?? ''));
      if ($articulo === '') {
        continue;
      }
      $cant = (float) ($lin['cantidad'] ?? 0);
      $precio = (float) ($lin['precio'] ?? 0);
      $pjeDto = (float) ($lin['pjeDto'] ?? 0);
      $importe = isset($lin['importe']) ? (float) $lin['importe'] : round($cant * $precio * (1 - $pjeDto / 100), 2);
      $meta = $this->metaArticuloLinea($articulo);
      $precioTarifa = isset($lin['precioTarifa']) && $lin['precioTarifa'] !== '' && $lin['precioTarifa'] !== null
        ? (float) $lin['precioTarifa']
        : ($meta['precioTarifa'] > 0 ? $meta['precioTarifa'] : $precio);
      $precioAlterado = array_key_exists('precioAlterado', $lin)
        ? (!empty($lin['precioAlterado']) ? 1 : 0)
        : (abs($precio - $precioTarifa) > 0.0001 ? 1 : 0);
      $ins->execute([
        'e' => $empresa,
        't' => $tipo,
        'a' => $albaran,
        'articulo' => $articulo,
        'descripcion' => $this->blankIfEmpty($lin['descripcion'] ?? null),
        'cantidad' => $cant,
        'precio' => $precio,
        'pjeDto' => $pjeDto,
        'importe' => $importe,
        'pjeIva' => (float) (($lin['pjeIva'] ?? 0) > 0 ? $lin['pjeIva'] : 21),
        'precioMedio' => $meta['precioMedio'],
        'precioAlterado' => $precioAlterado,
        'precioTarifa' => $precioTarifa,
        'ean' => $this->blankIfEmpty($lin['ean'] ?? $articulo),
        'unidadesPaquete' => isset($lin['unidadesPaquete']) && $lin['unidadesPaquete'] !== '' && $lin['unidadesPaquete'] !== null
          ? (float) $lin['unidadesPaquete'] : 1.0,
        'loteVenta' => $this->spaceIfEmpty($lin['loteVenta'] ?? null),
      ]);
    }
  }

  /** @return array{precioMedio: float, precioTarifa: float} */
  private function metaArticuloLinea(string $articulo): array
  {
    $out = ['precioMedio' => 0.0, 'precioTarifa' => 0.0];
    try {
      $st = $this->pdo->prepare(
        'SELECT PrecioMedio, PrecioVen1 FROM Articulos WHERE Codigo = :c'
      );
      $st->execute(['c' => $articulo]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if ($row !== false) {
        $out['precioMedio'] = (float) ($row['PrecioMedio'] ?? 0);
        $out['precioTarifa'] = (float) ($row['PrecioVen1'] ?? 0);
      }
    } catch (\Throwable $e) {
      // ignore
    }
    return $out;
  }

  /** @return array{bruto: float, descuento: float, iva: float, importe: float, pjeIva: float, pjeDto: float, bases: list<float>, pjes: list<float>, ivas: list<float>, lineasConDescuentos: bool} */
  private function totalesVacios(): array
  {
    return [
      'bruto' => 0.0,
      'descuento' => 0.0,
      'iva' => 0.0,
      'importe' => 0.0,
      'pjeIva' => 0.0,
      'pjeDto' => 0.0,
      'bases' => [0.0, 0.0, 0.0, 0.0],
      'pjes' => [0.0, 0.0, 0.0, 0.0],
      'ivas' => [0.0, 0.0, 0.0, 0.0],
      'lineasConDescuentos' => false,
    ];
  }

  /**
   * Agrupa bases/IVA por PjeIva de linea (como CalculoTotal de FrmVenta).
   * Si Empresas.SW_IVA (yIVA): el Precio de linea es PVP con IVA incluido
   * → base = totalConIva / (1+pje/100), iva = totalConIva - base (CalculoEspecialIvaIncluido).
   * Cabecera PjeDto/ImporteDtos = Dto1 del cliente (no el dto de linea).
   *
   * @param list<array<string, mixed>> $lineas
   * @param array<string, mixed> $body
   * @return array{bruto: float, descuento: float, iva: float, importe: float, pjeIva: float, pjeDto: float, bases: list<float>, pjes: list<float>, ivas: list<float>, lineasConDescuentos: bool}
   */
  private function calcularTotales(array $lineas, array $body): array
  {
    $empresa = trim((string) ($body['empresa'] ?? ''));
    $ivaIncluido = $empresa !== '' && $this->empresaPreciosIvaIncluido($empresa);
    $dto1 = $this->clienteDto1(trim((string) ($body['cliente'] ?? '')));

    $bruto = 0.0;
    $lineasConDescuentos = false;
    /** @var array<string, float> $acumPorIva */
    $acumPorIva = [];

    foreach ($lineas as $lin) {
      if (trim((string) ($lin['articulo'] ?? '')) === '') {
        continue;
      }
      $cant = (float) ($lin['cantidad'] ?? 0);
      $precio = (float) ($lin['precio'] ?? 0);
      $pjeDto = (float) ($lin['pjeDto'] ?? 0);
      if ($pjeDto != 0.0) {
        $lineasConDescuentos = true;
      }
      $pjeIvaLin = (float) ($lin['pjeIva'] ?? 0);
      if ($pjeIvaLin <= 0) {
        $fallback = isset($body['pjeIva1']) ? (float) $body['pjeIva1'] : 0.0;
        $pjeIvaLin = $fallback > 0 ? $fallback : 21.0;
      }
      $lineaBruto = $cant * $precio;
      $lineaDto = $lineaBruto * ($pjeDto / 100);
      $lineaNeto = round($lineaBruto - $lineaDto, 2);
      $bruto += $lineaBruto;
      $key = rtrim(rtrim(number_format($pjeIvaLin, 4, '.', ''), '0'), '.');
      if ($key === '') {
        $key = '0';
      }
      if (!isset($acumPorIva[$key])) {
        $acumPorIva[$key] = 0.0;
      }
      $acumPorIva[$key] += $lineaNeto;
    }

    $rates = array_keys($acumPorIva);
    usort($rates, static fn ($a, $b) => (float) $a <=> (float) $b);

    $bases = [0.0, 0.0, 0.0, 0.0];
    $pjes = [0.0, 0.0, 0.0, 0.0];
    $ivas = [0.0, 0.0, 0.0, 0.0];
    $ivaTotal = 0.0;
    $importe = 0.0;
    $i = 0;
    foreach ($rates as $rateKey) {
      if ($i >= 4) {
        break;
      }
      $rate = (float) $rateKey;
      $acum = round($acumPorIva[$rateKey], 2);
      if ($ivaIncluido) {
        $base = $rate > 0 ? round($acum / (1 + ($rate / 100)), 2) : $acum;
        $iva = round($acum - $base, 2);
        $importe += $acum;
      } else {
        $base = $acum;
        $iva = round($base * ($rate / 100), 2);
        $importe += $base + $iva;
      }
      $bases[$i] = $base;
      $pjes[$i] = $rate;
      $ivas[$i] = $iva;
      $ivaTotal += $iva;
      $i++;
    }

    // Legacy: Dto1 de cliente sobre bases → ImporteDtos/PjeDto de cabecera (no el dto de linea).
    $importeDtosCab = 0.0;
    if ($dto1 != 0.0) {
      for ($j = 0; $j < 4; $j++) {
        $dtoBase = round($bases[$j] * ($dto1 / 100), 2);
        $importeDtosCab += $dtoBase;
        $bases[$j] = round($bases[$j] - $dtoBase, 2);
        if ($ivaIncluido) {
          $ivas[$j] = round($bases[$j] * ($pjes[$j] / 100), 2);
        } else {
          $ivas[$j] = round($bases[$j] * ($pjes[$j] / 100), 2);
        }
      }
      $ivaTotal = array_sum($ivas);
      $importe = round(array_sum($bases) + $ivaTotal, 2);
    }

    return [
      'bruto' => round($bruto, 2),
      'descuento' => round($importeDtosCab, 2),
      'iva' => round($ivaTotal, 2),
      'importe' => round($importe, 2),
      'pjeIva' => $pjes[0],
      'pjeDto' => $dto1,
      'bases' => $bases,
      'pjes' => $pjes,
      'ivas' => $ivas,
      'lineasConDescuentos' => $lineasConDescuentos,
    ];
  }

  private function clienteDto1(string $cliente): float
  {
    if ($cliente === '') {
      return 0.0;
    }
    try {
      $st = $this->pdo->prepare('SELECT Dto1 FROM Clientes WHERE Codigo = :c');
      $st->execute(['c' => $cliente]);
      $v = $st->fetchColumn();
      return $v !== false && $v !== null ? (float) $v : 0.0;
    } catch (\Throwable $e) {
      return 0.0;
    }
  }

  /** Empresas.SW_IVA = yIVA legacy (precios de venta con IVA incluido). */
  private function empresaPreciosIvaIncluido(string $empresa): bool
  {
    try {
      $stmt = $this->pdo->prepare('SELECT SW_IVA FROM Empresas WHERE Codigo = :e');
      $stmt->execute(['e' => $empresa]);
      $v = $stmt->fetchColumn();
      return $v !== false && (int) $v !== 0;
    } catch (\Throwable $e) {
      return false;
    }
  }

  private function nextContadorEmpresa(string $empresa, string $campo): int
  {
    $allowed = ['UltTicket', 'UltFactura', 'UltAlbaranVen'];
    if (!in_array($campo, $allowed, true)) {
      throw new \InvalidArgumentException('Contador empresa no permitido');
    }
    $stmt = $this->pdo->prepare(
      "SELECT [{$campo}] FROM Empresas WITH (UPDLOCK, ROWLOCK) WHERE Codigo = :e"
    );
    $stmt->execute(['e' => $empresa]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      throw new \RuntimeException('Tienda no encontrada', 404);
    }
    $n = (int) ($row[$campo] ?? 0) + 1;
    $this->pdo->prepare("UPDATE Empresas SET [{$campo}] = :n WHERE Codigo = :e")
      ->execute(['n' => $n, 'e' => $empresa]);
    return $n;
  }

  /**
   * Legacy CreaFactura (Ccc.bas): inserta en Facturas al tipificar FacturaTipo=F.
   *
   * @param array<string, mixed> $actual ficha venta
   * @return array{factura: int, facturaTipo: string, estado: string}
   */
  private function crearRegistroFactura(
    string $empresa,
    string $tipo,
    int $albaran,
    array $actual,
    string $fpago,
    string $fecha,
    int $albaranTicketTransformado = 0
  ): array {
    $cabStmt = $this->pdo->prepare(
      'SELECT Cliente, SujetoPasivo, Importe, ImporteDtos, PjeDto, PagoaCuenta,
              ImporteBase1, ImporteBase2, ImporteBase3, ImporteBase4, ImporteBase5, ImporteBase6,
              PjeIva1, PjeIva2, PjeIva3, PjeIva4, PjeIva5, PjeIva6,
              ImporteIva1, ImporteIva2, ImporteIva3, ImporteIva4, ImporteIva5, ImporteIva6,
              PjeRec1, PjeRec2, PjeRec3, PjeRec4, PjeRec5, PjeRec6,
              ImporteRec1, ImporteRec2, ImporteRec3, ImporteRec4, ImporteRec5, ImporteRec6
       FROM AlbaranesVentasCab
       WHERE Empresa = :e AND Tipo = :t AND Albaran = :a'
    );
    $cabStmt->execute(['e' => $empresa, 't' => $tipo, 'a' => $albaran]);
    $cab = $cabStmt->fetch(PDO::FETCH_ASSOC);
    if ($cab === false) {
      throw new \RuntimeException('Venta no encontrada al crear factura', 404);
    }

    $cliente = trim((string) ($cab['Cliente'] ?? $actual['cliente'] ?? ''));
    if ($cliente === '') {
      throw new \InvalidArgumentException('Cliente obligatorio para facturar');
    }

    $fpagoCodigo = trim($fpago);
    if ($fpagoCodigo === '' || $fpagoCodigo === ' ') {
      try {
        $st = $this->pdo->prepare('SELECT FormaPago FROM Clientes WHERE Codigo = :c');
        $st->execute(['c' => $cliente]);
        $fpagoCodigo = trim((string) ($st->fetchColumn() ?: ''));
      } catch (\Throwable $e) {
        $fpagoCodigo = '';
      }
    }

    $metaFpago = $this->metaFormaPagoFactura($fpagoCodigo);
    // Legacy: Agrupacion=3 + CobroDeArqueo → Estado G + FacturaContadoDiferida; resto Estado F.
    $contadoDiferida = $metaFpago['agrupacion'] === 3 && $metaFpago['cobroDeArqueo'];
    $estado = $contadoDiferida ? 'G' : 'F';

    $importe = (float) ($cab['Importe'] ?? 0);
    $facturaTipo = ($importe < 0 && $this->empresaFacturasRectificativas($empresa)) ? 'A' : 'F';
    $factura = $this->nextNumeroFactura($empresa, $facturaTipo === 'A' ? 'UltAbono' : 'UltFactura', $contadoDiferida);

    $existe = $this->pdo->prepare(
      'SELECT 1 FROM Facturas WHERE Empresa = :e AND FacturaTipo = :ft AND Factura = :f'
    );
    $existe->execute(['e' => $empresa, 'ft' => $facturaTipo, 'f' => $factura]);
    if ($existe->fetchColumn() !== false) {
      throw new \RuntimeException("Factura {$facturaTipo}/{$factura} ya existe", 409);
    }

    $pagoACuenta = (float) ($cab['PagoaCuenta'] ?? 0);
    $sujetoPasivo = !empty($cab['SujetoPasivo']) || !empty($actual['sujetoPasivo']) ? 1 : 0;

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
        TrasformacionTicketFactura, AlbaranTicketTransformado
      ) VALUES (
        :empresa, :facturaTipo, :factura, :cliente, CONVERT(datetime, :fecha, 120),
        :b1, :b2, :b3, :b4, :b5, :b6,
        :pi1, :pi2, :pi3, :pi4, :pi5, :pi6,
        :ii1, :ii2, :ii3, :ii4, :ii5, :ii6,
        :pr1, :pr2, :pr3, :pr4, :pr5, :pr6,
        :ir1, :ir2, :ir3, :ir4, :ir5, :ir6,
        :pjeDto, :importeDtos, :importe, :fpago, :estado,
        0, 0, 0, :importeLiquidado, :contadoDiferida,
        0, 0, 0, :pagoACuenta, 0, :sujetoPasivo,
        :trasformacionTicket, :albaranTicket
      )';

    $f = static fn (string $k) => (float) ($cab[$k] ?? 0);
    $this->pdo->prepare($sql)->execute([
      'empresa' => $empresa,
      'facturaTipo' => $facturaTipo,
      'factura' => $factura,
      'cliente' => $cliente,
      'fecha' => $fecha,
      'b1' => $f('ImporteBase1'),
      'b2' => $f('ImporteBase2'),
      'b3' => $f('ImporteBase3'),
      'b4' => $f('ImporteBase4'),
      'b5' => $f('ImporteBase5'),
      'b6' => $f('ImporteBase6'),
      'pi1' => $f('PjeIva1'),
      'pi2' => $f('PjeIva2'),
      'pi3' => $f('PjeIva3'),
      'pi4' => $f('PjeIva4'),
      'pi5' => $f('PjeIva5'),
      'pi6' => $f('PjeIva6'),
      'ii1' => $f('ImporteIva1'),
      'ii2' => $f('ImporteIva2'),
      'ii3' => $f('ImporteIva3'),
      'ii4' => $f('ImporteIva4'),
      'ii5' => $f('ImporteIva5'),
      'ii6' => $f('ImporteIva6'),
      'pr1' => $f('PjeRec1'),
      'pr2' => $f('PjeRec2'),
      'pr3' => $f('PjeRec3'),
      'pr4' => $f('PjeRec4'),
      'pr5' => $f('PjeRec5'),
      'pr6' => $f('PjeRec6'),
      'ir1' => $f('ImporteRec1'),
      'ir2' => $f('ImporteRec2'),
      'ir3' => $f('ImporteRec3'),
      'ir4' => $f('ImporteRec4'),
      'ir5' => $f('ImporteRec5'),
      'ir6' => $f('ImporteRec6'),
      'pjeDto' => $f('PjeDto'),
      'importeDtos' => $f('ImporteDtos'),
      'importe' => $importe,
      'fpago' => $fpagoCodigo !== '' ? $fpagoCodigo : null,
      'estado' => $estado,
      'importeLiquidado' => $contadoDiferida ? $pagoACuenta : 0.0,
      'contadoDiferida' => $contadoDiferida ? 1 : 0,
      'pagoACuenta' => $pagoACuenta,
      'sujetoPasivo' => $sujetoPasivo,
      'trasformacionTicket' => $albaranTicketTransformado > 0 ? 1 : 0,
      'albaranTicket' => $albaranTicketTransformado > 0 ? $albaranTicketTransformado : 0,
    ]);

    return [
      'factura' => $factura,
      'facturaTipo' => $facturaTipo,
      'estado' => $estado,
    ];
  }

  /**
   * UltFactura / UltAbono (+ UltFacturaDiferida si contado diferida, como AumentaContadorEmpresas Facturacion=D).
   * ContadorFacturasAñoMes=S → AAMMxxxxx (legacy DesParametros.ini).
   */
  private function nextNumeroFactura(string $empresa, string $campo, bool $facturacionDiferida): int
  {
    $allowed = ['UltFactura', 'UltAbono'];
    if (!in_array($campo, $allowed, true)) {
      throw new \InvalidArgumentException('Contador factura no permitido');
    }

    $cols = $campo;
    if ($facturacionDiferida) {
      $cols .= $campo === 'UltFactura' ? ', UltFacturaDiferida' : ', UltAbonoDiferido';
    }
    $stmt = $this->pdo->prepare(
      "SELECT {$cols} FROM Empresas WITH (UPDLOCK, ROWLOCK) WHERE Codigo = :e"
    );
    $stmt->execute(['e' => $empresa]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      throw new \RuntimeException('Tienda no encontrada', 404);
    }

    $campoUsar = $campo;
    if ($facturacionDiferida) {
      $alt = $campo === 'UltFactura' ? 'UltFacturaDiferida' : 'UltAbonoDiferido';
      if (array_key_exists($alt, $row)
        && (int) ($row[$alt] ?? 0) !== 0
        && (int) ($row[$alt] ?? 0) > (int) ($row[$campo] ?? 0)
      ) {
        $campoUsar = $alt;
      }
    }

    $n = (int) ($row[$campoUsar] ?? 0) + 1;
    $this->pdo->prepare("UPDATE Empresas SET [{$campoUsar}] = :n WHERE Codigo = :e")
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

  private function empresaFacturasRectificativas(string $empresa): bool
  {
    try {
      $st = $this->pdo->prepare('SELECT FacturasRectificativas FROM Empresas WHERE Codigo = :e');
      $st->execute(['e' => $empresa]);
      $v = $st->fetchColumn();
      return $v !== false && (int) $v !== 0;
    } catch (\Throwable $e) {
      return false;
    }
  }

  /** @return array{cobroDeArqueo: bool, agrupacion: int} */
  private function metaFormaPagoFactura(string $codigo): array
  {
    $out = ['cobroDeArqueo' => false, 'agrupacion' => 0];
    if ($codigo === '') {
      return $out;
    }
    try {
      $st = $this->pdo->prepare(
        'SELECT CobroDeArqueo, Agrupacion FROM FormasPago WHERE Codigo = :c'
      );
      $st->execute(['c' => $codigo]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if ($row === false) {
        return $out;
      }
      $out['cobroDeArqueo'] = !empty($row['CobroDeArqueo']);
      $out['agrupacion'] = (int) ($row['Agrupacion'] ?? 0);
    } catch (\Throwable $e) {
      // Agrupacion puede no existir en algunos esquemas.
      try {
        $st = $this->pdo->prepare('SELECT CobroDeArqueo FROM FormasPago WHERE Codigo = :c');
        $st->execute(['c' => $codigo]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
          $out['cobroDeArqueo'] = !empty($row['CobroDeArqueo']);
        }
      } catch (\Throwable $e2) {
        // ignore
      }
    }
    return $out;
  }

  /**
   * Legacy Add_Arq + contadores Sesiones al tipificar Ticket/Factura contado.
   *
   * @param array<string, mixed> $actual
   */
  private function acumularArqueoTrasFinalizar(
    string $empresaVenta,
    string $puesto,
    int $sesion,
    string $opcion,
    string $fpago1,
    float $importe,
    array $actual
  ): void {
    if ($puesto === '' || $sesion <= 0) {
      return;
    }

    $ctx = $this->arqueo->asegurarSesionPuesto($puesto, $empresaVenta);
    $empresaArq = $ctx['empresa'] !== '' ? $ctx['empresa'] : $empresaVenta;
    $sesion = $ctx['sesion'] > 0 ? $ctx['sesion'] : $sesion;

    $pagoACuenta = 0.0;
    try {
      $tipo = (string) ($actual['tipo'] ?? 'A');
      $albaran = (int) ($actual['albaran'] ?? 0);
      if ($albaran > 0) {
        $st = $this->pdo->prepare(
          'SELECT PagoaCuenta FROM AlbaranesVentasCab
           WHERE Empresa = :e AND Tipo = :t AND Albaran = :a'
        );
        $st->execute(['e' => $empresaVenta, 't' => $tipo, 'a' => $albaran]);
        $pagoACuenta = (float) ($st->fetchColumn() ?: 0);
      }
    } catch (\Throwable $e) {
      $pagoACuenta = 0.0;
    }
    $importeArq = $importe - $pagoACuenta;

    $meta = $this->metaFormaPagoFactura($fpago1);
    if ($meta['cobroDeArqueo']) {
      $this->arqueo->addArq($empresaArq, $puesto, $sesion, $fpago1, $importeArq);
    }

    if ($opcion === 'T') {
      $this->arqueo->incrementarContadorDocumento($empresaArq, $puesto, $sesion, 'Tickets', $importe);
    } elseif ($opcion === 'F') {
      $this->arqueo->incrementarContadorDocumento($empresaArq, $puesto, $sesion, 'Facturas', $importe);
    }
  }

  /**
   * Contado = FormasPago con CobroDeArqueo, AbrirCajon o FacturacionDirecta
   * (legacy: "si su forma de pago es de contado no deja hacer albaranes").
   *
   * @param array<string, mixed> $actual ficha venta
   */
  private function esFormaPagoContado(array $actual): bool
  {
    $codigo = '';
    $formas = $actual['formasPago'] ?? [];
    if (is_array($formas) && isset($formas[0]) && is_array($formas[0])) {
      $codigo = trim((string) ($formas[0]['codigo'] ?? ''));
    }
    if ($codigo === '' || $codigo === ' ') {
      $cliente = trim((string) ($actual['cliente'] ?? ''));
      if ($cliente !== '') {
        try {
          $st = $this->pdo->prepare('SELECT FormaPago FROM Clientes WHERE Codigo = :c');
          $st->execute(['c' => $cliente]);
          $codigo = trim((string) ($st->fetchColumn() ?: ''));
        } catch (\Throwable $e) {
          return false;
        }
      }
    }
    if ($codigo === '') {
      return false;
    }
    try {
      $st = $this->pdo->prepare(
        'SELECT CobroDeArqueo, AbrirCajon, FacturacionDirecta FROM FormasPago WHERE Codigo = :c'
      );
      $st->execute(['c' => $codigo]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if ($row === false) {
        return false;
      }
      return !empty($row['CobroDeArqueo']) || !empty($row['AbrirCajon']) || !empty($row['FacturacionDirecta']);
    } catch (\Throwable $e) {
      return false;
    }
  }

  public function marcarImpreso(string $empresa, string $tipo, int $albaran): array
  {
    $actual = $this->consulta->obtenerFicha($empresa, $tipo, $albaran);
    if ($actual === null) {
      throw new \RuntimeException('Venta no encontrada', 404);
    }

    $this->pdo->prepare(
      'UPDATE AlbaranesVentasCab SET Impreso = 1
       WHERE Empresa = :e AND Tipo = :t AND Albaran = :a'
    )->execute(['e' => $empresa, 't' => $tipo, 'a' => $albaran]);

    $detalle = $this->consulta->obtenerFicha($empresa, $tipo, $albaran);
    if ($detalle === null) {
      throw new \RuntimeException('No se pudo releer tras marcar impreso');
    }
    return $detalle;
  }

  /** @param array<string, mixed> $actual */
  private function fpagoCodigo(array $actual, int $index): string
  {
    $formas = $actual['formasPago'] ?? [];
    if (!is_array($formas) || !isset($formas[$index]) || !is_array($formas[$index])) {
      return '';
    }
    return trim((string) ($formas[$index]['codigo'] ?? ''));
  }

  /** @param array<string, mixed> $actual */
  private function fpagoImporte(array $actual, int $index): float
  {
    $formas = $actual['formasPago'] ?? [];
    if (!is_array($formas) || !isset($formas[$index]) || !is_array($formas[$index])) {
      return 0.0;
    }
    return (float) ($formas[$index]['importe'] ?? 0);
  }

  private function nullIfEmpty($v): ?string
  {
    if ($v === null) {
      return null;
    }
    $s = trim((string) $v);
    return $s === '' ? null : $s;
  }

  /** Campos texto vacios: cadena vacia (no NULL), como pide paridad con Crystal/legacy. */
  private function blankIfEmpty($v): string
  {
    if ($v === null || is_bool($v)) {
      return '';
    }
    return trim((string) $v);
  }

  /**
   * Codigos char (Representante, etc.): nunca booleanos ni literales "true"/"false".
   * Legacy: espacio si vacio.
   */
  private function codigoCharOEspacio($v): string
  {
    if ($v === null || is_bool($v)) {
      return ' ';
    }
    $s = trim((string) $v);
    if ($s === '' || strcasecmp($s, 'true') === 0 || strcasecmp($s, 'false') === 0) {
      return ' ';
    }
    return $s;
  }

  /** Legacy suele grabar espacio en blanco en vez de NULL en campos char. */
  private function spaceIfEmpty($v): string
  {
    if ($v === null || is_bool($v)) {
      return ' ';
    }
    $s = trim((string) $v);
    return $s === '' ? ' ' : $s;
  }

  /** Normaliza a yyyy-mm-dd HH:ii:ss para CONVERT(..., 120). */
  private function normalizeFecha($value): string
  {
    if ($value === null || trim((string) $value) === '') {
      return date('Y-m-d H:i:s');
    }
    $raw = trim(str_replace('T', ' ', (string) $value));
    if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:[ ](\d{2}:\d{2}:\d{2}))?/', $raw, $m)) {
      return $m[1] . ' ' . ($m[2] ?? '12:00:00');
    }
    $ts = strtotime($raw);
    return $ts ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
  }
}
