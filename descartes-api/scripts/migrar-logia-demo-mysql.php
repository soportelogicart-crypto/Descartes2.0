<?php

declare(strict_types=1);

/**
 * Copia logia_demo de SQL Server al MySQL descrito en var/mysql-destino.json.
 * No modifica var/instalacion.json.
 *
 * Uso: php scripts/migrar-logia-demo-mysql.php
 */

use Descartes\Api\Config\Database;
use Descartes\Api\Config\InstalacionConfig;
use Descartes\Api\Services\Instalacion\MysqlSchemaService;

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

ini_set('memory_limit', '1024M');
set_time_limit(0);

$instalacionPath = InstalacionConfig::path();
$instalacionAntes = is_file($instalacionPath) ? sha1_file($instalacionPath) : null;

$origen = Database::resolveConfig();
if (($origen['motor'] ?? 'sqlsrv') !== 'sqlsrv') {
  fwrite(STDERR, "La configuración activa no es SQL Server.\n");
  exit(1);
}
// La instalación por defecto puede ser otra base. La copia solo lee logia_demo.
$origen['database'] = 'logia_demo';

$destino = cargarDestino(dirname(__DIR__) . '/var/mysql-destino.json');
$logPath = dirname(__DIR__) . '/var/migracion-logia-demo.log';
$log = fopen($logPath, 'wb');
if ($log === false) {
  fwrite(STDERR, "No se pudo crear el registro de la migración.\n");
  exit(1);
}

$sqlsrv = conectarSqlServer($origen);
$nombreOrigen = (string) $sqlsrv->query('SELECT DB_NAME()')->fetchColumn();
if (strcasecmp(trim($nombreOrigen), 'logia_demo') !== 0) {
  fwrite(STDERR, "SQL Server no está en logia_demo.\n");
  exit(1);
}

$mysqlAdmin = conectarMysql($destino, false);
$yaHay = tablasDestino($mysqlAdmin, $destino['database']);
if ($yaHay > 0) {
  fwrite(STDERR, "La base MySQL «{$destino['database']}» ya tiene {$yaHay} tablas. No se borra nada.\n");
  exit(1);
}

echo "Regenerando esquema desde logia_demo...\n";
$export = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/export-mysql-schema.php') . ' logia_demo';
passthru($export, $codigoExport);
if ($codigoExport !== 0) {
  fwrite(STDERR, "No se pudo regenerar el esquema MySQL.\n");
  exit(1);
}

$nombreBd = str_replace('`', '``', $destino['database']);
$mysqlAdmin->exec(
  'CREATE DATABASE IF NOT EXISTS `' . $nombreBd . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
);
$mysql = conectarMysql($destino, true);
$mysql->exec('SET FOREIGN_KEY_CHECKS = 0');
$mysql->exec('SET UNIQUE_CHECKS = 0');
(new MysqlSchemaService())->asegurar($mysql);

$tablas = tablasOrigen($sqlsrv);
$diferencias = [];
$fechas = 0;
$total = count($tablas);
$n = 0;

foreach ($tablas as $tabla) {
  $n++;
  $copiadas = copiarTabla($sqlsrv, $mysql, $tabla, $log, $fechas);
  $enDestino = (int) $mysql->query('SELECT COUNT(*) FROM ' . qi($tabla))->fetchColumn();
  echo sprintf("%d/%d %s %d %d\n", $n, $total, $tabla, $copiadas, $enDestino);
  fflush(STDOUT);
  if ($copiadas !== $enDestino) {
    $diferencias[] = $tabla . ' origen=' . $copiadas . ' destino=' . $enDestino;
    fwrite($log, "DIFERENCIA {$tabla} origen={$copiadas} destino={$enDestino}\n");
  }
}

$instalacionDespues = is_file($instalacionPath) ? sha1_file($instalacionPath) : null;
if ($instalacionAntes !== $instalacionDespues) {
  fwrite(STDERR, "Se ha modificado var/instalacion.json. No debería ocurrir.\n");
  exit(1);
}

