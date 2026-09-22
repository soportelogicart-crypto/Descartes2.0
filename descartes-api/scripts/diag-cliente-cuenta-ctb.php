<?php

declare(strict_types=1);

/**
 * Informe: cliente + cuenta contable asociada.
 * Uso: php scripts/diag-cliente-cuenta-ctb.php 000000921
 */

require dirname(__DIR__) . '/vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

use Descartes\Api\Config\Database;

$codigo = trim((string) ($argv[1] ?? '000000921'));
$pdo = Database::fromEnv();

function out(string $title, mixed $data): void
{
  echo "\n=== {$title} ===\n";
  if ($data === false || $data === null) {
    echo "(sin datos)\n";
    return;
  }
  echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}

$st = $pdo->prepare(
  'SELECT TOP 1
     RTRIM(Codigo) AS Codigo,
     RazonSocial,
     NIF,
     Direccion,
     Poblacion,
     CodigoPostal,
     Provincia,
     Pais,
     Telefono1,
     Fax,
     FormaPago,
     DiaPago1,
     DiaPago2,
     Banco,
     CuentaBancaria,
     Swift,
     IBAN,
     ReferenciaMandato,
     FechaFirmaMandato,
     EmailFacturacion,
     TratamientoFiscal,
     CuentaCtb,
     CuentaCtb2,
     CuentaCtbIta,
     Empresa,
     FechaAlta,
     Actividad
   FROM Clientes
   WHERE RTRIM(Codigo) = :c'
);
$st->execute(['c' => $codigo]);
$cli = $st->fetch(PDO::FETCH_ASSOC);
out('CLIENTE ' . $codigo, $cli);

if ($cli === false) {
  exit(1);
}

$cuentaCtb2 = isset($cli['CuentaCtb2']) && (float) $cli['CuentaCtb2'] != 0.0
  ? (string) (int) (float) $cli['CuentaCtb2']
  : '';
$cuentaCtb = trim((string) ($cli['CuentaCtb'] ?? ''));
$digitos = preg_replace('/\D+/', '', $codigo) ?: '';
$fallback = '430' . str_pad(substr($digitos, -6), 6, '0', STR_PAD_LEFT);

out('RESOLUCION CUENTA (como TraspasoContableService)', [
  'prioridad1_CuentaCtb2' => $cuentaCtb2 !== '' ? $cuentaCtb2 : '(vacio/0)',
  'prioridad2_CuentaCtb' => $cuentaCtb !== '' ? $cuentaCtb : '(vacio)',
  'prioridad3_fallback_430xxxxxx' => $fallback,
  'cuentaEfectiva' => $cuentaCtb2 !== '' ? $cuentaCtb2 : ($cuentaCtb !== '' ? $cuentaCtb : $fallback),
]);

$candidatos = array_values(array_unique(array_filter([
  $cuentaCtb2,
  $cuentaCtb,
  $fallback,
  '430' . str_pad(substr($digitos, -6), 6, '0', STR_PAD_LEFT),
  '430' . $digitos,
])));

foreach ($candidatos as $c) {
  $q = $pdo->prepare(
    'SELECT TOP 1
       RTRIM(Codigo) AS Codigo,
       NivelAnterior,
       Descripcion,
       UltNivel,
       Nif,
       Direccion,
       Poblacion,
       CodPostal,
       Provincia,
       Pais,
       Telefono,
       Fax,
       FormaPago,
       DiaPago1,
       DiaPago2,
       Banco,
       CtaBancaria,
       Swift,
       IBAN,
       ReferenciaMandato,
       FechaFirmaMandato,
       EmailFacturacion,
       TratamientoFiscal,
       Tipo,
       TipoGestion,
       CentroCoste,
       DesgloseAnalitica
     FROM Cuentas
     WHERE RTRIM(Codigo) = :c'
  );
  $q->execute(['c' => $c]);
  $row = $q->fetch(PDO::FETCH_ASSOC);
  out('CUENTA ' . $c, $row !== false ? $row : '(no existe)');
}

// Buscar por NIF / razón social por si el código de cuenta no es el esperado
$nif = trim((string) ($cli['NIF'] ?? ''));
if ($nif !== '') {
  $q = $pdo->prepare(
    "SELECT TOP 20 RTRIM(Codigo) AS Codigo, Descripcion, Nif
     FROM Cuentas
     WHERE RTRIM(ISNULL(Nif,'')) = :nif
     ORDER BY Codigo"
  );
  $q->execute(['nif' => $nif]);
  out('CUENTAS CON MISMO NIF', $q->fetchAll(PDO::FETCH_ASSOC));
}

