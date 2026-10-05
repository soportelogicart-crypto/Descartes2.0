<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Ventas;

use Descartes\Api\Services\Facturacion\FacturacionRetencionIrpfService;
use Descartes\Api\Services\Facturacion\RecibosFacturaService;
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
  private FidelizacionService $fidelizacion;
  private ValeService $vales;
  private FidelizacionValesSemestreService $fidelizacionVales;
  private RecibosFacturaService $recibos;
  private FacturacionRetencionIrpfService $retencionIrpf;

  public function __construct(
    PDO $pdo,
    VentaConsultaService $consulta,
    ?ArqueoService $arqueo = null,
    ?FidelizacionService $fidelizacion = null,
    ?ValeService $vales = null,
    ?FidelizacionValesSemestreService $fidelizacionVales = null,
    ?RecibosFacturaService $recibos = null,
    ?FacturacionRetencionIrpfService $retencionIrpf = null
  ) {
    $this->pdo = $pdo;
    $this->consulta = $consulta;
    $this->arqueo = $arqueo ?? new ArqueoService($pdo);
    $this->fidelizacion = $fidelizacion ?? new FidelizacionService($pdo);
    $this->vales = $vales ?? new ValeService($pdo);
    $this->fidelizacionVales = $fidelizacionVales
      ?? new FidelizacionValesSemestreService($pdo, $this->vales);
    $this->recibos = $recibos ?? new RecibosFacturaService($pdo);
    $this->retencionIrpf = $retencionIrpf ?? new FacturacionRetencionIrpfService($pdo);
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
    if ($vendedor === null) {
      $vendedor = $this->vendedorDelPuesto($puesto);
    }
    $fecha = $this->normalizeFecha($body['fecha'] ?? null);
    $representante = $this->codigoCharOEspacio($body['representante'] ?? null);
    $transporte = $this->spaceIfEmpty($body['transporte'] ?? null);
    $agente = $this->spaceIfEmpty($body['agente'] ?? null);
    $genAlbaranCompras = isset($body['genAlbaranCompras']) && (int) $body['genAlbaranCompras'] > 0
      ? (int) $body['genAlbaranCompras']
      : 0;

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
          NULL, :lineasConDescuentos, NULL, :genAlbaranCompras, 0, 0,
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
        'genAlbaranCompras' => $genAlbaranCompras,
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
    if ($this->esTicketCerrado($actual)) {
      return $this->actualizarDatosFiscalesTicket($empresa, $tipo, $albaran, $actual, $body);
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
   * Ticket cerrado: solo cliente / NIF / razón / contacto (para poder pasarlo a factura).
   * No toca líneas ni importes.
   *
   * @param array<string, mixed> $actual
   * @param array<string, mixed> $body
   * @return array<string, mixed>
   */
  private function actualizarDatosFiscalesTicket(
    string $empresa,
    string $tipo,
    int $albaran,
    array $actual,
    array $body
  ): array {
    $this->pdo->beginTransaction();
    try {
      $sql = 'UPDATE AlbaranesVentasCab SET
          Cliente = :cliente, RazonSocial = :razonSocial, RazonSocial2 = :razonSocial2,
          NIF = :nif, Vendedor = :vendedor,
          DireccionEnvio = :direccionEnvio, PoblacionEnvio = :poblacionEnvio,
          CodigoPostalEnvio = :codigoPostalEnvio, ProvinciaEnvio = :provinciaEnvio, PaisEnvio = :paisEnvio,
          Telefono = :telefono, Telefono2 = :telefono2, Fax = :fax, Email = :email
        WHERE Empresa = :empresa AND Tipo = :tipo AND Albaran = :albaran';
      $this->pdo->prepare($sql)->execute([
        'empresa' => $empresa,
        'tipo' => $tipo,
        'albaran' => $albaran,
        'cliente' => $this->nullIfEmpty($body['cliente'] ?? $actual['cliente'] ?? null),
        'razonSocial' => $this->nullIfEmpty($body['razonSocial'] ?? $actual['razonSocial'] ?? null),
        'razonSocial2' => $this->blankIfEmpty($body['razonSocial2'] ?? $actual['razonSocial2'] ?? null),
        'nif' => $this->blankIfEmpty($body['nif'] ?? $actual['nif'] ?? null),
        'vendedor' => $this->nullIfEmpty($body['vendedor'] ?? $actual['vendedor'] ?? null),
        'direccionEnvio' => $this->spaceIfEmpty($body['direccionEnvio'] ?? $actual['direccionEnvio'] ?? null),
        'poblacionEnvio' => $this->spaceIfEmpty($body['poblacionEnvio'] ?? $actual['poblacionEnvio'] ?? null),
        'codigoPostalEnvio' => $this->spaceIfEmpty($body['codigoPostalEnvio'] ?? $actual['codigoPostalEnvio'] ?? null),
        'provinciaEnvio' => $this->spaceIfEmpty($body['provinciaEnvio'] ?? $actual['provinciaEnvio'] ?? null),
        'paisEnvio' => $this->spaceIfEmpty($body['paisEnvio'] ?? $actual['paisEnvio'] ?? null),
        'telefono' => $this->blankIfEmpty($body['telefono'] ?? $actual['telefono'] ?? null),
        'telefono2' => $this->blankIfEmpty($body['telefono2'] ?? $actual['telefono2'] ?? null),
        'fax' => $this->blankIfEmpty($body['fax'] ?? $actual['fax'] ?? null),
        'email' => $this->blankIfEmpty($body['email'] ?? $actual['email'] ?? null),
      ]);
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
    if (trim((string) ($actual['vendedor'] ?? '')) === '') {
      throw new \InvalidArgumentException(
        'No se puede finalizar la venta sin vendedor. Seleccione el vendedor en la cabecera.'
      );
    }
    // Factura (directa o ticket→factura): NIF y razón. Contado ZZZZZZZZZ vale si están.
    if ($opcion === 'F') {
      $nif = strtoupper(preg_replace('/[\s.\-]/', '', trim((string) ($actual['nif'] ?? ''))) ?? '');
      $razon = trim((string) ($actual['razonSocial'] ?? ''));
      $nifInvalido = strlen($nif) < 7 || (bool) preg_match('/^[0X]+$/', $nif);
      if ($nifInvalido || $razon === '') {
        throw new \InvalidArgumentException(
          'Para factura hacen falta NIF válido y razón social. Use Ticket o Presupuesto.'
        );
      }
    }

    // Cliente de contado = forma de pago con CobroDeArqueo: no permite Albaran.
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
    $estadoPrevioFidelizacion = [
      'sesion' => (int) ($actual['sesion'] ?? 0),
      'facturaTipo' => strtoupper(trim((string) ($actual['facturaTipo'] ?? ''))),
      'factura' => (int) ($actual['factura'] ?? 0),
    ];
    /** @var list<string> */
    $avisosFidelizacion = [];
    $resultadoValeFidelizacion = null;
    $resultadoPuntosCanje = null;
    $puntosFidelizacion = null;
    // Contado/ticket: forma de pago elegida (legacy Frame1/DbList2: CobroDeArqueo).
    $fpagoBody = trim((string) ($body['fpago1'] ?? ''));
    $fpago1 = $fpagoBody !== '' ? $fpagoBody : trim((string) ($this->fpagoCodigo($actual, 0)));
    if ($fpago1 === '') {
      $fpago1 = 'EU';
    }
    /** @var array{empresa: string, tipo: string, albaran: int, ticketNegativo: int, albaranTicketNegativo: int}|null $conversion */
    $conversion = null;

    $this->pdo->beginTransaction();
    try {
      if (!$esTicketAFactura && !empty($body['aplicarValeFidelizacion']) && in_array($opcion, ['T', 'F'], true)) {
        $clienteVale = trim((string) ($actual['cliente'] ?? ''));
        if ($clienteVale !== '' && strtoupper($clienteVale) !== 'ZZZZZZZZZ') {
          $disponible = $this->vales->fidelizacionDisponible($empresa, $clienteVale, true);
          $objetivo = min((float) $disponible['saldo'], max(0, $importe));
          if ($objetivo > 0) {
            $totalesVale = $this->calcularTotales((array) ($actual['lineas'] ?? []), [
              'empresa' => $empresa,
              'cliente' => $clienteVale,
              'pjeIva1' => $actual['pjeIva1'] ?? 0,
              'importeDtoFidelizacion' => $objetivo,
            ]);
            $this->actualizarTotalesFidelizacion(
              $empresa,
              $tipoActual,
              $albaran,
              $totalesVale
            );
            $aplicado = (float) ($totalesVale['descuentoFidelizacion'] ?? 0);
            $resultadoValeFidelizacion = $this->vales->consumirFidelizacion(
              $empresa,
              $clienteVale,
              $aplicado,
              $tipoActual,
              $albaran
            );
            $actual = $this->consulta->obtenerFicha($empresa, $tipoActual, $albaran) ?? $actual;
            $importe = (float) ($actual['importe'] ?? 0);
          }
        }
      }

      $resultadoPuntosCanje = null;
      if (!$esTicketAFactura && !empty($body['aplicarPuntosFidelizacion']) && in_array($opcion, ['T', 'A', 'F'], true)) {
        $clientePuntos = trim((string) ($actual['cliente'] ?? ''));
        $canje = $this->fidelizacion->canjeDisponible($empresa, $clientePuntos, $importe);
        if (!empty($canje['aplica'])) {
          $dtoYa = (float) ($actual['descuentoFidelizacion'] ?? 0);
          $totalesPuntos = $this->calcularTotales((array) ($actual['lineas'] ?? []), [
            'empresa' => $empresa,
            'cliente' => $clientePuntos,
            'pjeIva1' => $actual['pjeIva1'] ?? 0,
            'importeDtoFidelizacion' => round($dtoYa + (float) $canje['euros'], 2),
          ]);
          $this->actualizarTotalesFidelizacion($empresa, $tipoActual, $albaran, $totalesPuntos);
          $this->fidelizacion->descontarPuntos($clientePuntos, (int) $canje['puntosUsables']);
          $resultadoPuntosCanje = $canje;
          $actual = $this->consulta->obtenerFicha($empresa, $tipoActual, $albaran) ?? $actual;
          $importe = (float) ($actual['importe'] ?? 0);
        }
      }

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
      } elseif ($opcion === 'F' && $esTicketAFactura) {
        // El ticket ya cobrado se conserva. Un ticket negativo lo compensa y la factura
        // de contado queda como documento fiscal, sin volver a cobrar ni a mover stock.
        $conversion = $this->convertirTicketCobradoAFactura(
          $empresa,
          $tipoActual,
          $albaran,
          $actual,
          $fpago1,
          $puesto,
          $sesion,
          $ahora
        );
      } elseif ($opcion === 'F') {
        // Legacy FacturaCliente → CreaFactura.
        $albaranTicketOrigen = 0;
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

      $resultadoFid = ['avisos' => [], 'aplicado' => false, 'puntos' => 0, 'acumulados' => 0.0];
      if ($conversion !== null) {
        // El ticket original ya sumó puntos. Los dos documentos nuevos no vuelven a sumar.
      } else {
      $resultadoFid = $this->fidelizacion->procesarAlCierre(
        $empresa,
        $actual,
        $opcion,
        $esTicketAFactura,
        $estadoPrevioFidelizacion
      );
      $avisosFidelizacion = $resultadoFid['avisos'];

      if (in_array($opcion, ['T', 'F', 'A'], true)) {
        $this->actualizarFechaUltimaVentaArticulos($empresa, $tipoActual, $albaran, $actual);
        $puntosCompra = $esTicketAFactura ? 0.0 : max(0, $importe);
        if (!$esTicketAFactura) {
          $elegible = $this->fidelizacionVales->importeElegibleDocumento($empresa, $tipoActual, $albaran);
          if ($elegible !== null) {
            $puntosCompra = $elegible;
          }
        }
        $puntosFidelizacion = $this->fidelizacionVales->puntosCliente(
          $empresa,
          trim((string) ($actual['cliente'] ?? '')),
          $puntosCompra
        );
        if ($puntosFidelizacion !== null) {
          $this->pdo->prepare(
            'UPDATE AlbaranesVentasCab
             SET PuntosFidelizacionCompra = :compra,
                 PuntosFidelizacionAcumulados = :acumulados
             WHERE Empresa = :e AND Tipo = :t AND Albaran = :a'
          )->execute([
            'compra' => $puntosFidelizacion['compra'],
            'acumulados' => $puntosFidelizacion['acumulados'],
            'e' => $empresa,
            't' => $tipoActual,
            'a' => $albaran,
          ]);
          $clientePuntos = trim((string) ($actual['cliente'] ?? ''));
          if ($clientePuntos !== '') {
            $this->pdo->prepare(
              'UPDATE Clientes SET AcumuladoPuntos = :p WHERE RTRIM(Codigo) = :c'
            )->execute([
              'p' => $puntosFidelizacion['acumulados'],
              'c' => $clientePuntos,
            ]);
          }
        }
      }

      if ($puntosFidelizacion === null && (int) ($resultadoFid['puntos'] ?? 0) > 0) {
        $puntosFidelizacion = [
          'compra' => (int) $resultadoFid['puntos'],
          'acumulados' => (float) ($resultadoFid['acumulados'] ?? 0),
        ];
        $this->pdo->prepare(
          'UPDATE AlbaranesVentasCab
           SET PuntosFidelizacionCompra = :compra,
               PuntosFidelizacionAcumulados = :acumulados
           WHERE Empresa = :e AND Tipo = :t AND Albaran = :a'
        )->execute([
          'compra' => $puntosFidelizacion['compra'],
          'acumulados' => $puntosFidelizacion['acumulados'],
          'e' => $empresa,
          't' => $tipoActual,
          'a' => $albaran,
        ]);
      }
      }

      $this->pdo->commit();
    } catch (\Throwable $e) {
      $this->pdo->rollBack();
      throw $e;
    }

    $detalle = $conversion !== null
      ? $this->consulta->obtenerFicha($conversion['empresa'], $conversion['tipo'], $conversion['albaran'])
      : $this->consulta->obtenerFicha($empresa, $tipoActual, $albaran);
    if ($detalle === null) {
      throw new \RuntimeException('No se pudo releer tras finalizar');
    }
    if ($conversion !== null) {
      $detalle['ticketNegativo'] = $conversion['ticketNegativo'];
      $detalle['albaranTicketNegativo'] = $conversion['albaranTicketNegativo'];
    }
    if ($avisosFidelizacion !== []) {
      $detalle['avisosFidelizacion'] = $avisosFidelizacion;
    }
    if ($resultadoValeFidelizacion !== null) {
      $detalle['valeFidelizacion'] = $resultadoValeFidelizacion;
    }
    if ($resultadoPuntosCanje !== null) {
      $detalle['puntosCanje'] = $resultadoPuntosCanje;
    }
    if ($puntosFidelizacion !== null) {
      $detalle['fidelizacionPuntos'] = $puntosFidelizacion;
    }
    return $detalle;
  }

  /**
   * @param array<string, mixed> $totales
   */
  private function actualizarTotalesFidelizacion(
    string $empresa,
    string $tipo,
    int $albaran,
    array $totales
  ): void {
    $st = $this->pdo->prepare(
      'UPDATE AlbaranesVentasCab SET
         Importe = :importe, ImporteDtos = :importeDtos,
         ImporteDtoFidelizacion = :dtoFid,
         ImporteBase1 = :b1, ImporteBase2 = :b2, ImporteBase3 = :b3, ImporteBase4 = :b4,
         PjeIva1 = :p1, PjeIva2 = :p2, PjeIva3 = :p3, PjeIva4 = :p4,
         ImporteIva1 = :i1, ImporteIva2 = :i2, ImporteIva3 = :i3, ImporteIva4 = :i4
       WHERE Empresa = :e AND Tipo = :t AND Albaran = :a'
    );
    $st->execute([
      'importe' => (float) ($totales['importe'] ?? 0),
      'importeDtos' => (float) ($totales['descuento'] ?? 0),
      'dtoFid' => (float) ($totales['descuentoFidelizacion'] ?? 0),
      'b1' => (float) ($totales['bases'][0] ?? 0),
      'b2' => (float) ($totales['bases'][1] ?? 0),
      'b3' => (float) ($totales['bases'][2] ?? 0),
      'b4' => (float) ($totales['bases'][3] ?? 0),
      'p1' => (float) ($totales['pjes'][0] ?? 0),
      'p2' => (float) ($totales['pjes'][1] ?? 0),
      'p3' => (float) ($totales['pjes'][2] ?? 0),
      'p4' => (float) ($totales['pjes'][3] ?? 0),
      'i1' => (float) ($totales['ivas'][0] ?? 0),
      'i2' => (float) ($totales['ivas'][1] ?? 0),
      'i3' => (float) ($totales['ivas'][2] ?? 0),
      'i4' => (float) ($totales['ivas'][3] ?? 0),
      'e' => $empresa,
      't' => $tipo,
      'a' => $albaran,
    ]);
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
   * Crea albarán de abono (parcial por líneas) desde un albarán de cargo.
   * Cantidades negativas, AlbaranOrigenAbono, listo para pendientes de facturar.
   *
   * @param array<string, mixed> $body { nroLins?: list<int>, observacion?: string }
   * @return array<string, mixed> ficha del abono creado
   */
  /**
   * Ticket ya cobrado → ticket negativo que lo compensa + factura de contado.
   * Caja y stock no cambian: el negativo y la factura usan la misma forma de pago
   * y no vuelven a rebajar existencias.
   *
   * @param array<string, mixed> $actual
   * @return array{empresa: string, tipo: string, albaran: int, ticketNegativo: int, albaranTicketNegativo: int}
   */
  private function convertirTicketCobradoAFactura(
    string $empresa,
    string $tipo,
    int $albaran,
    array $actual,
    string $fpago,
    string $puesto,
    int $sesion,
    string $ahora
  ): array {
    if ((float) ($actual['importe'] ?? 0) <= 0) {
      throw new \InvalidArgumentException('Solo se puede pasar a factura un ticket de venta, no uno negativo');
    }
    $ya = $this->facturaDeTicketConvertido($empresa, $albaran);
    if ($ya !== null) {
      throw new \InvalidArgumentException("Este ticket ya se pasó a la factura {$ya}");
    }

    $numeroTicket = (int) ($actual['factura'] ?? 0);
    $albaranNegativo = $this->reservarAlbaranEnTransaccion($empresa);
    $this->copiarDocumentoVenta($empresa, $tipo, $albaran, 'A', $albaranNegativo, true);
    $ticketNegativo = $this->nextContadorEmpresa($empresa, 'UltTicket');
    $notaNegativo = "Anulación del ticket T-{$numeroTicket} por paso a factura";
    $this->pdo->prepare(
      'UPDATE AlbaranesVentasCab SET
          FacturaTipo = \'T\', Factura = :factura, Estado = NULL,
          Fecha = CONVERT(datetime, :fecha, 120),
          FechaCobro = CONVERT(datetime, :fechaCobro, 120),
          Fpago1 = :fpago, ImpFpago1 = Importe,
          Sesion = :sesion, RebajeStock = 0, Impreso = 0,
          EmpresaFacturacion = :empresaFacturacion,
          AlbaranOrigenAbono = :origen,
          Observaciones = :obs
       WHERE Empresa = :e AND Tipo = \'A\' AND Albaran = :a'
    )->execute([
      'factura' => $ticketNegativo,
      'fecha' => $ahora,
      'fechaCobro' => $ahora,
      'fpago' => $fpago,
      'sesion' => $sesion,
      'empresaFacturacion' => $empresa,
      'origen' => $albaran,
      'obs' => $notaNegativo,
      'e' => $empresa,
      'a' => $albaranNegativo,
    ]);
    $importeNegativo = -abs((float) ($actual['importe'] ?? 0));
    $this->acumularArqueoTrasFinalizar(
      $empresa,
      $puesto,
      $sesion,
      'T',
      $fpago,
      $importeNegativo,
      ['tipo' => 'A', 'albaran' => $albaranNegativo]
    );

    $albaranFactura = $this->reservarAlbaranEnTransaccion($empresa);
    $this->copiarDocumentoVenta($empresa, $tipo, $albaran, 'A', $albaranFactura, false);
    $creada = $this->crearRegistroFactura(
      $empresa,
      'A',
      $albaranFactura,
      $actual,
      $fpago,
      $ahora,
      $albaran,
      true
    );
    $this->pdo->prepare(
      'UPDATE AlbaranesVentasCab SET
          FacturaTipo = :facturaTipo, Factura = :factura, Estado = \'F\',
          Fecha = CONVERT(datetime, :fecha, 120),
          FechaCobro = CONVERT(datetime, :fechaCobro, 120),
          Fpago1 = :fpago, ImpFpago1 = Importe,
          Sesion = :sesion, RebajeStock = 0, Impreso = 0,
          EmpresaFacturacion = :empresaFacturacion,
          Observaciones = :obs
       WHERE Empresa = :e AND Tipo = \'A\' AND Albaran = :a'
    )->execute([
      'facturaTipo' => $creada['facturaTipo'],
      'factura' => $creada['factura'],
      'fecha' => $ahora,
      'fechaCobro' => $ahora,
      'fpago' => $fpago,
      'sesion' => $sesion,
      'empresaFacturacion' => $empresa,
      'obs' => "Factura del ticket T-{$numeroTicket}",
      'e' => $empresa,
      'a' => $albaranFactura,
    ]);
    $this->acumularArqueoTrasFinalizar(
      $empresa,
      $puesto,
      $sesion,
      'F',
      $fpago,
      abs((float) ($actual['importe'] ?? 0)),
      ['tipo' => 'A', 'albaran' => $albaranFactura]
    );

    $this->anotarObservaciones(
      $empresa,
      $tipo,
      $albaran,
      "Pasado a factura {$creada['facturaTipo']}-{$creada['factura']} (ticket negativo T-{$ticketNegativo})"
    );

    return [
      'empresa' => $empresa,
      'tipo' => 'A',
      'albaran' => $albaranFactura,
      'ticketNegativo' => $ticketNegativo,
      'albaranTicketNegativo' => $albaranNegativo,
    ];
  }

  private function facturaDeTicketConvertido(string $empresa, int $albaran): ?int
  {
    try {
      $st = $this->pdo->prepare(
        'SELECT TOP 1 Factura FROM Facturas
         WHERE Empresa = :e AND ISNULL(TrasformacionTicketFactura, 0) = 1
           AND AlbaranTicketTransformado = :a'
      );
      $st->execute(['e' => $empresa, 'a' => $albaran]);
      $n = $st->fetchColumn();
      return $n === false || (int) $n <= 0 ? null : (int) $n;
    } catch (\Throwable $e) {
      return null;
    }
  }

  /** Copia cabecera y líneas. En negativo invierte cantidades e importes y no rebaja stock. */
  private function copiarDocumentoVenta(
    string $empresa,
    string $tipoOrigen,
    int $albaranOrigen,
    string $tipoDestino,
    int $albaranDestino,
    bool $negativo
  ): void {
    $signo = $negativo ? -1 : 1;
    $this->copiarFila(
      'AlbaranesVentasCab',
      'Empresa = :e AND Tipo = :t AND Albaran = :a',
      ['e' => $empresa, 't' => $tipoOrigen, 'a' => $albaranOrigen],
      [
        'Tipo' => [$tipoDestino, null],
        'Albaran' => [$albaranDestino, null],
        'FacturaTipo' => [null, 'literal-null'],
        'Factura' => [0, null],
        'Estado' => [null, 'literal-null'],
        'Sesion' => [0, null],
        'RebajeStock' => [0, null],
        'Impreso' => [0, null],
        'AlbaranOrigenAbono' => [0, null],
        'Importe' => ["[Importe] * {$signo}", 'expr'],
        'ImporteDtos' => ["[ImporteDtos] * {$signo}", 'expr'],
        'ImpFpago1' => ["[ImpFpago1] * {$signo}", 'expr'],
        'ImpFpago2' => ["[ImpFpago2] * {$signo}", 'expr'],
        'PagoaCuenta' => ["[PagoaCuenta] * {$signo}", 'expr'],
        'ImporteBase1' => ["[ImporteBase1] * {$signo}", 'expr'],
        'ImporteBase2' => ["[ImporteBase2] * {$signo}", 'expr'],
        'ImporteBase3' => ["[ImporteBase3] * {$signo}", 'expr'],
        'ImporteBase4' => ["[ImporteBase4] * {$signo}", 'expr'],
        'ImporteBase5' => ["[ImporteBase5] * {$signo}", 'expr'],
        'ImporteBase6' => ["[ImporteBase6] * {$signo}", 'expr'],
        'ImporteIva1' => ["[ImporteIva1] * {$signo}", 'expr'],
        'ImporteIva2' => ["[ImporteIva2] * {$signo}", 'expr'],
        'ImporteIva3' => ["[ImporteIva3] * {$signo}", 'expr'],
        'ImporteIva4' => ["[ImporteIva4] * {$signo}", 'expr'],
        'ImporteIva5' => ["[ImporteIva5] * {$signo}", 'expr'],
        'ImporteIva6' => ["[ImporteIva6] * {$signo}", 'expr'],
        'ImporteRec1' => ["[ImporteRec1] * {$signo}", 'expr'],
        'ImporteRec2' => ["[ImporteRec2] * {$signo}", 'expr'],
        'ImporteRec3' => ["[ImporteRec3] * {$signo}", 'expr'],
        'ImporteRec4' => ["[ImporteRec4] * {$signo}", 'expr'],
        'ImporteRec5' => ["[ImporteRec5] * {$signo}", 'expr'],
        'ImporteRec6' => ["[ImporteRec6] * {$signo}", 'expr'],
        'TicketSI_IdentificativoTBAI' => [null, 'literal-null'],
        'TicketSI_IdentificativoTBAIQR' => [null, 'literal-null'],
        'TicketSI_Estado' => [null, 'literal-null'],
        'TicketSI_QR' => [null, 'literal-null'],
        'TicketSI_FechaServidor' => [null, 'literal-null'],
        'Firma' => [null, 'literal-null'],
        'FirmaFacturaAnterior' => [null, 'literal-null'],
        'PuntosFidelizacionCompra' => [0, null],
        'PuntosFidelizacionAcumulados' => [0, null],
        'ImporteDtoFidelizacion' => [0, null],
        'FidelizacionCalculada' => [0, null],
        'ImporteFidelizacion' => [0, null],
      ]
    );
    $this->copiarFila(
      'AlbaranesVentasLin',
      'Empresa = :e AND Tipo = :t AND Albaran = :a',
      ['e' => $empresa, 't' => $tipoOrigen, 'a' => $albaranOrigen],
      [
        'Tipo' => [$tipoDestino, null],
        'Albaran' => [$albaranDestino, null],
        'Cantidad' => ["[Cantidad] * {$signo}", 'expr'],
        'Importe' => ["[Importe] * {$signo}", 'expr'],
        'RebajeStock' => [0, null],
      ]
    );
    try {
      $this->pdo->prepare(
        'UPDATE AlbaranesVentasLin SET RebajeStock = 0
         WHERE Empresa = :e AND Tipo = :t AND Albaran = :a'
      )->execute(['e' => $empresa, 't' => $tipoDestino, 'a' => $albaranDestino]);
    } catch (\Throwable $e) {
      // La línea puede no tener la columna; la cabecera ya queda con RebajeStock = 0.
    }
  }

  /** UltAlbaranVen dentro de la transacción de finalizar, sin abrir otra. */
  private function reservarAlbaranEnTransaccion(string $empresa): int
  {
    $n = $this->nextContadorEmpresa($empresa, 'UltAlbaranVen');
    $ocupado = $this->pdo->prepare(
      'SELECT 1 FROM AlbaranesVentasCab WHERE Empresa = :e AND Tipo = \'A\' AND Albaran = :a'
    );
    $ocupado->execute(['e' => $empresa, 'a' => $n]);
    if ($ocupado->fetchColumn() === false) {
      return $n;
    }
    $max = $this->pdo->prepare(
      'SELECT ISNULL(MAX(Albaran), 0) + 1 FROM AlbaranesVentasCab WITH (UPDLOCK, ROWLOCK)
       WHERE Empresa = :e AND Tipo = \'A\''
    );
    $max->execute(['e' => $empresa]);
    $n = (int) $max->fetchColumn();
    $this->pdo->prepare('UPDATE Empresas_Ges SET UltAlbaranVen = :n WHERE Codigo = :e')
      ->execute(['n' => $n, 'e' => $empresa]);
    return $n;
  }

  private function anotarObservaciones(string $empresa, string $tipo, int $albaran, string $nota): void
  {
    $limite = 240;
    try {
      $st = $this->pdo->prepare(
        'SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_NAME = :tabla AND COLUMN_NAME = :columna'
      );
      $st->execute(['tabla' => 'AlbaranesVentasCab', 'columna' => 'Observaciones']);
      $len = $st->fetchColumn();
      if ($len !== false && $len !== null && (int) $len > 0) {
        $limite = (int) $len;
      }
    } catch (\Throwable $e) {
      $limite = 240;
    }
    $limite = max(1, $limite);
    $prefijo = $nota . ' | ';
    if (strlen($prefijo) > $limite) {
      $prefijo = substr($nota, 0, $limite);
    }
    $this->pdo->prepare(
      "UPDATE AlbaranesVentasCab SET Observaciones = LEFT(:prefijo + ISNULL(CAST(Observaciones AS nvarchar(4000)), N''), {$limite})
       WHERE Empresa = :e AND Tipo = :t AND Albaran = :a"
    )->execute([
      'prefijo' => $prefijo,
      'e' => $empresa,
      't' => $tipo,
      'a' => $albaran,
    ]);
  }

  /** NULL sin tipo se interpreta como int y no entra en columnas image o fecha. */
  private function sqlNullSegunTipo(string $tipo): string
  {
    return match ($tipo) {
      'image' => 'CAST(NULL AS image)',
      'datetime', 'datetime2', 'smalldatetime', 'date' => 'CAST(NULL AS datetime)',
      'ntext' => 'CAST(NULL AS ntext)',
      'text' => 'CAST(NULL AS text)',
      'nvarchar', 'nchar', 'varchar', 'char' => 'CAST(NULL AS nvarchar(100))',
      'bit' => 'CAST(NULL AS bit)',
      'float', 'real' => 'CAST(NULL AS float)',
      default => 'CAST(NULL AS int)',
    };
  }

  /**
   * @param array<string, mixed> $where
   * @param array<string, array{0: mixed, 1: ?string}> $reemplazos
   */
  private function copiarFila(string $tabla, string $whereSql, array $where, array $reemplazos): void
  {
    if (!in_array($tabla, ['AlbaranesVentasCab', 'AlbaranesVentasLin'], true)) {
      throw new \InvalidArgumentException('Tabla no copiable');
    }
    $cols = $this->pdo->query(
      "SELECT c.name AS name, c.is_identity AS identidad, c.is_computed AS calculada, ty.name AS tipo
       FROM sys.columns c
       INNER JOIN sys.types ty ON c.user_type_id = ty.user_type_id
       WHERE c.object_id = OBJECT_ID('{$tabla}')
       ORDER BY c.column_id"
    );
    if ($cols === false) {
      throw new \RuntimeException("No se pudo leer la estructura de {$tabla}");
    }
    $nombres = [];
    $select = [];
    $params = $where;
    $i = 0;
    while ($col = $cols->fetch(PDO::FETCH_ASSOC)) {
      $nombre = (string) $col['name'];
      $tipo = strtolower((string) $col['tipo']);
      if ((int) $col['identidad'] === 1 || (int) ($col['calculada'] ?? 0) === 1 || $tipo === 'timestamp' || $tipo === 'rowversion') {
        continue;
      }
      $nombres[] = '[' . $nombre . ']';
      if (!array_key_exists($nombre, $reemplazos)) {
        $select[] = '[' . $nombre . ']';
        continue;
      }
      [$valor, $modo] = $reemplazos[$nombre];
      if ($modo === 'expr') {
        $select[] = (string) $valor;
        continue;
      }
      if ($modo === 'literal-null' || $valor === null) {
        $select[] = $this->sqlNullSegunTipo($tipo);
        continue;
      }
      $marca = ':cp' . $i;
      $i++;
      $select[] = $marca;
      $params[substr($marca, 1)] = $valor;
    }
    if ($nombres === []) {
      throw new \RuntimeException("{$tabla} no tiene columnas copiables");
    }
    $sql = 'INSERT INTO [' . $tabla . '] (' . implode(', ', $nombres) . ')
            SELECT ' . implode(', ', $select) . '
            FROM [' . $tabla . '] WHERE ' . $whereSql;
    $this->pdo->prepare($sql)->execute($params);
  }

  public function crearAbonoDesdeAlbaran(string $empresa, string $tipo, int $albaran, array $body = []): array
  {
    $origen = $this->consulta->obtenerFicha($empresa, $tipo, $albaran);
    if ($origen === null) {
      throw new \RuntimeException('Albarán origen no encontrado', 404);
    }

    $origenAbono = (int) ($origen['albaranOrigenAbono'] ?? 0);
    if ($origenAbono > 0 || (float) ($origen['importe'] ?? 0) < 0) {
      throw new \InvalidArgumentException('No se puede abonar un albarán que ya es abono');
    }

    $ft = strtoupper(trim((string) ($origen['facturaTipo'] ?? '')));
    $factura = (int) ($origen['factura'] ?? 0);
    $sesion = (int) ($origen['sesion'] ?? 0);

    $facturaEstado = null;
    $contadoDiferida = false;
    if ($ft === 'F' && $factura > 0) {
      try {
        $st = $this->pdo->prepare(
          'SELECT TOP 1 Estado, FacturaContadoDiferida
           FROM Facturas
           WHERE Empresa = :e AND FacturaTipo = :ft AND Factura = :f'
        );
        $st->execute(['e' => $empresa, 'ft' => $ft, 'f' => $factura]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
          $facturaEstado = $row['Estado'] !== null ? trim((string) $row['Estado']) : null;
          $contadoDiferida = !empty($row['FacturaContadoDiferida']);
        }
      } catch (\Throwable $e) {
        // Se valida abajo con lo disponible.
      }
    }

    $esTicket = $ft === 'T' && $factura > 0;
    $esAlbaranCerrado = ($ft === '' || $ft === 'Z') && $factura <= 0 && $sesion > 0;
    $fe = strtoupper(trim((string) ($facturaEstado ?? '')));
    $esFacturaContado = $ft === 'F' && $factura > 0 && !$contadoDiferida && $fe === 'F';

    if ($ft === 'F' && $factura > 0 && ($contadoDiferida || $fe === 'G')) {
      throw new \InvalidArgumentException(
        'Factura a crédito / diferida: use rectificativa de factura, no abono de albarán.'
      );
    }
    if ($ft === 'F' && $factura > 0 && !$esFacturaContado) {
      throw new \InvalidArgumentException(
        'Solo se puede abonar facturas de contado (Estado F). Use rectificativa si es crédito.'
      );
    }
    if ($ft === 'A' && $factura > 0) {
      throw new \InvalidArgumentException('No se puede abonar un documento ya tipificado como abono de factura');
    }
    if (!$esTicket && !$esAlbaranCerrado && !$esFacturaContado) {
      if ($sesion <= 0 && $factura <= 0) {
        throw new \InvalidArgumentException('Debe finalizar el documento antes de generar el abono');
      }
      throw new \InvalidArgumentException(
        'Solo se puede abonar albaranes cerrados, tickets o facturas de contado'
      );
    }

    $lineasOrig = $origen['lineas'] ?? [];
    if (!is_array($lineasOrig) || $lineasOrig === []) {
      throw new \InvalidArgumentException('El albarán no tiene líneas para abonar');
    }

    /** @var list<int> $nroLins */
    $nroLins = [];
    if (isset($body['nroLins']) && is_array($body['nroLins'])) {
      foreach ($body['nroLins'] as $n) {
        $nroLins[] = (int) $n;
      }
      $nroLins = array_values(array_unique(array_filter($nroLins, static fn ($n) => $n > 0)));
    }

    $seleccionadas = [];
    foreach ($lineasOrig as $lin) {
      if (!is_array($lin)) {
        continue;
      }
      $art = trim((string) ($lin['articulo'] ?? ''));
      if ($art === '' || strtoupper($art) === 'NO') {
        continue;
      }
      $nro = (int) ($lin['nroLin'] ?? 0);
      if ($nroLins !== [] && !in_array($nro, $nroLins, true)) {
        continue;
      }
      $cant = (float) ($lin['cantidad'] ?? 0);
      if (abs($cant) < 0.0001) {
        continue;
      }
      $precio = (float) ($lin['precio'] ?? 0);
      $pjeDto = (float) ($lin['pjeDto'] ?? 0);
      $cantNeg = -abs($cant);
      $importe = round($cantNeg * $precio * (1 - $pjeDto / 100), 2);
      $seleccionadas[] = [
        'articulo' => $art,
        'descripcion' => $lin['descripcion'] ?? null,
        'loteVenta' => $lin['loteVenta'] ?? null,
        'cantidad' => $cantNeg,
        'precio' => $precio,
        'pjeDto' => $pjeDto,
        'importe' => $importe,
        'pjeIva' => (float) ($lin['pjeIva'] ?? 21),
        'nroLinOrigen' => $nro,
      ];
    }

    if ($seleccionadas === []) {
      throw new \InvalidArgumentException('Seleccione al menos una línea para abonar');
    }

    $estadoAb = $this->consulta->resolverEstadoAbonos($empresa, $albaran, $lineasOrig);
    $nroLinsAbonados = $estadoAb['nroLinsAbonados'];
    if ($nroLinsAbonados !== []) {
      $conflictos = [];
      foreach ($seleccionadas as $lin) {
        $nro = (int) ($lin['nroLinOrigen'] ?? 0);
        if ($nro > 0 && in_array($nro, $nroLinsAbonados, true)) {
          $conflictos[] = $nro;
        }
      }
      if ($conflictos !== []) {
        sort($conflictos);
        $nums = implode(', ', $conflictos);
        $msg = count($conflictos) === count($seleccionadas)
          ? 'Las líneas seleccionadas ya fueron abonadas anteriormente'
          : "Una o más líneas ya fueron abonadas (líneas: {$nums})";
        throw new \InvalidArgumentException($msg);
      }
    }

    $etiquetaOrigen = $this->etiquetaDocumentoVenta($origen, $albaran);
    array_unshift($seleccionadas, [
      'articulo' => 'NO',
      'descripcion' => 'Abono de ' . $etiquetaOrigen,
      'loteVenta' => null,
      'cantidad' => 0.0,
      'precio' => 0.0,
      'pjeDto' => 0.0,
      'importe' => 0.0,
      'pjeIva' => 0.0,
    ]);

    $totales = $this->calcularTotales($seleccionadas, [
      'pjeDto' => $origen['pjeDto'] ?? 0,
      'empresa' => $empresa,
      'cliente' => $origen['cliente'] ?? null,
    ]);

    $nuevoAlbaran = $this->nextAlbaran($empresa, 'A');
    $fecha = $this->normalizeFecha(date('Y-m-d'));
    $observacion = trim((string) ($body['observacion'] ?? $body['observacionDevolucion'] ?? ''));

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
          PuestoApertura, Portes, Tarifa, Plataforma, NumeroDeSerie, Observaciones, AlbaranOrigenAbono
        ) VALUES (
          :empresa, :albaran, \'A\', :puesto, :cliente, :razonSocial, :razonSocial2, :nif,
          CONVERT(datetime, :fecha, 120), :vendedor,
          :representante, :transporte, :direccionEnvio, :poblacionEnvio, :codigoPostalEnvio, :provinciaEnvio, :paisEnvio,
          :importeBase1, :importeBase2, :importeBase3, :importeBase4,
          :pjeIva1, :pjeIva2, :pjeIva3, :pjeIva4,
          :importeIva1, :importeIva2, :importeIva3, :importeIva4,
          0, 0, 0, 0,
          0, 0, 0, 0,
          :pjeDto, :importeDtos, :importe,
          :fpago1, :fpago2, 0, 0,
          NULL, NULL, NULL, 0, :pedido,
          0, 0, 1, 0,
          :referencia1, :referencia2, :agente, :almacen, NULL,
          0, 0, 0, 0,
          NULL, NULL, :telefono, :telefono2, :fax, :email,
          NULL, NULL, NULL, 0, 0,
          0, 0, 0, 0, 0, 0, 0, 0,
          0, 0, 0, 0,
          :vendedorApertura, 0, 0, NULL, 0, NULL,
          CONVERT(datetime, :fechaApertura, 120), :lineasConDescuentos, NULL, 0, 0, 0,
          :puestoApertura, 0, NULL, :plataforma, :numeroDeSerie, :observaciones, :albaranOrigenAbono
        )';

      $fpagos = $origen['formasPago'] ?? [];
      $fp1 = is_array($fpagos[0] ?? null) ? ($fpagos[0]['codigo'] ?? null) : ($origen['fpago1'] ?? null);
      $fp2 = is_array($fpagos[1] ?? null) ? ($fpagos[1]['codigo'] ?? null) : ($origen['fpago2'] ?? null);

      $obs = trim((string) ($origen['observaciones'] ?? ''));
      if ($observacion !== '') {
        $obs = trim($obs . ($obs !== '' ? ' | ' : '') . 'Abono: ' . $observacion);
      }
      $obs = trim($obs . ($obs !== '' ? ' | ' : '') . 'Abono de albarán ' . $albaran);

      $this->pdo->prepare($sql)->execute([
        'empresa' => $empresa,
        'albaran' => $nuevoAlbaran,
        'puesto' => $this->nullIfEmpty($origen['puesto'] ?? '99') ?? '99',
        'cliente' => $this->nullIfEmpty($origen['cliente'] ?? null),
        'razonSocial' => $this->nullIfEmpty($origen['razonSocial'] ?? null),
        'razonSocial2' => $this->blankIfEmpty($origen['razonSocial2'] ?? null),
        'nif' => $this->blankIfEmpty($origen['nif'] ?? null),
        'fecha' => $fecha,
        'fechaApertura' => $fecha,
        'vendedor' => $this->nullIfEmpty($origen['vendedor'] ?? null),
        'representante' => $this->codigoCharOEspacio($origen['representante'] ?? null),
        'transporte' => $this->spaceIfEmpty($origen['transporte'] ?? null),
        'direccionEnvio' => $this->blankIfEmpty($origen['direccionEnvio'] ?? null),
        'poblacionEnvio' => $this->blankIfEmpty($origen['poblacionEnvio'] ?? null),
        'codigoPostalEnvio' => $this->blankIfEmpty($origen['codigoPostalEnvio'] ?? null),
        'provinciaEnvio' => $this->blankIfEmpty($origen['provinciaEnvio'] ?? null),
        'paisEnvio' => $this->blankIfEmpty($origen['paisEnvio'] ?? null),
        'importeBase1' => (float) ($totales['bases'][0] ?? 0),
        'importeBase2' => (float) ($totales['bases'][1] ?? 0),
        'importeBase3' => (float) ($totales['bases'][2] ?? 0),
        'importeBase4' => (float) ($totales['bases'][3] ?? 0),
        'pjeIva1' => (float) ($totales['pjes'][0] ?? 0),
        'pjeIva2' => (float) ($totales['pjes'][1] ?? 0),
        'pjeIva3' => (float) ($totales['pjes'][2] ?? 0),
        'pjeIva4' => (float) ($totales['pjes'][3] ?? 0),
        'importeIva1' => (float) ($totales['ivas'][0] ?? 0),
        'importeIva2' => (float) ($totales['ivas'][1] ?? 0),
        'importeIva3' => (float) ($totales['ivas'][2] ?? 0),
        'importeIva4' => (float) ($totales['ivas'][3] ?? 0),
        'pjeDto' => (float) ($totales['pjeDto'] ?? 0),
        'importeDtos' => (float) ($totales['descuento'] ?? 0),
        'importe' => (float) ($totales['importe'] ?? 0),
        'fpago1' => $this->nullIfEmpty($fp1),
        'fpago2' => $this->nullIfEmpty($fp2),
        'pedido' => isset($origen['pedido']) && (int) $origen['pedido'] > 0 ? (int) $origen['pedido'] : null,
        'referencia1' => $this->blankIfEmpty($origen['referencia1'] ?? null),
        'referencia2' => $this->blankIfEmpty($origen['referencia2'] ?? null),
        'agente' => $this->spaceIfEmpty($origen['agente'] ?? null),
        'almacen' => isset($origen['almacen']) ? (int) $origen['almacen'] : null,
        'telefono' => $this->blankIfEmpty($origen['telefono'] ?? null),
        'telefono2' => $this->blankIfEmpty($origen['telefono2'] ?? null),
        'fax' => $this->blankIfEmpty($origen['fax'] ?? null),
        'email' => $this->blankIfEmpty($origen['email'] ?? null),
        'vendedorApertura' => $this->nullIfEmpty($origen['vendedor'] ?? null),
        'lineasConDescuentos' => !empty($totales['lineasConDescuentos']) ? 1 : 0,
        'puestoApertura' => $this->nullIfEmpty($origen['puesto'] ?? null),
        'plataforma' => $this->blankIfEmpty($origen['plataforma'] ?? null),
        'numeroDeSerie' => $this->blankIfEmpty($origen['numeroDeSerie'] ?? null),
        'observaciones' => $this->blankIfEmpty($obs),
        'albaranOrigenAbono' => $albaran,
      ]);

      $this->insertarLineasAbono($empresa, 'A', $nuevoAlbaran, $seleccionadas, $albaran, $observacion);

      $this->pdo->commit();
    } catch (\Throwable $e) {
      if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      throw $e;
    }

    $ficha = $this->consulta->obtenerFicha($empresa, 'A', $nuevoAlbaran);
    if ($ficha === null) {
      throw new \RuntimeException('No se pudo releer el abono creado');
    }
    return $ficha;
  }

  /**
   * Etiqueta legible del documento origen (albarán / ticket / factura contado).
   *
   * @param array<string, mixed> $doc
   */
  private function etiquetaDocumentoVenta(array $doc, int $albaranFallback): string
  {
    $albaran = (int) ($doc['albaran'] ?? $albaranFallback);
    $ft = strtoupper(trim((string) ($doc['facturaTipo'] ?? '')));
    $factura = (int) ($doc['factura'] ?? 0);
    if ($ft === 'T' && $factura > 0) {
      return "Ticket T-{$factura} (albarán {$albaran})";
    }
    if ($ft === 'F' && $factura > 0) {
      return "Factura F-{$factura} (albarán {$albaran})";
    }
    if ($ft === 'A' && $factura > 0) {
      return "Abono A-{$factura} (albarán {$albaran})";
    }
    return "Albarán {$albaran}";
  }

  /**
   * @param list<array<string, mixed>> $lineas
   */
  private function insertarLineasAbono(
    string $empresa,
    string $tipo,
    int $albaran,
    array $lineas,
    int $albaranOrigen,
    string $observacion
  ): void {
    $sqlConOrigenCompleto = 'INSERT INTO AlbaranesVentasLin (
         Empresa, Tipo, Albaran, Articulo, Descripcion, Cantidad, Precio, PjeDto, Importe, PjeIva, PjeRec,
         RebajeStock, PrecioMedio, Plato, Cocina, PrecioMenu, PrecioAlterado, PrecioHabitacion,
         PrecioTarifa, Ean, UnidadesPaquete, LoteVenta, AlbaranOrigenAbono, ObservacionDevolucion, NroLinOrigen
       ) VALUES (
         :e, :t, :a, :articulo, :descripcion, :cantidad, :precio, :pjeDto, :importe, :pjeIva, 0,
         :rebajeStock, :precioMedio, 0, 0, 0, :precioAlterado, 0,
         :precioTarifa, :ean, :unidadesPaquete, :loteVenta, :albaranOrigenAbono, :observacionDevolucion, :nroLinOrigen
       )';
    $sqlConOrigen = 'INSERT INTO AlbaranesVentasLin (
         Empresa, Tipo, Albaran, Articulo, Descripcion, Cantidad, Precio, PjeDto, Importe, PjeIva, PjeRec,
         RebajeStock, PrecioMedio, Plato, Cocina, PrecioMenu, PrecioAlterado, PrecioHabitacion,
         PrecioTarifa, Ean, UnidadesPaquete, LoteVenta, AlbaranOrigenAbono, ObservacionDevolucion
       ) VALUES (
         :e, :t, :a, :articulo, :descripcion, :cantidad, :precio, :pjeDto, :importe, :pjeIva, 0,
         :rebajeStock, :precioMedio, 0, 0, 0, :precioAlterado, 0,
         :precioTarifa, :ean, :unidadesPaquete, :loteVenta, :albaranOrigenAbono, :observacionDevolucion
       )';
    $sqlSinOrigen = 'INSERT INTO AlbaranesVentasLin (
         Empresa, Tipo, Albaran, Articulo, Descripcion, Cantidad, Precio, PjeDto, Importe, PjeIva, PjeRec,
         RebajeStock, PrecioMedio, Plato, Cocina, PrecioMenu, PrecioAlterado, PrecioHabitacion,
         PrecioTarifa, Ean, UnidadesPaquete, LoteVenta
       ) VALUES (
         :e, :t, :a, :articulo, :descripcion, :cantidad, :precio, :pjeDto, :importe, :pjeIva, 0,
         :rebajeStock, :precioMedio, 0, 0, 0, :precioAlterado, 0,
         :precioTarifa, :ean, :unidadesPaquete, :loteVenta
       )';

    $modoInsercion = 'completo';
    try {
      $ins = $this->pdo->prepare($sqlConOrigenCompleto);
    } catch (\Throwable $e) {
      try {
        $ins = $this->pdo->prepare($sqlConOrigen);
        $modoInsercion = 'origen';
      } catch (\Throwable $e2) {
        $ins = $this->pdo->prepare($sqlSinOrigen);
        $modoInsercion = 'basico';
      }
    }

    $metas = $this->metasArticulosLinea($lineas);
    foreach ($lineas as $lin) {
      $articulo = trim((string) ($lin['articulo'] ?? ''));
      if ($articulo === '') {
        continue;
      }
      $esComentario = strtoupper($articulo) === 'NO';
      if ($esComentario) {
        $cant = 0.0;
        $precio = 0.0;
        $pjeDto = 0.0;
        $importe = 0.0;
        $pjeIva = 0.0;
        $meta = ['precioMedio' => 0.0, 'precioTarifa' => 0.0];
        $precioTarifa = 0.0;
        $precioAlterado = 0;
        $rebajeStock = 0;
      } else {
        $cant = (float) ($lin['cantidad'] ?? 0);
        $precio = (float) ($lin['precio'] ?? 0);
        $pjeDto = (float) ($lin['pjeDto'] ?? 0);
        $importe = isset($lin['importe']) ? (float) $lin['importe'] : round($cant * $precio * (1 - $pjeDto / 100), 2);
        $meta = $this->metaDeLinea($metas, $articulo);
        $precioTarifa = $meta['precioTarifa'] > 0 ? $meta['precioTarifa'] : $precio;
        $precioAlterado = abs($precio - $precioTarifa) > 0.0001 ? 1 : 0;
        $pjeIva = (float) (($lin['pjeIva'] ?? 0) > 0 ? $lin['pjeIva'] : 21);
        $rebajeStock = 1;
      }
      $params = [
        'e' => $empresa,
        't' => $tipo,
        'a' => $albaran,
        'articulo' => $esComentario ? 'NO' : $articulo,
        'descripcion' => $this->blankIfEmpty($lin['descripcion'] ?? null),
        'cantidad' => $cant,
        'precio' => $precio,
        'pjeDto' => $pjeDto,
        'importe' => $importe,
        'pjeIva' => $pjeIva,
        'rebajeStock' => $rebajeStock,
        'precioMedio' => $meta['precioMedio'],
        'precioAlterado' => $precioAlterado,
        'precioTarifa' => $precioTarifa,
        'ean' => $this->blankIfEmpty($lin['ean'] ?? ($esComentario ? 'NO' : $articulo)),
        'unidadesPaquete' => $esComentario ? 0.0 : 1.0,
        'loteVenta' => $this->spaceIfEmpty($lin['loteVenta'] ?? null),
      ];
      if ($modoInsercion !== 'basico') {
        $params['albaranOrigenAbono'] = $albaranOrigen;
        $params['observacionDevolucion'] = $this->blankIfEmpty($observacion !== '' ? $observacion : null);
      }
      if ($modoInsercion === 'completo') {
        $nroLinOrigen = (int) ($lin['nroLinOrigen'] ?? 0);
        $params['nroLinOrigen'] = $nroLinOrigen > 0 ? $nroLinOrigen : null;
      }
      try {
        $ins->execute($params);
      } catch (\Throwable $e) {
        if ($modoInsercion === 'completo') {
          $modoInsercion = 'origen';
          $ins = $this->pdo->prepare($sqlConOrigen);
          unset($params['nroLinOrigen']);
          $ins->execute($params);
        } elseif ($modoInsercion === 'origen') {
          $modoInsercion = 'basico';
          $ins = $this->pdo->prepare($sqlSinOrigen);
          unset($params['albaranOrigenAbono'], $params['observacionDevolucion']);
          $ins->execute($params);
        } else {
          throw $e;
        }
      }
    }
  }

  /**
   * Vendedor del puesto para el alta de venta (sin exigir permiso de Mantenimiento).
   *
   * @return array{puesto: string, existe: bool, vendedor: ?string, vendedorNombre: ?string}
   */
  public function datosPuesto(string $puesto): array
  {
    $puesto = trim($puesto);
    $existe = $puesto !== '' && $this->puestoExiste($puesto);
    $vendedor = $this->vendedorDelPuesto($puesto);
    return [
      'puesto' => $puesto,
      'existe' => $existe,
      'vendedor' => $vendedor,
      'vendedorNombre' => $vendedor !== null ? $this->nombreVendedor($vendedor) : null,
    ];
  }

  private function puestoExiste(string $puesto): bool
  {
    try {
      $stmt = $this->pdo->prepare('SELECT 1 FROM Puestos WHERE RTRIM(Puesto) = :p');
      $stmt->execute(['p' => $puesto]);
      return (bool) $stmt->fetchColumn();
    } catch (\Throwable $e) {
      return false;
    }
  }

  /**
   * Reserva el siguiente albaran de venta desde Empresas_Ges.UltAlbaranVen (contador tienda)
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
        'SELECT UltAlbaranVen, Almacen FROM Empresas_Ges WITH (UPDLOCK, ROWLOCK) WHERE Codigo = :e'
      );
      $stmt->execute(['e' => $empresa]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      if ($row === false) {
        throw new \RuntimeException('Tienda no encontrada', 404);
      }

      $albaran = (int) ($row['UltAlbaranVen'] ?? 0) + 1;
      $this->pdo->prepare(
        'UPDATE Empresas_Ges SET UltAlbaranVen = :n WHERE Codigo = :e'
      )->execute(['n' => $albaran, 'e' => $empresa]);

      $puestoLimpio = $puesto !== null ? trim($puesto) : '';
      $vendedor = $this->vendedorDelPuesto($puestoLimpio);

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

  private function vendedorDelPuesto(string $puesto): ?string
  {
    $puesto = trim($puesto);
    if ($puesto === '' || $puesto === '99') {
      return $this->vendedorDelUsuarioSesion();
    }

    try {
      $stmt = $this->pdo->prepare(
        'SELECT RTRIM(Trabajador) FROM Puestos WHERE RTRIM(Puesto) = :puesto'
      );
      $stmt->execute(['puesto' => $puesto]);
      $vendedor = $stmt->fetchColumn();
      $codigo = $vendedor !== false ? trim((string) $vendedor) : '';
      if ($codigo !== '') {
        return $codigo;
      }
    } catch (\Throwable $e) {
      // Compatibilidad con instalaciones pendientes de la columna Trabajador.
    }

    return $this->vendedorDelUsuarioSesion();
  }

  /** Si el puesto no tiene vendedor, el del usuario que ha iniciado sesión. */
  private function vendedorDelUsuarioSesion(): ?string
  {
    $usuario = trim((string) ($_SESSION['usuario']['codigo'] ?? ''));
    if ($usuario === '') {
      return null;
    }
    try {
      $stmt = $this->pdo->prepare(
        'SELECT TOP 1 RTRIM(Codigo) FROM Vendedores
         WHERE RTRIM(Usuario) = :u AND ISNULL(Baja, 0) = 0
         ORDER BY CASE WHEN RTRIM(Codigo) = :mismo THEN 0 ELSE 1 END, Codigo'
      );
      $stmt->execute(['u' => $usuario, 'mismo' => $usuario]);
      $codigo = trim((string) ($stmt->fetchColumn() ?: ''));
      return $codigo !== '' ? $codigo : null;
    } catch (\Throwable $e) {
      return null;
    }
  }

  private function nombreVendedor(string $codigo): ?string
  {
    $codigo = trim($codigo);
    if ($codigo === '') {
      return null;
    }
    try {
      $stmt = $this->pdo->prepare(
        'SELECT RTRIM(Nombre) FROM Vendedores WHERE RTRIM(Codigo) = :c'
      );
      $stmt->execute(['c' => $codigo]);
      $nombre = $stmt->fetchColumn();
      $s = $nombre !== false ? trim((string) $nombre) : '';
      return $s !== '' ? $s : null;
    } catch (\Throwable $e) {
      return null;
    }
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

    $metas = $this->metasArticulosLinea($lineas);
    foreach ($lineas as $lin) {
      $articulo = trim((string) ($lin['articulo'] ?? ''));
      if ($articulo === '') {
        continue;
      }
      // Legacy: Articulo "NO" = línea de comentario (sin importe ni stock).
      $esComentario = strtoupper($articulo) === 'NO';
      if ($esComentario) {
        $cant = 0.0;
        $precio = 0.0;
        $pjeDto = 0.0;
        $importe = 0.0;
        $pjeIva = 0.0;
        $meta = ['precioMedio' => 0.0, 'precioTarifa' => 0.0];
        $precioTarifa = 0.0;
        $precioAlterado = 0;
      } else {
        $cant = (float) ($lin['cantidad'] ?? 0);
        $precio = (float) ($lin['precio'] ?? 0);
        $pjeDto = (float) ($lin['pjeDto'] ?? 0);
        $importe = isset($lin['importe']) ? (float) $lin['importe'] : round($cant * $precio * (1 - $pjeDto / 100), 2);
        $meta = $this->metaDeLinea($metas, $articulo);
        $precioTarifa = isset($lin['precioTarifa']) && $lin['precioTarifa'] !== '' && $lin['precioTarifa'] !== null
          ? (float) $lin['precioTarifa']
          : ($meta['precioTarifa'] > 0 ? $meta['precioTarifa'] : $precio);
        $precioAlterado = array_key_exists('precioAlterado', $lin)
          ? (!empty($lin['precioAlterado']) ? 1 : 0)
          : (abs($precio - $precioTarifa) > 0.0001 ? 1 : 0);
        $pjeIva = (float) (($lin['pjeIva'] ?? 0) > 0 ? $lin['pjeIva'] : 21);
      }
      $ins->execute([
        'e' => $empresa,
        't' => $tipo,
        'a' => $albaran,
        'articulo' => $esComentario ? 'NO' : $articulo,
        'descripcion' => $this->blankIfEmpty($lin['descripcion'] ?? null),
        'cantidad' => $cant,
        'precio' => $precio,
        'pjeDto' => $pjeDto,
        'importe' => $importe,
        'pjeIva' => $pjeIva,
        'precioMedio' => $meta['precioMedio'],
        'precioAlterado' => $precioAlterado,
        'precioTarifa' => $precioTarifa,
        'ean' => $this->blankIfEmpty($lin['ean'] ?? ($esComentario ? 'NO' : $articulo)),
        'unidadesPaquete' => $esComentario ? 0.0 : (
          isset($lin['unidadesPaquete']) && $lin['unidadesPaquete'] !== '' && $lin['unidadesPaquete'] !== null
            ? (float) $lin['unidadesPaquete'] : 1.0
        ),
        'loteVenta' => $this->spaceIfEmpty($lin['loteVenta'] ?? null),
      ]);
    }
  }

  /**
   * @param list<array<string, mixed>> $lineas
   * @return array<string, array{precioMedio: float, precioTarifa: float}>|null
   */
  private function metasArticulosLinea(array $lineas): ?array
  {
    $codigos = [];
    foreach ($lineas as $lin) {
      $articulo = trim((string) ($lin['articulo'] ?? ''));
      if ($articulo !== '' && strtoupper($articulo) !== 'NO') {
        $codigos[$articulo] = $articulo;
      }
    }
    $map = [];
    foreach ($codigos as $codigo) {
      $map[$codigo] = ['precioMedio' => 0.0, 'precioTarifa' => 0.0];
    }
    if ($map === []) {
      return $map;
    }

    try {
      foreach (array_chunk(array_keys($map), 400) as $chunk) {
        $placeholders = [];
        $params = [];
        foreach ($chunk as $i => $codigo) {
          $clave = 'c' . $i;
          $placeholders[] = ':' . $clave;
          $params[$clave] = $codigo;
        }
        $st = $this->pdo->prepare(
          'SELECT Codigo, PrecioMedio, PrecioVen1 FROM Articulos
           WHERE Codigo IN (' . implode(', ', $placeholders) . ')'
        );
        $st->execute($params);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
          $claveFila = rtrim((string) ($row['Codigo'] ?? ''));
          foreach ($chunk as $codigo) {
            if (rtrim($codigo) === $claveFila) {
              $map[$codigo] = [
                'precioMedio' => (float) ($row['PrecioMedio'] ?? 0),
                'precioTarifa' => (float) ($row['PrecioVen1'] ?? 0),
              ];
            }
          }
        }
      }
    } catch (\Throwable $e) {
      return null;
    }

    return $map;
  }

  /**
   * @param array<string, array{precioMedio: float, precioTarifa: float}>|null $metas
   * @return array{precioMedio: float, precioTarifa: float}
   */
  private function metaDeLinea(?array $metas, string $articulo): array
  {
    if ($metas === null) {
      return $this->metaArticuloLinea($articulo);
    }
    return $metas[$articulo] ?? ['precioMedio' => 0.0, 'precioTarifa' => 0.0];
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
   * Si Empresas_Ges.SW_IVA (yIVA): el Precio de linea es PVP con IVA incluido
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
      $art = trim((string) ($lin['articulo'] ?? ''));
      // Legacy: "NO" = comentario, no entra en importes.
      if ($art === '' || strtoupper($art) === 'NO') {
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

    // Vale de fidelización: descuento fijo repartido proporcionalmente entre
    // los grupos de IVA. Así reduce bases/cuotas correctamente sin ser pago.
    $dtoFidelizacionSolicitado = round(max(0, (float) ($body['importeDtoFidelizacion'] ?? 0)), 2);
    $dtoFidelizacion = 0.0;
    $totalAntesFidelizacion = round(array_sum($bases) + array_sum($ivas), 2);
    if ($dtoFidelizacionSolicitado > 0 && $totalAntesFidelizacion > 0) {
      $objetivo = min($dtoFidelizacionSolicitado, $totalAntesFidelizacion);
      $restante = $objetivo;
      $indices = [];
      for ($j = 0; $j < 4; $j++) {
        if (round($bases[$j] + $ivas[$j], 2) > 0) {
          $indices[] = $j;
        }
      }
      foreach ($indices as $pos => $j) {
        $brutoGrupo = round($bases[$j] + $ivas[$j], 2);
        $ultimo = $pos === count($indices) - 1;
        $parte = $ultimo
          ? $restante
          : round($objetivo * $brutoGrupo / $totalAntesFidelizacion, 2);
        $parte = min($parte, $brutoGrupo);
        $nuevoBruto = round($brutoGrupo - $parte, 2);
        $nuevaBase = $pjes[$j] > 0
          ? round($nuevoBruto / (1 + $pjes[$j] / 100), 2)
          : $nuevoBruto;
        $bases[$j] = $nuevaBase;
        $ivas[$j] = round($nuevoBruto - $nuevaBase, 2);
        $restante = round($restante - $parte, 2);
      }
      $importe = round(array_sum($bases) + array_sum($ivas), 2);
      $ivaTotal = round(array_sum($ivas), 2);
      $dtoFidelizacion = round($totalAntesFidelizacion - $importe, 2);
    }

    return [
      'bruto' => round($bruto, 2),
      'descuento' => round($importeDtosCab, 2),
      'descuentoFidelizacion' => $dtoFidelizacion,
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

  /** Empresas_Ges.SW_IVA = yIVA legacy (precios de venta con IVA incluido). */
  private function empresaPreciosIvaIncluido(string $empresa): bool
  {
    try {
      $stmt = $this->pdo->prepare('SELECT SW_IVA FROM Empresas_Ges WHERE Codigo = :e');
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
      "SELECT [{$campo}] FROM Empresas_Ges WITH (UPDLOCK, ROWLOCK) WHERE Codigo = :e"
    );
    $stmt->execute(['e' => $empresa]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      throw new \RuntimeException('Tienda no encontrada', 404);
    }
    $n = (int) ($row[$campo] ?? 0) + 1;
    $this->pdo->prepare("UPDATE Empresas_Ges SET [{$campo}] = :n WHERE Codigo = :e")
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
    int $albaranTicketTransformado = 0,
    bool $forzarContado = false
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
    $contadoDiferida = !$forzarContado && $metaFpago['agrupacion'] === 3 && $metaFpago['cobroDeArqueo'];
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
    $irpf = $this->retencionIrpf->calcular($empresa, $cliente, [[
      'empresa' => $empresa,
      'tipo' => $tipo,
      'albaran' => $albaran,
    ]]);

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
        :pjeIrpf, :basIrpf, :impIrpf, :pagoACuenta, 0, :sujetoPasivo,
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
      'pjeIrpf' => $irpf['pjeRetIrpf'],
      'basIrpf' => $irpf['basRetIrpf'],
      'impIrpf' => $irpf['impRetIrpf'],
      'pagoACuenta' => $pagoACuenta,
      'sujetoPasivo' => $sujetoPasivo,
      'trasformacionTicket' => $albaranTicketTransformado > 0 ? 1 : 0,
      'albaranTicket' => $albaranTicketTransformado > 0 ? $albaranTicketTransformado : 0,
    ]);

    $recibos = $this->recibos->generar($empresa, $facturaTipo, $factura);

    return [
      'factura' => $factura,
      'facturaTipo' => $facturaTipo,
      'estado' => $estado,
      'recibos' => $recibos,
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
      "SELECT {$cols} FROM Empresas_Ges WITH (UPDLOCK, ROWLOCK) WHERE Codigo = :e"
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
    $this->pdo->prepare("UPDATE Empresas_Ges SET [{$campoUsar}] = :n WHERE Codigo = :e")
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
      $st = $this->pdo->prepare('SELECT FacturasRectificativas FROM Empresas_Ges WHERE Codigo = :e');
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
   * Contado = FormasPago.CobroDeArqueo. Sin ese flag, el cliente es de crédito.
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
      $st = $this->pdo->prepare('SELECT CobroDeArqueo FROM FormasPago WHERE Codigo = :c');
      $st->execute(['c' => $codigo]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      return $row !== false && !empty($row['CobroDeArqueo']);
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

  /** @param array<string, mixed> $cab */
  private function actualizarFechaUltimaVentaArticulos(
    string $empresa,
    string $tipo,
    int $albaran,
    array $cab
  ): void {
    $fecha = $this->normalizeFecha($cab['fecha'] ?? null);
    try {
      $this->pdo->prepare(
        'UPDATE a SET a.[FechaUltimaVenta] = CASE
           WHEN a.[FechaUltimaVenta] IS NULL
             OR CONVERT(date, a.[FechaUltimaVenta]) < CONVERT(date, :fecha, 120)
           THEN CONVERT(datetime, :fecha, 120)
           ELSE a.[FechaUltimaVenta]
         END
         FROM [Articulos] a
         WHERE RTRIM(a.[Codigo]) IN (
           SELECT DISTINCT RTRIM(l.[Articulo])
           FROM [AlbaranesVentasLin] l
           WHERE l.[Empresa] = :empresa AND l.[Tipo] = :tipo AND l.[Albaran] = :albaran
             AND RTRIM(ISNULL(l.[Articulo], \'\')) <> \'\'
             AND RTRIM(UPPER(l.[Articulo])) <> \'NO\'
         )'
      )->execute([
        'fecha' => $fecha,
        'empresa' => $empresa,
        'tipo' => $tipo,
        'albaran' => $albaran,
      ]);
    } catch (\Throwable $e) {
      // No bloquear cierre de venta si falla el maestro.
    }
  }
}
