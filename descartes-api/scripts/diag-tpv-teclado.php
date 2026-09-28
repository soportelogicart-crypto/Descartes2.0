<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

use Descartes\Api\Config\Database;

$pdo = Database::fromEnv();

$puestos = $pdo->query(
  "SELECT RTRIM(Puesto) AS puesto,
          RTRIM(ISNULL(Descripcion, '')) AS descripcion,
          ISNULL(Teclado, 0) AS teclado,
          ISNULL(Tarifa, 0) AS tarifa
   FROM Puestos
   ORDER BY Puesto"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$teclados = $pdo->query(
  "SELECT RTRIM(c.H_GENERAL) AS general,
          RTRIM(ISNULL(c.H_NOMBRE, '')) AS nombre,
          (SELECT COUNT(*) FROM DefPlus d WHERE d.H_GENERAL = c.H_GENERAL) AS botones
   FROM DefPlusC c
   ORDER BY c.H_GENERAL"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

echo json_encode(
  ['puestos' => $puestos, 'teclados' => $teclados],
  JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
) . PHP_EOL;
