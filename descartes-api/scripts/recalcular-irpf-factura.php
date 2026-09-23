<?php

declare(strict_types=1);

/**
 * Recalcula y graba la retencion IRPF de una factura ya emitida.
 * Uso: php recalcular-irpf-factura.php <empresa> <tipo> <numero> [--aplicar]
 * Sin --aplicar solo muestra lo que haria.
 */

require dirname(__DIR__) . '/vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

use Descartes\Api\Config\Database;
use Descartes\Api\Services\Facturacion\FacturacionRetencionIrpfService;
use Descartes\Api\Services\Facturacion\RecibosFacturaService;

$empresa = (string) ($argv[1] ?? '1');
$tipo = (string) ($argv[2] ?? 'F');
$numero = (int) ($argv[3] ?? 0);
$aplicar = in_array('--aplicar', $argv, true);

$pdo = Database::fromEnv();
echo '== ' . $pdo->query('SELECT DB_NAME()')->fetchColumn() . ' ==' . PHP_EOL;

$st = $pdo->prepare(
  "SELECT RTRIM(Cliente) AS Cliente, ISNULL(PjeRetIrpf, 0) AS Pje,
          ISNULL(BasRetIrpf, 0) AS Bas, ISNULL(ImpRetIrpf, 0) AS Imp
   FROM Facturas WHERE Empresa = :e AND FacturaTipo = :t AND Factura = :f"
);
$st->execute(['e' => $empresa, 't' => $tipo, 'f' => $numero]);
$factura = $st->fetch(PDO::FETCH_ASSOC);
if ($factura === false) {
  echo "Factura no encontrada." . PHP_EOL;
  exit(1);
}

$st = $pdo->prepare(
  "SELECT RTRIM(Empresa) AS empresa, RTRIM(Tipo) AS tipo, Albaran AS albaran
   FROM AlbaranesVentasCab
   WHERE EmpresaFacturacion = :e AND FacturaTipo = :t AND Factura = :f"
);
$st->execute(['e' => $empresa, 't' => $tipo, 'f' => $numero]);
$albaranes = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$irpf = (new FacturacionRetencionIrpfService($pdo))->calcular(
  $empresa,
  (string) $factura['Cliente'],
  $albaranes
);

printf(
  "Actual:   pje=%s bas=%s imp=%s%sNuevo:    pje=%s bas=%s imp=%s%s",
  $factura['Pje'], $factura['Bas'], $factura['Imp'], PHP_EOL,
  $irpf['pjeRetIrpf'], $irpf['basRetIrpf'], $irpf['impRetIrpf'], PHP_EOL
);

if (!$aplicar) {
  echo "Simulacion: añade --aplicar para grabar." . PHP_EOL;
  exit(0);
}

$up = $pdo->prepare(
  "UPDATE Facturas
   SET PjeRetIrpf = :pje, BasRetIrpf = :bas, ImpRetIrpf = :imp
   WHERE Empresa = :e AND FacturaTipo = :t AND Factura = :f"
);
$up->execute([
  'pje' => $irpf['pjeRetIrpf'],
  'bas' => $irpf['basRetIrpf'],
  'imp' => $irpf['impRetIrpf'],
  'e' => $empresa,
  't' => $tipo,
  'f' => $numero,
]);
echo 'Actualizadas ' . $up->rowCount() . ' filas.' . PHP_EOL;

// Los recibos se emitieron sobre el bruto: hay que rehacerlos sobre el liquido.
$st = $pdo->prepare(
  "SELECT COUNT(*) FROM Recibos
   WHERE Empresa = :e AND FacturaTipo = :t AND Factura = :f
     AND (ISNULL(Liquidado, 0) <> 0 OR ISNULL(Remesado, 0) <> 0)"
);
$st->execute(['e' => $empresa, 't' => $tipo, 'f' => $numero]);
if ((int) $st->fetchColumn() > 0) {
  echo "Recibos liquidados o remesados: no se regeneran." . PHP_EOL;
  exit(0);
}

$pdo->prepare(
  "DELETE FROM Recibos WHERE Empresa = :e AND FacturaTipo = :t AND Factura = :f"
)->execute(['e' => $empresa, 't' => $tipo, 'f' => $numero]);

$recibos = (new RecibosFacturaService($pdo))->generar($empresa, $tipo, $numero);
echo 'Recibos regenerados: ' . json_encode($recibos) . PHP_EOL;
