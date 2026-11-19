const test = require('node:test')
const assert = require('node:assert/strict')
const {
  rutaRaizValida,
  nombreValido,
  rutaCarpetaCliente,
  rutaDentro,
  explicarAcceso,
} = require('../electron/carpeta-compartida-rutas')

test('acepta solo rutas UNC como raíz', () => {
  assert.equal(rutaRaizValida('\\\\PC-CAJA\\Descartes'), '\\\\PC-CAJA\\Descartes')
  assert.equal(rutaRaizValida('\\\\PC-CAJA\\Descartes\\'), '\\\\PC-CAJA\\Descartes')
  assert.equal(rutaRaizValida('  \\\\Ordenador de Ponç\\Carpeta Compartida  '), '\\\\Ordenador de Ponç\\Carpeta Compartida')
  assert.equal(rutaRaizValida('Z:\\Descartes'), null)
  assert.equal(rutaRaizValida('C:\\Descartes'), null)
  assert.equal(rutaRaizValida('\\\\PC'), null)
  assert.equal(rutaRaizValida('\\\\PC\\'), null)
  assert.equal(rutaRaizValida('\\\\PC\\..\\otra'), null)
  assert.equal(rutaRaizValida(''), null)
  assert.equal(rutaRaizValida(null), null)
})

test('nombres: sin separadores, sin prohibidos y sin punto final', () => {
  assert.equal(nombreValido('Facturas 2026'), true)
  assert.equal(nombreValido('logo.png'), true)
  assert.equal(nombreValido('..'), false)
  assert.equal(nombreValido('.'), false)
  assert.equal(nombreValido('a\\b'), false)
  assert.equal(nombreValido('a/b'), false)
  assert.equal(nombreValido('mal*nombre'), false)
  assert.equal(nombreValido('acaba-en-punto.'), false)
  assert.equal(nombreValido(''), false)
})

test('rutaDentro resuelve subcarpetas y no sale de la raíz', () => {
  const raiz = '\\\\PC-CAJA\\Descartes'
  assert.equal(rutaDentro(raiz), raiz)
  assert.equal(rutaDentro(raiz, 'Documentos\\2026'), '\\\\PC-CAJA\\Descartes\\Documentos\\2026')
  assert.equal(rutaDentro(raiz, 'Documentos', 'albaran.pdf'), '\\\\PC-CAJA\\Descartes\\Documentos\\albaran.pdf')
  assert.equal(rutaDentro(raiz, 'Documentos/2026'), '\\\\PC-CAJA\\Descartes\\Documentos\\2026')

  assert.equal(rutaDentro(raiz, '..'), null)
  assert.equal(rutaDentro(raiz, 'Documentos\\..\\..\\Windows'), null)
  assert.equal(rutaDentro(raiz, '', '..'), null)
  assert.equal(rutaDentro(raiz, 'Documentos', 'x\\y'), null)
  assert.equal(rutaDentro(raiz, 'C:\\Windows'), null)
})

test('la carpeta del cliente queda dentro de Clientes y no sale con .. ni separadores', () => {
  const raiz = '\\\\PC-CAJA\\Descartes'
  assert.equal(rutaCarpetaCliente(raiz, 'C0001'), '\\\\PC-CAJA\\Descartes\\Clientes\\C0001')
  assert.equal(rutaCarpetaCliente(raiz, 'A\\B'), '\\\\PC-CAJA\\Descartes\\Clientes\\AB')
  assert.equal(rutaCarpetaCliente(raiz, '..\\..\\Windows'), '\\\\PC-CAJA\\Descartes\\Clientes\\Windows')
  assert.equal(rutaCarpetaCliente(raiz, '..'), null)
  assert.equal(rutaCarpetaCliente(raiz, '   '), null)
  assert.equal(rutaCarpetaCliente('C:\\local', 'C0001'), null)
  const rara = rutaCarpetaCliente(raiz, '..\\..\\Windows')
  assert.ok(rara.startsWith(raiz + '\\Clientes\\'))
  assert.equal(rara.includes('..'), false)
})

test('UNKNOWN en el propio PC sin recurso compartido explica que hay que compartir', () => {
  const msg = explicarAcceso('\\\\DESKTOP-L8F6T4Q\\datos-descartes', 'UNKNOWN', {
    mismoEquipo: true,
    recursoExiste: false,
  })
  assert.match(msg, /no hay un recurso compartido llamado «datos-descartes»/)
  assert.match(msg, /Uso compartido avanzado/)
})

test('UNKNOWN de otro PC no culpa al recurso local', () => {
  const msg = explicarAcceso('\\\\SERVIDOR\\Descartes', 'UNKNOWN', { mismoEquipo: false })
  assert.match(msg, /No se llega/)
  assert.doesNotMatch(msg, /Uso compartido avanzado/)
})
