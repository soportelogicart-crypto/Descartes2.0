const sql = require('mssql')
const { loadConfig } = require('./config')

let poolPromise = null

function buildSqlConfig() {
  const cfg = loadConfig().database || {}
  return {
    server: cfg.server || 'localhost',
    database: cfg.database || 'larasa',
    user: cfg.user || '',
    password: cfg.password || '',
    port: cfg.port || 1433,
    options: {
      encrypt: cfg.options?.encrypt === true,
      trustServerCertificate: cfg.options?.trustServerCertificate !== false,
      enableArithAbort: true,
    },
    pool: {
      max: 10,
      min: 0,
      idleTimeoutMillis: 30000,
    },
  }
}

function getPool() {
  if (!poolPromise) {
    const cfg = buildSqlConfig()
    poolPromise = new sql.ConnectionPool(cfg)
      .connect()
      .then((pool) => {
        console.log(`[abc-ventas] SQL Server conectado: ${cfg.server}/${cfg.database}`)
        pool.on('error', (err) => {
          console.error('[abc-ventas] Error pool SQL:', err)
          poolPromise = null
        })
        return pool
      })
      .catch((err) => {
        poolPromise = null
        throw err
      })
  }
  return poolPromise
}

async function query(sqlText, params = {}) {
  const pool = await getPool()
  const request = pool.request()
  for (const [key, value] of Object.entries(params)) {
    request.input(key, value)
  }
  return request.query(sqlText)
}

async function closePool() {
  if (!poolPromise) return
  try {
    const pool = await poolPromise
    await pool.close()
  } catch {
    // ignore
  } finally {
    poolPromise = null
  }
}

/** Cierra el pool actual para forzar reconexión con la config nueva. */
async function resetPool() {
  await closePool()
  return getPool()
}

module.exports = {
  sql,
  getPool,
  query,
  closePool,
  resetPool,
}
