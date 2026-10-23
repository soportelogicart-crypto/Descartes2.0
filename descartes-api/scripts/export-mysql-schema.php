<?php

declare(strict_types=1);

/**
 * Lee la estructura (sin datos) del SQL Server configurado y escribe
 * database/schema/mysql/001-esquema.sql.
 *
 * Uso: php scripts/export-mysql-schema.php
 */

use Descartes\Api\Config\Database;

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$config = Database::resolveConfig();
if (($config['motor'] ?? 'sqlsrv') !== 'sqlsrv') {
  fwrite(STDERR, "La configuración activa no es SQL Server.\n");
  exit(1);
}
if (isset($argv[1]) && trim((string) $argv[1]) !== '') {
  $config['database'] = trim((string) $argv[1]);
}
$pdo = Database::createPdo($config);
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlsrv') {
  fwrite(STDERR, "La configuración activa no es SQL Server.\n");
  exit(1);
}

$columnas = $pdo->query(
  "SELECT
      t.name AS table_name,
      c.column_id,
      c.name AS column_name,
      bt.name AS base_type,
      c.max_length,
      c.precision,
      c.scale,
      c.is_nullable,
      c.is_identity,
      c.is_computed,
      dc.definition AS default_def
   FROM sys.tables t
   INNER JOIN sys.schemas s ON s.schema_id = t.schema_id
   INNER JOIN sys.columns c ON c.object_id = t.object_id
   INNER JOIN sys.types ty ON ty.user_type_id = c.user_type_id
   INNER JOIN sys.types bt ON bt.user_type_id = ty.system_type_id
   LEFT JOIN sys.default_constraints dc
     ON dc.parent_object_id = c.object_id AND dc.parent_column_id = c.column_id
   WHERE t.is_ms_shipped = 0 AND s.name = 'dbo'
   ORDER BY t.name, c.column_id"
)->fetchAll(PDO::FETCH_ASSOC);

$claves = [];
foreach ($pdo->query(
  "SELECT t.name AS table_name, c.name AS column_name, ic.key_ordinal
   FROM sys.indexes i
   INNER JOIN sys.tables t ON t.object_id = i.object_id
   INNER JOIN sys.schemas s ON s.schema_id = t.schema_id
   INNER JOIN sys.index_columns ic ON ic.object_id = i.object_id AND ic.index_id = i.index_id
   INNER JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
   WHERE i.is_primary_key = 1 AND s.name = 'dbo'
   ORDER BY t.name, ic.key_ordinal"
) as $row) {
  $claves[$row['table_name']][] = $row['column_name'];
}

$unicos = [];
foreach ($pdo->query(
  "SELECT t.name AS table_name, i.name AS index_name, c.name AS column_name, ic.key_ordinal
   FROM sys.indexes i
   INNER JOIN sys.tables t ON t.object_id = i.object_id
   INNER JOIN sys.schemas s ON s.schema_id = t.schema_id
   INNER JOIN sys.index_columns ic ON ic.object_id = i.object_id AND ic.index_id = i.index_id
   INNER JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
   WHERE i.is_unique = 1 AND i.is_primary_key = 0 AND i.has_filter = 0
     AND ic.is_included_column = 0 AND s.name = 'dbo'
   ORDER BY t.name, i.name, ic.key_ordinal"
) as $row) {
  $unicos[$row['table_name']][$row['index_name']][] = $row['column_name'];
}

$tablas = [];
foreach ($columnas as $row) {
  $tablas[$row['table_name']][] = $row;
}

if ($tablas === []) {
  fwrite(STDERR, "No hay tablas dbo en la base configurada.\n");
  exit(1);
}

$omitidos = [];
$sql = "-- Esquema MySQL generado desde la estructura de SQL Server. Sin datos.\n"
  . "SET NAMES utf8mb4;\n"
  . "SET FOREIGN_KEY_CHECKS = 0;\n\n";

