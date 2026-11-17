<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Facturacion;

use DOMDocument;
use DOMElement;

/**
 * Facturae 3.2.2 sin firma. El cliente descarga el XML y lo firma aparte.
 */
final class FacturaeXmlService
{
  private const NS = 'http://www.facturae.gob.es/formato/Versiones/Facturaev3_2_2.xml';

  private ImpresionFacturasService $impresion;

  public function __construct(ImpresionFacturasService $impresion)
  {
    $this->impresion = $impresion;
  }

  /**
   * @return array{xml: string, filename: string}
   */
  public function generar(string $empresa, string $facturaTipo, int $factura): array
  {
    $empresa = trim($empresa);
    $facturaTipo = strtoupper(trim($facturaTipo));
    if ($empresa === '' || $facturaTipo === '' || $factura <= 0) {
      throw new \InvalidArgumentException('Factura no indicada');
    }

    $doc = $this->impresion->cargarCruda($empresa, $facturaTipo, $factura);
    if ($doc === null) {
      throw new \RuntimeException('Factura no encontrada', 404);
    }

    $cab = $doc['cab'];
    if (empty($cab['FacturaE'])) {
      throw new \InvalidArgumentException('El cliente no tiene Factura-e');
    }

    $centros = [
      '01' => trim((string) ($cab['OficinaContable'] ?? '')),
      '02' => trim((string) ($cab['OrganoGestor'] ?? '')),
      '03' => trim((string) ($cab['UnidadTramitadora'] ?? '')),
    ];
    foreach (['01' => 'oficina contable', '02' => 'organo gestor', '03' => 'unidad tramitadora'] as $rol => $nombre) {
      if ($centros[$rol] === '') {
        throw new \InvalidArgumentException('Falta ' . $nombre . ' en la ficha del cliente');
      }
    }
    $proponente = trim((string) ($cab['OrganoProponente'] ?? ''));
    if ($proponente !== '') {
      $centros['04'] = $proponente;
    }

    $lineas = $this->lineasFacturables($doc['lineas'], !empty($cab['EmpSwIva']));
    if ($lineas === []) {
      throw new \InvalidArgumentException('La factura no tiene lineas');
    }

    $emisor = $this->parte(
      (string) ($cab['EmpNif'] ?? ''),
      (string) (($cab['EmpNombreFiscal'] ?? '') !== '' ? $cab['EmpNombreFiscal'] : ($cab['EmpNombre'] ?? '')),
      (string) ($cab['EmpDireccion'] ?? ''),
      (string) ($cab['EmpCodigoPostal'] ?? ''),
      (string) ($cab['EmpPoblacion'] ?? ''),
      (string) ($cab['EmpProvincia'] ?? ''),
      (string) ($cab['EmpPais'] ?? ''),
      'emisor'
    );
    $receptor = $this->parte(
      (string) ($cab['NIF'] ?? ''),
      (string) ($cab['RazonSocial'] ?? ''),
      (string) ($cab['Direccion'] ?? ''),
      (string) ($cab['CodigoPostal'] ?? ''),
      (string) ($cab['Poblacion'] ?? ''),
      (string) ($cab['Provincia'] ?? ''),
      (string) ($cab['Pais'] ?? ''),
      'cliente',
      (string) ($cab['TratamientoFiscal'] ?? '')
    );

    $totales = $this->totales($lineas, $cab);
    $pagos = $this->pagos($cab, $doc['recibos'], $totales['executable']);

    $xml = $this->xml(
      $empresa,
      $facturaTipo,
      $factura,
      $this->fechaIso($cab['Fecha'] ?? null),
      $emisor,
      $receptor,
      $centros,
      $lineas,
      $totales,
      $pagos
    );

    return [
      'xml' => $xml,
      'filename' => 'facturae-' . $facturaTipo . $factura . '.xml',
    ];
  }

