/**
 * `grupo` / `grupoVuelta` abren otro nivel (DefPlus M / F). Con F, tras vender
 * el teclado vuelve a ese grupo en lugar de al último abierto.
 */
export type TpvBotonTipo = 'articulo' | 'texto' | 'grupo' | 'grupoVuelta' | 'vacio'

export type TpvBoton = {
  /** Posición fija legacy: 0..7 en la columna de grupos, 0..13 en la rejilla 2x7. */
  posicion: number
  tecla: number
  etiqueta1: string | null
  etiqueta2: string | null
  etiqueta3: string | null
  colorFondo: string | null
  colorTexto: string | null
  /** Ruta absoluta de la imagen en el disco del PC de caja (H_ICON). */
  icono: string | null
  tipo: TpvBotonTipo
  nivelDestino: string | null
  articulo?: string | null
  texto?: string | null
  pedirPrecio?: boolean
  /** Pantallas de complementos (DefPlus O### / P### de la plantilla 001). */
  obligatorios?: string[]
  opcionales?: string[]
  tarifa?: number | null
  /** Grupo sin texto ni imagen: legacy no lo enseña. */
  visible: boolean
}

export type TpvNivel = {
  general: string
  nivel: string
  nombre: string | null
  grupos: TpvBoton[]
  botones: TpvBoton[]
  /** Falso en el último nivel posible (9 caracteres) y en complementos. */
  puedeTenerGrupos: boolean
}

export type TpvBotonAsignacion = {
  tipo: 'articulo' | 'texto' | 'grupo'
  articulo?: string
  texto?: string
  etiqueta1: string
  etiqueta2: string
  etiqueta3: string
  colorFondo: string | null
  colorTexto: string | null
  icono: string | null
  pedirPrecio?: boolean
}

/** Columna de grupos (nivel 000) o rejilla de botones del nivel abierto. */
export type TpvZonaTeclado = 'grupo' | 'boton'

/** Nivel que se abre al empezar y tras cada venta si no hay grupo de vuelta (a_plu legacy). */
export const TPV_NIVEL_INICIAL = '001'
export const TPV_NIVEL_GRUPOS = '000'
export const TPV_POSICIONES_GRUPO = 8
export const TPV_POSICIONES_BOTON = 14

/** Cliente de venta rápida: si el cajero no elige nadie, el ticket va a este código. */
export const CLIENTE_RAPIDO_TPV = 'ZZZZZZZZZ'

/**
 * Entrada del cajón OTRAS FUNCIONES. La botonera de caja solo tiene sitio para
 * lo que se usa en cada venta; el resto se declara como datos para que sumar
 * una función sea añadir un elemento a la lista, no rehacer la pantalla.
 */
export type TpvFuncionExtra = {
  id: string
  etiqueta: string
  ayuda?: string
  deshabilitada?: boolean
  tono?: 'normal' | 'aviso' | 'peligro' | 'activo'
}

export type TpvTicketEspera = {
  empresa: string
  tipo: string
  albaran: number
  fecha: string | null
  cliente: string
  razonSocial: string
  importe: number
  lineas: number
}

/** Forma de pago de contado (CobroDeArqueo) disponible en la caja. */
export type TpvFormaPago = {
  codigo: string
  descripcion: string
  etiqueta: string
  abrirCajon: boolean
  copiasTicket: number
  datafono: boolean
  /** Legacy: marcado junto a Datafono significa terminal integrado. */
  chipAcumuladoMenu: boolean
  emv: boolean
  agrupacion: number
  /** Forma de pago Vale: el cliente entrega un vale en lugar de dinero. */
  vales: boolean
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
  datafono: string | null
  terminalDatafono: string | null
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
  /** Línea regalada por una oferta: la oferta no le recalcula el descuento. */
  regalo?: boolean
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
