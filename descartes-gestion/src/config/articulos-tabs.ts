export type ArticuloFieldType = 'text' | 'number' | 'checkbox' | 'textarea' | 'select' | 'date'

export type ArticuloFieldLayout = 'inline' | 'checkbox' | 'textarea'

export type ArticuloField = {
  key: string
  label: string
  type?: ArticuloFieldType
  layout?: ArticuloFieldLayout
  span?: 1 | 2 | 3 | 4
  /** Ancho maximo del control (p.ej. '11rem') para igualar campos de una seccion. */
  inputWidth?: string
  readOnly?: boolean
  required?: boolean
  maxLength?: number
  lookup?: boolean
  optionsSource?:
    | 'macrofamilias'
    | 'familias'
    | 'subfamilias'
    | 'secciones'
    | 'subsecciones'
    | 'agrupaciones'
    | 'impuestos'
    | 'proveedores'
}

export type ArticuloSection = {
  title: string
  columns?: 1 | 2 | 3 | 4
  /** Reparte los campos rellenando cada columna de arriba abajo en vez de por filas. */
  flow?: 'column'
  /** Campos por columna cuando flow es 'column'. */
  rows?: number
  /** Ancho fijo de la columna de etiquetas: alinea todos los inputs de la seccion. */
  labelWidth?: string
  /** Ancho maximo por defecto de los controles de la seccion (cada campo puede sobreescribirlo). */
  inputWidth?: string
  /** Cabeceras de columna (p.ej. Unidades | Precio) para rejillas tipo legacy. */
  columnHeaders?: string[]
  /** Oculta la etiqueta de cada campo (útil con columnHeaders). */
  hideFieldLabels?: boolean
  fields: ArticuloField[]
}

export type ArticuloTab = {
  id: string
  label: string
  sections: ArticuloSection[]
}

function cb(key: string, label: string, extra: Partial<ArticuloField> = {}): ArticuloField {
  return { key, label, type: 'checkbox', layout: 'checkbox', ...extra }
}

function inline(key: string, label: string, extra: Partial<ArticuloField> = {}): ArticuloField {
  return { key, label, layout: 'inline', ...extra }
}

function area(key: string, label: string, extra: Partial<ArticuloField> = {}): ArticuloField {
  return { key, label, type: 'textarea', layout: 'textarea', span: 4, ...extra }
}