  /**
   * @param list<array<string, mixed>> $lineas
   * @return list<array{descripcion: string, cantidad: float, precio: float, dto: float, pjeIva: float, bruto: float, descuento: float, base: float}>
   */
  private function lineasFacturables(array $lineas, bool $ivaIncluido): array
  {
    $out = [];
    foreach ($lineas as $lin) {
      $cantidad = (float) ($lin['Cantidad'] ?? 0);
      $importe = (float) ($lin['Importe'] ?? 0);
      if (abs($cantidad) < 0.0000001 && abs($importe) < 0.0001) {
        continue;
      }
      $pjeIva = round((float) ($lin['PjeIva'] ?? 0), 2);
      $precio = (float) ($lin['Precio'] ?? 0);
      if ($ivaIncluido && $pjeIva > 0) {
        $precio = $precio / (1 + $pjeIva / 100);
      }
      if (abs($cantidad) < 0.0000001) {
        $cantidad = $importe < 0 ? -1.0 : 1.0;
        $precio = abs($importe);
        if ($ivaIncluido && $pjeIva > 0) {
          $precio = $precio / (1 + $pjeIva / 100);
        }
      }
      $bruto = round($cantidad * $precio, 2);
      $dto = round((float) ($lin['PjeDto'] ?? 0), 2);
      $descuento = round($bruto * $dto / 100, 2);
      $base = round($bruto - $descuento, 2);
      $descripcion = trim((string) ($lin['Descripcion'] ?? ''));
      if ($descripcion === '') {
        $descripcion = trim((string) ($lin['Articulo'] ?? ''));
      }
      if ($descripcion === '') {
        $descripcion = 'Linea';
      }
      $out[] = [
        'descripcion' => $descripcion,
        'cantidad' => $cantidad,
        'precio' => $precio,
        'dto' => $dto,
        'pjeIva' => $pjeIva,
        'bruto' => $bruto,
        'descuento' => $descuento,
        'base' => $base,
      ];
    }
    return $out;
  }

  /**
   * @param list<array{base: float, pjeIva: float, bruto: float, descuento: float}> $lineas
   * @param array<string, mixed> $cab
   * @return array{gross: float, descuento: float, base: float, cuota: float, irpf: float, total: float, executable: float, impuestos: list<array{pje: float, base: float, cuota: float, recargo: float, cuotaRecargo: float}>}
   */
  private function totales(array $lineas, array $cab): array
  {
    $gross = 0.0;
    $base = 0.0;
    /** @var array<string, array{pje: float, base: float}> $grupos */
    $grupos = [];
    foreach ($lineas as $lin) {
      $gross += $lin['bruto'];
      $base += $lin['base'];
      $clave = number_format($lin['pjeIva'], 2, '.', '');
      if (!isset($grupos[$clave])) {
        $grupos[$clave] = ['pje' => $lin['pjeIva'], 'base' => 0.0];
      }
      $grupos[$clave]['base'] += $lin['base'];
    }
    $gross = round($gross, 2);
    $base = round($base, 2);

    $baseCab = 0.0;
    /** @var array<string, array{recargo: float, cuotaRecargo: float}> $recargos */
    $recargos = [];
    for ($i = 1; $i <= 6; $i++) {
      $b = round((float) ($cab["ImporteBase{$i}"] ?? 0), 2);
      if (abs($b) < 0.0001) {
        continue;
      }
      $baseCab += $b;
      $pje = round((float) ($cab["PjeIva{$i}"] ?? 0), 2);
      $clave = number_format($pje, 2, '.', '');
      $recargos[$clave] = [
        'recargo' => round((float) ($cab["PjeRec{$i}"] ?? 0), 2),
        'cuotaRecargo' => round((float) ($cab["ImporteRec{$i}"] ?? 0), 2),
      ];
    }
    $baseCab = round($baseCab, 2);
    $dtoCab = round((float) ($cab['ImporteDtos'] ?? 0), 2);
    $descuentoGeneral = 0.0;
    if ($dtoCab > 0.004 && abs($gross - $dtoCab - $baseCab) <= 0.05) {
      $descuentoGeneral = $dtoCab;
    }

    $baseAntes = round($base - $descuentoGeneral, 2);
    $factor = abs($base) > 0.0001 && $descuentoGeneral > 0 ? $baseAntes / $base : 1.0;

    $impuestos = [];
    $cuota = 0.0;
    foreach ($grupos as $clave => $grupo) {
      $baseGrupo = round($grupo['base'] * $factor, 2);
      $cuotaGrupo = round($baseGrupo * $grupo['pje'] / 100, 2);
      $rec = $recargos[$clave] ?? ['recargo' => 0.0, 'cuotaRecargo' => 0.0];
      $cuotaRecargo = $rec['cuotaRecargo'];
      if (abs($cuotaRecargo) < 0.0001 && $rec['recargo'] > 0) {
        $cuotaRecargo = round($baseGrupo * $rec['recargo'] / 100, 2);
      }
      $cuota += $cuotaGrupo + $cuotaRecargo;
      $impuestos[] = [
        'pje' => $grupo['pje'],
        'base' => $baseGrupo,
        'cuota' => $cuotaGrupo,
        'recargo' => $rec['recargo'],
        'cuotaRecargo' => round($cuotaRecargo, 2),
      ];
    }
    $cuota = round($cuota, 2);
    $irpf = round((float) ($cab['ImpRetIrpf'] ?? 0), 2);
    $total = round($baseAntes + $cuota - $irpf, 2);
    $aCuenta = round((float) ($cab['PagoACuenta'] ?? 0), 2);
    $executable = round($total - $aCuenta, 2);

    return [
      'gross' => $gross,
      'descuento' => round($descuentoGeneral, 2),
      'base' => $baseAntes,
      'cuota' => $cuota,
      'irpf' => $irpf,
      'total' => $total,
      'executable' => $executable,
      'impuestos' => $impuestos,
      'pjeIrpf' => round((float) ($cab['PjeRetIrpf'] ?? 0), 2),
      'baseIrpf' => round((float) ($cab['BasRetIrpf'] ?? 0), 2),
    ];
  }