foreach ($tablas as $nombre => $cols) {
  $pk = $claves[$nombre] ?? [];
  $definidas = [];
  foreach ($cols as $col) {
    $definidas[] = columnaMysql($col);
  }
  encajarFila($definidas, $pk);
  encajarClave($definidas, $pk);

  $lineas = [];
  $identidad = false;
  foreach ($definidas as $col) {
    $linea = '  ' . qi($col['name']) . ' ' . $col['mysql'];
    if (!$col['null']) {
      $linea .= ' NOT NULL';
    }
    if ($col['identity'] && !$identidad) {
      if ($col['null']) {
        $linea .= ' NOT NULL';
      }
      $linea .= ' AUTO_INCREMENT';
      $identidad = true;
    } elseif ($col['default'] !== null && !esTextoLargo($col['mysql'])) {
      $linea .= ' DEFAULT ' . $col['default'];
    } elseif ($col['default'] !== null) {
      $omitidos[] = $nombre . '.' . $col['name'] . ' DEFAULT ' . $col['default'];
    }
    $lineas[] = $linea;
  }

  if ($pk !== []) {
    $pkSql = [];
    foreach ($pk as $columna) {
      $pkSql[] = qi($columna);
    }
    $lineas[] = '  PRIMARY KEY (' . implode(', ', $pkSql) . ')';
  }

  foreach ($definidas as $col) {
    if (!$col['identity']) {
      continue;
    }
    // InnoDB solo admite AUTO_INCREMENT si la columna es la primera de algún índice.
    if (($pk[0] ?? '') !== $col['name']) {
      $lineas[] = '  KEY ' . qi(identificador('ix_' . $nombre . '_' . $col['name'])) . ' (' . qi($col['name']) . ')';
    }
    break;
  }

  $vistos = [];
  foreach ($unicos[$nombre] ?? [] as $indice => $columnasIndice) {
    $partes = [];
    $sirve = true;
    foreach ($columnasIndice as $columna) {
      $def = buscar($definidas, $columna);
      if ($def === null || esTextoLargo($def['mysql'])) {
        $sirve = false;
        break;
      }
      $partes[] = qi($columna);
    }
    if (!$sirve || $partes === []) {
      continue;
    }
    $firma = implode(',', $partes);
    if (isset($vistos[$firma])) {
      continue;
    }
    $vistos[$firma] = true;
    $lineas[] = '  UNIQUE KEY ' . qi(identificador('UX_' . $indice)) . ' (' . $firma . ')';
  }

  $sql .= 'CREATE TABLE IF NOT EXISTS ' . qi($nombre) . " (\n"
    . implode(",\n", $lineas)
    . "\n) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4;\n\n";
}

$dir = dirname(__DIR__) . '/database/schema/mysql';
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
  fwrite(STDERR, "No se pudo crear {$dir}\n");
  exit(1);
}
$path = $dir . '/001-esquema.sql';
if (file_put_contents($path, $sql) === false) {
  fwrite(STDERR, "No se pudo escribir el esquema.\n");
  exit(1);
}

echo 'Tablas: ' . count($tablas) . PHP_EOL;
echo 'Bytes: ' . strlen($sql) . PHP_EOL;
echo 'Destino: ' . $path . PHP_EOL;
if ($omitidos !== []) {
  echo 'Defaults no volcados: ' . count($omitidos) . PHP_EOL;
}

/**
 * @param array<string, mixed> $col
 * @return array{name: string, mysql: string, null: bool, identity: bool, default: ?string}
 */
function columnaMysql(array $col): array
{
  $tipo = strtolower((string) $col['base_type']);
  $max = (int) $col['max_length'];
  $precision = (int) $col['precision'];
  $scale = (int) $col['scale'];
  $mysql = tipoMysql($tipo, $max, $precision, $scale);
  $def = defaultMysql(isset($col['default_def']) ? (string) $col['default_def'] : '');

  return [
    'name' => (string) $col['column_name'],
    'mysql' => $mysql,
    'null' => !empty($col['is_nullable']) || !empty($col['is_computed']),
    'identity' => !empty($col['is_identity']),
    'default' => $def,
  ];
}

function tipoMysql(string $tipo, int $max, int $precision, int $scale): string
{
  switch ($tipo) {
    case 'bigint':
      return 'bigint';
    case 'int':
      return 'int';
    case 'smallint':
      return 'smallint';
    case 'tinyint':
      return 'tinyint';
    case 'bit':
      return 'tinyint(1)';
    case 'decimal':
    case 'numeric':
      $p = max(1, min(65, $precision));
      $s = max(0, min(30, $scale));
      if ($s > $p) {
        $s = $p;
      }
      return "decimal({$p},{$s})";
    case 'money':
      return 'decimal(19,4)';
    case 'smallmoney':
      return 'decimal(10,4)';
    case 'float':
      return 'double';
    case 'real':
      return 'float';
    case 'date':
      return 'date';
    case 'time':
      return 'time(0)';
    case 'datetime':
    case 'datetime2':
    case 'smalldatetime':
    case 'datetimeoffset':
      return 'datetime';
    case 'char':
    case 'nchar':
      $n = $tipo === 'nchar' ? intdiv($max, 2) : $max;
      $n = max(1, min(255, $n));
      return "char({$n})";
    case 'varchar':
    case 'nvarchar':
      if ($max < 0) {
        return 'longtext';
      }
      $n = $tipo === 'nvarchar' ? intdiv($max, 2) : $max;
      if ($n > 255) {
        return 'text';
      }
      if ($n < 1) {
        $n = 1;
      }
      return "varchar({$n})";
    case 'text':
    case 'ntext':
    case 'xml':
      return 'longtext';
    case 'binary':
      $n = max(1, min(255, $max));
      return "binary({$n})";
    case 'varbinary':
      return $max < 0 ? 'longblob' : 'blob';
    case 'image':
      return 'longblob';
    case 'uniqueidentifier':
      return 'char(36)';
    case 'timestamp':
      return 'binary(8)';
    default:
      return 'longtext';
  }
}

