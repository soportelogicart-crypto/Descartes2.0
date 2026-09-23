<?php

declare(strict_types=1);

/**
 * Por que un cliente no aparece en el grid de Mantenimiento -> Clientes.
 * Uso: php scripts/diag-cliente-busqueda.php SHOROBAN
 */

require dirname(__DIR__) . '/vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

use Descartes\Api\Config\Database;

$texto = trim((string) ($argv[1] ?? 'SHOROBAN'));
$pdo = Database::fromEnv();

function out(string $title, mixed $data): void
{
  echo "\n=== {$title} ===\n";
  echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}

$st = $pdo->prepare(
  "SELECT RTRIM(Codigo) AS Codigo, RTRIM(Empresa) AS Empresa, RazonSocial, NIF, Baja
     FROM Clientes
    WHERE RazonSocial LIKE :q OR Codigo LIKE :q2 OR NIF LIKE :q3"
);
$like = '%' . $texto . '%';
$st->execute(['q' => $like, 'q2' => $like, 'q3' => $like]);
$filas = $st->fetchAll(PDO::FETCH_ASSOC);
out("Coincidencias con '{$texto}'", $filas);

foreach ($filas as $fila) {
  $codigo = (string) $fila['Codigo'];
  $pos = $pdo->prepare('SELECT COUNT(*) FROM Clientes WHERE Codigo < :c');
  $pos->execute(['c' => $codigo]);
  out("Posicion de {$codigo} ordenando por Codigo", [
    'clientesAntes' => (int) $pos->fetchColumn(),
    'limiteGrid' => 200,
  ]);
}

out('Total clientes', (int) $pdo->query('SELECT COUNT(*) FROM Clientes')->fetchColumn());