  /**
   * @param array<string, mixed> $cab
   * @param list<array<string, mixed>> $recibos
   * @return list<array{fecha: string, importe: float, medio: string, iban: string, adeudo: bool}>
   */
  private function pagos(array $cab, array $recibos, float $executable): array
  {
    $codigo = (int) ($cab['FacteFpCodigo'] ?? 0);
    if ($codigo < 1 || $codigo > 19) {
      if (!empty($cab['FacteTransferencia'])) {
        $codigo = 4;
      } elseif (!empty($cab['FacteReciboDom'])) {
        $codigo = 2;
      } else {
        $codigo = 1;
      }
    }
    $medio = sprintf('%02d', $codigo);
    $ibanAcreedor = strtoupper((string) preg_replace('/\s+/', '', (string) ($cab['FacteIban'] ?? '')));
    $ibanDeudor = strtoupper((string) preg_replace('/\s+/', '', (string) ($cab['IBAN'] ?? '')));
    if ($medio === '04' && $ibanAcreedor === '') {
      throw new \InvalidArgumentException(
        'La forma de pago es transferencia y no tiene IBAN de Factura-e'
      );
    }
    $iban = $medio === '02' ? $ibanDeudor : ($medio === '04' ? $ibanAcreedor : '');
    $adeudo = $medio === '02';

    $cuotas = [];
    foreach ($recibos as $recibo) {
      $importe = round((float) ($recibo['Importe'] ?? 0), 2);
      if (abs($importe) < 0.004) {
        continue;
      }
      $cuotas[] = [
        'fecha' => $this->fechaIso($recibo['Vencimiento'] ?? null),
        'importe' => $importe,
        'medio' => $medio,
        'iban' => $iban,
        'adeudo' => $adeudo,
      ];
    }
    $suma = 0.0;
    foreach ($cuotas as $cuota) {
      $suma += $cuota['importe'];
    }
    if ($cuotas === [] || abs($suma - $executable) > 0.05) {
      $cuotas = [[
        'fecha' => $this->fechaIso($cab['Fecha'] ?? null),
        'importe' => $executable,
        'medio' => $medio,
        'iban' => $iban,
        'adeudo' => $adeudo,
      ]];
    }
    return $cuotas;
  }

  /**
   * @return array{nif: string, nombre: string, direccion: string, cp: string, poblacion: string, provincia: string, pais: string, residencia: string, persona: string}
   */
  private function parte(
    string $nif,
    string $nombre,
    string $direccion,
    string $cp,
    string $poblacion,
    string $provincia,
    string $pais,
    string $quien,
    string $tratamiento = ''
  ): array {
    $nif = strtoupper((string) preg_replace('/[\s\-]/', '', $nif));
    $nombre = trim($nombre);
    $direccion = trim($direccion);
    $poblacion = trim($poblacion);
    $provincia = trim($provincia);
    $paisIso = $this->paisIso($pais);
    $cp = $this->codigoPostal($cp, $paisIso);
    if ($nif === '' || $nombre === '' || $direccion === '' || $cp === '' || $poblacion === '' || $provincia === '') {
      throw new \InvalidArgumentException(
        'Faltan NIF o direccion del ' . $quien . ' para Factura-e'
      );
    }
    $residencia = 'R';
    $trato = strtoupper(trim($tratamiento));
    if ($trato === 'I') {
      $residencia = 'U';
    } elseif ($trato === 'E') {
      $residencia = 'E';
    }
    if ($residencia === 'R' && !str_starts_with($nif, 'ES')) {
      $nif = 'ES' . $nif;
    }

    return [
      'nif' => $nif,
      'nombre' => $nombre,
      'direccion' => $direccion,
      'cp' => $cp,
      'poblacion' => $poblacion,
      'provincia' => $provincia,
      'pais' => $paisIso,
      'residencia' => $residencia,
      'persona' => $this->persona($nif),
    ];
  }

