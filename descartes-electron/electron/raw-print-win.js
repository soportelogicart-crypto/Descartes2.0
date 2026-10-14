/**
 * Envío RAW a impresora Windows (cola de impresión, tipo RAW).
 * Sin dependencias nativas: PowerShell + winspool.
 * La clase C# se compila una vez a una DLL y las siguientes impresiones solo la cargan.
 */

'use strict'

const fs = require('fs')
const os = require('os')
const path = require('path')
const { execFile, spawn } = require('child_process')
const { promisify } = require('util')

const execFileAsync = promisify(execFile)

const ASSEMBLY_VERSION = '1'
/** Una mirada a la cola tras enviar: solo retenido, error o pausa. No se espera a que salga. */
const COMPROBACION_COLA_MS = 300
/** Tope por si el proceso deja de responder. No es la espera del ticket. */
const RESPUESTA_MS = 12000

const CSHARP = `
using System;
using System.Runtime.InteropServices;

public class DescartesRawPrinter {
  [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Ansi)]
  public class DOCINFOA {
    [MarshalAs(UnmanagedType.LPStr)] public string pDocName;
    [MarshalAs(UnmanagedType.LPStr)] public string pOutputFile;
    [MarshalAs(UnmanagedType.LPStr)] public string pDataType;
  }

  [DllImport("winspool.Drv", EntryPoint = "OpenPrinterA", SetLastError = true, CharSet = CharSet.Ansi, ExactSpelling = true, CallingConvention = CallingConvention.StdCall)]
  public static extern bool OpenPrinter([MarshalAs(UnmanagedType.LPStr)] string szPrinter, out IntPtr hPrinter, IntPtr pd);

  [DllImport("winspool.Drv", EntryPoint = "ClosePrinter", SetLastError = true, ExactSpelling = true, CallingConvention = CallingConvention.StdCall)]
  public static extern bool ClosePrinter(IntPtr hPrinter);

  [DllImport("winspool.Drv", EntryPoint = "StartDocPrinterA", SetLastError = true, CharSet = CharSet.Ansi, ExactSpelling = true, CallingConvention = CallingConvention.StdCall)]
  public static extern bool StartDocPrinter(IntPtr hPrinter, int level, [In, MarshalAs(UnmanagedType.LPStruct)] DOCINFOA di);

  [DllImport("winspool.Drv", EntryPoint = "EndDocPrinter", SetLastError = true, ExactSpelling = true, CallingConvention = CallingConvention.StdCall)]
  public static extern bool EndDocPrinter(IntPtr hPrinter);

  [DllImport("winspool.Drv", EntryPoint = "StartPagePrinter", SetLastError = true, ExactSpelling = true, CallingConvention = CallingConvention.StdCall)]
  public static extern bool StartPagePrinter(IntPtr hPrinter);

  [DllImport("winspool.Drv", EntryPoint = "EndPagePrinter", SetLastError = true, ExactSpelling = true, CallingConvention = CallingConvention.StdCall)]
  public static extern bool EndPagePrinter(IntPtr hPrinter);

  [DllImport("winspool.Drv", EntryPoint = "WritePrinter", SetLastError = true, ExactSpelling = true, CallingConvention = CallingConvention.StdCall)]
  public static extern bool WritePrinter(IntPtr hPrinter, IntPtr pBytes, int dwCount, out int dwWritten);

  public static void SendBytes(string printer, byte[] bytes) {
    IntPtr hPrinter;
    if (!OpenPrinter(printer, out hPrinter, IntPtr.Zero)) {
      throw new Exception("OpenPrinter failed: " + Marshal.GetLastWin32Error());
    }
    try {
      DOCINFOA di = new DOCINFOA();
      di.pDocName = "DescartesTicket";
      di.pDataType = "RAW";
      if (!StartDocPrinter(hPrinter, 1, di)) {
        throw new Exception("StartDocPrinter failed: " + Marshal.GetLastWin32Error());
      }
      try {
        if (!StartPagePrinter(hPrinter)) {
          throw new Exception("StartPagePrinter failed: " + Marshal.GetLastWin32Error());
        }
        try {
          IntPtr p = Marshal.AllocHGlobal(bytes.Length);
          try {
            Marshal.Copy(bytes, 0, p, bytes.Length);
            int written;
            if (!WritePrinter(hPrinter, p, bytes.Length, out written)) {
              throw new Exception("WritePrinter failed: " + Marshal.GetLastWin32Error());
            }
          } finally {
            Marshal.FreeHGlobal(p);
          }
        } finally {
          EndPagePrinter(hPrinter);
        }
      } finally {
        EndDocPrinter(hPrinter);
      }
    } finally {
      ClosePrinter(hPrinter);
    }
  }
}
`.trim()

