const { query } = require('../db')

const DIMENSIONES = ['vendedores']
const ORDENES = ['margen', 'importe', 'cantidad', 'coste', 'vendedor']
const VALORES = ['precioMedio', 'precioMedioActual', 'ultimoPrecio', 'sinValorTarifa']
const TIPOS_VENTA = {
  todos: { mode: 'todos' },
  ticket: { mode: 'facturaTipos', values: ['T'] },
  facturas: { mode: 'facturaTipos', values: ['F'] },
  ticketFacturas: { mode: 'facturaTipos', values: ['T', 'F'] },
  // Albaranes = documentos sin facturar (FacturaTipo vacio). En BD casi todos
  // los registros tienen Tipo='A'; el tipo de venta real esta en FacturaTipo.
  albaranes: { mode: 'albaranes' },
  // Provisional: mismos que Ticket+Facturas hasta confirmar criterio Contado.
  ticketsFacturasContado: { mode: 'facturaTipos', values: ['T', 'F'] },
}

function fechaDia(value) {
  if (value === null || value === undefined || value === '') return null
  const s = String(value).trim().slice(0, 10)
  if (!/^\d{4}-\d{2}-\d{2}$/.test(s)) return null
  return s
}

function filled(value) {
  return value !== null && value !== undefined && value !== ''
}

function parseBool(value, defaultValue = true) {
  if (value === undefined || value === null || value === '') return defaultValue
  if (typeof value === 'boolean') return value
  const s = String(value).toLowerCase()
  if (['false', '0', 'no', 'off'].includes(s)) return false
  if (['true', '1', 'yes', 'on'].includes(s)) return true
  return defaultValue
}

function totalesVacios() {
  return { unidades: 0, dto: 0, importe: 0, coste: 0, margen: 0, mAgr: 0 }
}

function redondearTotales(t) {
  return {
    unidades: round(t.unidades, 3),
    dto: round(t.dto, 2),
    importe: round(t.importe, 2),
    coste: round(t.coste, 2),
    margen: round(t.margen, 2),
    mAgr: round(t.mAgr ?? 0, 2),
  }
}

function round(n, digits) {
  const f = 10 ** digits
  return Math.round((Number(n) + Number.EPSILON) * f) / f
}

function normalizeTipoVenta(raw) {
  const s = String(raw ?? 'todos').trim()
  const key = s.toLowerCase().replace(/[\s+_]+/g, '')
  const aliases = {
    todos: 'todos',
    ticket: 'ticket',
    facturas: 'facturas',
    factura: 'facturas',
    ticketfacturas: 'ticketFacturas',
    ticketfactura: 'ticketFacturas',
    albaranes: 'albaranes',
    albaran: 'albaranes',
    ticketsfacturascontado: 'ticketsFacturasContado',
    ticketfacturascontado: 'ticketsFacturasContado',
    // Compatibilidad con valores antiguos T/A/F
    t: 'ticket',
    f: 'facturas',
    a: 'albaranes',
  }
  return aliases[key] || 'todos'
}

function tipoVentaLabel(tipoKey) {
  const labels = {
    todos: 'TODOS',
    ticket: 'TICKET',
    facturas: 'FACTURAS',
    ticketFacturas: 'TICKET+FACTURAS',
    albaranes: 'ALBARANES',
    ticketsFacturasContado: 'TICKETS+FACTURAS CONTADO',
  }
  return labels[tipoKey] || 'TODOS'
}

/**
 * Expresion SQL del coste unitario segun "Valor".
 * - precioMedio: PrecioMedio de linea / articulo (historico del documento)
 * - precioMedioActual: PrecioMedio actual del articulo
 * - ultimoPrecio: PrecioUltimo del articulo
 * - sinValorTarifa: 0 (provisional; confirmar si debe usar PrecioTarifa u otro criterio)
 */
function costeExpression(valor) {
  if (valor === 'ultimoPrecio') {
    return 'COALESCE(NULLIF(a.[PrecioUltimo], 0), NULLIF(a.[PrecioMedio], 0), 0)'
  }
  if (valor === 'precioMedioActual') {
    return 'COALESCE(NULLIF(a.[PrecioMedio], 0), 0)'
  }
  if (valor === 'sinValorTarifa') {
    return '0'
  }
  // precioMedio (documento / linea)
  return 'COALESCE(NULLIF(l.[PrecioMedio], 0), NULLIF(a.[PrecioMedio], 0), 0)'
}