  /**
   * @param array<string, string> $centros
   * @param list<array{descripcion: string, cantidad: float, precio: float, dto: float, pjeIva: float, bruto: float, descuento: float, base: float}> $lineas
   * @param array{gross: float, descuento: float, base: float, cuota: float, irpf: float, total: float, executable: float, impuestos: list<array{pje: float, base: float, cuota: float, recargo: float, cuotaRecargo: float}>, pjeIrpf?: float, baseIrpf?: float} $totales
   * @param list<array{fecha: string, importe: float, medio: string, iban: string, adeudo: bool}> $pagos
   */
  private function xml(
    string $empresa,
    string $serie,
    int $numero,
    string $fecha,
    array $emisor,
    array $receptor,
    array $centros,
    array $lineas,
    array $totales,
    array $pagos
  ): string {
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = true;
    $root = $dom->createElementNS(self::NS, 'fe:Facturae');
    $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ds', 'http://www.w3.org/2000/09/xmldsig#');
    $dom->appendChild($root);

    $file = $this->el($dom, 'FileHeader');
    $file->appendChild($this->el($dom, 'SchemaVersion', '3.2.2'));
    $file->appendChild($this->el($dom, 'Modality', 'I'));
    $file->appendChild($this->el($dom, 'InvoiceIssuerType', 'EM'));
    $batch = $this->el($dom, 'Batch');
    $batchId = substr($empresa . $serie . $numero . $fecha, 0, 70);
    $batch->appendChild($this->el($dom, 'BatchIdentifier', $batchId));
    $batch->appendChild($this->el($dom, 'InvoicesCount', '1'));
    $batch->appendChild($this->importe($dom, 'TotalInvoicesAmount', $totales['total']));
    $batch->appendChild($this->importe($dom, 'TotalOutstandingAmount', $totales['executable']));
    $batch->appendChild($this->importe($dom, 'TotalExecutableAmount', $totales['executable']));
    $batch->appendChild($this->el($dom, 'InvoiceCurrencyCode', 'EUR'));
    $file->appendChild($batch);
    $root->appendChild($file);

    $parties = $this->el($dom, 'Parties');
    $parties->appendChild($this->party($dom, 'SellerParty', $emisor, []));
    $parties->appendChild($this->party($dom, 'BuyerParty', $receptor, $centros));
    $root->appendChild($parties);

    $invoices = $this->el($dom, 'Invoices');
    $invoice = $this->el($dom, 'Invoice');
    $header = $this->el($dom, 'InvoiceHeader');
    $header->appendChild($this->el($dom, 'InvoiceNumber', (string) $numero));
    $header->appendChild($this->el($dom, 'InvoiceSeriesCode', $serie));
    $header->appendChild($this->el($dom, 'InvoiceDocumentType', 'FC'));
    $header->appendChild($this->el($dom, 'InvoiceClass', 'OO'));
    $invoice->appendChild($header);

    $issue = $this->el($dom, 'InvoiceIssueData');
    $issue->appendChild($this->el($dom, 'IssueDate', $fecha));
    $issue->appendChild($this->el($dom, 'InvoiceCurrencyCode', 'EUR'));
    $issue->appendChild($this->el($dom, 'TaxCurrencyCode', 'EUR'));
    $issue->appendChild($this->el($dom, 'LanguageName', 'es'));
    $invoice->appendChild($issue);

    $invoice->appendChild($this->taxes($dom, 'TaxesOutputs', $totales['impuestos']));
    $pjeIrpf = (float) ($totales['pjeIrpf'] ?? 0);
    $irpf = (float) $totales['irpf'];
    if (abs($irpf) > 0.004) {
      $invoice->appendChild($this->taxes($dom, 'TaxesWithheld', [[
        'pje' => $pjeIrpf,
        'base' => (float) ($totales['baseIrpf'] ?? $totales['base']),
        'cuota' => $irpf,
        'recargo' => 0.0,
        'cuotaRecargo' => 0.0,
        'tipo' => '04',
      ]]));
    }

    $invoiceTotals = $this->el($dom, 'InvoiceTotals');
    $invoiceTotals->appendChild($this->el($dom, 'TotalGrossAmount', $this->money($totales['gross'])));
    $invoiceTotals->appendChild($this->el($dom, 'TotalGeneralDiscounts', $this->money($totales['descuento'])));
    $invoiceTotals->appendChild($this->el($dom, 'TotalGeneralSurcharges', '0.00'));
    $invoiceTotals->appendChild($this->el($dom, 'TotalGrossAmountBeforeTaxes', $this->money($totales['base'])));
    $invoiceTotals->appendChild($this->el($dom, 'TotalTaxOutputs', $this->money($totales['cuota'])));
    $invoiceTotals->appendChild($this->el($dom, 'TotalTaxesWithheld', $this->money($irpf)));
    $invoiceTotals->appendChild($this->el($dom, 'InvoiceTotal', $this->money($totales['total'])));
    $invoiceTotals->appendChild($this->el($dom, 'TotalOutstandingAmount', $this->money($totales['executable'])));
    $invoiceTotals->appendChild($this->el($dom, 'TotalExecutableAmount', $this->money($totales['executable'])));
    $invoice->appendChild($invoiceTotals);

    $items = $this->el($dom, 'Items');
    foreach ($lineas as $lin) {
      $items->appendChild($this->linea($dom, $lin));
    }
    $invoice->appendChild($items);

    $payment = $this->el($dom, 'PaymentDetails');
    foreach ($pagos as $pago) {
      $inst = $this->el($dom, 'Installment');
      $inst->appendChild($this->el($dom, 'InstallmentDueDate', $pago['fecha']));
      $inst->appendChild($this->el($dom, 'InstallmentAmount', $this->money($pago['importe'])));
      $inst->appendChild($this->el($dom, 'PaymentMeans', $pago['medio']));
      if ($pago['iban'] !== '') {
        $cuenta = $this->el($dom, $pago['adeudo'] ? 'AccountToBeDebited' : 'AccountToBeCredited');
        $cuenta->appendChild($this->el($dom, 'IBAN', $pago['iban']));
        $inst->appendChild($cuenta);
      }
      $payment->appendChild($inst);
    }
    $invoice->appendChild($payment);
    $invoices->appendChild($invoice);
    $root->appendChild($invoices);

    $xml = $dom->saveXML();
    if ($xml === false) {
      throw new \RuntimeException('No se pudo generar el XML');
    }
    return $xml;
  }