echo 'Tablas: ' . $total . PHP_EOL;
echo 'Fechas ajustadas: ' . $fechas . PHP_EOL;
if ($diferencias !== []) {
  fwrite(STDERR, "Recuentos distintos:\n" . implode("\n", $diferencias) . "\n");
  exit(1);
}

echo "OK\n";
exit(0);

/**
 * @return array{server: string, port: int, database: string, user: string, password: string}
 */
function cargarDestino(string $path): array
{
  if (!is_file($path)) {
    fwrite(STDERR, "Falta {$path}\n");
    fwrite(STDERR, "Cree ese fichero local con server, port, database, user y password. No se versiona.\n");
    exit(1);
  }
  $data = json_decode((string) file_get_contents($path), true);
  if (!is_array($data)) {
    fwrite(STDERR, "mysql-destino.json no es un JSON válido.\n");
    exit(1);
  }
  $server = trim((string) ($data['server'] ?? ''));
  $database = trim((string) ($data['database'] ?? ''));
  $user = trim((string) ($data['user'] ?? ''));
  if ($server === '' || $database === '' || $user === '') {
    fwrite(STDERR, "mysql-destino.json necesita server, database y user.\n");
    exit(1);
  }
  if (!preg_match('/^[A-Za-z0-9_]+$/', $database)) {
    fwrite(STDERR, "El nombre de la base MySQL solo puede tener letras, números y _.\n");
    exit(1);
  }

  return [
    'server' => $server,
    'port' => max(1, (int) ($data['port'] ?? 3306)),
    'database' => $database,
    'user' => $user,
    'password' => (string) ($data['password'] ?? ''),
  ];
}

/** @param array{server: string, database: string, user: string, password: string, trust_cert: bool, encrypt: bool} $config */
function conectarSqlServer(array $config): PDO
{
  $partes = [
    'Server=' . $config['server'],
    'Database=' . $config['database'],
  ];
  if (!empty($config['encrypt'])) {
    $partes[] = 'Encrypt=yes';
  }
  $partes[] = 'TrustServerCertificate=' . (!empty($config['trust_cert']) ? 'yes' : 'no');
  $pdo = new PDO('sqlsrv:' . implode(';', $partes), $config['user'], $config['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  $pdo->exec('SET DATEFORMAT ymd');
  if (defined('PDO::SQLSRV_ATTR_ENCODING') && defined('PDO::SQLSRV_ENCODING_UTF8')) {
    $pdo->setAttribute(PDO::SQLSRV_ATTR_ENCODING, PDO::SQLSRV_ENCODING_UTF8);
  }

  return $pdo;
}

/** @param array{server: string, port: int, database: string, user: string, password: string} $destino */
function conectarMysql(array $destino, bool $conBase): PDO
{
  if (!extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "Falta la extensión pdo_mysql de PHP.\n");
    exit(1);
  }
  $dsn = 'mysql:host=' . $destino['server'] . ';port=' . $destino['port'] . ';charset=utf8mb4';
  if ($conBase) {
    $dsn .= ';dbname=' . $destino['database'];
  }
  $pdo = new PDO($dsn, $destino['user'], $destino['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'",
  ]);

  return $pdo;
}

function tablasDestino(PDO $pdo, string $database): int
{
  $stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = :db AND table_type = 'BASE TABLE'"
  );
  $stmt->execute(['db' => $database]);

  return (int) $stmt->fetchColumn();
}

/** @return list<string> */
function clavePrimaria(PDO $pdo, string $tabla): array
{
  $stmt = $pdo->prepare(
    'SELECT c.name AS nombre
     FROM sys.indexes i
     INNER JOIN sys.tables t ON t.object_id = i.object_id
     INNER JOIN sys.schemas s ON s.schema_id = t.schema_id
     INNER JOIN sys.index_columns ic ON ic.object_id = i.object_id AND ic.index_id = i.index_id
     INNER JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
     WHERE i.is_primary_key = 1 AND s.name = \'dbo\' AND t.name = :tabla
     ORDER BY ic.key_ordinal'
  );
  $stmt->execute(['tabla' => $tabla]);
  $claves = [];
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $claves[] = (string) $row['nombre'];
  }

  return $claves;
}

