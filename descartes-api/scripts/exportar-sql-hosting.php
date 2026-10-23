<?php

declare(strict_types=1);

/**
 * Genera un .sql.gz para importar en el MySQL del hosting.
 * Los nombres de tabla salen del esquema (mayúsculas correctas).
 * En Windows el volcado los escribe en minúsculas y en Linux no coinciden.
 *
 * Uso: php scripts/exportar-sql-hosting.php
 */

$raiz = dirname(__DIR__);
$esquema = $raiz . '/database/schema/mysql/001-esquema.sql';
$datos = $raiz . '/var/logia-demo-datos.sql';
$destino = $raiz . '/var/logia-demo-hosting.sql.gz';
$mysqldump = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';

if (!is_file($esquema)) {
  fwrite(STDERR, "No está el esquema MySQL.\n");
  exit(1);
}
if (!is_file($mysqldump)) {
  fwrite(STDERR, "No está mysqldump de XAMPP.\n");
  exit(1);
}

$textoEsquema = file_get_contents($esquema);
if ($textoEsquema === false) {
  fwrite(STDERR, "No se pudo leer el esquema.\n");
  exit(1);
}

preg_match_all('/CREATE TABLE IF NOT EXISTS `([^`]+)`/', $textoEsquema, $coincidencias);
$nombres = $coincidencias[1];
if ($nombres === []) {
  fwrite(STDERR, "El esquema no tiene tablas.\n");
  exit(1);
}

$buscar = [];
$reemplazar = [];
foreach ($nombres as $nombre) {
  $buscar[] = '`' . strtolower($nombre) . '`';
  $reemplazar[] = '`' . $nombre . '`';
}

if (!is_dir($raiz . '/var') && !mkdir($raiz . '/var', 0775, true) && !is_dir($raiz . '/var')) {
  fwrite(STDERR, "No se pudo crear var/.\n");
  exit(1);
}

echo 'Volcando datos de logia_demo...' . PHP_EOL;
$comando = escapeshellarg($mysqldump)
  . ' --host=127.0.0.1 --user=root --default-character-set=utf8mb4'
  . ' --no-create-info --skip-add-locks --skip-comments --skip-triggers'
  . ' --hex-blob --complete-insert --single-transaction'
  . ' --net-buffer-length=1048576'
  . ' logia_demo';
$volcado = popen($comando, 'rb');
if ($volcado === false) {
  fwrite(STDERR, "No se pudo ejecutar mysqldump.\n");
  exit(1);
}
$archivoDatos = fopen($datos, 'wb');
if ($archivoDatos === false) {
  fwrite(STDERR, "No se pudo crear el volcado temporal.\n");
  exit(1);
}
stream_copy_to_stream($volcado, $archivoDatos);
$codigo = pclose($volcado);
fclose($archivoDatos);
if ($codigo !== 0) {
  fwrite(STDERR, "mysqldump terminó con código {$codigo}.\n");
  exit(1);
}

echo 'Ajustando nombres de tabla...' . PHP_EOL;
$entrada = fopen($datos, 'rb');
$salida = gzopen($destino, 'wb9');
if ($entrada === false || $salida === false) {
  fwrite(STDERR, "No se pudo abrir el fichero de salida.\n");
  exit(1);
}

gzwrite($salida, "-- Importar en una base MySQL vacía del hosting.\n");
gzwrite($salida, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");
gzwrite($salida, $textoEsquema);
gzwrite($salida, "\n");

while (!feof($entrada)) {
  $linea = fgets($entrada, 16 * 1024 * 1024);
  if ($linea === false) {
    break;
  }
  gzwrite($salida, str_replace($buscar, $reemplazar, $linea));
}

gzwrite($salida, "\nSET FOREIGN_KEY_CHECKS = 1;\nSET UNIQUE_CHECKS = 1;\n");
fclose($entrada);
gzclose($salida);
unlink($datos);

$bytes = filesize($destino);
echo 'Tablas: ' . count($nombres) . PHP_EOL;
echo 'Destino: ' . $destino . PHP_EOL;
echo 'Bytes: ' . $bytes . PHP_EOL;
