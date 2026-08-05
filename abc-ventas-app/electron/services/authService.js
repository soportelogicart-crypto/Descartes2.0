const { query } = require('../db')

/**
 * Login contra tabla Vendedores (Codigo + PassWord).
 * PassWord es int en legado; se compara como texto recortado.
 * @param {string} usuario  Codigo del vendedor
 * @param {string} password
 */
async function login(usuario, password) {
  const codigo = String(usuario ?? '').trim()
  const pass = String(password ?? '').trim()

  if (!codigo || !pass) {
    const err = new Error('Codigo y contraseña son obligatorios')
    err.code = 'VALIDACION'
    throw err
  }

  const result = await query(
    `SELECT TOP 1
       RTRIM([Codigo]) AS codigo,
       RTRIM(ISNULL([Nombre], '')) AS nombre,
       RTRIM(ISNULL([Usuario], '')) AS usuario
     FROM [Vendedores]
     WHERE RTRIM(ISNULL([Codigo], '')) = @codigo
       AND RTRIM(CAST([PassWord] AS nvarchar(50))) = @password`,
    { codigo, password: pass }
  )

  const row = result.recordset?.[0]
  if (!row) {
    const err = new Error('Codigo o contraseña incorrectos')
    err.code = 'AUTH'
    throw err
  }

  return {
    codigo: String(row.codigo ?? ''),
    nombre: String(row.nombre ?? ''),
    usuario: String(row.usuario ?? ''),
  }
}

module.exports = { login }
