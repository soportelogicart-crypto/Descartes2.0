<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

use Descartes\Api\Config\Database;
use Descartes\Api\Services\Facturacion\FacturacionRetencionIrpfService;

$pdo = Database::fromEnv();
$facturas = $pdo->query(
  "SELECT TOP 10
          RTRIM(Empresa) AS Empresa, RTRIM(FacturaTipo) AS FacturaTipo,
          Factura, RTRIM(Cliente) AS Cliente,
          PjeRetIrpf, BasRetIrpf, ImpRetIrpf
   FROM Facturas
   WHERE ABS(ISNULL(ImpRetIrpf, 0)) >= 0.005
   ORDER BY Fecha DESC"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$servicio = new FacturacionRetencionIrpfService($pdo);
foreach ($facturas as $factura) {
  $st = $pdo->prepare(
    "SELECT RTRIM(Empresa) AS empresa, RTRIM(Tipo) AS tipo, Albaran AS albaran
     FROM AlbaranesVentasCab
     WHERE EmpresaFacturacion = :empresa
       AND FacturaTipo = :tipoFactura
       AND Factura = :factura"
  );
  $st->execute([
    'empresa' => $factura['Empresa'],
    'tipoFactura' => $factura['FacturaTipo'],
    'factura' => $factura['Factura'],
  ]);
  $albaranes = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  echo json_encode([
    'factura' => $factura,
    'albaranes' => count($albaranes),
    'calculado' => $servicio->calcular(
      (string) $factura['Empresa'],
      (string) $factura['Cliente'],
      $albaranes
    ),
  ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
}

if ($facturas === []) {
  echo "No hay facturas legacy con retención IRPF para comparar." . PHP_EOL;
  $candidato = $pdo->query(
    "SELECT TOP 1
            RTRIM(c.Empresa) AS empresa, RTRIM(c.Tipo) AS tipo, c.Albaran AS albaran,
            RTRIM(c.Cliente) AS cliente, e.PjeRetIrpf
     FROM AlbaranesVentasCab c
     INNER JOIN Clientes cli ON cli.Codigo = c.Cliente AND ISNULL(cli.RetIrpf, 0) <> 0
     INNER JOIN Empresas_Ges e ON e.Codigo = c.Empresa AND ISNULL(e.PjeRetIrpf, 0) <> 0
     INNER JOIN AlbaranesVentasLin l
       ON l.Empresa = c.Empresa AND l.Tipo = c.Tipo AND l.Albaran = c.Albaran
     INNER JOIN Articulos a ON a.Codigo = l.Articulo AND ISNULL(a.RetIrpf, 0) <> 0
     ORDER BY c.Fecha DESC"
  )->fetch(PDO::FETCH_ASSOC);
  if ($candidato !== false) {
    echo json_encode([
      'candidato' => $candidato,
      'calculado' => $servicio->calcular(
        (string) $candidato['empresa'],
        (string) $candidato['cliente'],
        [[
          'empresa' => (string) $candidato['empresa'],
          'tipo' => (string) $candidato['tipo'],
          'albaran' => (int) $candidato['albaran'],
        ]]
      ),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
  } else {
    echo "No hay un albarán que combine cliente, tienda y artículo marcados." . PHP_EOL;
  }
}