  /** @param array{nif: string, nombre: string, direccion: string, cp: string, poblacion: string, provincia: string, pais: string, residencia: string, persona: string} $parte
   * @param array<string, string> $centros
   */
  private function party(DOMDocument $dom, string $nombre, array $parte, array $centros): DOMElement
  {
    $party = $this->el($dom, $nombre);
    $tax = $this->el($dom, 'TaxIdentification');
    $tax->appendChild($this->el($dom, 'PersonTypeCode', $parte['persona']));
    $tax->appendChild($this->el($dom, 'ResidenceTypeCode', $parte['residencia']));
    $tax->appendChild($this->el($dom, 'TaxIdentificationNumber', $parte['nif']));
    $party->appendChild($tax);

    if ($centros !== []) {
      $admin = $this->el($dom, 'AdministrativeCentres');
      foreach ($centros as $rol => $codigo) {
        $centre = $this->el($dom, 'AdministrativeCentre');
        $centre->appendChild($this->el($dom, 'CentreCode', $codigo));
        $centre->appendChild($this->el($dom, 'RoleTypeCode', $rol));
        $centre->appendChild($this->el($dom, 'Name', $this->corte($parte['nombre'], 40)));
        $centre->appendChild($this->direccion($dom, $parte));
        $admin->appendChild($centre);
      }
      $party->appendChild($admin);
    }

    if ($parte['persona'] === 'F') {
      $partesNombre = preg_split('/\s+/u', trim($parte['nombre'])) ?: [];
      $apellido = count($partesNombre) > 1 ? (string) array_pop($partesNombre) : '-';
      $nombre = trim(implode(' ', $partesNombre));
      if ($nombre === '') {
        $nombre = $parte['nombre'];
      }
      $persona = $this->el($dom, 'Individual');
      $persona->appendChild($this->el($dom, 'Name', $this->corte($nombre, 40)));
      $persona->appendChild($this->el($dom, 'FirstSurname', $this->corte($apellido, 40)));
      $persona->appendChild($this->direccion($dom, $parte));
      $party->appendChild($persona);
    } else {
      $legal = $this->el($dom, 'LegalEntity');
      $legal->appendChild($this->el($dom, 'CorporateName', $this->corte($parte['nombre'], 80)));
      $legal->appendChild($this->direccion($dom, $parte));
      $party->appendChild($legal);
    }
    return $party;
  }

