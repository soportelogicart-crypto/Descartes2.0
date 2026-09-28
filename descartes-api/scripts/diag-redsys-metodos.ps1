# Diagnóstico Redsys COM (ejecutar: SysWOW64\powershell.exe -File diag-redsys-metodos.ps1)
$ErrorActionPreference = 'Continue'
$o = New-Object -ComObject DllTpvpcLatente.TpvpImplantado
$ini = $o.IniTpvpcLatente('022477657','1','442544c11efa0315780b','COM9:,19200,N,8,1','8.1')
Write-Host "Ini => $ini"
$t = [type]::GetTypeFromProgID('DllTpvpcLatente.TpvpImplantado')
foreach ($m in $t.GetMethods() | Where-Object { $_.DeclaringType.Name -ne 'Object' -and $_.Name -match 'ComContable|OperCom|DevSin|OperPin' }) {
  $ps = ($m.GetParameters() | ForEach-Object { $_.ParameterType.Name + ' ' + $_.Name }) -join ', '
  Write-Host "$($m.Name)($ps)"
}
try { [void]$o.ParaTpvpcLatente() } catch {}
try { [void][Runtime.InteropServices.Marshal]::FinalReleaseComObject($o) } catch {}
