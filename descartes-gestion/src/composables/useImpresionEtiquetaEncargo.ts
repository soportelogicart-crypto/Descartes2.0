/**
 * Etiquetas de los sobres de un encargo (una por sobre).
 *
 * - Plantilla: tipo `etiqueta-encargo`; la elige `Puestos.FormatoEtiquetasEncargo`,
 *   si no la activa de la empresa y, si no hay ninguna, la estándar de código.
 * - Impresora: `Puestos.ImpresoraEtiquetasEncargo`, distinta a la de etiquetas de artículo.
 * - Código de barras: tienda + albarán (Code 128), el mismo que se busca en Ventas y Encargos.
 */
import { api } from '@/api/client'
import { getDescartesBridge, imprimirEtiquetaViaBridge } from '@/bridge/electron'
import {
  cargarPuesto,
  resolverNombreImpresoraDoc,
} from '@/composables/impresionDocumentoA4Shared'
import {
  listarPlantillasEtiqueta,
  resolverPlantillaEtiqueta,
} from '@/composables/usePlantillaEtiqueta'
import {
  datosEtiquetaEncargo,
  prepararRenderEtiqueta,
} from '@/config/documentos-plantillas/etiqueta-html'

type RespuestaEtiquetaEncargo = {
  codigoBarras?: string
  nombre?: string
  telefono?: string
  telefono2?: string
  email?: string
  fecha?: string
  tienda?: string
  etiquetas?: { producto?: string; articulo?: string; sobre?: string }[]
  copias?: number
}

export type ResultadoImpresionEtiquetasEncargo = {
  etiquetas: number
  impresora: string
}

/** `2026-11-10 09:30:00` → `10/11/2026`. */
function fechaCorta(valor: unknown): string {
  const m = String(valor ?? '').match(/^(\d{4})-(\d{2})-(\d{2})/)
  return m ? `${m[3]}/${m[2]}/${m[1]}` : String(valor ?? '')
}

/** Une varias etiquetas en un solo documento, una por página. */
function unirPaginas(htmls: string[]): string {
  if (htmls.length === 1) return htmls[0]
  const cuerpo = (h: string) => /<body[^>]*>([\s\S]*)<\/body>/i.exec(h)?.[1] ?? ''
  const salto =
    '<style>.page{page-break-after:always}.page:last-child{page-break-after:auto}</style>'
  return htmls[0]
    .replace(/<body[^>]*>[\s\S]*<\/body>/i, `<body>${htmls.map(cuerpo).join('\n')}</body>`)
    .replace('</head>', `${salto}</head>`)
}

/**
 * Pide al API las etiquetas del encargo (marca como impresas) y las manda a la impresora
 * de etiquetas de encargo del puesto. Lanza con un mensaje claro si falta algo.
 */
export async function imprimirEtiquetasEncargo(
  numero: number,
  puestoCodigo: string,
  empresaCodigo: string
): Promise<ResultadoImpresionEtiquetasEncargo> {
  const codigoPuesto = String(puestoCodigo ?? '').trim()
  if (!codigoPuesto) {
    throw new Error('No hay puesto configurado en este equipo.')
  }
  const puesto = await cargarPuesto(codigoPuesto)
  const impresora = await resolverNombreImpresoraDoc(puesto, 'impresoraEtiquetasEncargo', null)
  if (!impresora.nombre && getDescartesBridge()) {
    throw new Error(
      'Este puesto no tiene impresora para las etiquetas de encargo. ' +
        'Configúrela en Puestos → Impresoras → Etiquetas encargo.'
    )
  }

  const lista = await listarPlantillasEtiqueta(empresaCodigo, 'etiqueta-encargo')
  const plantilla = resolverPlantillaEtiqueta(lista, {
    nombrePreferido: String(puesto.formatoEtiquetasEncargo ?? '').trim(),
    tipoFallback: 'etiqueta-encargo',
  })

  const { data } = await api.post<RespuestaEtiquetaEncargo>(`/api/encargos/${numero}/etiqueta`, {
    vuelta: false,
  })
  const codigo = String(data.codigoBarras ?? '').trim()
  if (!codigo) {
    throw new Error('El encargo no tiene albarán: no se puede generar el código de barras.')
  }
  const total = Math.max(1, Number(data.copias) || data.etiquetas?.length || 1)
  const items =
    data.etiquetas && data.etiquetas.length > 0
      ? data.etiquetas
      : Array.from({ length: total }, (_, i) => ({ producto: '', sobre: `${i + 1}/${total}` }))
  const telefono = [data.telefono, data.telefono2]
    .map((t) => String(t ?? '').trim())
    .filter(Boolean)
    .join(' · ')

  const preparadas = items.map((it) =>
    prepararRenderEtiqueta(
      plantilla,
      datosEtiquetaEncargo({
        cliente: data.nombre,
        telefono,
        email: data.email,
        fecha: fechaCorta(data.fecha),
        producto: it.producto,
        sobre: `sobre ${String(it.sobre ?? '')}`.trim(),
        codigoBarras: codigo,
        tienda: data.tienda,
      })
    )
  )

  await imprimirEtiquetaViaBridge({
    html: unirPaginas(preparadas.map((p) => p.html)),
    pageWidthMm: preparadas[0].pageWidthMm,
    pageHeightMm: preparadas[0].pageHeightMm,
    copies: 1,
    impresora: impresora.nombre || undefined,
    impresoraId: impresora.id ?? undefined,
    silent: Boolean(impresora.nombre),
  })
  return { etiquetas: preparadas.length, impresora: impresora.nombre }
}
