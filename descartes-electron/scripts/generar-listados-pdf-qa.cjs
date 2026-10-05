const { app, BrowserWindow } = require('electron')
const fs = require('node:fs')
const path = require('node:path')

const VITE_URL = process.env.DESCARTES_VITE_URL || 'http://127.0.0.1:5173'
const outputDir = path.resolve(__dirname, '..', '..', 'Detalles', 'listados', 'qa-pdf')

async function cargarGeneradores() {
  const win = new BrowserWindow({
    show: false,
    webPreferences: { contextIsolation: true, sandbox: true },
  })
  await win.loadURL(`${VITE_URL}/login`)

  const documentos = await win.webContents.executeJavaScript(`
    (async () => {
      const listado = await import('/src/composables/imprimirListadoHtml.ts')
      const estilos = await import('/src/composables/listadoPrintStyles.ts')
      const compras = await import('/src/composables/abcComprasInformeHtml.ts')
      const ventas = await import('/src/composables/abcVentasInformeHtml.ts')
      const tickets = await import('/src/composables/informeTicketsPlantillas.ts')

      const fila = (n, f) => Array.from({ length: n }, (_, i) => f(i))
      const envolver = (nombre, html, forzarVertical = false) => {
        const orientacion = forzarVertical ? 'vertical' : estilos.orientacionDocumentoHtml(html)
        return {
          nombre,
          landscape: orientacion === 'horizontal',
          html: orientacion === 'horizontal' ? estilos.aplicarApaisadoHtml(html) : html,
        }
      }
      const tabla = (nombre, opciones) => {
        const html = listado.construirListadoHtml(opciones)
        return { nombre, html, landscape: html.includes('A4 landscape') }
      }

      const docs = []
      docs.push(tabla('01-stock', {
        titulo: 'Stock por artículo',
        metaLineas: ['Almacén: Todos', 'Agrupación: Artículo'],
        thead: ['Código', 'Descripción', 'Unidades', 'Artículos'],
        filas: fila(28, i => [
          String(1000 + i),
          'Artículo de prueba ' + (i + 1),
          (1200 - i * 7).toLocaleString('es-ES', { minimumFractionDigits: 2 }),
          String(i + 1),
        ]),
        pie: ['Total unidades: 30.905,00'],
      }))
      docs.push(tabla('02-stock-minimos', {
        titulo: 'Stock bajo mínimos',
        metaLineas: ['Almacén: 01 · Proveedor: Todos'],
        thead: ['Artículo', 'Descripción', 'Alm.', 'Stock', 'Mínimo', 'Óptimo', 'Faltan', 'Prov.'],
        filas: fila(26, i => [
          String(2000 + i), 'Artículo con stock insuficiente ' + (i + 1), '01',
          String(i), '10,00', '25,00', String(25 - i), 'P' + String(i % 4 + 1).padStart(2, '0'),
        ]),
        pie: ['26 artículos bajo mínimos'],
      }))
      docs.push(tabla('03-extracto-clientes', {
        titulo: 'Extracto de clientes',
        metaLineas: ['Cliente: 00001 CLIENTE PRUEBA', 'Fecha: 01/01/2026 - 01/10/2026'],
        thead: ['Fecha', 'Documento', 'Tienda', 'Concepto', 'Debe', 'Haber', 'Saldo'],
        filas: fila(30, i => [
          String(i + 1).padStart(2, '0') + '/09/2026', 'A 2600' + String(i).padStart(4, '0'),
          '01', i % 3 ? 'Venta a crédito' : 'Cobro recibido',
          i % 3 ? '125,50' : '', i % 3 ? '' : '200,00', (1000 + i * 25.5).toLocaleString('es-ES'),
        ]),
        pie: ['Debe: 2.510,00 € · Haber: 2.000,00 € · Saldo: 510,00 €'],
      }))
      docs.push(tabla('04-informe-iva', {
        titulo: 'Informe de IVA',
        metaLineas: ['Fecha: 01/09/2026 - 30/09/2026', 'Tiendas: Todas'],
        thead: [
          'Fecha', 'Documento', 'Tienda', 'Cliente',
          'Base 4%', 'IVA 4%', 'Base 10%', 'IVA 10%', 'Base 21%', 'IVA 21%', 'Total',
        ],
        filas: fila(24, i => [
          '30/09/2026', 'T 2600' + String(i).padStart(4, '0'), '01', 'CLIENTE ' + (i + 1),
          '0,00', '0,00', '100,00', '10,00', '250,00', '52,50', '412,50',
        ]),
        pie: ['24 documentos · Base: 8.400,00 € · Cuota: 1.500,00 € · Total: 9.900,00 €'],
      }))
      docs.push(tabla('05-mantenimiento-clientes', {
        titulo: 'Listado de clientes',
        metaLineas: ['Filtro: Activos · 30 filas'],
        thead: ['Código', 'Razón social', 'NIF', 'Dirección', 'Población', 'Provincia', 'CP', 'Teléfono', 'Email'],
        filas: fila(30, i => [
          String(i + 1).padStart(5, '0'), 'CLIENTE DE PRUEBA ' + (i + 1), 'B1234567' + (i % 10),
          'CALLE MAYOR ' + (i + 1), 'BARCELONA', 'BARCELONA', '08001',
          '934000' + String(i).padStart(3, '0'), 'cliente' + i + '@empresa.example',
        ]),
        pie: ['30 fila(s)'],
      }))

      const totalVentas = {
        unidades: 73, dto: 4.24, importe: 36023.16, coste: 945.36,
        margen: 35077.80, pjeMargen: 97.38, pjeSobreTotal: 100, tickets: 58,
      }
      const grupoVentas = (codigo, nombre, importe, pje) => ({
        codigo, nombre,
        totales: { ...totalVentas, unidades: 7, importe, pjeSobreTotal: pje },
        articulos: [{
          codigo, descripcion: nombre, unidades: 7, dto: 0, importe,
          coste: importe * 0.4, margen: importe * 0.6, pjeMargen: 60,
          pjeSobreTotal: pje, mAgr: 12,
        }],
      })
      const ventasBase = {
        orden: 'importe', valor: 'precioMedio', iva: 'incluido', imArticulos: true,
        tipoVenta: 'todas', divisa: 'EU', fechaDesde: '2026-01-01',
        fechaHasta: '2026-10-01', totales: totalVentas,
      }
      const ventasArticulos = ventas.construirHtmlInformeAbcVentas({
        ...ventasBase, dimension: 'articulos', agrupacionArticulos: 'sinAgrupacion',
        formatoArticulos: 'normal',
        grupos: fila(24, i => grupoVentas(
          String(4000 + i), 'Artículo vendido con descripción larga ' + (i + 1),
          2000 - i * 55, 10 - i * 0.2,
        )),
      }, {
        tituloCabecera: 'ABC de ventas (Artículos)',
        periodo: 'Fecha 01/01/2026 - 01/10/2026',
        ivaLabel: 'IVA incluido',
      })
      docs.push(envolver('06-abc-ventas-articulos', ventasArticulos))
      const dias = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo']
      const ventasDias = ventas.construirHtmlInformeAbcVentas({
        ...ventasBase, dimension: 'dias-semana', informePlanoDiasSemanaAbc: true,
        graficoPor: 'importe',
        grupos: dias.map((nombre, i) => grupoVentas(String(i + 1), nombre, 7000 - i * 700, 25 - i * 2)),
      }, {
        tituloCabecera: 'ABC de ventas (Días de la semana)',
        periodo: 'Fecha 01/01/2026 - 01/10/2026',
        ivaLabel: 'IVA incluido',
      })
      docs.push(envolver('07-abc-ventas-dias-semana', ventasDias))

      const totalCompras = {
        unidades: 190, dto: 12, importe: 28800, coste: 21400,
        margen: 7400, pjeMargen: 25.69, pjeSobreTotal: 100,
      }
      const gruposCompras = fila(8, i => ({
        codigo: String(3000 + i),
        nombre: 'FAMILIA DE COMPRAS ' + (i + 1),
        totales: { ...totalCompras, unidades: 24, importe: 3600, pjeSobreTotal: 12.5 },
        articulos: fila(3, j => ({
          codigo: String(5000 + i * 10 + j),
          descripcion: 'Artículo comprado con descripción extensa ' + (j + 1),
          unidades: 8, dto: 1.5, importe: 1200, coste: 900, margen: 300,
          pjeMargen: 25, pjeSobreTotal: 4.17, mAgr: 100,
        })),
      }))
      const comprasHtml = compras.construirHtmlInformeAbcCompras({
        dimension: 'familias', orden: 'importe', valor: 'precioMedio',
        imArticulos: 'desglosado', divisa: 'EU', formatoJerarquia: 'normal',
        mostrarTotalGrupo: true, fechaDesde: '2026-01-01', fechaHasta: '2026-10-01',
        totales: totalCompras, grupos: gruposCompras,
      }, {
        tituloCabecera: 'ABC de compras (Familias)',
        periodo: 'Fecha 01/01/2026 - 01/10/2026',
      })
      docs.push(envolver('08-abc-compras-familias', comprasHtml))

      const perfil = {
        efectivo: { importe: 450.76, conteo: 24, pje: 19.85 },
        cheques: { importe: 0, conteo: 0, pje: 0 },
        tarjetas: { importe: 1215.38, conteo: 39, pje: 78.4 },
        creditos: { importe: 0, conteo: 0, pje: 0 },
        vales: { importe: 0, conteo: 0, pje: 0 },
        otros: { importe: 0, conteo: 0, pje: 1.75 },
      }
      const desgloseIva = [0, 4, 7, 10, 21].map(p => ({
        pjeIva: p, pjeRecargo: 0,
        base: p === 10 ? 698.23 : p === 21 ? 742.23 : 0,
        iva: p === 10 ? 69.79 : p === 21 ? 155.89 : 0,
        recargo: 0, importe: 0,
      }))
      const resumen = {
        empresa: '1', tiendaNombre: 'TIENDA DE PRUEBA', fecha: '2026-09-30',
        tickets: 36, ticketsVenta: 35, abonos: 1, numeroArticulos: 122,
        primerTicket: 26000001, ultimoTicket: 26000036, base: 1440.46,
        iva: 225.68, recargo: 0, importe: 1666.14, importeMinimo: -0.3,
        importeMaximo: 190, importeMedio: 46.28, desgloseIva, formasPago: [],
        perfilPago: perfil,
      }
      const items = fila(36, i => ({
        empresa: '1', tiendaNombre: 'TIENDA DE PRUEBA', albaran: 1000 + i,
        fecha: '2026-09-30', numeroTicket: 26000001 + i, puesto: '01',
        vendedor: '1', cliente: 'CONTADO', razonSocial: 'CONTADO', nif: 'ZZZZZZZZ',
        importe: i % 3 ? 22.5 : 5.4, sesion: 3, estado: '', formaPago: i % 2 ? 'DT' : 'EU',
        fpago1: i % 2 ? 'DT' : 'EU', fpago2: '', impFpago1: i % 3 ? 22.5 : 5.4,
        impFpago2: 0, base: i % 3 ? 20.21 : 4.91, iva: i % 3 ? 2.29 : 0.49,
        recargo: 0, numeroArticulos: 2,
        desgloseIva: i % 3
          ? [
              { pjeIva: 10, base: 17.73, iva: 1.77, pjeRecargo: 0, recargo: 0, importe: 19.5 },
              { pjeIva: 21, base: 2.48, iva: 0.52, pjeRecargo: 0, recargo: 0, importe: 3 },
            ]
          : [{ pjeIva: 10, base: 4.91, iva: 0.49, pjeRecargo: 0, recargo: 0, importe: 5.4 }],
      }))
      const ticketBase = {
        fechaDesde: '2026-09-30', fechaHasta: '2026-09-30', empresa: '1',
        puesto: '', vendedor: '', divisa: 'EU', soloNumerados: true, items,
        resumenDiario: [resumen], resumenTiendas: [{ ...resumen, fecha: null }],
        totales: { ...resumen, fecha: null }, truncado: false, limite: 5000,
      }
      for (const [formato, nombre] of [
        ['desglosado', '09-tickets-desglosado'],
        ['resumido', '10-tickets-resumido'],
        ['superResumido', '11-tickets-super-resumido'],
        ['exportar', '12-tickets-diario-iva'],
      ]) {
        const data = {
          ...ticketBase,
          formato,
          exportar: formato === 'exportar'
            ? items.flatMap(t => t.desgloseIva.map(d => ({
                empresa: t.empresa, tiendaNombre: t.tiendaNombre, fecha: t.fecha,
                numeroTicket: t.numeroTicket, tipo: 'Ticket', cliente: t.cliente,
                razonSocial: t.razonSocial, nif: t.nif, ...d,
              })))
            : [],
        }
        docs.push(envolver(nombre, tickets.construirHtmlInformeTicketsLegacy(data), true))
      }
      return docs
    })()
  `)

  return { documentos, win }
}