let dllPromise = null

function isWindows() {
  return process.platform === 'win32'
}

function dllPath() {
  return path.join(os.tmpdir(), 'descartes-raw-printer', `DescartesRawPrinter-v${ASSEMBLY_VERSION}.dll`)
}

function writePs1(filePath, source) {
  fs.writeFileSync(filePath, `\uFEFF${source}`, 'utf8')
}

function psQuote(value) {
  return String(value || '').replace(/'/g, "''")
}

async function ensureRawPrinterDll() {
  if (!dllPromise) {
    dllPromise = compileDll().catch((err) => {
      dllPromise = null
      throw err
    })
  }
  return dllPromise
}

async function compileDll() {
  const dll = dllPath()
  if (fs.existsSync(dll) && fs.statSync(dll).size > 0) return dll
  fs.mkdirSync(path.dirname(dll), { recursive: true })
  const psPath = path.join(path.dirname(dll), 'compile.ps1')
  const ps = `
$ErrorActionPreference = 'Stop'
Add-Type -TypeDefinition @"
${CSHARP}
"@ -OutputAssembly '${psQuote(dll)}' -OutputType Library
Write-Output 'COMPILED'
`.trim()
  writePs1(psPath, ps)
  await execFileAsync(
    'powershell.exe',
    ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', psPath],
    { windowsHide: true, timeout: 30000, maxBuffer: 1024 * 1024 }
  )
  if (!fs.existsSync(dll) || fs.statSync(dll).size === 0) {
    throw new Error('No se pudo preparar el acceso RAW a la impresora')
  }
  return dll
}

const WORKER_PS = `
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Console]::InputEncoding = New-Object System.Text.UTF8Encoding $false
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding $false
Add-Type -Path $args[0]
try { Get-PrintJob -ErrorAction SilentlyContinue | Out-Null } catch {}
[Console]::Out.WriteLine('READY')
[Console]::Out.Flush()
while ($true) {
  $line = [Console]::In.ReadLine()
  if ($null -eq $line) { break }
  if ($line.Length -eq 0) { continue }
  try {
    if ($line -eq 'PING') {
      [Console]::Out.WriteLine('PONG')
      [Console]::Out.Flush()
      continue
    }
    $tab = $line.IndexOf([char]9)
    $tab2 = if ($tab -ge 0) { $line.IndexOf([char]9, $tab + 1) } else { -1 }
    if ($tab -lt 1 -or $tab2 -le $tab -or $line.Substring(0, $tab) -ne 'PRINT') {
      [Console]::Out.WriteLine('ERR:comando')
      [Console]::Out.Flush()
      continue
    }
    $printerName = $line.Substring($tab + 1, $tab2 - $tab - 1)
    $binPath = $line.Substring($tab2 + 1)
    Get-PrintJob -PrinterName $printerName -ErrorAction SilentlyContinue | ForEach-Object {
      $st = [string]$_.JobStatus
      if ($st -match 'Retain|Error|Offline|Paused') {
        Remove-PrintJob -PrinterName $printerName -ID $_.Id -ErrorAction SilentlyContinue
      }
    }
    $bytes = [System.IO.File]::ReadAllBytes($binPath)
    [DescartesRawPrinter]::SendBytes($printerName, $bytes)
    Start-Sleep -Milliseconds ${COMPROBACION_COLA_MS}
    $bad = @(Get-PrintJob -PrinterName $printerName -ErrorAction SilentlyContinue |
      Where-Object { ([string]$_.JobStatus) -match 'Retain|Error|Offline|Paused' })
    if ($bad.Count -gt 0) {
      [Console]::Out.WriteLine('STUCK:' + [string]$bad[0].JobStatus)
    } else {
      [Console]::Out.WriteLine('OK')
    }
  } catch {
    [Console]::Out.WriteLine('ERR:' + $_.Exception.Message)
  }
  [Console]::Out.Flush()
}
`.trim()

/** Proceso PowerShell vivo: la DLL ya está cargada y cada ticket entra por stdin. */
let workerPromise = null
/** Sube al cerrar, para que el fallo del proceso viejo no borre el que acaba de arrancar. */
let workerGeneracion = 0
/** Los tickets van de uno en uno: la respuesta de stdout tiene que coincidir con el envío. */
let colaEnvio = Promise.resolve()

function workerScriptPath(dll) {
  return path.join(path.dirname(dll), 'raw-worker.ps1')
}

function cerrarWorker() {
  workerGeneracion += 1
  const actual = workerPromise
  workerPromise = null
  if (!actual) return
  actual
    .then((worker) => {
      try {
        worker.child.kill()
      } catch (_) {
        /* ignore */
      }
    })
    .catch(() => {})
}

function startWorker(dll) {
  const scriptPath = workerScriptPath(dll)
  writePs1(scriptPath, WORKER_PS)
  const child = spawn(
    'powershell.exe',
    ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', scriptPath, dll],
    { windowsHide: true, stdio: ['pipe', 'pipe', 'pipe'] }
  )
  let buf = ''
  let listo = false
  const pendientes = []
  let abrir
  let fallarApertura
  const abierto = new Promise((resolve, reject) => {
    abrir = resolve
    fallarApertura = reject
  })

  const rechazarPendientes = (err) => {
    const copia = pendientes.splice(0)
    for (const item of copia) item.reject(err)
  }

  const apertura = setTimeout(() => {
    if (listo) return
    try {
      child.kill()
    } catch (_) {
      /* ignore */
    }
    fallarApertura(new Error('El envío RAW no arrancó'))
  }, RESPUESTA_MS)

  child.stderr.on('data', () => {})
  child.stdin.on('error', () => {})
  child.stdout.setEncoding('utf8')
  child.stdout.on('data', (chunk) => {
    buf += chunk
    let corte = buf.indexOf('\n')
    while (corte >= 0) {
      const linea = buf.slice(0, corte).replace(/\r$/, '').replace(/^\uFEFF/, '')
      buf = buf.slice(corte + 1)
      corte = buf.indexOf('\n')
      if (!linea) continue
      if (!listo) {
        if (linea === 'READY') {
          listo = true
          clearTimeout(apertura)
          abrir(api)
        }
        continue
      }
      const item = pendientes.shift()
      if (item) item.resolve(linea)
    }
  })
  child.on('error', (err) => {
    clearTimeout(apertura)
    if (!listo) fallarApertura(err)
    else rechazarPendientes(err)
  })
  child.on('exit', (code) => {
    clearTimeout(apertura)
    const err = new Error(`El envío RAW se cerró (${code == null ? 'sin código' : code})`)
    if (!listo) fallarApertura(err)
    else rechazarPendientes(err)
  })

  const api = {
    child,
    request(linea) {
      return new Promise((resolve, reject) => {
        if (child.exitCode != null || child.killed) {
          reject(new Error('El envío RAW no está disponible'))
          return
        }
        let cerrado = false
        const terminar = (fn, valor) => {
          if (cerrado) return
          cerrado = true
          clearTimeout(temporizador)
          fn(valor)
        }
        const temporizador = setTimeout(() => {
          terminar(reject, new Error('El envío RAW no respondió'))
          try {
            child.kill()
          } catch (_) {
            /* ignore */
          }
        }, RESPUESTA_MS)
        const item = {
          resolve: (valor) => terminar(resolve, valor),
          reject: (err) => terminar(reject, err),
        }
        pendientes.push(item)
        child.stdin.write(`${linea}\n`, 'utf8', (err) => {
          if (!err) return
          const idx = pendientes.indexOf(item)
          if (idx >= 0) pendientes.splice(idx, 1)
          terminar(reject, err)
        })
      })
    },
  }
  return abierto
}

function ensureWorker(dll) {
  if (!workerPromise) {
    const gen = workerGeneracion
    workerPromise = startWorker(dll).catch((err) => {
      if (workerGeneracion === gen) workerPromise = null
      throw err
    })
  }
  return workerPromise
}

function encolar(tarea) {
  const ejecucion = colaEnvio.then(tarea, tarea)
  colaEnvio = ejecucion.then(
    () => {},
    () => {}
  )
  return ejecucion
}

async function pedirImpresion(dll, printerName, binPath) {
  const worker = await ensureWorker(dll)
  return worker.request(`PRINT\t${printerName}\t${binPath}`)
}

/** Arranca el proceso al abrir la aplicación, para que el primer ticket no pague el arranque. */
async function precalentarRawPrinter() {
  if (!isWindows()) return
  const dll = await ensureRawPrinterDll()
  await ensureWorker(dll)
}

function cerrarRawPrinter() {
  cerrarWorker()
}

/**
 * @param {string} printerName nombre de impresora Windows
 * @param {Buffer} data
 * @returns {Promise<{ ok: boolean, message: string, stuck?: boolean, status?: string }>}
 */
async function printRawWindows(printerName, data) {
  const name = String(printerName || '').trim()
  if (!name || /[\t\r\n]/.test(name)) {
    return { ok: false, message: name ? 'Nombre de impresora no válido' : 'Nombre de impresora vacío' }
  }
  if (!Buffer.isBuffer(data) || data.length === 0) {
    return { ok: false, message: 'No hay datos para imprimir' }
  }

  let dll
  try {
    dll = await ensureRawPrinterDll()
  } catch (err) {
    const msg = err && err.message ? String(err.message) : String(err)
    return { ok: false, message: msg.slice(0, 500) }
  }

  const tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'descartes-ticket-'))
  const binPath = path.join(tmpDir, 'ticket.bin')

  try {
    fs.writeFileSync(binPath, data)
    const linea = await encolar(async () => {
      try {
        return await pedirImpresion(dll, name, binPath)
      } catch (_) {
        cerrarWorker()
        return pedirImpresion(dll, name, binPath)
      }
    })
    const out = String(linea || '').trim()
    if (out.startsWith('STUCK:')) {
      return {
        ok: false,
        stuck: true,
        status: out.slice('STUCK:'.length).trim(),
        message: 'El ticket sigue en la cola de impresión',
      }
    }
    if (out.startsWith('ERR:')) {
      return { ok: false, message: out.slice('ERR:'.length).trim().slice(0, 500) }
    }
    if (out !== 'OK') {
      return { ok: false, message: out ? out.slice(0, 400) : 'Impresión RAW sin confirmación' }
    }
    return { ok: true, message: `Ticket enviado a «${name}» (${data.length} bytes RAW)` }
  } catch (err) {
    const msg = err && err.message ? String(err.message) : String(err)
    return { ok: false, message: msg.slice(0, 500) }
  } finally {
    try {
      fs.rmSync(tmpDir, { recursive: true, force: true })
    } catch (_) {
      /* ignore */
    }
  }
}

module.exports = {
  isWindows,
  printRawWindows,
  precalentarRawPrinter,
  cerrarRawPrinter,
}
