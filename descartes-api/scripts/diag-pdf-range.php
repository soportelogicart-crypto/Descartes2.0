<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

use Descartes\Api\Config\Database;

$pdo = Database::fromEnv();
$sql = "SELECT COUNT(*) n,
  SUM(ISNULL(ImporteBase1,0)+ISNULL(ImporteBase2,0)+ISNULL(ImporteBase3,0)
      +ISNULL(ImporteBase4,0)+ISNULL(ImporteBase5,0)+ISNULL(ImporteBase6,0)) base,
  SUM(ISNULL(ImporteIva1,0)+ISNULL(ImporteIva2,0)+ISNULL(ImporteIva3,0)
      +ISNULL(ImporteIva4,0)+ISNULL(ImporteIva5,0)+ISNULL(ImporteIva6,0)) iva,
  SUM(Importe) tot
FROM Facturas
WHERE RTRIM(FacturaTipo)='F' AND Factura BETWEEN 226470 AND 226505
  AND Factura NOT IN (226503,226504)";
print_r($pdo->query($sql)->fetch(PDO::FETCH_ASSOC));
