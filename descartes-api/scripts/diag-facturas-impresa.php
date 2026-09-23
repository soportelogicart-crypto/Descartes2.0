<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

use Descartes\Api\Config\Database;

$pdo = Database::fromEnv();
echo '== ' . $pdo->query('SELECT DB_NAME()')->fetchColumn() . ' ==' . PHP_EOL;

foreach ($pdo->query(
  "SELECT TOP 15 RTRIM(Empresa) AS Empresa, RTRIM(FacturaTipo) AS Tipo, Factura,
          RTRIM(Cliente) AS Cliente, Fecha, RTRIM(ISNULL(Estado, '')) AS Estado,
          ISNULL(Impresa, 0) AS Impresa
   FROM Facturas ORDER BY Fecha DESC, Factura DESC"
)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $f) {
  echo sprintf(
    "  %s/%s/%-8s cli=%-12s %s estado=%-2s Impresa=%s",
    $f['Empresa'], $f['Tipo'], $f['Factura'], $f['Cliente'],
    substr((string) $f['Fecha'], 0, 10), $f['Estado'], $f['Impresa']
  ) . PHP_EOL;
}