function addRange(where, params, column, filtros, desdeKey, hastaKey, paramPrefix) {
  const desde = String(filtros[desdeKey] ?? '').trim()
  const hasta = String(filtros[hastaKey] ?? '').trim()
  if (desde !== '') {
    const key = `${paramPrefix}Desde`
    where.push(`${column} >= @${key}`)
    params[key] = desde
  }
  if (hasta !== '') {
    const key = `${paramPrefix}Hasta`
    where.push(`${column} <= @${key}`)
    params[key] = hasta
  }
}

function buildWhere(filtros, fechaDesde, fechaHasta) {
  const where = [
    "l.[Articulo] <> 'NO'",
    'c.[Fecha] >= CONVERT(datetime, @fechaDesde, 120)',
    'c.[Fecha] <= CONVERT(datetime, @fechaHasta, 120)',
  ]
  const params = {
    fechaDesde,
    fechaHasta,
  }

  const tipoKey = normalizeTipoVenta(filtros.tipoVenta)
  const tipoCfg = TIPOS_VENTA[tipoKey] || TIPOS_VENTA.todos

  // El tipo de venta del informe legado se distingue por FacturaTipo
  // (casi todos los documentos tienen c.Tipo = 'A').
  if (tipoCfg.mode === 'todos') {
    where.push(
      "(c.[FacturaTipo] IS NULL OR LTRIM(RTRIM(c.[FacturaTipo])) = '' OR LTRIM(RTRIM(c.[FacturaTipo])) IN ('F', 'A', 'T'))",
    )
  } else if (tipoCfg.mode === 'albaranes') {
    where.push(
      "(c.[FacturaTipo] IS NULL OR LTRIM(RTRIM(c.[FacturaTipo])) = '' OR LTRIM(RTRIM(c.[FacturaTipo])) = 'A')",
    )
  } else if (tipoCfg.mode === 'facturaTipos') {
    const values = tipoCfg.values || []
    if (values.length === 1) {
      where.push('LTRIM(RTRIM(c.[FacturaTipo])) = @tipoVenta')
      params.tipoVenta = values[0]
    } else if (values.length > 1) {
      const placeholders = values.map((_, i) => `@tipoVenta${i}`)
      values.forEach((t, i) => {
        params[`tipoVenta${i}`] = t
      })
      where.push(`LTRIM(RTRIM(c.[FacturaTipo])) IN (${placeholders.join(', ')})`)
    }
  }

  addRange(where, params, 'c.[Empresa]', filtros, 'tiendaDesde', 'tiendaHasta', 'tienda')
  addRange(where, params, 'c.[Vendedor]', filtros, 'vendedorDesde', 'vendedorHasta', 'vendedor')
  addRange(where, params, 'c.[Agente]', filtros, 'agenteDesde', 'agenteHasta', 'agente')
  addRange(where, params, 'c.[Representante]', filtros, 'representanteDesde', 'representanteHasta', 'representante')
  addRange(where, params, 'c.[Cliente]', filtros, 'clienteDesde', 'clienteHasta', 'cliente')
  addRange(where, params, 'a.[Familia]', filtros, 'familiaDesde', 'familiaHasta', 'familia')
  addRange(where, params, 'a.[Subfamilia]', filtros, 'subfamiliaDesde', 'subfamiliaHasta', 'subfamilia')
  addRange(where, params, 'f.[MacroFamilia]', filtros, 'macroFamiliaDesde', 'macroFamiliaHasta', 'macroFamilia')
  addRange(where, params, 'a.[Agrupacion]', filtros, 'agrupacionDesde', 'agrupacionHasta', 'agrupacion')
  addRange(where, params, 'l.[Articulo]', filtros, 'articuloDesde', 'articuloHasta', 'articulo')
  addRange(where, params, 'a.[UltProveedor]', filtros, 'proveedorDesde', 'proveedorHasta', 'proveedor')
  addRange(where, params, 'a.[Seccion]', filtros, 'seccionDesde', 'seccionHasta', 'seccion')
  addRange(where, params, 'a.[SubSeccion]', filtros, 'subSeccionDesde', 'subSeccionHasta', 'subSeccion')
  addRange(where, params, 'cl.[Actividad]', filtros, 'actividadDesde', 'actividadHasta', 'actividad')
  addRange(where, params, 'cl.[TipoDescuento]', filtros, 'tipoDescuentoDesde', 'tipoDescuentoHasta', 'tipoDescuento')

  if (filled(filtros.tarifa)) {
    where.push('cl.[Tarifa] = @tarifa')
    params.tarifa = Number(filtros.tarifa)
  }

  return { whereSql: where.join(' AND '), params, tipoKey }
}

