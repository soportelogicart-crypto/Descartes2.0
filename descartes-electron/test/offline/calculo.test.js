const test = require('node:test')
const assert = require('node:assert/strict')
const {
  calcularTotales,
  claveCache,
  descuentoLinea,
  importeLinea,
  normalizar,
  numeroOffline,
} = require('../../electron/offline/calculo')

test('importe de línea con descuento redondeado a céntimos', () => {
  assert.equal(importeLinea(3, 1.99), 5.97)
  assert.equal(importeLinea(2, 10, 15), 17)
  assert.equal(importeLinea(1, 0.333, 0), 0.33)
})

test('totales con IVA incluido: el importe es la suma de líneas', () => {
  const t = calcularTotales(
    [
      { articulo: 'A1', cantidad: 2, precio: 12.1, pjeIva: 21 },
      { articulo: 'B2', cantidad: 1, precio: 10.4, pjeIva: 4 },
      { articulo: 'NO', cantidad: 1, precio: 99, pjeIva: 21 },
    ],
    { ivaIncluido: true }
  )
  assert.equal(t.importe, 34.6)
  assert.deepEqual(t.importesIva, [
    { pjeIva: 4, base: 10, iva: 0.4 },
    { pjeIva: 21, base: 20, iva: 4.2 },
  ])
  assert.equal(t.iva, 4.6)
})

test('totales sin IVA incluido y descuento de cliente sobre las bases', () => {
  const t = calcularTotales([{ articulo: 'A1', cantidad: 1, precio: 100, pjeIva: 21 }], {
    ivaIncluido: false,
    dto1: 10,
  })
  assert.equal(t.descuento, 10)
  assert.deepEqual(t.importesIva, [{ pjeIva: 21, base: 90, iva: 18.9 }])
  assert.equal(t.importe, 108.9)
})

test('descuento de oferta: gana la más concreta y respeta fechas y tiendas', () => {
  const clase = { codigo: 'A1', familia: 'F1', subfamilia: 'S1', macrofamilia: 'M1', proveedor: 'P1' }
  const ofertas = [
    { tipo: '.', codigo: '', fecha_inicio: '2026-01-01', fecha_fin: null, descuento: 5, bloqueos: '[]' },
    { tipo: 'F', codigo: 'F1', fecha_inicio: '2026-01-01', fecha_fin: null, descuento: 10, bloqueos: '[]' },
    { tipo: 'A', codigo: 'A1', fecha_inicio: '2026-01-01', fecha_fin: '2026-02-01', descuento: 30, bloqueos: '[]' },
    { tipo: 'A', codigo: 'A1', fecha_inicio: '2026-01-01', fecha_fin: null, descuento: 20, bloqueos: '["21"]' },
  ]
  const base = { ofertas, clase, cliente: 'C001', fecha: '2026-10-27' }
  assert.equal(descuentoLinea({ ...base, empresa: '1' }), 20)
  assert.equal(descuentoLinea({ ...base, empresa: '21' }), 10)
  assert.equal(descuentoLinea({ ...base, cliente: 'ZZZZZZZZZ', empresa: '1' }), 0)
})

test('clave de caché independiente del orden de los parámetros', () => {
  assert.equal(
    claveCache('/api/tpv/contexto?puesto=01', { empresa: '1' }),
    claveCache('/api/tpv/contexto', { empresa: '1', puesto: '01' })
  )
  assert.equal(claveCache('/api/x', { a: undefined }), '/api/x')
})

test('número de la serie del puesto y texto de búsqueda', () => {
  assert.equal(numeroOffline('01', 123), 'OFF-01-000123')
  assert.equal(numeroOffline(' p-2 ', 7), 'OFF-P2-000007')
  assert.equal(normalizar('  Camión ÁGUILA '), 'camion aguila')
})
