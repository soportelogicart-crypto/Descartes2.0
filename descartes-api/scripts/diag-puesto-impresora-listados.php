<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

use Descartes\Api\Config\Database;

$pdo = Database::fromEnv();

$rows = $pdo->query(
  "SELECT RTRIM(Puesto) AS puesto,
          RTRIM(ISNULL(Descripcion, '')) AS descripcion,
          RTRIM(ISNULL(Imp80, '')) AS imp80_listados,
          ISNULL(Impresora80, 0) AS impresora80_indice,
          RTRIM(ISNULL(ImpAlbaranes, '')) AS impAlbaranes
   FROM Puestos
   ORDER BY Puesto"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