function sqlMayor(array $pk): string
{
  $partes = [];
  $total = count($pk);
  for ($i = 0; $i < $total; $i++) {
    $trozo = [];
    for ($j = 0; $j < $i; $j++) {
      $trozo[] = qiSql($pk[$j]) . ' = ?';
    }
    $trozo[] = qiSql($pk[$i]) . ' > ?';
    $partes[] = '(' . implode(' AND ', $trozo) . ')';
  }

  return implode(' OR ', $partes);
}

/** @param array<string, mixed> $ultima */
function paramsMayor(array $pk, array $ultima): array
{
  $params = [];
  $total = count($pk);
  for ($i = 0; $i < $total; $i++) {
    for ($j = 0; $j <= $i; $j++) {
      $params[] = valorFila($ultima, $pk[$j]);
    }
  }

  return $params;
}

/** @param array<string, mixed> $fila */
function firmaPk(array $pk, array $fila): string
{
  $partes = [];
  foreach ($pk as $columna) {
    $valor = valorFila($fila, $columna);
    if ($valor instanceof DateTimeInterface) {
      $valor = $valor->format('Y-m-d H:i:s.u');
    }
    $partes[] = is_scalar($valor) || $valor === null ? (string) $valor : serialize($valor);
  }

  return implode("\x1e", $partes);
}

/** @param array<string, mixed> $fila */
function valorFila(array $fila, string $nombre): mixed
{
  if (array_key_exists($nombre, $fila)) {
    return $fila[$nombre];
  }
  foreach ($fila as $clave => $valor) {
    if (strcasecmp((string) $clave, $nombre) === 0) {
      return $valor;
    }
  }

  return null;
}

/** @return list<string> */
function tablasOrigen(PDO $pdo): array
{
  $nombres = [];
  $stmt = $pdo->query(
    "SELECT t.name AS nombre
     FROM sys.tables t
     INNER JOIN sys.schemas s ON s.schema_id = t.schema_id
     WHERE t.is_ms_shipped = 0 AND s.name = 'dbo'
     ORDER BY t.name"
  );
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $nombres[] = (string) $row['nombre'];
  }

  return $nombres;
}

