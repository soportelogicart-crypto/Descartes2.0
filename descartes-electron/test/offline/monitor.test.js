const test = require('node:test')
const assert = require('node:assert/strict')
const { red } = require('./simulador')
const monitor = require('../../electron/offline/monitor')

function reset() {
  monitor.detener()
  monitor.conectado = true
  monitor.inestable = false
  monitor.fallos = 0
  monitor.exitos = 0
  monitor.confirmadoPorUi = false
  monitor.apiBase = 'http://srv'
}

test.afterEach(() => monitor.detener())

test('Apache parado: el 500 del proxy, confirmado por la ventana, pasa a local', async () => {
  reset()
  red.responder = () => ({ status: 500, datos: null })
  monitor.avisarFallo()
  await monitor.comprobar()
  assert.equal(monitor.conectado, false)
})

test('un 500 con JSON lo ha contestado el PHP y no corta', async () => {
  reset()
  red.responder = () => ({ status: 500, datos: { error: 'fallo', codigo: 'ERROR' } })
  monitor.avisarFallo()
  await monitor.comprobar()
  assert.equal(monitor.conectado, true)
})

test('sin aviso de la ventana hacen falta tres fallos', async () => {
  reset()
  red.responder = () => ({ status: 500, datos: null })
  await monitor.comprobar()
  monitor.detener()
  assert.equal(monitor.conectado, true)
  await monitor.comprobar()
  monitor.detener()
  assert.equal(monitor.conectado, true)
  await monitor.comprobar()
  assert.equal(monitor.conectado, false)
})
