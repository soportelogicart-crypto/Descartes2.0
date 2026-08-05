export type AbcIdioma = 'castellano' | 'catalan'

type Dict = Record<string, string>

const castellano: Dict = {
  inicio: 'Inicio',
  tituloListado: 'Listado ABC Ventas',
  tituloListadoClientes: 'Listado ABC Clientes',
  generar: 'Generar',
  generando: 'Generando…',
  excel: 'Excel',
  pdf: 'PDF',
  opciones: 'Opciones',
  dimension: 'Dimension',
  orden: 'Orden',
  idioma: 'Idioma',
  divisa: 'Divisa',
  iva: 'Iva',
  imArticulos: 'Im. Articulos',
  valor: 'Valor',
  tipoVenta: 'Tipo Venta',
  filtros: 'Filtros (Desde / Hasta)',
  fecha: 'Fecha',
  vendedor: 'Vendedor',
  vendedorSoloPropio: 'Solo puedes consultar tu propio ABC',
  codigo: 'Codigo',
  articulo: 'Articulo',
  unidades: 'Unidades',
  dto: 'Dto.',
  importe: 'Importe',
  coste: 'Coste',
  margen: 'Margen',
  pjeMargen: '%Margen',
  pjeSobTot: '%Sob.Tot',
  mAgr: 'M.Agr.',
  total: 'TOTAL',
  totalGeneral: 'TOTAL GENERAL',
  abcVentas: 'ABC VENTAS',
  abcClientes: 'ABC CLIENTES',
  previsualizacion: 'Previsualización',
  imprimir: 'Imprimir',
  cerrar: 'Cerrar',
  previewVacia: 'No hay listado para previsualizar. Genérelo desde el formulario.',
  sinNombre: '(sin nombre)',
  generandoListado: 'Generando listado...',
  ivaIncluido: 'IVA incluido',
  ivaDesglosado: 'IVA desglosado',
  conIva: 'Con Iva',
  sinIva: 'Sin Iva',
  ordenMargen: 'Margen',
  ordenImporte: 'Importe',
  ordenCantidad: 'Cantidad',
  ordenCoste: 'Coste',
  ordenVendedor: 'Vendedor',
  valorPrecioMedio: 'Precio Medio',
  valorPrecioMedioActual: 'Precio Medio Actual',
  valorUltimoPrecio: 'Ultimo Precio',
  valorSinValorTarifa: 'Sin valor Tarifa',
  tipoTodos: 'Todos',
  tipoTicket: 'Ticket',
  tipoFacturas: 'Facturas',
  tipoTicketFacturas: 'Ticket + Facturas',
  tipoAlbaranes: 'Albaranes',
  tipoTicketsFacturasContado: 'Tickets + Facturas Contado',
  si: 'Si',
  no: 'No',
  vendedores: 'Vendedores',
  clientes: 'Clientes',
  castellano: 'Castellano',
  catalan: 'Catalan',
  incluido: 'Incluido',
  desglosado: 'Desglosado',
  filtroMacroFamilia: 'MacroFamilia',
  filtroFamilia: 'Familia',
  filtroSubfamilia: 'Subfamilia',
  filtroAgrupacion: 'Agrupacion',
  filtroArticulo: 'Articulo',
  filtroTienda: 'Tienda',
  filtroCliente: 'Cliente',
  filtroProveedor: 'Proveedor',
  filtroSeccion: 'Seccion',
  filtroSubSeccion: 'SubSeccion',
  filtroActividad: 'Actividad',
  filtroTipoDescuento: 'Tipo Descuento',
}

