<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(dirname(__DIR__)))->safeLoad();

use Descartes\Api\Config\Database;

// Uso: php diag-fecha-albaran.php <empresa> <albaran> [tipo] [base1,base2]
$empresa = trim((string) ($argv[1] ?? '1'));
$albaran = (int) ($argv[2] ?? 0);
$tipo = trim((string) ($argv[3] ?? 'A'));
$bases = array_values(array_filter(array_map('trim', explode(',', (string) ($argv[4] ?? '')))));

$base = Database::resolveConfig();
if ($bases === []) {
  $bases = [$base['database']];
}

foreach ($bases as $database) {
  $pdo = Database::createPdo(array_merge($base, ['database' => $database]));
  echo "=== {$database} ===" . PHP_EOL;

  if ($albaran < 0) {
    // Resumen: que combinaciones Estado/FacturaTipo existen en la empresa.
    $st = $pdo->prepare(
      "SELECT RTRIM(ISNULL(Estado, '')) AS Estado, RTRIM(ISNULL(FacturaTipo, '')) AS FacturaTipo,
              CASE WHEN ISNULL(Sesion, 0) > 0 THEN 1 ELSE 0 END AS ConSesion,
              COUNT(*) AS Documentos
       FROM AlbaranesVentasCab
       WHERE LTRIM(RTRIM(Empresa)) = ?
       GROUP BY RTRIM(ISNULL(Estado, '')), RTRIM(ISNULL(FacturaTipo, '')),
                CASE WHEN ISNULL(Sesion, 0) > 0 THEN 1 ELSE 0 END
       ORDER BY Documentos DESC"
    );
    $st->execute([$empresa]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
      printf(
        "  Estado=%-2s FacturaTipo=%-2s conSesion=%d -> %d documentos%s",
        $row['Estado'] !== '' ? $row['Estado'] : '-',
        $row['FacturaTipo'] !== '' ? $row['FacturaTipo'] : '-',
        (int) $row['ConSesion'],
        (int) $row['Documentos'],
        PHP_EOL
      );
    }
    echo PHP_EOL;
    continue;
  }

  if ($albaran > 0) {
    $st = $pdo->prepare(
      "SELECT TOP 5 Empresa, Tipo, Albaran, Fecha, Cliente, RTRIM(ISNULL(Estado, '')) AS Estado,
              RTRIM(ISNULL(FacturaTipo, '')) AS FacturaTipo, ISNULL(Factura, 0) AS Factura,
              ISNULL(Sesion, 0) AS Sesion, LTRIM(RTRIM(ISNULL(Puesto, ''))) AS Puesto,
              ISNULL(Impreso, 0) AS Impreso
       FROM AlbaranesVentasCab
       WHERE LTRIM(RTRIM(Empresa)) = ? AND Albaran = ?"
    );
    $st->execute([$empresa, $albaran]);
  } else {
    $st = $pdo->prepare(
      "SELECT TOP 10 Empresa, Tipo, Albaran, Fecha, Cliente, RTRIM(ISNULL(Estado, '')) AS Estado,
              RTRIM(ISNULL(FacturaTipo, '')) AS FacturaTipo, ISNULL(Factura, 0) AS Factura,
              ISNULL(Sesion, 0) AS Sesion
       FROM AlbaranesVentasCab
       WHERE LTRIM(RTRIM(Empresa)) = ? AND LTRIM(RTRIM(Tipo)) = ?
       ORDER BY Fecha DESC, Albaran DESC"
    );
    $st->execute([$empresa, $tipo]);
  }

  $filas = $st->fetchAll(PDO::FETCH_ASSOC);
  if ($filas === []) {
    echo '  (sin documentos)' . PHP_EOL . PHP_EOL;
    continue;
  }

  foreach ($filas as $row) {
    $ft = strtoupper(trim((string) $row['FacturaTipo']));
    $factura = (int) $row['Factura'];
    $bloqueado = $ft === 'F' || $ft === 'A';
    $esTicket = $ft === 'T' && $factura > 0;
    $docKind = $esTicket ? 'ticket'
      : ($ft === 'R' ? 'presupuesto'
        : (($bloqueado && $factura > 0) ? 'factura' : 'albaran'));
    // Misma condicion que puedeEditarFechaCabecera en VentaDetalleView.
    $editable = !$bloqueado && !$esTicket && $docKind !== 'factura' && $docKind !== 'presupuesto';

    printf(
      "  %s/%s/%d  %s  Estado=%-2s FacturaTipo=%-2s Factura=%-8d Sesion=%-6d Puesto=%-4s doc=%-11s fechaEditable(en modificar)=%s%s",
      trim((string) $row['Empresa']),
      trim((string) $row['Tipo']),
      (int) $row['Albaran'],
      substr((string) $row['Fecha'], 0, 10),
      trim((string) $row['Estado']) !== '' ? trim((string) $row['Estado']) : '-',
      $ft !== '' ? $ft : '-',
      $factura,
      (int) ($row['Sesion'] ?? 0),
      trim((string) ($row['Puesto'] ?? '')) !== '' ? trim((string) $row['Puesto']) : '-',
      $docKind,
      $editable ? 'SI' : 'NO',
      PHP_EOL
    );
  }
  echo PHP_EOL;
}
