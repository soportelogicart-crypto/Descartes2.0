import type { DocumentoPreviewDatos } from '@/config/documentos-plantillas/preview-datos'
import {
  cargarPuesto,
  cargarTienda,
  extrasEmpresaParaDocumento,
  imprimirA4Html,
  prepararImpresionA4Puesto,
  type MetaImpresionA4Puesto,
  type PrepImpresionA4,
} from '@/composables/impresionDocumentoA4Shared'
import type { PedidoDetalle } from '@/types/ventas'

function fmtFecha(iso: string | null | undefined): string {
  if (!iso) return ''
  const d = String(iso).slice(0, 10)
  const [y, m, day] = d.split('-')
  if (!y || !m || !day) return String(iso)
  return `${day}/${m}/${y}`
}

function redondear2(n: number): number {
  return Math.round((Number(n) || 0) * 100) / 100
}

export function metaImpresionPedidoCliente(): MetaImpresionA4Puesto {
  return {
    plantillaTipo: 'pedido-cliente',
    formatoKey: 'formatoPedidos',
    nombreKey: 'impPedidos',
    indiceKey: 'impresoraPedidos',
    label: 'Pedido',
  }
}

export function pedidoClienteAPreviewDatos(
  ped: PedidoDetalle,
  extras: {
    empresaNombre?: string
    empresaNif?: string
    empresaDireccion?: string
    empresaCp?: string
    empresaPoblacion?: string
    empresaProvincia?: string
    empresaTelefono?: string
    empresaEmail?: string
    emblemaUrl?: string
  } = {}
): DocumentoPreviewDatos {
  const lineas = (ped.lineas ?? [])
    .filter((l) => {
      const art = String(l.articulo ?? '').trim()
      return art !== '' && art.toUpperCase() !== 'NO'
    })
    .map((l) => ({
      articulo: String(l.articulo ?? ''),
      descripcion: String(l.descripcion ?? ''),
      unidades: Number(l.cantidadPedida) || 0,
      precio: Number(l.precio) || 0,
      dto: Number(l.pjeDto) || 0,
      pjeIva: Number(l.pjeIva) || 0,
      importe: redondear2(Number(l.importe) || 0),
    }))

  const base = redondear2(Number(ped.importeBase1) || Number(ped.importe) || 0)
  const cuota = redondear2(Number(ped.importeIva1) || 0)
  const pje = Number(ped.pjeIva1) || 0

  return {
    empresa: {
      nombre: extras.empresaNombre || ped.empresa,
      razonSocial: extras.empresaNombre || ped.empresa,
      nif: extras.empresaNif || '',
      direccion: extras.empresaDireccion || '',
      cp: extras.empresaCp || '',
      poblacion: extras.empresaPoblacion || '',
      provincia: extras.empresaProvincia || '',
      telefono: extras.empresaTelefono || '',
      fax: '',
      email: extras.empresaEmail || '',
      emblemaUrl: extras.emblemaUrl || '',
      banco: '',
      iban: '',
      swift: '',
    },
    cliente: {
      codigo: String(ped.cliente ?? ''),
      nombre: String(ped.razonSocial ?? ''),
      direccion: String(ped.direccionEnvio ?? ''),
      cp: String(ped.codigoPostalEnvio ?? ''),
      poblacion: String(ped.poblacionEnvio ?? ''),
      provincia: String(ped.provinciaEnvio ?? ''),
      pais: String(ped.paisEnvio ?? ''),
      cif: String(ped.nif ?? ''),
      telefono: '',
      cuentaBancaria: '',
      iban: '',
      swift: '',
    },
    documento: {
      numero: String(ped.pedido),
      serie: '',
      fecha: fmtFecha(ped.fecha),
      suPedido: String(ped.suPedido ?? ''),
      albaran: '',
      terminalSesion: '',
      atendidoPor: String(ped.vendedor ?? ''),
      fechaEntrega: fmtFecha(ped.fechaPrevista),
      transportista: String(ped.transporte ?? ''),
      portes: '',
      observaciones: String(ped.observaciones ?? ''),
      formaPago: '',
      codigoBarras: '',
      pagina: '1/1',
    },
    lineas,
    totales: {
      base,
      ivas: cuota || pje ? [{ pje, base, cuota }] : [],
      importe: redondear2(Number(ped.importe) || 0),
    },
    vencimientos: [],
    verifactu: { qrPayload: '', url: '' },
    tienda: {
      literalFacturaDiferida: '',
      literalFacturaContado: '',
      literalPresupuesto: '',
      literalVale: '',
      literalTicket: 0,
    },
    puesto: { literales: [] },
  }
}

export async function prepararImpresionPedidoCliente(
  ped: PedidoDetalle,
  opciones: { puestoCodigo: string }
): Promise<PrepImpresionA4> {
  const puestoCodigo = String(opciones.puestoCodigo || '').trim()
  if (!puestoCodigo) {
    throw new Error('Configure el puesto de este equipo para imprimir.')
  }
  const empresa = String(ped.empresa || '').trim()
  const [puesto, tienda] = await Promise.all([cargarPuesto(puestoCodigo), cargarTienda(empresa)])
  const extras = await extrasEmpresaParaDocumento(tienda, puesto, empresa)
  const meta = metaImpresionPedidoCliente()
  return prepararImpresionA4Puesto({
    empresa,
    puestoCodigo,
    meta,
    datos: pedidoClienteAPreviewDatos(ped, extras),
    titulo: `${meta.label} · ${empresa}-${ped.pedido}`,
  })
}

export async function imprimirA4PedidoCliente(
  prep: PrepImpresionA4,
  html: string
): Promise<string> {
  return imprimirA4Html(prep, html)
}
