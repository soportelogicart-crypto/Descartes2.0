<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

use Descartes\Api\Config\Database;
use Descartes\Api\Services\Facturacion\FacturacionRetencionIrpfService;

$empresa = (string) ($argv[1] ?? '1');
$tipo = (string) ($argv[2] ?? 'F');
$numero = (int) ($argv[3] ?? 0);

$pdo = Database::fromEnv();
echo '== ' . $pdo->query('SELECT DB_NAME()')->fetchColumn() . " ==" . PHP_EOL;

$st = $pdo->prepare(
  "SELECT RTRIM(Empresa) AS Empresa, RTRIM(FacturaTipo) AS Tipo, Factura,
          RTRIM(Cliente) AS Cliente, Fecha, Importe,
          ISNULL(PjeRetIrpf, 0) AS Pje, ISNULL(BasRetIrpf, 0) AS Bas, ISNULL(ImpRetIrpf, 0) AS Imp
   FROM Facturas WHERE Empresa = :e AND FacturaTipo = :t AND Factura = :f"
);
$st->execute(['e' => $empresa, 't' => $tipo, 'f' => $numero]);
$factura = $st->fetch(PDO::FETCH_ASSOC);
if ($factura === false) {
  echo "Factura no encontrada." . PHP_EOL;
  exit(1);
}
echo 'Factura: ' . json_encode($factura, JSON_UNESCAPED_UNICODE) . PHP_EOL;

$st = $pdo->prepare(
  "SELECT RTRIM(Empresa) AS empresa, RTRIM(Tipo) AS tipo, Albaran AS albaran
   FROM AlbaranesVentasCab
   WHERE EmpresaFacturacion = :e AND FacturaTipo = :t AND Factura = :f"
);
$st->execute(['e' => $empresa, 't' => $tipo, 'f' => $numero]);
$albaranes = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
echo 'Albaranes: ' . json_encode($albaranes, JSON_UNESCAPED_UNICODE) . PHP_EOL;

$lin = $pdo->prepare(
  "SELECT RTRIM(l.Articulo) AS Articulo, l.Cantidad, l.Precio, l.PjeIva, l.PjeDto,
          ISNULL(a.RetIrpf, 0) AS ArtRetIrpf
   FROM AlbaranesVentasLin l
   LEFT JOIN Articulos a ON a.Codigo = l.Articulo
   WHERE l.Empresa = :e AND l.Tipo = :t AND l.Albaran = :a"
);
foreach ($albaranes as $alb) {
  $lin->execute(['e' => $alb['empresa'], 't' => $alb['tipo'], 'a' => $alb['albaran']]);
  foreach ($lin->fetchAll(PDO::FETCH_ASSOC) ?: [] as $l) {
    echo sprintf(
      "  alb %s/%s/%s  art=%-16s cant=%-8s precio=%-10s iva=%-6s dto=%-6s artIrpf=%s",
      $alb['empresa'], $alb['tipo'], $alb['albaran'], $l['Articulo'],
      $l['Cantidad'], $l['Precio'], $l['PjeIva'], $l['PjeDto'], $l['ArtRetIrpf']
    ) . PHP_EOL;
  }
}

$st = $pdo->prepare("SELECT ISNULL(RetIrpf, 0) FROM Clientes WHERE Codigo = :c");
$st->execute(['c' => $factura['Cliente']]);
echo 'Cliente RetIrpf: ' . var_export($st->fetchColumn(), true) . PHP_EOL;

$st = $pdo->prepare("SELECT ISNULL(PjeRetIrpf, 0) FROM Empresas_Ges WHERE Codigo = :e");
$st->execute(['e' => $empresa]);
echo 'Tienda PjeRetIrpf: ' . var_export($st->fetchColumn(), true) . PHP_EOL;

echo 'Recalculo: ' . json_encode(
  (new FacturacionRetencionIrpfService($pdo))->calcular(
    (string) $factura['Empresa'],
    (string) $factura['Cliente'],
    $albaranes
  )
) . PHP_EOL;