export const articuloTabs: ArticuloTab[] = [
  {
    id: 'general',
    label: 'General',
    sections: [
      {
        title: 'Identificacion',
        columns: 4,
        fields: [
          inline('alternativo', 'Alternativo', { span: 2 }),
          inline('codigoB2B', 'Codigo B2B', { span: 2 }),
          inline('idWeb', 'Id WEB', { span: 2 }),
        ],
      },
      {
        title: 'Clasificacion',
        columns: 2,
        labelWidth: '6.25rem',
        inputWidth: '100%',
        fields: [
          inline('macroFamilia', 'Macrofamilia', {
            lookup: true,
            optionsSource: 'macrofamilias',
            required: true,
            maxLength: 6,
          }),
          inline('familia', 'Familia', {
            lookup: true,
            optionsSource: 'familias',
            required: true,
            maxLength: 6,
          }),
          inline('subfamilia', 'Subfamilia', {
            lookup: true,
            optionsSource: 'subfamilias',
            required: true,
            maxLength: 6,
          }),
          inline('seccion', 'Seccion', {
            lookup: true,
            optionsSource: 'secciones',
            maxLength: 6,
          }),
          inline('subSeccion', 'Subseccion', {
            lookup: true,
            optionsSource: 'subsecciones',
            maxLength: 6,
          }),
          inline('agrupacion', 'Agrupacion', {
            lookup: true,
            optionsSource: 'agrupaciones',
            required: true,
            maxLength: 6,
          }),
          inline('proveedorHabitual', 'Proveedor', {
            lookup: true,
            optionsSource: 'proveedores',
            required: true,
            maxLength: 9,
          }),
          inline('impuestoCodigo', 'Impuesto', {
            lookup: true,
            optionsSource: 'impuestos',
            required: true,
            maxLength: 2,
          }),
          inline('impuestoAgrario', 'Imp. agrario', {
            lookup: true,
            optionsSource: 'impuestos',
            maxLength: 2,
          }),
        ],
      },
      {
        title: 'Resumen stock',
        columns: 2,
        labelWidth: '6.75rem',
        inputWidth: '100%',
        fields: [
          inline('pendienteRecibir', 'Pend. recibir', { type: 'number', readOnly: true }),
          inline('pendienteEntrega', 'Reservado / ped.', { type: 'number', readOnly: true }),
          inline('precioVen1', 'PVP 1', { type: 'number' }),
        ],
      },
    ],
  },
  {
    id: 'tarifas',
    label: 'Tarifas',
    sections: [
      {
        // Legacy: caja izquierda Unidades | Precio (Articulos.Cantidad/PrecioEsp).
        title: 'Unidades / precios especiales',
        columns: 2,
        columnHeaders: ['Unidades', 'Precio'],
        hideFieldLabels: true,
        fields: [
          inline('cantidad1', 'Unidades', { type: 'number' }),
          inline('precioEsp1', 'Precio', { type: 'number' }),
          inline('cantidad2', 'Unidades', { type: 'number' }),
          inline('precioEsp2', 'Precio', { type: 'number' }),
          inline('cantidad3', 'Unidades', { type: 'number' }),
          inline('precioEsp3', 'Precio', { type: 'number' }),
          inline('cantidad4', 'Unidades', { type: 'number' }),
          inline('precioEsp4', 'Precio', { type: 'number' }),
          inline('cantidad5', 'Unidades', { type: 'number' }),
          inline('precioEsp5', 'Precio', { type: 'number' }),
          inline('cantidad6', 'Unidades', { type: 'number' }),
          inline('precioEsp6', 'Precio', { type: 'number' }),
          inline('cantidad7', 'Unidades', { type: 'number' }),
          inline('precioEsp7', 'Precio', { type: 'number' }),
          inline('cantidad8', 'Unidades', { type: 'number' }),
          inline('precioEsp8', 'Precio', { type: 'number' }),
        ],
      },
      {
        title: 'Precios',
        columns: 1,
        labelWidth: '10.25rem',
        inputWidth: '100%',
        fields: [
          inline('precioVen1', 'PVP 1', { type: 'number' }),
          inline('precioVen2', 'PVP 2', { type: 'number' }),
          inline('precioVen3', 'PVP 3', { type: 'number' }),
          inline('precioVen4', 'PVP 4', { type: 'number' }),
          inline('precioVen5', 'PVP 5', { type: 'number' }),
          inline('precioVen6', 'PVP 6', { type: 'number' }),
          inline('precioVen7', 'PVP 7', { type: 'number' }),
          inline('precioVen8', 'PVP 8', { type: 'number' }),
          inline('precioVen9', 'PVP 9', { type: 'number' }),
        ],
      },
      {
        title: 'Costes',
        columns: 1,
        labelWidth: '10.25rem',
        inputWidth: '100%',
        fields: [
          inline('precioBase', 'Base', { type: 'number' }),
          inline('precioUltimo', 'Ultimo CT', { type: 'number' }),
          inline('precioUltimoST', 'Ultimo ST', { type: 'number' }),
          inline('precioMedio', 'Medio CT', { type: 'number' }),
          inline('precioMedioST', 'Medio ST', { type: 'number' }),
          inline('porcentajeCosteSobreVenta', '% Calculo Coste sobre PVP', { type: 'number' }),
        ],
      },
    ],
  },
  {
    id: 'parametros',
    label: 'Parametros',
    sections: [
      {
        title: 'Medidas y empaquetado',
        columns: 2,
        labelWidth: '8rem',
        inputWidth: '9.5rem',
        fields: [
          inline('pesoKilogramos', 'Peso kg', { type: 'number' }),
          inline('volumen', 'Volumen', { type: 'number' }),
          inline('empaquetado', 'Empaquetado', { type: 'number' }),
          inline('unidadEmpaquetado', 'Des. unidad empa'),
          inline('unidadesCompra', 'Unidades compra', { type: 'number' }),
          inline('unidadStock', 'Des. unidad stock'),
          inline('tiendaWeb', 'Tienda web', { type: 'number', span: 2 }),
          cb('transporteEspecial', 'Articulo transporte'),
          cb('envioWeb', 'Envio a WEB'),
        ],
      },
      {
        title: 'Bloqueos y flags',
        columns: 4,
        fields: [
          cb('bloqueoCompra', 'Bloqueado compra'),
          cb('bloqueoVenta', 'Bloqueado venta'),
          cb('bloqueoFidelizacion', 'Bloqueo fidelizacion'),
          cb('servicio', 'Servicio'),
          cb('ventaPorPeso', 'Venta por peso'),
          cb('pasaporteFitosanitario', 'Control fitosanitario'),
          cb('lotePasaporte', 'Lote pasaporte'),
          cb('fitoProfesional', 'Fitosanitario prof.'),
          inline('registroSanitario', 'Registro sanitario', { span: 2 }),
          inline('codigoIntrastat', 'Codigo Intrastat', { span: 2 }),
        ],
      },
      {
        title: 'Estado',
        columns: 4,
        fields: [
          inline('estado', 'Estado'),
          inline('inventario', 'Inventario'),
          cb('ventaSoloReserva', 'Solo reserva'),
          cb('etiquetaPrecio', 'Etiq. precio'),
          cb('retIrpf', 'Aplicar % ret. IRPF'),
          cb('impresionFichaBotanica', 'Imp. ficha botanica'),
          cb('etiquetaElectronica', 'Etiqueta electronica'),
          cb('solicitarLote', 'Solicitar lote'),
        ],
      },
      {
        title: 'Fechas y clasificacion',
        columns: 2,
        flow: 'column',
        rows: 3,
        labelWidth: '8rem',
        inputWidth: '9.5rem',
        fields: [
          inline('fechaAlta', 'Fecha alta', { type: 'date' }),
          inline('fechaUltCompra', 'Ultima compra', { type: 'date' }),
          inline('fechaBaja', 'Fecha baja', { type: 'date' }),
          inline('categoria', 'Categoria'),
          inline('contramarca', 'Contramarca', { type: 'number' }),
        ],
      },
      {
        title: 'Datos planta / jardin',
        columns: 2,
        labelWidth: '8rem',
        inputWidth: '9.5rem',
        fields: [
          inline('nombreBotanico', 'Nombre botanico', { span: 2, inputWidth: '100%' }),
          inline('floracion', 'Floracion'),
          inline('altura', 'Altura'),
          inline('exposicion', 'Exposicion'),
          inline('poda', 'Poda'),
          inline('tipoPlanta', 'Tipo planta'),
          inline('numAlveolos', 'N. alveolos', { type: 'number' }),
          inline('udsXAlveolo', 'Uds x alveolo', { type: 'number' }),
          inline('udsXSobre', 'Uds x sobre', { type: 'number' }),
          cb('stockBandejas', 'Stock x bandejas', { span: 2 }),
          inline('literalPlanta1', 'Literal 1', { span: 2, inputWidth: '100%' }),
          inline('literalPlanta2', 'Literal 2', { span: 2, inputWidth: '100%' }),
          inline('patron', 'Patron', { span: 2, inputWidth: '100%' }),
          inline('variedad', 'Variedad', { span: 2, inputWidth: '100%' }),
          inline('imagen', 'Imagen', { span: 2, inputWidth: '100%' }),
          inline('imagen2', 'Imagen 2', { span: 2, inputWidth: '100%' }),
          inline('fichaPlantilla', 'Plantilla ficha'),
        ],
      },
    ],
  },
  {
    id: 'observaciones',
    label: 'Observaciones',
    sections: [
      {
        title: 'Observaciones',
        columns: 4,
        fields: [
          area('observaciones', 'Observaciones'),
          area('comentarios', 'Comentarios'),
          inline('observacionesComerciales', 'Observaciones comerciales', { span: 4 }),
        ],
      },
    ],
  },
]

