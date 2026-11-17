<?php

declare(strict_types=1);

/**
 * Copia de seguridad de cada base MySQL del hosting: la de var/instalacion.json
 * y una por cada cliente de var/clientes.
 * Se ejecuta en el servidor (cron de hPanel), porque esas bases solo se alcanzan desde allí.
 *
 * Uso: php scripts/backup-bd.php [--destino=ruta] [--retener=14]
 * Por defecto guarda en var/backups y conserva 14 copias de cada cliente.
 * Cron diario en hPanel:
 *   php /ruta/de/la/api/scripts/backup-bd.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Descartes\Api\Config\Database;
use Descartes\Api\Config\InstalacionConfig;
use Dotenv\Dotenv;

$raiz = dirname(__DIR__);
Dotenv::createImmutable($raiz)->safeLoad();

$opciones = getopt('', ['destino:', 'retener:']);
$destino = (string) ($opciones['destino'] ?? (getenv('BACKUP_DESTINO') ?: ($raiz . '/var/backups')));
$retener = max(1, (int) ($opciones['retener'] ?? 14));

function localizarMysqldump(): string
{
  $forzada = getenv('MYSQLDUMP_PATH');
  if (is_string($forzada) && $forzada !== '' && is_file($forzada)) {
    return $forzada;
  }
  foreach (['C:\\xampp\\mysql\\bin\\mysqldump.exe', '/usr/bin/mysqldump', '/usr/local/bin/mysqldump'] as $ruta) {
    if (is_file($ruta)) {
      return $ruta;
    }
  }
  return 'mysqldump';
}

/** Escribe en consola y en var/backup.log. */
function registrar(string $linea, string $raiz): void
{
  $dir = $raiz . '/var';
  if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
  }
  @file_put_contents(
    $dir . '/backup.log',
    '[' . date('Y-m-d H:i:s') . '] ' . $linea . PHP_EOL,
    FILE_APPEND | LOCK_EX
  );
  echo $linea . PHP_EOL;
}

function copiaMysql(array $config, string $destino, string $base, string $nombreArchivo, string $sello): string
{
  if (!preg_match('/^[A-Za-z0-9_]+$/', $base)) {
    throw new RuntimeException('El nombre de la base solo puede tener letras, números y _');
  }
  if (!preg_match('/^[A-Za-z0-9_-]+$/', $nombreArchivo)) {
    throw new RuntimeException('El nombre del fichero de copia no es válido');
  }

  $ejecutable = localizarMysqldump();

  $servidor = (string) $config['server'];
  $puerto = '3306';
  if (preg_match('/^(.+):(\d+)$/', $servidor, $m) === 1) {
    $servidor = $m[1];
    $puerto = $m[2];
  }

  $temporal = $destino . DIRECTORY_SEPARATOR . $nombreArchivo . '-' . $sello . '.sql';
  $final = $temporal . '.gz';

  // La contraseña va por variable de entorno, no en la línea de comandos.
  putenv('MYSQL_PWD=' . (string) $config['password']);

  $comando = [
    $ejecutable,
    '--host=' . $servidor,
    '--port=' . $puerto,
    '--user=' . (string) $config['user'],
    '--default-character-set=utf8mb4',
    '--single-transaction',
    '--routines',
    '--triggers',
    '--events',
    '--hex-blob',
    '--complete-insert',
    '--result-file=' . $temporal,
    $base,
  ];

  $proceso = proc_open($comando, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
  if (!is_resource($proceso)) {
    throw new RuntimeException('No se pudo ejecutar mysqldump (' . $ejecutable . ')');
  }
  stream_get_contents($pipes[1]);
  $error = stream_get_contents($pipes[2]);
  fclose($pipes[1]);
  fclose($pipes[2]);
  $codigo = proc_close($proceso);

  if ($codigo !== 0 || !is_file($temporal)) {
    @unlink($temporal);
    throw new RuntimeException('mysqldump terminó con código ' . $codigo . ': ' . trim((string) $error));
  }

  $entrada = fopen($temporal, 'rb');
  $salida = gzopen($final, 'wb9');
  if ($entrada === false || $salida === false) {
    throw new RuntimeException('No se pudo comprimir la copia en ' . $final);
  }
  while (($bloque = fread($entrada, 1048576)) !== false && $bloque !== '') {
    gzwrite($salida, $bloque);
  }
  fclose($entrada);
  gzclose($salida);
  unlink($temporal);

  return $final;
}

/**
 * La instalación por defecto y cada cliente de var/clientes. Una sola vez por base.
 *
 * @return list<array{id: string, config: array{server: string, database: string, user: string, password: string, motor: string}}>
 */
function basesACopiar(): array
{
  $lista = [];
  $vistas = [];

  $anadir = static function (string $id, array $config) use (&$lista, &$vistas): void {
    $preparada = Database::prepareConfig($config);
    $base = $preparada['database'];
    if ($preparada['motor'] !== 'mysql' || $base === '' || isset($vistas[$base])) {
      return;
    }
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id) ?: 'cliente';
    $lista[] = ['id' => $id, 'config' => $preparada];
    $vistas[$base] = true;
  };

  $anadir('default', Database::resolveConfig());

  $dir = dirname(InstalacionConfig::path()) . '/clientes';
  foreach (glob($dir . '/*.json') ?: [] as $ruta) {
    $raw = file_get_contents($ruta);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data)) {
      continue;
    }
    $id = (string) ($data['id'] ?? pathinfo($ruta, PATHINFO_FILENAME));
    $anadir($id, $data);
  }

  return $lista;
}

