<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Descartes\Api\Config\Database;

$pdo = Database::fromEnv();
$st = $pdo->query(
  "SELECT TOP 20 Empresa, Tipo, Albaran, NroLin, Articulo
   FROM AlbaranesVentasLin WHERE NroLin = 0 ORDER BY Albaran DESC"
);
echo json_encode($st->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT) . "\n";
