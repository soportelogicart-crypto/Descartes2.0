const { query } = require('../db')

/**
 * Catalogos maestros para busqueda desde filtros ABC.
 * Whitelist fija: nunca se interpola el nombre de tabla desde el cliente.
 */
const ENTIDADES = {
  empresas: {
    table: 'Empresas_Ges',
    codeCol: 'Codigo',
    labelCol: 'Nombre',
    labelHeader: 'Nombre',
  },
  macroFamilias: {
    table: 'MacroFamilias',
    codeCol: 'Codigo',
    labelCol: 'Descripcion',
    labelHeader: 'Descripcion',
  },
  familias: {
    table: 'Familias',
    codeCol: 'Codigo',
    labelCol: 'Descripcion',
    labelHeader: 'Descripcion',
  },
  subfamilias: {
    table: 'Subfamilias',
    codeCol: 'Subfamilia',
    labelCol: 'Descripción',
    labelHeader: 'Descripcion',
  },
  agrupaciones: {
    table: 'Agrupaciones',
    codeCol: 'Codigo',
    labelCol: 'Descripcion',
    labelHeader: 'Descripcion',
  },
  articulos: {
    table: 'Articulos',
    codeCol: 'Codigo',
    labelCol: 'Descripcion',
    labelHeader: 'Descripcion',
  },
  clientes: {
    table: 'Clientes',
    codeCol: 'Codigo',
    labelCol: 'RazonSocial',
    labelHeader: 'Razon social',
  },
  proveedores: {
    table: 'Proveedores',
    codeCol: 'Codigo',
    labelCol: 'RazonSocial',
    labelHeader: 'Razon social',
  },
  secciones: {
    table: 'Secciones',
    codeCol: 'Codigo',
    labelCol: 'Descripcion',
    labelHeader: 'Descripcion',
  },
  subSecciones: {
    table: 'SubSecciones',
    codeCol: 'Codigo',
    labelCol: 'Descripcion',
    labelHeader: 'Descripcion',
  },
  actividades: {
    table: 'Actividades',
    codeCol: 'Codigo',
    labelCol: 'Descripcion',
    labelHeader: 'Descripcion',
  },
}

/**
 * @param {string} entidad
 * @param {string} [q]
 * @param {{ limit?: number }} [opts]
 */
async function buscar(entidad, q = '', opts = {}) {
  const meta = ENTIDADES[String(entidad ?? '').trim()]
  if (!meta) {
    const err = new Error(`Entidad de busqueda no soportada: ${entidad}`)
    err.code = 'VALIDACION'
    throw err
  }

  const limit = Math.min(Math.max(Number(opts.limit) || 200, 1), 500)
  const term = String(q ?? '').trim()
  const like = `%${term.replace(/[%_[\]]/g, '')}%`

  const sqlText = `
    SELECT TOP (${limit})
      RTRIM(CAST(t.[${meta.codeCol}] AS nvarchar(100))) AS codigo,
      RTRIM(ISNULL(CAST(t.[${meta.labelCol}] AS nvarchar(200)), N'')) AS etiqueta
    FROM [${meta.table}] t
    WHERE
      @q = N''
      OR RTRIM(CAST(t.[${meta.codeCol}] AS nvarchar(100))) LIKE @like
      OR RTRIM(ISNULL(CAST(t.[${meta.labelCol}] AS nvarchar(200)), N'')) LIKE @like
    ORDER BY RTRIM(CAST(t.[${meta.codeCol}] AS nvarchar(100)))
  `

  const result = await query(sqlText, { q: term, like })
  const items = (result.recordset || []).map((row) => ({
    codigo: String(row.codigo ?? '').trim(),
    etiqueta: String(row.etiqueta ?? '').trim(),
  }))

  return {
    entidad,
    labelHeader: meta.labelHeader,
    items,
  }
}

function entidadesDisponibles() {
  return Object.keys(ENTIDADES)
}

module.exports = { buscar, entidadesDisponibles, ENTIDADES }
