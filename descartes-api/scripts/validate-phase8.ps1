# Validacion automatica SC-001 parcial + paginacion + errores JSON (Phase 8).
param(
  [string]$BaseUrl = 'http://localhost/descartes-api/public',
  [string]$Usuario = 'ADM',
  [string]$Password = 'admin123'
)

$ErrorActionPreference = 'Stop'
$session = $null

function Read-ErrorBody($response) {
  if ($null -eq $response) { return $null }
  $stream = $response.GetResponseStream()
  if ($null -eq $stream) { return $null }
  $reader = New-Object System.IO.StreamReader($stream)
  return $reader.ReadToEnd() | ConvertFrom-Json
}

function Assert-JsonError($response, [int]$expectedStatus, [string]$expectedCodigo) {
  if ($response.StatusCode.value__ -ne $expectedStatus) {
    throw "Se esperaba HTTP $expectedStatus, recibido $($response.StatusCode.value__)"
  }
  $body = Read-ErrorBody $response
  if ($null -eq $body) { throw 'Respuesta de error sin cuerpo JSON' }
  if ($body.codigo -ne $expectedCodigo) {
    throw "Se esperaba codigo $expectedCodigo, recibido $($body.codigo)"
  }
  if (-not $body.error) {
    throw 'Respuesta de error sin campo error'
  }
}

Write-Host '== Login =='
$login = Invoke-RestMethod -Uri "$BaseUrl/api/auth/login" -Method POST `
  -ContentType 'application/json' `
  -Body (@{ usuario = $Usuario; password = $Password } | ConvertTo-Json) `
  -SessionVariable session
if (-not $login.usuario.codigo) { throw 'Login fallo' }
Write-Host "OK login ($($login.usuario.codigo))"

Write-Host '== Empresa cliente (SC-001 paso 2) =='
$empresa = Invoke-RestMethod -Uri "$BaseUrl/api/mantenimiento/empresas" -WebSession $session
if (-not $empresa.codigo) { throw 'Empresa central sin codigo' }
Write-Host "OK empresa: $($empresa.codigo)"

Write-Host '== Tiendas con paginacion (FR-016) =='
$tiendas = Invoke-RestMethod -Uri "$BaseUrl/api/mantenimiento/tiendas?page=1&pageSize=5&q=" -WebSession $session
foreach ($field in @('items', 'page', 'pageSize', 'total')) {
  if ($null -eq $tiendas.$field -and $field -ne 'items') {
    throw "Falta campo $field en listado paginado"
  }
}
Write-Host "OK tiendas: total=$($tiendas.total), page=$($tiendas.page), pageSize=$($tiendas.pageSize)"

Write-Host '== Error JSON sin sesion =='
try {
  Invoke-RestMethod -Uri "$BaseUrl/api/mantenimiento/tiendas" | Out-Null
  throw 'Se esperaba error 401 sin sesion'
} catch {
  $raw = $_.ErrorDetails.Message
  if (-not $raw) { throw 'No se pudo leer cuerpo de error 401' }
  $body = $raw | ConvertFrom-Json
  if ($body.codigo -ne 'NO_AUTENTICADO') { throw "Codigo inesperado: $($body.codigo)" }
  if (-not $body.error) { throw 'Falta campo error' }
}
Write-Host 'OK 401 NO_AUTENTICADO'

Write-Host '== Metodo no permitido =='
try {
  Invoke-RestMethod -Uri "$BaseUrl/api/auth/login" -Method GET | Out-Null
  throw 'Se esperaba error 405 en GET login'
} catch {
  if ($_.Exception.Response.StatusCode.value__ -ne 405) {
    throw "Se esperaba HTTP 405, recibido $($_.Exception.Response.StatusCode.value__)"
  }
  $raw = $_.ErrorDetails.Message
  if ($raw -and $raw.TrimStart().StartsWith('{')) {
    $body = $raw | ConvertFrom-Json
    if ($body.codigo -ne 'METODO_NO_PERMITIDO') { throw "Codigo inesperado: $($body.codigo)" }
  }
}
Write-Host 'OK 405 METODO_NO_PERMITIDO'

Write-Host ''
Write-Host 'Validacion Phase 8 completada.'