  /** @param array{direccion: string, cp: string, poblacion: string, provincia: string, pais: string} $parte */
  private function direccion(DOMDocument $dom, array $parte): DOMElement
  {
    $nodo = $parte['pais'] === 'ESP' ? 'AddressInSpain' : 'OverseasAddress';
    $dir = $this->el($dom, $nodo);
    $dir->appendChild($this->el($dom, 'Address', $this->corte($parte['direccion'], 80)));
    $dir->appendChild($this->el($dom, 'PostCode', $parte['cp']));
    $dir->appendChild($this->el($dom, 'Town', $this->corte($parte['poblacion'], 50)));
    $dir->appendChild($this->el($dom, 'Province', $this->corte($parte['provincia'], 20)));
    $dir->appendChild($this->el($dom, 'CountryCode', $parte['pais']));
    return $dir;
  }

  /**
   * @param list<array{pje: float, base: float, cuota: float, recargo: float, cuotaRecargo: float, tipo?: string}> $impuestos
   */
  private function taxes(DOMDocument $dom, string $nombre, array $impuestos): DOMElement
  {
    $nodo = $this->el($dom, $nombre);
    foreach ($impuestos as $imp) {
      $tax = $this->el($dom, 'Tax');
      $tax->appendChild($this->el($dom, 'TaxTypeCode', $imp['tipo'] ?? '01'));
      $tax->appendChild($this->el($dom, 'TaxRate', $this->money($imp['pje'])));
      $tax->appendChild($this->importe($dom, 'TaxableBase', $imp['base']));
      $tax->appendChild($this->importe($dom, 'TaxAmount', $imp['cuota']));
      if (($imp['tipo'] ?? '01') === '01' && abs($imp['cuotaRecargo']) > 0.004) {
        $tax->appendChild($this->el($dom, 'EquivalenceSurcharge', $this->money($imp['recargo'])));
        $tax->appendChild($this->importe($dom, 'EquivalenceSurchargeAmount', $imp['cuotaRecargo']));
      }
      $nodo->appendChild($tax);
    }
    return $nodo;
  }

  /** @param array{descripcion: string, cantidad: float, precio: float, dto: float, pjeIva: float, bruto: float, descuento: float, base: float} $lin */
  private function linea(DOMDocument $dom, array $lin): DOMElement
  {
    $nodo = $this->el($dom, 'InvoiceLine');
    $nodo->appendChild($this->el($dom, 'ItemDescription', $this->corte($lin['descripcion'], 2500)));
    $nodo->appendChild($this->el($dom, 'Quantity', $this->cantidad($lin['cantidad'])));
    $nodo->appendChild($this->el($dom, 'UnitOfMeasure', '01'));
    $nodo->appendChild($this->el($dom, 'UnitPriceWithoutTax', $this->precio($lin['precio'])));
    $nodo->appendChild($this->el($dom, 'TotalCost', $this->money($lin['bruto'])));
    if (abs($lin['descuento']) > 0.004) {
      $discounts = $this->el($dom, 'DiscountsAndRebates');
      $discount = $this->el($dom, 'Discount');
      $discount->appendChild($this->el($dom, 'DiscountReason', 'Descuento'));
      $discount->appendChild($this->el($dom, 'DiscountRate', $this->money($lin['dto'])));
      $discount->appendChild($this->el($dom, 'DiscountAmount', $this->money($lin['descuento'])));
      $discounts->appendChild($discount);
      $nodo->appendChild($discounts);
    }
    $nodo->appendChild($this->el($dom, 'GrossAmount', $this->money($lin['base'])));
    $cuota = round($lin['base'] * $lin['pjeIva'] / 100, 2);
    $nodo->appendChild($this->taxes($dom, 'TaxesOutputs', [[
      'pje' => $lin['pjeIva'],
      'base' => $lin['base'],
      'cuota' => $cuota,
      'recargo' => 0.0,
      'cuotaRecargo' => 0.0,
    ]]));
    return $nodo;
  }

