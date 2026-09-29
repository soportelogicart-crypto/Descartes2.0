/**
 * Envío RAW a impresora Windows (cola de impresión, tipo RAW).
 * Sin dependencias nativas: PowerShell + winspool.
 * La clase C# se compila una vez a una DLL y las siguientes impresiones solo la cargan.
 */

'use strict'

const fs = require('fs')
const os = require('os')
const path = require('path')
const { execFile } = require('child_process')
const { promisify } = require('util')

const execFileAsync = promisify(execFile)

const ASSEMBLY_VERSION = '1'
/** Si el trabajo sigue en «Printing» tras este tiempo, se considera atascado. */
const JOB_WAIT_MS = 8000

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

/**
 * @param {string} printerName nombre de impresora Windows
 * @param {Buffer} data
 * @returns {Promise<{ ok: boolean, message: string, stuck?: boolean, status?: string }>}
 */
async function printRawWindows(printerName, data) {
  const name = String(printerName || '').trim()
  if (!name) {
    return { ok: false, message: 'Nombre de impresora vacío' }
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
  const psPath = path.join(tmpDir, 'print-raw.ps1')

  try {
    fs.writeFileSync(binPath, data)

    const ps = `
$ErrorActionPreference = 'Stop'
$printerName = @'
${name.replace(/'/g, "''")}
'@
$binPath = @'
${binPath.replace(/'/g, "''")}
'@
$dllPath = @'
${dll.replace(/'/g, "''")}
'@

Add-Type -Path $dllPath

Get-PrintJob -PrinterName $printerName -ErrorAction SilentlyContinue | ForEach-Object {
  $st = [string]$_.JobStatus
  if ($st -match 'Retain|Error|Offline|Paused') {
    Remove-PrintJob -PrinterName $printerName -ID $_.Id -ErrorAction SilentlyContinue
  }
}

$bytes = [System.IO.File]::ReadAllBytes($binPath)
[DescartesRawPrinter]::SendBytes($printerName, $bytes)

$started = Get-Date
$deadline = $started.AddMilliseconds(${JOB_WAIT_MS})
do {
  Start-Sleep -Milliseconds 200
  $jobs = @(Get-PrintJob -PrinterName $printerName -ErrorAction SilentlyContinue |
    Where-Object { $_.PagesPrinted -eq 0 -and ([string]$_.JobStatus) -match 'Print|Retain|Error|Offline|Paused' })
  $elapsed = ((Get-Date) - $started).TotalMilliseconds
  if ($jobs.Count -eq 0) {
    if ($elapsed -ge 400) {
      Write-Output 'OK'
      exit 0
    }
    continue
  }
  $st = [string]$jobs[0].JobStatus
  if ($st -match 'Retain|Error|Offline|Paused') {
    Write-Output ('STUCK:' + $st)
    exit 0
  }
  if ((Get-Date) -ge $deadline) {
    Write-Output ('STUCK:' + $st)
    exit 0
  }
} while ($true)
`.trim()

    writePs1(psPath, ps)

    const { stdout, stderr } = await execFileAsync(
      'powershell.exe',
      ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', psPath],
      { windowsHide: true, timeout: JOB_WAIT_MS + 15000, maxBuffer: 1024 * 1024 }
    )

    const out = String(stdout || '').trim()
    if (out.startsWith('STUCK:')) {
      return {
        ok: false,
        stuck: true,
        status: out.slice('STUCK:'.length).trim(),
        message: 'El ticket sigue en la cola de impresión',
      }
    }
    if (!out.includes('OK')) {
      return {
        ok: false,
        message: stderr ? String(stderr).slice(0, 400) : 'Impresión RAW sin confirmación',
      }
    }
    return { ok: true, message: `Ticket enviado a «${name}» (${data.length} bytes RAW)` }
  } catch (err) {
    const msg = err && err.message ? String(err.message) : String(err)
    const stderr = err && err.stderr ? String(err.stderr) : ''
    return {
      ok: false,
      message: (stderr || msg).slice(0, 500),
    }
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
}