/** Borra las copias antiguas de este cliente (solo nombres con el formato de este script). */
function aplicarRetencion(string $destino, string $prefijo, int $retener): int
{
  $patron = '/^' . preg_quote($prefijo, '/') . '-\d{8}-\d{6}\.sql\.gz$/';
  $nombres = array_values(array_filter(
    scandir($destino) ?: [],
    static fn(string $n): bool => preg_match($patron, $n) === 1
  ));
  // El sello AAAAMMDD-HHMMSS ordena cronológicamente como texto.
  rsort($nombres, SORT_STRING);

  $borradas = 0;
  foreach (array_slice($nombres, $retener) as $antigua) {
    if (@unlink($destino . DIRECTORY_SEPARATOR . $antigua)) {
      $borradas++;
    }
  }
  return $borradas;
}

try {
  if (!is_dir($destino) && !mkdir($destino, 0775, true) && !is_dir($destino)) {
    throw new RuntimeException('No se pudo crear la carpeta de copias: ' . $destino);
  }

  $bases = basesACopiar();
  if ($bases === []) {
    throw new RuntimeException('No hay bases MySQL de clientes para copiar');
  }

  $sello = date('Ymd-His');
  $fallos = 0;
  foreach ($bases as $entrada) {
    $config = $entrada['config'];
    $base = (string) $config['database'];
    $prefijo = $entrada['id'] . '_' . $base;
    try {
      $archivo = copiaMysql($config, $destino, $base, $prefijo, $sello);
      $tamano = is_file($archivo) ? filesize($archivo) : 0;
      $borradas = aplicarRetencion($destino, $prefijo, $retener);
      registrar(
        'OK ' . $entrada['id'] . ' ' . $archivo . ' (' . $tamano . ' bytes). Antiguas borradas: ' . $borradas,
        $raiz
      );
    } catch (Throwable $e) {
      $fallos++;
      registrar('ERROR ' . $entrada['id'] . ' (' . $base . '): ' . $e->getMessage(), $raiz);
    }
  }

  exit($fallos === 0 ? 0 : 1);
} catch (Throwable $e) {
  registrar('ERROR: ' . $e->getMessage(), $raiz);
  exit(1);
}
