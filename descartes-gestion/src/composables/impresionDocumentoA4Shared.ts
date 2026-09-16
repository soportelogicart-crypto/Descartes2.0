import { api } from '@/api/client'
import { getDescartesBridge } from '@/bridge/electron'
import { cargarEmblemaEmpresa } from '@/composables/cargarEmblemaEmpresa'
import {
  documentosPlantillas,
  type DocumentoPlantilla,
} from '@/config/documentos-plantillas'
import type { DocumentoPreviewDatos } from '@/config/documentos-plantillas/preview-datos'

export type PrepImpresionA4 = {
  plantilla: DocumentoPlantilla
  datos: DocumentoPreviewDatos
  impresoraNombre: string
  impresoraId: number | null
  titulo: string
}

/** Claves Generales II del puesto para un documento A4. */
export type MetaImpresionA4Puesto = {
  plantillaTipo: string
  formatoKey: string
  nombreKey: string
  indiceKey: string
  label: string
}

export type PuestoDoc = Record<string, unknown>

export function clonePlantilla(p: DocumentoPlantilla): DocumentoPlantilla {
  return JSON.parse(JSON.stringify(p)) as DocumentoPlantilla
}

export function esqueletoPorTipo(tipo: string): DocumentoPlantilla | null {
  const base = documentosPlantillas.find((p) => p.tipo === tipo)
  return base ? clonePlantilla(base) : null
}

export async function cargarPuesto(codigo: string): Promise<PuestoDoc> {
  const { data } = await api.get(`/api/mantenimiento/puestos-trabajo/${encodeURIComponent(codigo)}`)
  return (data ?? {}) as PuestoDoc
}

export async function cargarTienda(codigo: string): Promise<Record<string, unknown>> {
  const { data } = await api.get(`/api/mantenimiento/tiendas/${encodeURIComponent(codigo)}`)
  return (data ?? {}) as Record<string, unknown>
}

export async function cargarPlantillasEmpresa(empresa: string): Promise<
  { id: number; tipo: string; nombre: string; activa: boolean; definicion: DocumentoPlantilla }[]
> {
  const { data } = await api.get('/api/mantenimiento/documento-plantillas', {
    params: { empresa: empresa.trim().toUpperCase() },
  })
  return ((data?.items ?? []) as Record<string, unknown>[]).map((raw) => ({
    id: Number(raw.id),
    tipo: String(raw.tipo ?? ''),
    nombre: String(raw.nombre ?? ''),
    activa: Boolean(raw.activa),
    definicion:
      raw.definicion && typeof raw.definicion === 'object'
        ? clonePlantilla(raw.definicion as DocumentoPlantilla)
        : esqueletoPorTipo(String(raw.tipo ?? 'albaran'))!,
  }))
}

export function literalesDesdePuesto(puesto: PuestoDoc): string[] {
  const out: string[] = []
  for (let i = 1; i <= 9; i++) {
    out.push(String(puesto[`literal${i}`] ?? '').trim())
  }
  return out
}

/** Si la plantilla guardada es el esqueleto base de una versión anterior, usa la del código. */
function plantillaVigente(guardada: DocumentoPlantilla, tipo: string): DocumentoPlantilla {
  const skeleton = esqueletoPorTipo(tipo)
  if (!skeleton) return guardada
  const mismaBase = !guardada.id || guardada.id === skeleton.id
  const vGuardada = Number(guardada.version || 0)
  const vBase = Number(skeleton.version || 0)
  if (mismaBase && vGuardada < vBase) return skeleton
  return guardada
}

export function resolverPlantilla(
  lista: { tipo: string; nombre: string; activa: boolean; definicion: DocumentoPlantilla }[],
  nombrePreferido: string,
  tipoPreferido: string
): DocumentoPlantilla {
  const aplicar = (p: DocumentoPlantilla) => plantillaVigente(p, tipoPreferido)
  const nombre = nombrePreferido.trim()
  if (nombre) {
    const byName = lista.find((p) => p.nombre.trim().toLowerCase() === nombre.toLowerCase())
    if (byName) return aplicar(byName.definicion)
  }
  const activa = lista.find((p) => p.tipo === tipoPreferido && p.activa)
  if (activa) return aplicar(activa.definicion)
  const anyTipo = lista.find((p) => p.tipo === tipoPreferido)
  if (anyTipo) return aplicar(anyTipo.definicion)
  return esqueletoPorTipo(tipoPreferido) || esqueletoPorTipo('albaran')!
}

export async function resolverNombreImpresoraDoc(
  puesto: PuestoDoc,
  nombreKey: string | null,
  indiceKey: string | null
): Promise<{ nombre: string; id: number | null }> {
  const id = indiceKey != null ? Number(puesto[indiceKey]) || null : null
  const corto = nombreKey != null ? String(puesto[nombreKey] ?? '').trim() : ''
  const bridge = getDescartesBridge()
  if (bridge) {
    try {
      const listed = await bridge.listPrinters()
      const printers = listed.printers || []
      if (id != null && id > 0) {
        const byId = printers.find((p) => p.id === id)
        if (byId) return { nombre: byId.name, id }
      }
      if (corto) {
        const w = corto.toLowerCase()
        const hit = printers.find((p) => {
          const n = p.name.toLowerCase()
          const d = String(p.displayName || '').toLowerCase()
          return n === w || d === w || n.startsWith(w) || d.startsWith(w) || n.includes(w)
        })
        if (hit) return { nombre: hit.name, id: hit.id }
      }
    } catch {
      /* ignore */
    }
  }
  return { nombre: corto, id }
}

