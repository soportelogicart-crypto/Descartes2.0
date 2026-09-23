<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

use Descartes\Api\Config\Database;

$pdo = Database::fromEnv();

echo '== Conexion: ' . $pdo->query('SELECT DB_NAME()')->fetchColumn() . ' @ '
  . $pdo->query('SELECT @@SERVERNAME')->fetchColumn() . ' ==' . PHP_EOL . PHP_EOL;

echo "== Empresas_Ges ==" . PHP_EOL;
foreach ($pdo->query(
  "SELECT RTRIM(Codigo) AS Codigo, ISNULL(PjeRetIrpf, 0) AS PjeRetIrpf,
          ISNULL(SW_IVA, 0) AS SW_IVA, ISNULL(CtbRetIrpf, 0) AS CtbRetIrpf
   FROM Empresas_Ges ORDER BY Codigo"
)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $f) {
  echo sprintf(
    "  tienda=%-6s PjeRetIrpf=%-8s SW_IVA=%-4s CtbRetIrpf=%s",
    $f['Codigo'], $f['PjeRetIrpf'], $f['SW_IVA'], $f['CtbRetIrpf']
  ) . PHP_EOL;
}

echo PHP_EOL . "== Clientes con RetIrpf ==" . PHP_EOL;
echo '  total marcados: '
  . $pdo->query("SELECT COUNT(*) FROM Clientes WHERE ISNULL(RetIrpf, 0) <> 0")->fetchColumn()
  . ' / ' . $pdo->query("SELECT COUNT(*) FROM Clientes")->fetchColumn() . PHP_EOL;
foreach ($pdo->query(
  "SELECT TOP 20 RTRIM(Codigo) AS Codigo FROM Clientes
   WHERE ISNULL(RetIrpf, 0) <> 0 ORDER BY Codigo"
)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $c) {
  echo '  ' . $c['Codigo'] . PHP_EOL;
}

echo PHP_EOL . "== Articulos con RetIrpf ==" . PHP_EOL;
echo '  total marcados: '
  . $pdo->query("SELECT COUNT(*) FROM Articulos WHERE ISNULL(RetIrpf, 0) <> 0")->fetchColumn()
  . ' / ' . $pdo->query("SELECT COUNT(*) FROM Articulos")->fetchColumn() . PHP_EOL;
foreach ($pdo->query(
  "SELECT TOP 20 RTRIM(Codigo) AS Codigo FROM Articulos
   WHERE ISNULL(RetIrpf, 0) <> 0 ORDER BY Codigo"
)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $a) {
  echo '  ' . $a['Codigo'] . PHP_EOL;
}

echo PHP_EOL . "== Ultimas facturas ==" . PHP_EOL;
foreach ($pdo->query(
  "SELECT TOP 10 RTRIM(Empresa) AS Empresa, RTRIM(FacturaTipo) AS Tipo, Factura,
          RTRIM(Cliente) AS Cliente, Fecha,
          ISNULL(PjeRetIrpf, 0) AS Pje, ISNULL(BasRetIrpf, 0) AS Bas,
          ISNULL(ImpRetIrpf, 0) AS Imp, Importe
   FROM Facturas ORDER BY Fecha DESC, Factura DESC"
)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $f) {
  echo sprintf(
    "  %s/%s/%s cli=%-12s %s pje=%s bas=%s imp=%s total=%s",
    $f['Empresa'], $f['Tipo'], $f['Factura'], $f['Cliente'],
    substr((string) $f['Fecha'], 0, 10), $f['Pje'], $f['Bas'], $f['Imp'], $f['Importe']
  ) . PHP_EOL;
}
