const { query, closePool } = require('../electron/db')
const { generar } = require('../electron/services/abcVentasService')

async function main() {
  const sample = await query(`
    SELECT TOP 5 CONVERT(varchar(10), Fecha, 120) AS f,
           RTRIM(Vendedor) AS v,
           RTRIM(ISNULL(FacturaTipo,'')) AS ft
    FROM AlbaranesVentasCab
    WHERE FacturaTipo IN ('F','A','T')
       OR FacturaTipo IS NULL
       OR LTRIM(RTRIM(FacturaTipo)) = ''
    ORDER BY Fecha DESC
  `)
  console.log('sample F/A/T', sample.recordset)

  const r = await generar({
    dimension: 'vendedores',
    orden: 'margen',
    iva: 'incluido',
    imArticulos: true,
    valor: 'precioMedio',
    tipoVenta: 'todos',
    fechaDesde: '2026-01-01',
    fechaHasta: '2026-07-24',
  })
  console.log('grupos', r.grupos.length, 'importe', r.totales.importe)
  if (r.grupos[0]) {
    console.log({
      codigo: r.grupos[0].codigo,
      nombre: r.grupos[0].nombre,
      arts: r.grupos[0].articulos.length,
      importe: r.grupos[0].totales.importe,
    })
  }
  await closePool()
}

main().catch((e) => {
  console.error(e)
  process.exit(1)
})
