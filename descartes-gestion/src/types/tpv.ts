export type TpvBotonTipo = 'articulo' | 'nivel' | 'vacio'

export type TpvBoton = {
  tecla: number
  nivel: string | null
  fila: number
  columna: number
  etiqueta1: string | null
  etiqueta2: string | null
  etiqueta3: string | null
  ancho: number
  alto: number
  articulo: string | null
  nivelDestino: string | null
  nivelVolver: string | null
  colorFondo: string | null
  colorTexto: string | null
  icono: string | null
  tarifa: number | null
  clase: string | null
  tipo: TpvBotonTipo
}

export type TpvNivel = {
  general: string
  nivel: string
  nombre: string | null
  columnas: number
  botones: TpvBoton[]
}

export type TpvNivelResumen = {
  nivel: string
  etiqueta: string
  botones: number
}

export type TpvBotonAsignacion =
  | {
      tipo: 'articulo'
      articulo: string
      etiqueta: string
      ancho?: number
      alto?: number
    }
  | {
      tipo: 'nivel'
      /** Vacío cuando `crearGrupo` pide un grupo nuevo: el nivel lo asigna el servidor. */
      nivelDestino: string
      crearGrupo?: boolean
      etiqueta: string
      ancho?: number
      alto?: number
    }

/** Cliente de venta rápida: si el cajero no elige nadie, el ticket va a este código. */
export const CLIENTE_RAPIDO_TPV = 'ZZZZZZZZZ'

/**
 * Geometría de la rejilla táctil de venta rápida.
 *
 * `DefPlus.H_TECLA` codifica la posición legacy como `fila * 20 + columna`, y
 * de ahí sale el ancho de cada tecla al repartir esas 20 columnas entre las
 * visibles. Vive aquí porque lo comparten la rejilla y el modal que guarda los
 * botones: si cada uno llevara su copia, al cambiar el número de columnas las
 * posiciones dejarían de cuadrar con lo guardado.
 */
export const TPV_COLUMNAS_LEGACY = 20
export const TPV_COLUMNAS_VISIBLES = 4
export const TPV_ANCHO_TECLA = Math.floor(TPV_COLUMNAS_LEGACY / TPV_COLUMNAS_VISIBLES)
/** Filas mínimas que se rellenan con huecos para ocupar la columna. */
export const TPV_FILAS_MINIMAS = 8

/** Forma de pago de contado (CobroDeArqueo) disponible en la caja. */
export type TpvFormaPago = {
  codigo: string
  descripcion: string
  etiqueta: string
  abrirCajon: boolean
  copiasTicket: number
}

export type TpvContexto = {
  empresa: string
  puesto: string
  sesion: number
  tecladoCodigo: number
  tecladoGeneral: string
  tarifa: number
  impresoraTickets: string | null
  formatoTickets: string | null
  vendedor: string | null
  clienteRapido: string
  formasPago: TpvFormaPago[]
}

export type TpvLineaBorrador = {
  articulo: string
  descripcion: string
  cantidad: number
  precio: number
  /** Descuento porcentual aplicado únicamente a esta línea. */
  pjeDto: number
  importe: number
  pjeIva: number
}

/** Número de ticket propuesto al abrir caja, antes de grabar la cabecera. */
export type TpvReservaTicket = {
  albaran: number
  vendedor: string | null
  almacen: number | null
}

/** Cliente asignable al ticket desde la caja. */
export type TpvCliente = {
  codigo: string
  razonSocial: string
  razonSocial2: string
  nif: string
  direccion: string
  poblacion: string
  codigoPostal: string
  provincia: string
  pais: string
  telefono: string
  email: string
  formaPago: string
  tarifa: number
}

/** Artículo pulsado sin PVP en la tarifa: espera que el cajero teclee el precio. */
export type TpvPrecioPendiente = {
  articulo: string
  descripcion: string
  cantidad: number
  pjeIva: number
}

export type TpvArticuloPrecio = {
  codigo: string
  descripcion: string
  precio: number
  iva: number | null
  bloqueado: boolean
  familiaCodigo?: string
  familiaDescripcion?: string
  subfamiliaCodigo?: string
  subfamiliaDescripcion?: string
  macroFamiliaCodigo?: string
  macroFamiliaDescripcion?: string
  agrupacionCodigo?: string
  agrupacionDescripcion?: string
}

/** Resultado de resolver código / Alternativo / EAN en el TPV. */
export type TpvArticuloResuelto = TpvArticuloPrecio & {
  matchPor: 'codigo' | 'alternativo' | 'ean'
  /** Unidades que aporta el EAN leído (1 si match por código o Alternativo). */
  unidadesPaquete: number
}
