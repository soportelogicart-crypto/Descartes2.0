<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Descartes\Api\Config\Database;

$pdo = Database::fromEnv();
$articulos = ['9999', '9996', '9997', '9998', '9995'];

foreach ([26000054, 26000055] as $alb) {
  echo "=== ALBARAN 1-{$alb} ===\n";
  $st = $pdo->prepare("SELECT * FROM AlbaranesCompraCab WHERE Empresa = '1' AND Albaran = :a");
  $st->execute(['a' => $alb]);
  $cab = $st->fetch(PDO::FETCH_ASSOC);
  if (!$cab) {
    echo "Cabecera no encontrada\n\n";
    continue;
  }
  $keys = [
    'Albaran', 'SuAlbaran', 'FechaAlbaran', 'Proveedor', 'Almacen', 'ImporteAlb', 'ImporteDtos',
    'ImporteIVA', 'ImporteRec', 'Observaciones', 'Actualizado', 'AlbaranDevolucion',
    'ImporteTransporte', 'CoeficienteTransporte', 'BrutoConTransporte', 'Estado', 'Serie', 'Proyecto',
  ];
  foreach ($keys as $k) {
    echo "{$k}: " . ($cab[$k] ?? '') . "\n";
  }

  $st2 = $pdo->prepare(
    'SELECT NroLin, Articulo, Descripcion, Cantidad, Precio, PjeDto, Dto1, Dto2, Dto3, Pedido, Lote, Almacen
     FROM AlbaranesComprasLin WHERE Empresa = \'1\' AND Albaran = :a ORDER BY NroLin'
  );
  $st2->execute(['a' => $alb]);
  echo "--- LINEAS ---\n";
  $sumImporte = 0.0;
  while ($l = $st2->fetch(PDO::FETCH_ASSOC)) {
    $cant = (float) ($l['Cantidad'] ?? 0);
    $precio = (float) ($l['Precio'] ?? 0);
    $dto1 = (float) ($l['Dto1'] ?? 0);
    $neto = round($cant * $precio * (1 - $dto1 / 100), 2);
    $sumImporte += $neto;
    echo sprintf(
      "NroLin=%s Art=%s Cant=%s Precio=%s Dto1=%s Neto=%s\n",
      $l['NroLin'],
      $l['Articulo'],
      $l['Cantidad'],
      $l['Precio'],
      $l['Dto1'],
      $neto
    );
  }
  echo "Suma neto lineas: {$sumImporte}\n\n";
}

echo "=== ARTICULOS (PrecioVen1, PrecioMedio, UltCoste si existe) ===\n";
$stArt = $pdo->prepare(
  'SELECT Codigo, PrecioVen1, PrecioVen2, PrecioMedio, UltCoste, CosteMedio
   FROM Articulos WHERE Codigo = :c'
);
foreach ($articulos as $cod) {
  try {
    $stArt->execute(['c' => $cod]);
    $a = $stArt->fetch(PDO::FETCH_ASSOC);
    if ($a) {
      echo implode(' | ', array_map(
        static fn ($k, $v) => "{$k}={$v}",
        array_keys($a),
        array_values($a)
      )) . "\n";
    }
  } catch (Throwable $e) {
    $stArt2 = $pdo->prepare('SELECT Codigo, PrecioVen1, PrecioMedio FROM Articulos WHERE Codigo = :c');
    $stArt2->execute(['c' => $cod]);
    $a = $stArt2->fetch(PDO::FETCH_ASSOC);
    echo ($a ? json_encode($a) : "No {$cod}") . "\n";
  }
}

echo "\n=== STOCK almacen 99 agosto 2026 ===\n";
try {
  $stStock = $pdo->query(
    "SELECT Codigo, Almacen, [Año], Mes, Entradas, ValorEntradas
     FROM Stock WHERE Almacen = 99 AND [Año] = 2026 AND Mes = 8
       AND Codigo IN ('9999','9996','9997','9998','9995')
     ORDER BY Codigo"
  );
  while ($s = $stStock->fetch(PDO::FETCH_ASSOC)) {
    echo json_encode($s) . "\n";
  }
} catch (Throwable $e) {
  echo 'Stock query error: ' . $e->getMessage() . "\n";
}

echo "\n=== COMPARATIVA RESUMEN ===\n";
$rows = [];
foreach ([26000054, 26000055] as $alb) {
  $st = $pdo->prepare(
    "SELECT c.ImporteAlb, c.ImporteTransporte, c.CoeficienteTransporte, c.BrutoConTransporte,
            c.Actualizado, c.SuAlbaran, c.Observaciones,
            (SELECT SUM(l.Cantidad * l.Precio) FROM AlbaranesComprasLin l WHERE l.Empresa='1' AND l.Albaran=c.Albaran) AS sumCantPrecio
     FROM AlbaranesCompraCab c WHERE c.Empresa='1' AND c.Albaran=:a"
  );
  $st->execute(['a' => $alb]);
  $rows[$alb] = $st->fetch(PDO::FETCH_ASSOC);
}
foreach ($rows as $alb => $r) {
  echo "Albaran {$alb}: ImporteAlb={$r['ImporteAlb']} BrutoConTrans={$r['BrutoConTransporte']} Trans={$r['ImporteTransporte']} Coef={$r['CoeficienteTransporte']} SumLineas={$r['sumCantPrecio']} Actualizado={$r['Actualizado']}\n";
}
