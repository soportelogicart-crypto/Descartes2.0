/**
 * Impresión A4 de documentos de Compras (004 US6 / T039).
 * Usa plantilla + impresora Generales II del puesto (no tickets térmicos).
 */
import {
  cargarPuesto,
  cargarTienda,
  extrasEmpresaParaDocumento,
  imprimirA4Html,
  prepararImpresionA4Puesto,
  type PrepImpresionA4,
} from '@/composables/impresionDocumentoA4Shared'
import {
  albaranCompraAPreviewDatos,
  metaImpresionAlbaranCompra,
  metaImpresionPedidoCompra,
  pedidoProveedorAPreviewDatos,
} from '@/composables/compraDocumentoPreview'
import type { AlbaranCompraDetalle, PedidoProveedorDetalle } from '@/types/compras'

export type { PrepImpresionA4 }

export async function prepararImpresionAlbaranCompra(
  alb: AlbaranCompraDetalle,
  opciones: { puestoCodigo: string }
): Promise<PrepImpresionA4> {
  const puestoCodigo = String(opciones.puestoCodigo || '').trim()
  if (!puestoCodigo) {
    throw new Error('Configure el puesto de este equipo para imprimir.')
  }
  const empresa = String(alb.empresa || '').trim()
  const [puesto, tienda] = await Promise.all([
    cargarPuesto(puestoCodigo),
    cargarTienda(empresa),
  ])
  const extras = await extrasEmpresaParaDocumento(tienda, puesto, empresa)
  const meta = metaImpresionAlbaranCompra()
  const datos = albaranCompraAPreviewDatos(alb, extras)
  return prepararImpresionA4Puesto({
    empresa,
    puestoCodigo,
    meta,
    datos,
    titulo: `${meta.label} · ${empresa}-${alb.albaran}`,
  })
}

export async function prepararImpresionPedidoProveedor(
  ped: PedidoProveedorDetalle,
  opciones: { puestoCodigo: string }
): Promise<PrepImpresionA4> {
  const puestoCodigo = String(opciones.puestoCodigo || '').trim()
  if (!puestoCodigo) {
    throw new Error('Configure el puesto de este equipo para imprimir.')
  }
  const empresa = String(ped.empresa || '').trim()
  const [puesto, tienda] = await Promise.all([
    cargarPuesto(puestoCodigo),
    cargarTienda(empresa),
  ])
  const extras = await extrasEmpresaParaDocumento(tienda, puesto, empresa)
  const meta = metaImpresionPedidoCompra()
  const datos = pedidoProveedorAPreviewDatos(ped, extras)
  return prepararImpresionA4Puesto({
    empresa,
    puestoCodigo,
    meta,
    datos,
    titulo: `${meta.label} · ${empresa}-${ped.pedido}`,
  })
}

export async function imprimirA4CompraPreparado(
  prep: PrepImpresionA4,
  html: string
): Promise<string> {
  return imprimirA4Html(prep, html)
}
