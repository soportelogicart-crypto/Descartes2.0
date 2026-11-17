<#
.SYNOPSIS
Programa la copia de seguridad diaria de la base de datos como tarea de Windows.

.EXAMPLE
powershell -ExecutionPolicy Bypass -File scripts\programar-backup.ps1
Copia diaria a las 02:00 en C:\DescartesBackups, conservando 14.

.EXAMPLE
powershell -ExecutionPolicy Bypass -File scripts\programar-backup.ps1 -Hora 03:30 -Destino D:\Copias -Retener 30

.EXAMPLE
powershell -ExecutionPolicy Bypass -File scripts\programar-backup.ps1 -Desactivar
#>
param(
  [string]$Hora = '02:00',
  [string]$Destino = 'C:\DescartesBackups',
  [int]$Retener = 14,
  [string]$Php = 'C:\xampp\php\php.exe',
  [switch]$Desactivar
)

$ErrorActionPreference = 'Stop'
$nombreTarea = 'Descartes-Backup-BD'

if ($Desactivar) {
  Unregister-ScheduledTask -TaskName $nombreTarea -Confirm:$false -ErrorAction SilentlyContinue
  Write-Host "Tarea '$nombreTarea' eliminada."
  exit 0
}

if (-not (Test-Path $Php)) {
  $enPath = Get-Command php -ErrorAction SilentlyContinue
  if (-not $enPath) {
    throw "No encuentro PHP. Indícalo con -Php 'ruta\php.exe'."
  }
  $Php = $enPath.Source
}

$raizApi = Split-Path $PSScriptRoot -Parent
$script = Join-Path $PSScriptRoot 'backup-bd.php'
if (-not (Test-Path $script)) {
  throw "No encuentro $script"
}

$argumentos = "`"$script`" --destino=`"$Destino`" --retener=$Retener"
$accion = New-ScheduledTaskAction -Execute $Php -Argument $argumentos -WorkingDirectory $raizApi
$disparador = New-ScheduledTaskTrigger -Daily -At $Hora
# S4U: corre con la sesión del usuario actual aunque no haya nadie conectado, sin pedir contraseña.
$principal = New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$env:USERNAME" -LogonType S4U -RunLevel Limited
# StartWhenAvailable: si el PC estaba apagado a la hora prevista, la copia se hace al encenderlo.
$ajustes = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Hours 2)

Register-ScheduledTask -TaskName $nombreTarea -Action $accion -Trigger $disparador -Principal $principal -Settings $ajustes -Force | Out-Null

Write-Host "Tarea '$nombreTarea' programada cada día a las $Hora."
Write-Host "Destino: $Destino  |  Copias conservadas: $Retener"
Write-Host "Registro: $raizApi\var\backup.log"