$razon = trim((string) ($cli['RazonSocial'] ?? ''));
if ($razon !== '') {
  $q = $pdo->prepare(
    "SELECT TOP 20 RTRIM(Codigo) AS Codigo, Descripcion, Nif
     FROM Cuentas
     WHERE Descripcion LIKE :q
     ORDER BY Codigo"
  );
  $q->execute(['q' => '%' . mb_substr($razon, 0, 20) . '%']);
  out('CUENTAS POR DESCRIPCION PARECIDA', $q->fetchAll(PDO::FETCH_ASSOC));
}

// Contadores / parametros relacionados con UltCuenta si existen
$cols = $pdo->query(
  "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_NAME = 'Parametros' AND COLUMN_NAME LIKE '%Cuenta%'"
)->fetchAll(PDO::FETCH_COLUMN);
out('COLUMNAS Parametros *Cuenta*', $cols);

$colsC = $pdo->query(
  "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_NAME LIKE '%Contador%' AND COLUMN_NAME LIKE '%Cuenta%'"
)->fetchAll(PDO::FETCH_COLUMN);
out('COLUMNAS Contadores *Cuenta*', $colsC);

// Tablas con UltCuenta / UltimaCuenta en el esquema
$tablas = $pdo->query(
  "SELECT TABLE_NAME, COLUMN_NAME
   FROM INFORMATION_SCHEMA.COLUMNS
   WHERE COLUMN_NAME LIKE '%Ult%Cuenta%' OR COLUMN_NAME LIKE 'UltCuenta%'
   ORDER BY TABLE_NAME, COLUMN_NAME"
)->fetchAll(PDO::FETCH_ASSOC);
out('COLUMNAS Ult*Cuenta* EN BD', $tablas);

// Empresa del cliente y ContaPropia
$emp = trim((string) ($cli['Empresa'] ?? ''));
if ($emp !== '') {
  $q = $pdo->prepare(
    'SELECT TOP 1 RTRIM(Empresa) AS Empresa, ContaPropia, Serie, SerieA, SerieDif, SerieADif, ApunteDiario
     FROM Parametros WHERE RTRIM(Empresa) = :e'
  );
  $q->execute(['e' => $emp]);
  out('PARAMETROS CONTABILIDAD EMPRESA ' . $emp, $q->fetch(PDO::FETCH_ASSOC));
}

$r = $pdo->query(
  'SELECT TOP 5 RTRIM(Empresa) AS Empresa, Cuenta FROM Parametros ORDER BY Empresa'
)->fetchAll(PDO::FETCH_ASSOC);
out('Parametros.Cuenta (muestra)', $r);

$r = $pdo->query(
  "SELECT TOP 8 RTRIM(Codigo) AS Codigo, RazonSocial, CuentaCtb2, FechaAlta
   FROM Clientes
   WHERE ISNULL(CuentaCtb2, 0) = 0
   ORDER BY FechaAlta DESC"
)->fetchAll(PDO::FETCH_ASSOC);
out('Clientes recientes SIN CuentaCtb2', $r);

$r = $pdo->query(
  "SELECT TOP 8 RTRIM(Codigo) AS Codigo, RazonSocial, CuentaCtb2, FechaAlta
   FROM Clientes
   WHERE ISNULL(CuentaCtb2, 0) <> 0
   ORDER BY FechaAlta DESC"
)->fetchAll(PDO::FETCH_ASSOC);
out('Clientes recientes CON CuentaCtb2', $r);

$r = $pdo->query(
  "SELECT TOP 10 RTRIM(Codigo) AS Codigo, Descripcion, UltNivel, NivelAnterior
   FROM Cuentas
   WHERE RTRIM(Codigo) IN ('43','430','4300') OR RTRIM(Codigo) LIKE '4300%' AND LEN(RTRIM(Codigo)) <= 5
   ORDER BY Codigo"
)->fetchAll(PDO::FETCH_ASSOC);
out('Niveles padre plan 430*', $r);

// Clientes sin fila en Cuentas para su CuentaCtb2
$r = $pdo->query(
  "SELECT TOP 10 RTRIM(c.Codigo) AS Codigo, c.RazonSocial, c.CuentaCtb2
   FROM Clientes c
   WHERE ISNULL(c.CuentaCtb2, 0) <> 0
     AND NOT EXISTS (
       SELECT 1 FROM Cuentas cu
       WHERE RTRIM(cu.Codigo) = CAST(CAST(c.CuentaCtb2 AS bigint) AS varchar(20))
     )
   ORDER BY c.FechaAlta DESC"
)->fetchAll(PDO::FETCH_ASSOC);
out('Clientes con CuentaCtb2 pero SIN fila en Cuentas', $r);

echo "\nOK\n";
