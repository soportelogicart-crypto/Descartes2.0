/**
 * Envío RAW a impresora Windows (cola de impresión, tipo RAW).
 * Sin dependencias nativas: PowerShell + winspool.
 */

'use strict'

const fs = require('fs')
const os = require('os')
const path = require('path')
const { execFile } = require('child_process')
const { promisify } = require('util')

const execFileAsync = promisify(execFile)

function isWindows() {
  return process.platform === 'win32'
}

/**
 * @param {string} printerName nombre de impresora Windows
 * @param {Buffer} data
 * @returns {Promise<{ ok: boolean, message: string }>}
 */
async function printRawWindows(printerName, data) {
  const name = String(printerName || '').trim()
  if (!name) {
    return { ok: false, message: 'Nombre de impresora vacío' }
  }
  if (!Buffer.isBuffer(data) || data.length === 0) {
    return { ok: false, message: 'No hay datos para imprimir' }
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

Add-Type -TypeDefinition @"
using System;
using System.IO;
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
"@

$bytes = [System.IO.File]::ReadAllBytes($binPath)
[DescartesRawPrinter]::SendBytes($printerName, $bytes)
Write-Output 'OK'
`.trim()

    fs.writeFileSync(psPath, ps, 'utf8')

    const { stdout, stderr } = await execFileAsync(
      'powershell.exe',
      ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', psPath],
      { windowsHide: true, timeout: 20000, maxBuffer: 1024 * 1024 }
    )

    const out = String(stdout || '').trim()
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
