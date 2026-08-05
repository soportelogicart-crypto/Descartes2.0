<?php

declare(strict_types=1);

require 'C:/xampp/htdocs/descartes-api/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable('C:/xampp/htdocs/descartes-api');
$dotenv->safeLoad();

use Descartes\Api\Config\Database;
use Descartes\Api\Config\EntityConfig;

try {
  $config = EntityConfig::get('puestos-trabajo');
  echo 'EntityConfig OK, fields=' . count($config['fields'] ?? []) . PHP_EOL;
} catch (Throwable $e) {
  echo 'EntityConfig FAIL: ' . $e->getMessage() . PHP_EOL;
  exit(1);
}

$pdo = Database::fromEnv();
$container = require 'C:/xampp/htdocs/descartes-api/src/bootstrap.php';
// bootstrap is a closure - skip

$stmt = $pdo->query('SELECT TOP 1 [Puesto] FROM [Puestos] ORDER BY [Puesto]');
$row = $stmt->fetch();
$codigo = $row ? trim((string) $row['Puesto']) : '01';
echo "Sample puesto: {$codigo}" . PHP_EOL;

$stmt = $pdo->prepare('SELECT COUNT(*) AS n FROM [Puestos]');
$stmt->execute();
echo 'Puestos count: ' . $stmt->fetch()['n'] . PHP_EOL;

$stmt = $pdo->query(
  "SELECT COUNT(*) AS n FROM [Empresas] e WHERE EXISTS (SELECT 1 FROM [Parametros] p WHERE RTRIM(p.[Empresa]) = RTRIM(e.[Codigo]))"
);
echo 'Tiendas con Parametros: ' . $stmt->fetch()['n'] . PHP_EOL;

echo 'Done' . PHP_EOL;
