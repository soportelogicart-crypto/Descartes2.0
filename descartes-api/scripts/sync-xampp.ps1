# Copia la API PHP al despliegue XAMPP en UTF-8 sin BOM.
# Sincroniza todo src\ y public\ (ventas, facturacion, compras, mantenimiento, etc.).
# Doc: ../SYNC-XAMPP.md (feature 004-compras-gestion T011).
$utf8 = New-Object System.Text.UTF8Encoding $false
$apiRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$xamppRoot = 'C:\xampp\htdocs\descartes-api'

if (-not (Test-Path $xamppRoot)) {
  Write-Error "No existe $xamppRoot"
  exit 1
}

function Read-SourceText([string]$path) {
  $bytes = [System.IO.File]::ReadAllBytes($path)
  if ($bytes.Length -ge 2 -and $bytes[1] -eq 0) {
    return [System.IO.File]::ReadAllText($path, [System.Text.Encoding]::Unicode)
  }
  return [System.IO.File]::ReadAllText($path, $utf8)
}

function Sync-Tree([string]$relativeDir, [string[]]$include = @('*.php', '*.htaccess', '*.json')) {
  $srcDir = Join-Path $apiRoot $relativeDir
  if (-not (Test-Path $srcDir)) {
    Write-Warning "No existe $srcDir"
    return
  }
  $files = Get-ChildItem -Path $srcDir -Recurse -File -Include $include
  foreach ($file in $files) {
    $rel = $file.FullName.Substring($apiRoot.Length).TrimStart('\', '/')
    # Normalizar separadores
    $rel = $rel -replace '/', '\'
    $dst = Join-Path $xamppRoot $rel
    $dir = Split-Path $dst -Parent
    if (-not (Test-Path $dir)) {
      New-Item -ItemType Directory -Path $dir -Force | Out-Null
    }
    $content = Read-SourceText $file.FullName
    [System.IO.File]::WriteAllText($dst, $content, $utf8)
    Write-Host "OK $rel"
  }
}

Sync-Tree 'src'
Sync-Tree 'public'
Sync-Tree 'database' @('*.sql')

$varDir = Join-Path $xamppRoot 'var'
if (-not (Test-Path $varDir)) {
  New-Item -ItemType Directory -Path $varDir -Force | Out-Null
  Write-Host 'OK var\ (directorio instalacion)'
}

$dependenciasCambiadas = $false
foreach ($manifest in @('composer.json', 'composer.lock')) {
  $src = Join-Path $apiRoot $manifest
  $dst = Join-Path $xamppRoot $manifest
  if (-not (Test-Path $dst) -or (Get-FileHash $src).Hash -ne (Get-FileHash $dst).Hash) {
    Copy-Item $src $dst -Force
    $dependenciasCambiadas = $true
    Write-Host "OK $manifest"
  }
}

if ($dependenciasCambiadas) {
  Push-Location $xamppRoot
  try {
    & composer install --no-dev --optimize-autoloader --no-interaction
    if ($LASTEXITCODE -ne 0) {
      throw 'composer install ha fallado en el despliegue XAMPP'
    }
  } finally {
    Pop-Location
  }
}

Write-Host 'Sincronizado en UTF-8 (src + public + database/migrations + dependencias Composer).'