/** @param resource $log */
function copiarTabla(PDO $sqlsrv, PDO $mysql, string $tabla, $log, int &$fechas): int
{
  $stmt = $sqlsrv->prepare(
    'SELECT c.name AS nombre, c.is_computed AS calculada
     FROM sys.columns c
     INNER JOIN sys.tables t ON t.object_id = c.object_id
     INNER JOIN sys.schemas s ON s.schema_id = t.schema_id
     WHERE s.name = \'dbo\' AND t.name = :tabla
     ORDER BY c.column_id'
  );
  $stmt->execute(['tabla' => $tabla]);
  $origenCols = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $metaStmt = $mysql->prepare(
    'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, EXTRA
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tabla
     ORDER BY ORDINAL_POSITION'
  );
  $metaStmt->execute(['tabla' => $tabla]);
  $destino = [];
  while ($row = $metaStmt->fetch(PDO::FETCH_ASSOC)) {
    $destino[strtolower((string) $row['COLUMN_NAME'])] = $row;
  }
  if ($destino === []) {
    throw new RuntimeException("No existe la tabla {$tabla} en MySQL");
  }

  $usar = [];
  foreach ($origenCols as $col) {
    $nombre = (string) $col['nombre'];
    $clave = strtolower(identificador($nombre));
    if (!isset($destino[$clave])) {
      if (!empty($col['calculada'])) {
        continue;
      }
      throw new RuntimeException("Falta la columna {$tabla}.{$nombre} en MySQL");
    }
    $usar[] = [
      'src' => $nombre,
      'dest' => (string) $destino[$clave]['COLUMN_NAME'],
      'tipo' => (string) $destino[$clave]['COLUMN_TYPE'],
      'nulo' => strtoupper((string) $destino[$clave]['IS_NULLABLE']) === 'YES',
      'identidad' => stripos((string) $destino[$clave]['EXTRA'], 'auto_increment') !== false,
    ];
  }

  if ($usar === []) {
    throw new RuntimeException("La tabla {$tabla} no tiene columnas que copiar");
  }

  $listaSrc = implode(', ', array_map(static fn (array $c): string => qiSql($c['src']), $usar));
  $listaDest = implode(', ', array_map(static fn (array $c): string => qi($c['dest']), $usar));
  $marcas = '(' . implode(', ', array_fill(0, count($usar), '?')) . ')';
  $tamano = max(1, min(80, intdiv(1500, count($usar))));
  $pk = clavePrimaria($sqlsrv, $tabla);
  $orden = implode(', ', array_map(static fn (string $col): string => qiSql($col), $pk));
  $copiadas = 0;
  $ultima = null;
  $firmaAnterior = '';

  while (true) {
    if ($pk === []) {
      $lectura = $sqlsrv->query('SELECT ' . $listaSrc . ' FROM ' . qiSql($tabla));
      $filas = $lectura === false ? [] : ($lectura->fetchAll(PDO::FETCH_ASSOC) ?: []);
    } elseif ($ultima === null) {
      $lectura = $sqlsrv->query(
        'SELECT TOP ' . $tamano . ' ' . $listaSrc . ' FROM ' . qiSql($tabla) . ' ORDER BY ' . $orden
      );
      $filas = $lectura === false ? [] : ($lectura->fetchAll(PDO::FETCH_ASSOC) ?: []);
    } else {
      $lectura = $sqlsrv->prepare(
        'SELECT TOP ' . $tamano . ' ' . $listaSrc . ' FROM ' . qiSql($tabla)
        . ' WHERE ' . sqlMayor($pk) . ' ORDER BY ' . $orden
      );
      $lectura->execute(paramsMayor($pk, $ultima));
      $filas = $lectura->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    if ($filas === []) {
      break;
    }

    foreach (array_chunk($filas, $tamano) as $grupo) {
      $valoresSql = [];
      $params = [];
      foreach ($grupo as $fila) {
        $valoresSql[] = $marcas;
        foreach ($usar as $col) {
          $params[] = valorMysql(valorFila($fila, $col['src']), $col, $tabla, $log, $fechas);
        }
      }
      try {
        $mysql->prepare(
          'INSERT INTO ' . qi($tabla) . " ({$listaDest}) VALUES " . implode(', ', $valoresSql)
        )->execute($params);
      } catch (PDOException $e) {
        throw new RuntimeException($tabla . ': ' . $e->getMessage(), 0, $e);
      }
    }
    $copiadas += count($filas);
    if ($pk === [] || count($filas) < $tamano) {
      break;
    }
    $ultima = $filas[count($filas) - 1];
    $firma = firmaPk($pk, $ultima);
    if ($firma === $firmaAnterior) {
      throw new RuntimeException("No avanza la lectura de {$tabla}");
    }
    $firmaAnterior = $firma;
  }

  foreach ($usar as $col) {
    if (!$col['identidad']) {
      continue;
    }
    $max = $mysql->query('SELECT MAX(' . qi($col['dest']) . ') FROM ' . qi($tabla))->fetchColumn();
    if ($max !== null && $max !== false && is_numeric($max)) {
      $siguiente = (int) $max + 1;
      $mysql->exec('ALTER TABLE ' . qi($tabla) . ' AUTO_INCREMENT = ' . $siguiente);
    }
    break;
  }

  return $copiadas;
}

/**
 * @param array{src: string, dest: string, tipo: string, nulo: bool, identidad: bool} $col
 * @param resource $log
 */
function valorMysql(mixed $valor, array $col, string $tabla, $log, int &$fechas): mixed
{
  if (is_bool($valor)) {
    $valor = $valor ? 1 : 0;
  }
  if ($valor instanceof DateTimeInterface) {
    $valor = $valor->format('Y-m-d H:i:s');
  }
  if (is_resource($valor)) {
    $leido = stream_get_contents($valor);
    $valor = $leido === false ? null : $leido;
  }

  $tipo = strtolower($col['tipo']);
  if ($valor === null) {
    return $col['nulo'] ? null : (esFecha($tipo) ? fechaMinima($tipo) : null);
  }

  if (esFecha($tipo)) {
    $normal = normalizarFecha((string) $valor, $tipo);
    if ($normal === null) {
      $fechas++;
      if ($fechas <= 200) {
        fwrite($log, "Fecha {$tabla}.{$col['src']}: " . str_replace(["\r", "\n"], ' ', (string) $valor) . "\n");
      }
      return $col['nulo'] ? null : fechaMinima($tipo);
    }
    return $normal;
  }

  if (str_contains($tipo, 'tinyint') && is_string($valor) && strlen($valor) === 1 && ($valor === "\x00" || $valor === "\x01")) {
    return ord($valor);
  }

  if (is_string($valor) && str_contains($tipo, 'char(36)') && strlen($valor) === 38 && $valor[0] === '{') {
    return substr($valor, 1, 36);
  }

  if (is_string($valor) && preg_match('/^(?:var)?char\((\d+)\)/', $tipo, $m) === 1) {
    $max = (int) $m[1];
    if (mb_strlen($valor) > $max) {
      fwrite($log, "Recorte {$tabla}.{$col['src']} a {$max} caracteres\n");
      return mb_substr($valor, 0, $max);
    }
  }

  return $valor;
}

function esFecha(string $tipo): bool
{
  return str_contains($tipo, 'date') || str_contains($tipo, 'time');
}

function fechaMinima(string $tipo): string
{
  if (str_starts_with($tipo, 'time')) {
    return '00:00:00';
  }
  if (str_starts_with($tipo, 'date') && !str_contains($tipo, 'datetime')) {
    return '1000-01-01';
  }

  return '1000-01-01 00:00:00';
}

function normalizarFecha(string $valor, string $tipo): ?string
{
  $valor = trim($valor);
  if ($valor === '' || str_starts_with($valor, '0000')) {
    return null;
  }
  if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}):(\d{2}))?/', $valor, $m) !== 1) {
    return null;
  }
  $anio = (int) $m[1];
  $mes = (int) $m[2];
  $dia = (int) $m[3];
  if ($anio < 1000 || $anio > 9999 || !checkdate($mes, $dia, $anio)) {
    return null;
  }
  if (str_starts_with($tipo, 'time')) {
    return sprintf('%02d:%02d:%02d', (int) ($m[4] ?? 0), (int) ($m[5] ?? 0), (int) ($m[6] ?? 0));
  }
  if (str_starts_with($tipo, 'date') && !str_contains($tipo, 'datetime')) {
    return sprintf('%04d-%02d-%02d', $anio, $mes, $dia);
  }
  $hora = sprintf('%02d:%02d:%02d', (int) ($m[4] ?? 0), (int) ($m[5] ?? 0), (int) ($m[6] ?? 0));

  return sprintf('%04d-%02d-%02d %s', $anio, $mes, $dia, $hora);
}

function qi(string $nombre): string
{
  return '`' . str_replace('`', '``', identificador($nombre)) . '`';
}

function qiSql(string $nombre): string
{
  return '[' . str_replace(']', ']]', $nombre) . ']';
}

function identificador(string $nombre): string
{
  if (strlen($nombre) <= 64) {
    return $nombre;
  }

  return substr($nombre, 0, 55) . '_' . substr(md5($nombre), 0, 8);
}