async function imprimir(win, documento) {
  await win.webContents.executeJavaScript(`
    document.open()
    document.write(${JSON.stringify(documento.html)})
    document.close()
  `)
  await new Promise((resolve) => setTimeout(resolve, 120))
  const diagnostico = await win.webContents.executeJavaScript(`
    (() => {
      const tablas = [...document.querySelectorAll('table')]
      const desbordadas = tablas.filter(t => t.scrollWidth > t.clientWidth + 2).length
      return { tablas: tablas.length, desbordadas, ancho: document.documentElement.scrollWidth }
    })()
  `)
  const pdf = await win.webContents.printToPDF({
    pageSize: 'A4',
    landscape: documento.landscape,
    printBackground: true,
    margins: { marginType: 'none' },
  })
  const destino = path.join(outputDir, `${documento.nombre}.pdf`)
  fs.writeFileSync(destino, pdf)
  return { ...documento, ...diagnostico, destino }
}

app.whenReady().then(async () => {
  try {
    fs.mkdirSync(outputDir, { recursive: true })
    const { documentos, win } = await cargarGeneradores()
    const resultados = []
    for (const documento of documentos) resultados.push(await imprimir(win, documento))
    win.destroy()
    for (const r of resultados) {
      console.log(
        `${r.nombre}: ${r.landscape ? 'horizontal' : 'vertical'} · ` +
        `${r.tablas} tabla(s) · desbordadas=${r.desbordadas} · ${r.destino}`,
      )
    }
    fs.writeFileSync(
      path.join(outputDir, 'resultado.json'),
      JSON.stringify(
        resultados.map(({ nombre, landscape, tablas, desbordadas, ancho, destino }) => ({
          nombre,
          orientacion: landscape ? 'horizontal' : 'vertical',
          tablas,
          tablasDesbordadas: desbordadas,
          anchoDocumentoPx: ancho,
          archivo: path.basename(destino),
        })),
        null,
        2,
      ),
      'utf8',
    )
    if (resultados.some((r) => r.desbordadas > 0)) process.exitCode = 2
  } catch (error) {
    console.error(error)
    process.exitCode = 1
  } finally {
    app.quit()
  }
})
