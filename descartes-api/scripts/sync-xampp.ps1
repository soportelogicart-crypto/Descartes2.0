# Copia archivos PHP al despliegue XAMPP en UTF-8 sin BOM.
$utf8 = New-Object System.Text.UTF8Encoding $false
$apiRoot = Join-Path $PSScriptRoot '..'
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

$relativePaths = @(
  'src\bootstrap.php',
  'src\Config\tiendas-fields.php',
  'src\Config\puestos-fields.php',
  'src\Config\articulos-fields.php',
  'src\Config\clientes-fields.php',
  'src\Config\proveedores-fields.php',
  'src\Config\entities.php',
  'src\Config\EntityConfig.php',
  'src\Services\MantenimientoService.php',
  'src\Services\DependencyCheckService.php',
  'src\Services\RolService.php',
  'src\Services\PermissionService.php',
  'src\Middleware\CorsMiddleware.php',
  'src\Http\ApiErrorHandler.php',
  'src\Http\ErrorResponse.php',
  'src\Controllers\AuthController.php',
  'src\Controllers\MantenimientoController.php',
  'src\Controllers\ConfigEquipoController.php',
  'src\Controllers\ClienteController.php',
  'src\Controllers\ProveedorController.php',
  'src\Controllers\CodigoPostalController.php',
  'src\Controllers\OfertaClienteController.php',
  'src\Controllers\OfertaProveedorController.php',
  'src\Repositories\ConfigEquipoRepository.php',
  'src\Repositories\ClientesDireccionesRepository.php',
  'src\Repositories\ClientesContactosRepository.php',
  'src\Repositories\ProveedoresContactosRepository.php',
  'src\Repositories\OfertasClientesRepository.php',
  'src\Repositories\OfertasProveedorRepository.php',
  'src\Repositories\CodigoPostalRepository.php',
  'src\Services\ConfigEquipoService.php',
  'src\Routes\mantenimiento.php',
  'public\index.php',
  'public\.htaccess'
)

foreach ($rel in $relativePaths) {
  $src = Join-Path $apiRoot $rel
  $dst = Join-Path $xamppRoot $rel
  if (-not (Test-Path $src)) { continue }
  $dir = Split-Path $dst -Parent
  if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
  $content = Read-SourceText $src
  [System.IO.File]::WriteAllText($dst, $content, $utf8)
  Write-Host "OK $rel"
}

Write-Host 'Sincronizado en UTF-8.'