/** Empresa/tienda → bloque común de preview A4. */
export function extrasEmpresaDesdeTienda(
  tienda: Record<string, unknown>,
  puesto: PuestoDoc
): {
  empresaNombre: string
  empresaNif: string
  empresaDireccion: string
  empresaCp: string
  empresaPoblacion: string
  empresaProvincia: string
  empresaTelefono: string
  empresaEmail: string
  literalesPuesto: string[]
  literalTicket: number
  literalFacturaDiferida: string
  literalFacturaContado: string
  literalPresupuesto: string
  literalVale: string
  preciosIvaIncluido: boolean
  emblemaUrl: string
} {
  return {
    empresaNombre: String(tienda.nombre ?? tienda.nombreFiscal ?? ''),
    empresaNif: String(tienda.nif ?? ''),
    empresaDireccion: String(tienda.direccion ?? ''),
    empresaCp: String(tienda.codigoPostal ?? ''),
    empresaPoblacion: String(tienda.poblacion ?? ''),
    empresaProvincia: String(tienda.provincia ?? ''),
    empresaTelefono: String(tienda.telefono1 ?? ''),
    empresaEmail: String(tienda.email ?? ''),
    literalesPuesto: literalesDesdePuesto(puesto),
    literalTicket: Number(tienda.literalTicket ?? 3) || 3,
    literalFacturaDiferida: String(tienda.literalFacturaDiferida ?? ''),
    literalFacturaContado: String(tienda.literalFacturaContado ?? ''),
    literalPresupuesto: String(tienda.literalPresupuesto ?? ''),
    literalVale: String(tienda.literalVale ?? ''),
    // Empresas.SW_IVA: precios de línea con IVA incluido.
    preciosIvaIncluido: Boolean(tienda.swIva),
    emblemaUrl: '',
  }
}

/** Datos de tienda/puesto más el logo de la carpeta `logos`, si existe. */
export async function extrasEmpresaParaDocumento(
  tienda: Record<string, unknown>,
  puesto: PuestoDoc,
  empresaCodigo: string
): Promise<ReturnType<typeof extrasEmpresaDesdeTienda> & { emblemaUrl: string }> {
  const extras = extrasEmpresaDesdeTienda(tienda, puesto)
  return {
    ...extras,
    emblemaUrl: await cargarEmblemaEmpresa(empresaCodigo || String(tienda.codigo ?? '')),
  }
}

/**
 * Prepara preview A4: plantilla del diseñador + impresora Generales II del puesto.
 */
export async function prepararImpresionA4Puesto(opciones: {
  empresa: string
  puestoCodigo: string
  meta: MetaImpresionA4Puesto
  datos: DocumentoPreviewDatos
  titulo: string
}): Promise<PrepImpresionA4> {
  const puestoCodigo = opciones.puestoCodigo.trim()
  if (!puestoCodigo) {
    throw new Error('Configure el puesto de este equipo para imprimir.')
  }
  const empresa = opciones.empresa.trim()
  if (!empresa) {
    throw new Error('Empresa (tienda) obligatoria para imprimir.')
  }

  const [puesto, plantillas] = await Promise.all([
    cargarPuesto(puestoCodigo),
    cargarPlantillasEmpresa(empresa),
  ])

  const formatoNombre = String(puesto[opciones.meta.formatoKey] ?? '').trim()
  const plantilla = resolverPlantilla(
    plantillas,
    formatoNombre,
    opciones.meta.plantillaTipo
  )
  const imp = await resolverNombreImpresoraDoc(
    puesto,
    opciones.meta.nombreKey,
    opciones.meta.indiceKey
  )

  return {
    plantilla,
    datos: opciones.datos,
    impresoraNombre: imp.nombre,
    impresoraId: imp.id,
    titulo: opciones.titulo,
  }
}

/** Imprime HTML A4 vía bridge Electron o diálogo del navegador (sin marcar documento). */
export async function imprimirA4Html(prep: PrepImpresionA4, html: string): Promise<string> {
  if (!html.trim()) {
    throw new Error('No se pudo generar el documento A4')
  }
  const bridge = getDescartesBridge()
  if (bridge?.printHtml) {
    const res = await bridge.printHtml({
      html,
      impresora: prep.impresoraNombre || undefined,
      impresoraId: prep.impresoraId ?? undefined,
      silent: Boolean(prep.impresoraNombre || prep.impresoraId),
    })
    if (!res.ok) {
      throw new Error(res.message || 'Error al imprimir A4')
    }
    return res.message || `Enviado a ${res.impresora || prep.impresoraNombre}`
  }

  const w = window.open('', '_blank', 'width=820,height=1100')
  if (!w) {
    throw new Error('Popup bloqueado: permita ventanas emergentes para imprimir')
  }
  w.document.write(html)
  w.document.close()
  w.focus()
  w.print()
  return prep.impresoraNombre
    ? `Diálogo de impresión abierto (preferida: ${prep.impresoraNombre})`
    : 'Diálogo de impresión abierto'
}