const catalan: Dict = {
  inicio: 'Inici',
  tituloListado: 'Llistat ABC Vendes',
  tituloListadoClientes: 'Llistat ABC Clients',
  generar: 'Generar',
  generando: 'Generant…',
  excel: 'Excel',
  pdf: 'PDF',
  opciones: 'Opcions',
  dimension: 'Dimensió',
  orden: 'Ordre',
  idioma: 'Idioma',
  divisa: 'Divisa',
  iva: 'Iva',
  imArticulos: 'Im. Articles',
  valor: 'Valor',
  tipoVenta: 'Tipus Venda',
  filtros: 'Filtres (Des de / Fins a)',
  fecha: 'Data',
  vendedor: 'Venedor',
  vendedorSoloPropio: 'Només pots consultar el teu propi ABC',
  codigo: 'Codi',
  articulo: 'Article',
  unidades: 'Unitats',
  dto: 'Dto.',
  importe: 'Import',
  coste: 'Cost',
  margen: 'Marge',
  pjeMargen: '%Marge',
  pjeSobTot: '%Sob.Tot',
  mAgr: 'M.Agr.',
  total: 'TOTAL',
  totalGeneral: 'TOTAL GENERAL',
  abcVentas: 'ABC VENDES',
  abcClientes: 'ABC CLIENTS',
  previsualizacion: 'Previsualització',
  imprimir: 'Imprimir',
  cerrar: 'Tancar',
  previewVacia: 'No hi ha llistat per previsualitzar. Genereu-lo des del formulari.',
  sinNombre: '(sense nom)',
  generandoListado: 'Generant llistat...',
  ivaIncluido: 'IVA inclòs',
  ivaDesglosado: 'IVA desglossat',
  conIva: 'Amb Iva',
  sinIva: 'Sense Iva',
  ordenMargen: 'Marge',
  ordenImporte: 'Import',
  ordenCantidad: 'Quantitat',
  ordenCoste: 'Cost',
  ordenVendedor: 'Venedor',
  valorPrecioMedio: 'Preu Mitjà',
  valorPrecioMedioActual: 'Preu Mitjà Actual',
  valorUltimoPrecio: 'Últim Preu',
  valorSinValorTarifa: 'Sense valor Tarifa',
  tipoTodos: 'Tots',
  tipoTicket: 'Ticket',
  tipoFacturas: 'Factures',
  tipoTicketFacturas: 'Ticket + Factures',
  tipoAlbaranes: 'Albarans',
  tipoTicketsFacturasContado: 'Tickets + Factures Comptat',
  si: 'Sí',
  no: 'No',
  vendedores: 'Venedors',
  clientes: 'Clients',
  castellano: 'Castellà',
  catalan: 'Català',
  incluido: 'Inclòs',
  desglosado: 'Desglossat',
  filtroMacroFamilia: 'MacroFamília',
  filtroFamilia: 'Família',
  filtroSubfamilia: 'Subfamília',
  filtroAgrupacion: 'Agrupació',
  filtroArticulo: 'Article',
  filtroTienda: 'Botiga',
  filtroCliente: 'Client',
  filtroProveedor: 'Proveïdor',
  filtroSeccion: 'Secció',
  filtroSubSeccion: 'SubSecció',
  filtroActividad: 'Activitat',
  filtroTipoDescuento: 'Tipus Descompte',
}

const dicts: Record<AbcIdioma, Dict> = { castellano, catalan }

export function normalizeIdioma(raw: unknown): AbcIdioma {
  const s = String(raw ?? 'castellano').trim().toLowerCase()
  return s === 'catalan' || s === 'català' || s === 'ca' ? 'catalan' : 'castellano'
}

export function t(idioma: unknown, key: string): string {
  const lang = normalizeIdioma(idioma)
  return dicts[lang][key] ?? dicts.castellano[key] ?? key
}

export function labelOrden(idioma: unknown, orden: string): string {
  const map: Record<string, string> = {
    margen: 'ordenMargen',
    importe: 'ordenImporte',
    cantidad: 'ordenCantidad',
    coste: 'ordenCoste',
    vendedor: 'ordenVendedor',
  }
  return t(idioma, map[orden] || 'ordenMargen')
}

export function labelValor(idioma: unknown, valor: string): string {
  const map: Record<string, string> = {
    precioMedio: 'valorPrecioMedio',
    precioMedioActual: 'valorPrecioMedioActual',
    ultimoPrecio: 'valorUltimoPrecio',
    precioUltimo: 'valorUltimoPrecio',
    sinValorTarifa: 'valorSinValorTarifa',
  }
  return t(idioma, map[valor] || 'valorPrecioMedio')
}

export function labelIva(idioma: unknown, iva: string): string {
  const s = String(iva ?? '').toLowerCase()
  if (s === 'desglosado' || s === 'excluido') return t(idioma, 'ivaDesglosado')
  return t(idioma, 'ivaIncluido')
}

export function labelFiltro(idioma: unknown, label: string): string {
  const map: Record<string, string> = {
    MacroFamilia: 'filtroMacroFamilia',
    Familia: 'filtroFamilia',
    Subfamilia: 'filtroSubfamilia',
    Agrupacion: 'filtroAgrupacion',
    Articulo: 'filtroArticulo',
    Tienda: 'filtroTienda',
    Cliente: 'filtroCliente',
    Proveedor: 'filtroProveedor',
    Seccion: 'filtroSeccion',
    SubSeccion: 'filtroSubSeccion',
    Actividad: 'filtroActividad',
    'Tipo Descuento': 'filtroTipoDescuento',
  }
  const key = map[label]
  return key ? t(idioma, key) : label
}