function defaultMysql(string $def): ?string
{
  $def = trim($def);
  if ($def === '') {
    return null;
  }
  $anterior = '';
  while ($def !== $anterior && str_starts_with($def, '(') && str_ends_with($def, ')')) {
    $anterior = $def;
    $def = trim(substr($def, 1, -1));
  }
  if (preg_match('/^(?:getdate|sysdatetime|sysutcdatetime|getutcdate)\(\)$/i', $def) === 1) {
    return 'CURRENT_TIMESTAMP';
  }
  if (preg_match('/^-?\d+(?:\.\d+)?$/', $def) === 1) {
    return $def;
  }
  if (preg_match("/^N?'((?:''|[^'])*)'$/i", $def, $m) === 1) {
    $texto = str_replace("''", "'", $m[1]);
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})(?:\s+(\d{2}:\d{2}:\d{2}))?$#', $texto, $fecha) === 1) {
      $hora = $fecha[4] ?? '00:00:00';
      return sprintf("'%04d-%02d-%02d %s'", (int) $fecha[3], (int) $fecha[2], (int) $fecha[1], $hora);
    }
    return "'" . str_replace("'", "''", $texto) . "'";
  }
  if (strcasecmp($def, 'NULL') === 0) {
    return 'NULL';
  }

  return null;
}

/** @param list<array{name: string, mysql: string, null: bool, identity: bool, default: ?string}> $cols */
function encajarFila(array &$cols, array $pk): void
{
  $guard = 0;
  while ($guard++ < 400 && pesoFila($cols) > 7500) {
    $idx = -1;
    $mayor = 0;
    foreach ($cols as $i => $col) {
      if (in_array($col['name'], $pk, true) || !preg_match('/^(?:var)?char\(/', $col['mysql'])) {
        continue;
      }
      $peso = pesoTipo($col['mysql']);
      if ($peso > $mayor) {
        $mayor = $peso;
        $idx = $i;
      }
    }
    if ($idx < 0) {
      break;
    }
    $cols[$idx]['mysql'] = 'text';
  }
}

/** @param list<array{name: string, mysql: string, null: bool, identity: bool, default: ?string}> $cols */
function encajarClave(array &$cols, array $pk): void
{
  if ($pk === []) {
    return;
  }
  $guard = 0;
  while ($guard++ < 50 && pesoClave($cols, $pk) > 3000) {
    $idx = -1;
    $mayor = 0;
    foreach ($cols as $i => $col) {
      if (!in_array($col['name'], $pk, true) || !preg_match('/^(?:var)?char\((\d+)\)/', $col['mysql'], $m)) {
        continue;
      }
      $n = (int) $m[1];
      if ($n > $mayor) {
        $mayor = $n;
        $idx = $i;
      }
    }
    if ($idx < 0 || $mayor <= 32) {
      break;
    }
    $nuevo = max(32, intdiv($mayor, 2));
    $tipo = str_starts_with($cols[$idx]['mysql'], 'char(') ? 'char' : 'varchar';
    $cols[$idx]['mysql'] = $tipo . '(' . $nuevo . ')';
  }
}

/** @param list<array{mysql: string}> $cols */
function pesoFila(array $cols): int
{
  $total = 0;
  foreach ($cols as $col) {
    $total += pesoTipo($col['mysql']);
  }
  return $total;
}

/** @param list<array{name: string, mysql: string}> $cols */
function pesoClave(array $cols, array $pk): int
{
  $total = 0;
  foreach ($pk as $nombre) {
    $def = buscar($cols, $nombre);
    $total += $def === null ? 0 : pesoTipo($def['mysql']);
  }
  return $total;
}

function pesoTipo(string $tipo): int
{
  if (preg_match('/^(?:var)?char\((\d+)\)/', $tipo, $m) === 1) {
    return ((int) $m[1]) * 4 + 2;
  }
  if (str_contains($tipo, 'text') || str_contains($tipo, 'blob')) {
    return 20;
  }
  if (str_starts_with($tipo, 'decimal')) {
    return 16;
  }
  return 8;
}

function esTextoLargo(string $tipo): bool
{
  return str_contains($tipo, 'text') || str_contains($tipo, 'blob');
}

/** @param list<array{name: string, mysql: string}> $cols */
function buscar(array $cols, string $nombre): ?array
{
  foreach ($cols as $col) {
    if ($col['name'] === $nombre) {
      return $col;
    }
  }
  return null;
}

function qi(string $nombre): string
{
  return '`' . str_replace('`', '``', identificador($nombre)) . '`';
}

function identificador(string $nombre): string
{
  if (strlen($nombre) <= 64) {
    return $nombre;
  }
  return substr($nombre, 0, 55) . '_' . substr(md5($nombre), 0, 8);
}
