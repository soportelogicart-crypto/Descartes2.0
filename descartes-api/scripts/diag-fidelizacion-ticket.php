<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

use Descartes\Api\Config\Database;

// Uso: php diag-fidelizacion-ticket.php [base1,base2]
// Modelo de fidelizacion de cada tienda y puntos guardados en los ultimos tickets con cliente.
$bases = array_values(array_filter(array_map('trim', explode(',', (string) ($argv[1] ?? '')))));
$base = Database::resolveConfig();
if ($bases === []) {
  $bases = [$base['database']];
}

foreach ($bases as $database) {
  $pdo = Database::createPdo(array_merge($base, ['database' => $database]));
  echo "=== {$database} ===" . PHP_EOL;

  $tiendas = $pdo->query(
    "SELECT RTRIM(e.Codigo) AS Codigo, RTRIM(ISNULL(e.TipoCalculoFidelizacion, '')) AS Tipo,
            RTRIM(ISNULL(t.Motor, '')) AS Motor, ISNULL(e.BloqueoFidelizacion, 0) AS Bloqueo
     FROM Empresas_Ges e
     LEFT JOIN TiposCalculoFidelizacion t ON RTRIM(t.Codigo) = RTRIM(ISNULL(e.TipoCalculoFidelizacion, ''))
     ORDER BY e.Codigo"
  )->fetchAll(PDO::FETCH_ASSOC);
  foreach ($tiendas as $t) {
    printf("  Tienda %-4s tipo=%-8s motor=%-15s bloqueo=%d%s", $t['Codigo'], $t['Tipo'], $t['Motor'], (int) $t['Bloqueo'], PHP_EOL);
  }

  $cols = $pdo->query(
    "SELECT COL_LENGTH('dbo.AlbaranesVentasCab', 'PuntosFidelizacionCompra')"
  )->fetchColumn();
  if ($cols === null || $cols === false) {
    echo "  AlbaranesVentasCab SIN columnas PuntosFidelizacion* (falta migracion)" . PHP_EOL . PHP_EOL;
    continue;
  }

  $filas = $pdo->query(
    "SELECT TOP 10 RTRIM(a.Empresa) AS Empresa, a.Albaran, RTRIM(a.Cliente) AS Cliente, a.Importe,
            CONVERT(varchar(16), a.Fecha, 120) AS Fecha, RTRIM(ISNULL(a.FacturaTipo, '')) AS FT, a.Factura,
            a.PuntosFidelizacionCompra AS PC, a.PuntosFidelizacionAcumulados AS PA,
            c.AcumuladoFidelizacion AS AcEuros, c.AcumuladoPuntos AS AcPuntos,
            c.PjeFidelizacion AS Pje, RTRIM(ISNULL(c.TarjetaFidelizacion, '')) AS Tarjeta
     FROM AlbaranesVentasCab a
     LEFT JOIN Clientes c ON RTRIM(c.Codigo) = RTRIM(a.Cliente)
     WHERE ISNULL(a.Sesion, 0) > 0 AND RTRIM(ISNULL(a.Cliente, '')) NOT IN ('', 'ZZZZZZZZZ')
     ORDER BY a.Fecha DESC, a.Albaran DESC"
  )->fetchAll(PDO::FETCH_ASSOC);
  foreach ($filas as $f) {
    printf(
      "  %s/%s %s ft=%-1s fac=%-9s cli=%-9s imp=%8.2f puntosCompra=%s puntosAcum=%s | cliente acumEuros=%s acumPuntos=%s pje=%s tarjeta=%s%s",
      $f['Empresa'], $f['Albaran'], $f['Fecha'], $f['FT'], (string) $f['Factura'], $f['Cliente'], (float) $f['Importe'],
      var_export($f['PC'], true), var_export($f['PA'], true),
      var_export($f['AcEuros'], true), var_export($f['AcPuntos'], true),
      var_export($f['Pje'], true), $f['Tarjeta'], PHP_EOL
    );
  }
  echo PHP_EOL;
}