  private function importe(DOMDocument $dom, string $nombre, float $valor): DOMElement
  {
    $nodo = $this->el($dom, $nombre);
    $nodo->appendChild($this->el($dom, 'TotalAmount', $this->money($valor)));
    return $nodo;
  }

  private function el(DOMDocument $dom, string $nombre, ?string $texto = null): DOMElement
  {
    // El XSD oficial no marca los hijos como qualified: solo la raíz lleva el
    // espacio de nombres. Con el prefijo fe: en cada etiqueta el validador no ve la factura.
    $nodo = $dom->createElement($nombre);
    if ($texto !== null) {
      $nodo->appendChild($dom->createTextNode($texto));
    }
    return $nodo;
  }

  private function money(float $valor): string
  {
    return number_format(round($valor, 2), 2, '.', '');
  }

  private function precio(float $valor): string
  {
    return number_format($valor, 6, '.', '');
  }

  private function cantidad(float $valor): string
  {
    $texto = number_format($valor, 6, '.', '');
    $texto = rtrim(rtrim($texto, '0'), '.');
    return $texto === '' || $texto === '-' ? '0' : $texto;
  }

  private function corte(string $texto, int $max): string
  {
    $texto = trim(preg_replace('/\s+/u', ' ', $texto) ?? $texto);
    if (function_exists('mb_substr')) {
      return mb_substr($texto, 0, $max);
    }
    return substr($texto, 0, $max);
  }

  private function fechaIso(mixed $fecha): string
  {
    if ($fecha instanceof \DateTimeInterface) {
      return $fecha->format('Y-m-d');
    }
    $texto = trim((string) $fecha);
    if ($texto === '') {
      return date('Y-m-d');
    }
    $ts = strtotime($texto);
    return $ts === false ? date('Y-m-d') : date('Y-m-d', $ts);
  }

  private function codigoPostal(string $cp, string $pais): string
  {
    $cp = strtoupper(trim($cp));
    if ($pais === 'ESP') {
      if (preg_match('/(\d{5})/', $cp, $m)) {
        return $m[1];
      }
      return '';
    }
    return substr($cp, 0, 10);
  }

  private function paisIso(string $pais): string
  {
    $p = strtoupper(trim($pais));
    $p = strtr($p, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U']);
    $mapa = [
      '' => 'ESP',
      'ES' => 'ESP',
      'ESP' => 'ESP',
      'ESPANA' => 'ESP',
      'SPAIN' => 'ESP',
      'FR' => 'FRA',
      'FRA' => 'FRA',
      'FRANCIA' => 'FRA',
      'FRANCE' => 'FRA',
      'PT' => 'PRT',
      'PRT' => 'PRT',
      'PORTUGAL' => 'PRT',
      'DE' => 'DEU',
      'DEU' => 'DEU',
      'ALEMANIA' => 'DEU',
      'GERMANY' => 'DEU',
      'IT' => 'ITA',
      'ITA' => 'ITA',
      'ITALIA' => 'ITA',
      'ITALY' => 'ITA',
      'GB' => 'GBR',
      'UK' => 'GBR',
      'GBR' => 'GBR',
      'REINO UNIDO' => 'GBR',
      'BE' => 'BEL',
      'BEL' => 'BEL',
      'BELGICA' => 'BEL',
      'NL' => 'NLD',
      'NLD' => 'NLD',
      'PAISES BAJOS' => 'NLD',
      'HOLANDA' => 'NLD',
      'AD' => 'AND',
      'ANDORRA' => 'AND',
    ];
    if (isset($mapa[$p])) {
      return $mapa[$p];
    }
    if (preg_match('/^[A-Z]{3}$/', $p)) {
      return $p;
    }
    return 'ESP';
  }

  private function persona(string $nif): string
  {
    $cuerpo = str_starts_with($nif, 'ES') ? substr($nif, 2) : $nif;
    $inicial = substr($cuerpo, 0, 1);
    $sociedad = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'J', 'N', 'P', 'Q', 'R', 'S', 'U', 'V', 'W'];
    return in_array($inicial, $sociedad, true) ? 'J' : 'F';
  }
}
