<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

$pdo = Descartes\Api\Config\Database::fromEnv();

foreach (['001411', '001412'] as $art) {
  echo "=== $art ===\n";
  $st = $pdo->prepare('SELECT UltProveedor, PrecioUltimo, PrecioMedio FROM Articulos WHERE RTRIM(Codigo)=:a');
  $st->execute(['a' => $art]);
  echo 'Articulos: ' . json_encode($st->fetch(PDO::FETCH_ASSOC)) . "\n";
  $st2 = $pdo->prepare('SELECT Proveedor, PjeDto FROM OfertasProveedor WHERE RTRIM(Articulo)=:a');
  $st2->execute(['a' => $art]);
  foreach ($st2 as $r) {
    echo 'Oferta: ' . json_encode($r) . "\n";
  }
}
