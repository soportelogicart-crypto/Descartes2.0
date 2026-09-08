<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Descartes\Api\Config\Database;
use Descartes\Api\Services\Facturacion\ConexionContableService;

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$pdo = Database::fromEnv();
$checks = [
  'empresaGestion' => "SELECT TOP 1 Codigo, Nombre, UltFactura FROM Empresas_Ges",
  'usuarioGestion' => "SELECT TOP 1 Codigo, Rol, Baja FROM Usuarios_Ges",
  'contabilidad' => "SELECT TOP 1 Codigo, UltNum FROM Series",
];

foreach ($checks as $name => $sql) {
  $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
  echo $name . ': ' . ($row === false ? 'sin filas' : 'OK') . PHP_EOL;
}

$conexion = new ConexionContableService($pdo);
$config = $conexion->paraEmpresa('1');
echo 'conexionContable: ' . $config['pdo']->query('SELECT DB_NAME()')->fetchColumn() . PHP_EOL;
echo 'conexionCompartida: ' . ($config['pdo'] === $pdo ? 'OK' : 'ERROR') . PHP_EOL;
echo 'serie: ' . $config['serie'] . PHP_EOL;