export function articuloVacio(): Record<string, unknown> {
  return {
    codigo: '',
    descripcion: '',
    activo: true,
    bloqueoCompra: false,
    bloqueoVenta: false,
    servicio: false,
    ventaPorPeso: false,
    precioVen1: 0,
    precioVenta: 0,
    fechaAlta: new Date().toISOString().slice(0, 10),
    precios: [],
    stock: [],
  }
}

/** Campos obligatorios de ficha (API camelCase). */
export const ARTICULO_CAMPOS_OBLIGATORIOS: { key: string; label: string }[] = [
  { key: 'codigo', label: 'Codigo' },
  { key: 'descripcion', label: 'Descripcion' },
  { key: 'macroFamilia', label: 'Macrofamilia' },
  { key: 'familia', label: 'Familia' },
  { key: 'subfamilia', label: 'Subfamilia' },
  { key: 'agrupacion', label: 'Agrupacion' },
  { key: 'proveedorHabitual', label: 'Proveedor' },
  { key: 'impuestoCodigo', label: 'Impuesto' },
]

export function camposArticuloObligatoriosVacios(ficha: Record<string, unknown>): string[] {
  return ARTICULO_CAMPOS_OBLIGATORIOS.filter((c) => !String(ficha[c.key] ?? '').trim()).map((c) => c.key)
}

/** Un mensaje por cada campo obligatorio vacio (para modales secuenciales). */
export function mensajesArticuloObligatoriosVacios(ficha: Record<string, unknown>): string[] {
  return ARTICULO_CAMPOS_OBLIGATORIOS.filter((c) => !String(ficha[c.key] ?? '').trim()).map(
    (c) => `El campo "${c.label}" es obligatorio.`
  )
}

/**
 * Obligatorios alineados con alta legacy:
 * codigo, descripcion, macrofamilia, familia, subfamilia, agrupacion, proveedor, impuesto.
 */
export function validarArticuloObligatorios(ficha: Record<string, unknown>): string | null {
  const msgs = mensajesArticuloObligatoriosVacios(ficha)
  return msgs[0] ?? null
}