function ordenValorArticulo(orden, { unidades, importe, coste, margen, codigo }) {
  if (orden === 'cantidad') return unidades
  if (orden === 'importe') return importe
  if (orden === 'coste') return coste
  if (orden === 'vendedor') return null // se ordena por codigo textual
  return margen
}

/**
 * Listado ABC Ventas — dimensión vendedores (formato Crystal).
 * @param {Record<string, unknown>} filtros
 */
async function generar(filtros = {}) {
  const dimension = String(filtros.dimension ?? 'vendedores').trim().toLowerCase()
  if (!DIMENSIONES.includes(dimension)) {
    const err = new Error('Dimension no soportada. Disponible: vendedores')
    err.code = 'VALIDACION'
    throw err
  }

  let orden = String(filtros.orden ?? 'margen').trim().toLowerCase()
  if (!ORDENES.includes(orden)) orden = 'margen'

  let valor = String(filtros.valor ?? 'precioMedio').trim()
  // compat legado
  if (valor === 'precioUltimo') valor = 'ultimoPrecio'
  const valorMap = {
    preciomedio: 'precioMedio',
    preciomedioactual: 'precioMedioActual',
    ultimoprecio: 'ultimoPrecio',
    sinvalortarifa: 'sinValorTarifa',
  }
  const valorNorm = valorMap[valor.toLowerCase()] || valor
  valor = VALORES.includes(valorNorm) ? valorNorm : 'precioMedio'

  const idioma = String(filtros.idioma ?? 'castellano').trim().toLowerCase() === 'catalan'
    ? 'catalan'
    : 'castellano'

  const ivaRaw = String(filtros.iva ?? 'incluido').trim().toLowerCase()
  // "desglosado" = importes sin IVA (antes "excluido")
  const ivaDesglosado = ivaRaw === 'desglosado' || ivaRaw === 'excluido'
  const iva = ivaDesglosado ? 'desglosado' : 'incluido'
  const ivaIncluido = !ivaDesglosado
  const imArticulos = parseBool(filtros.imArticulos, true)

  const fechaDesdeDia = fechaDia(filtros.fechaDesde)
  const fechaHastaDia = fechaDia(filtros.fechaHasta)
  if (!fechaDesdeDia || !fechaHastaDia) {
    const err = new Error('Fecha desde y fecha hasta son obligatorias (YYYY-MM-DD)')
    err.code = 'VALIDACION'
    throw err
  }
  const fechaDesde = `${fechaDesdeDia} 00:00:00`
  const fechaHasta = `${fechaHastaDia} 23:59:59`

  const { whereSql, params, tipoKey } = buildWhere(filtros, fechaDesde, fechaHasta)

  const factorIva = ivaIncluido
    ? '1.0'
    : '(1.0 / NULLIF(1.0 + ISNULL(l.[PjeIva], 0) / 100.0, 0))'

  const costeBase = costeExpression(valor)
  const pjeIvaCoste = 'COALESCE(NULLIF(l.[PjeIva], 0), ISNULL(i.[PjeIVA], 0), 0)'
  const costeUnit = ivaIncluido
    ? `(${costeBase} * (1.0 + (${pjeIvaCoste}) / 100.0))`
    : costeBase
  const costeExpr = `(${costeUnit} * ISNULL(l.[Cantidad], 0))`

  const cant = 'ISNULL(l.[Cantidad], 0)'
  const precio = 'ISNULL(l.[Precio], 0)'
  const impLin = 'ISNULL(l.[Importe], 0)'
  const pjeLin = 'ISNULL(l.[PjeDto], 0)'
  const pjeCab = 'ISNULL(c.[PjeDto], 0)'
  const dtoSinIva = `CASE
              WHEN ${pjeLin} <> 0 THEN
                CASE
                  WHEN ${pjeCab} <> 0 THEN
                    ((${cant} * ${precio}) - ${impLin}) + ((${impLin} / 100.0) * ${pjeCab})
                  ELSE
                    (${cant} * ${precio}) - ${impLin}
                END
              ELSE
                CASE
                  WHEN ${pjeCab} <> 0 THEN
                    (${impLin} / 100.0) * ${pjeCab}
                  ELSE
                    0
                END
            END`
  const dtoExpr = `(${factorIva} * (${dtoSinIva}))`
  const importeExpr = `(${factorIva} * ((${cant} * ${precio}) - (${dtoSinIva})))`
  const albaranKey = "RTRIM(c.[Empresa]) + N'|' + RTRIM(c.[Tipo]) + N'|' + CAST(c.[Albaran] AS nvarchar(20))"

  const sqlText = `SELECT
              RTRIM(ISNULL(c.[Vendedor], '')) AS vendedor,
              MAX(RTRIM(ISNULL(v.[Nombre], ''))) AS vendedorNombre,
              RTRIM(ISNULL(l.[Articulo], '')) AS articulo,
              MAX(RTRIM(ISNULL(a.[Descripcion], ''))) AS descripcion,
              SUM(ISNULL(l.[Cantidad], 0)) AS unidades,
              SUM(${dtoExpr}) AS dto,
              SUM(${importeExpr}) AS importe,
              SUM(${costeExpr}) AS coste,
              COUNT(DISTINCT ${albaranKey}) AS numAlbaranes
            FROM [AlbaranesVentasCab] c
            INNER JOIN [AlbaranesVentasLin] l
              ON c.[Empresa] = l.[Empresa] AND c.[Albaran] = l.[Albaran] AND c.[Tipo] = l.[Tipo]
            LEFT JOIN [Articulos] a ON a.[Codigo] = l.[Articulo]
            LEFT JOIN [Impuestos] i ON i.[Codigo] = COALESCE(NULLIF(RTRIM(a.[Impuesto]), ''), 'NO')
            LEFT JOIN [Vendedores] v ON RTRIM(v.[Codigo]) = RTRIM(c.[Vendedor])
            LEFT JOIN [Clientes] cl ON cl.[Codigo] = c.[Cliente]
            LEFT JOIN [Familias] f ON f.[Codigo] = a.[Familia]
            WHERE ${whereSql}
            GROUP BY RTRIM(ISNULL(c.[Vendedor], '')), RTRIM(ISNULL(l.[Articulo], ''))`

  const result = await query(sqlText, params)
  const rows = result.recordset || []

  const sqlVendAlbaranes = `SELECT
              RTRIM(ISNULL(c.[Vendedor], '')) AS vendedor,
              COUNT(DISTINCT ${albaranKey}) AS numAlbaranes
            FROM [AlbaranesVentasCab] c
            INNER JOIN [AlbaranesVentasLin] l
              ON c.[Empresa] = l.[Empresa] AND c.[Albaran] = l.[Albaran] AND c.[Tipo] = l.[Tipo]
            LEFT JOIN [Articulos] a ON a.[Codigo] = l.[Articulo]
            LEFT JOIN [Clientes] cl ON cl.[Codigo] = c.[Cliente]
            LEFT JOIN [Familias] f ON f.[Codigo] = a.[Familia]
            WHERE ${whereSql}
            GROUP BY RTRIM(ISNULL(c.[Vendedor], ''))`

  const vendAlbResult = await query(sqlVendAlbaranes, params)
  /** @type {Map<string, number>} */
  const albaranesPorVendedor = new Map()
  let albaranesGeneral = 0
  for (const row of vendAlbResult.recordset || []) {
    const vend = String(row.vendedor ?? '')
    const n = Number(row.numAlbaranes ?? 0)
    albaranesPorVendedor.set(vend, n)
    albaranesGeneral += n
  }

  /** @type {Map<string, { codigo: string, nombre: string, articulos: any[] }>} */
  const byVendor = new Map()

  for (const row of rows) {
    const vend = String(row.vendedor ?? '')
    if (!byVendor.has(vend)) {
      byVendor.set(vend, {
        codigo: vend,
        nombre: String(row.vendedorNombre ?? ''),
        articulos: [],
      })
    }

    const unidades = Number(row.unidades ?? 0)
    const dto = Number(row.dto ?? 0)
    const importe = Number(row.importe ?? 0)
    const coste = Number(row.coste ?? 0)
    const numAlbaranes = Number(row.numAlbaranes ?? 0)
    const margen = importe - coste
    const pjeMargen = importe !== 0 ? (100 * margen) / importe : 0
    const mAgr = numAlbaranes > 0 ? importe / numAlbaranes : 0
    const codigoArt = String(row.articulo ?? '')

    const ordenValor = ordenValorArticulo(orden, {
      unidades,
      importe,
      coste,
      margen,
      codigo: codigoArt,
    })

    byVendor.get(vend).articulos.push({
      codigo: codigoArt,
      descripcion: String(row.descripcion ?? ''),
      unidades: round(unidades, 3),
      dto: round(dto, 2),
      importe: round(importe, 2),
      coste: round(coste, 2),
      margen: round(margen, 2),
      pjeMargen: round(pjeMargen, 2),
      mAgr: round(mAgr, 2),
      _ordenValor: ordenValor,
    })
  }

  const grupos = []
  const totGeneral = totalesVacios()

  for (const grupo of byVendor.values()) {
    const arts = [...grupo.articulos]
    arts.sort((a, b) => {
      if (orden === 'vendedor') {
        return String(a.codigo).localeCompare(String(b.codigo), 'es')
      }
      const cmp = b._ordenValor - a._ordenValor
      if (cmp !== 0) return cmp
      return String(a.codigo).localeCompare(String(b.codigo), 'es')
    })

    const totGrupo = totalesVacios()
    for (const a of arts) {
      totGrupo.unidades += a.unidades
      totGrupo.dto += a.dto
      totGrupo.importe += a.importe
      totGrupo.coste += a.coste
      totGrupo.margen += a.margen
    }

    let baseSob = totGrupo.margen
    if (orden === 'cantidad') baseSob = totGrupo.unidades
    else if (orden === 'importe') baseSob = totGrupo.importe
    else if (orden === 'coste') baseSob = totGrupo.coste
    else if (orden === 'vendedor') baseSob = totGrupo.importe || 1
    if (baseSob === 0) baseSob = 1

    const lineas = []
    for (const a of arts) {
      const baseLinea = orden === 'vendedor' ? a.importe : a._ordenValor
      const sob = (100 * (baseLinea ?? 0)) / baseSob
      const { _ordenValor, ...rest } = a
      rest.pjeSobreTotal = round(sob, 2)
      if (imArticulos) lineas.push(rest)
    }

    const totales = redondearTotales(totGrupo)
    totales.pjeMargen = totales.importe !== 0
      ? round((100 * totales.margen) / totales.importe, 2)
      : 0
    totales.pjeSobreTotal = 100
    const nAlb = albaranesPorVendedor.get(grupo.codigo) || 0
    totales.mAgr = nAlb > 0 ? round(totales.importe / nAlb, 2) : 0

    let grupoOrden = totales.margen
    if (orden === 'cantidad') grupoOrden = totales.unidades
    else if (orden === 'importe') grupoOrden = totales.importe
    else if (orden === 'coste') grupoOrden = totales.coste
    else if (orden === 'vendedor') grupoOrden = 0

    grupos.push({
      codigo: grupo.codigo,
      nombre: grupo.nombre,
      totales,
      articulos: lineas,
      _ordenValor: grupoOrden,
      _ordenCodigo: grupo.codigo,
    })

    totGeneral.unidades += totales.unidades
    totGeneral.dto += totales.dto
    totGeneral.importe += totales.importe
    totGeneral.coste += totales.coste
    totGeneral.margen += totales.margen
  }

  grupos.sort((a, b) => {
    if (orden === 'vendedor') {
      return String(a._ordenCodigo).localeCompare(String(b._ordenCodigo), 'es')
    }
    const cmp = b._ordenValor - a._ordenValor
    if (cmp !== 0) return cmp
    return String(a.codigo).localeCompare(String(b.codigo), 'es')
  })

  for (const g of grupos) {
    delete g._ordenValor
    delete g._ordenCodigo
  }

  const totales = redondearTotales(totGeneral)
  totales.pjeMargen = totales.importe !== 0
    ? round((100 * totales.margen) / totales.importe, 2)
    : 0
  totales.pjeSobreTotal = 100
  totales.mAgr = albaranesGeneral > 0 ? round(totales.importe / albaranesGeneral, 2) : 0

  return {
    dimension,
    orden,
    valor,
    iva,
    idioma,
    imArticulos,
    tipoVenta: tipoVentaLabel(tipoKey),
    divisa: String(filtros.divisa ?? 'EU'),
    fechaDesde: fechaDesdeDia,
    fechaHasta: fechaHastaDia,
    totales,
    grupos,
  }
}

module.exports = { generar }
