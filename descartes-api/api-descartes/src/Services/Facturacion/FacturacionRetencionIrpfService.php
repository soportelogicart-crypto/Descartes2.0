<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Facturacion;

use PDO;

/**
 * Calcula la retención de una factura con la regla legacy confirmada:
 * cliente marcado + artículo marcado, sobre la base de esas líneas sin IVA.
 */
final class FacturacionRetencionIrpfService
{
  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  /**
   * @param list<array{empresa: string, tipo: string, albaran: int}> $albaranes
   * @return array{pjeRetIrpf: float, basRetIrpf: float, impRetIrpf: float}
   */
  public function calcular(
    string $empresaFacturacion,
    string $cliente,
    array $albaranes
  ): array {
    $cero = ['pjeRetIrpf' => 0.0, 'basRetIrpf' => 0.0, 'impRetIrpf' => 0.0];
    $empresaFacturacion = trim($empresaFacturacion);
    $cliente = trim($cliente);
    if ($empresaFacturacion === '' || $cliente === '' || $albaranes === []) {
      return $cero;
    }

    $st = $this->pdo->prepare(
      'SELECT TOP 1
              ISNULL(PjeRetIrpf, 0) AS PjeRetIrpf,
              ISNULL(SW_IVA, 0) AS PreciosIvaIncluido
       FROM Empresas_Ges
       WHERE Codigo = :empresa'
    );
    $st->execute(['empresa' => $empresaFacturacion]);
    $empresa = $st->fetch(PDO::FETCH_ASSOC);
    $pje = round((float) ($empresa['PjeRetIrpf'] ?? 0), 4);
    if ($pje == 0.0) {
      return $cero;
    }

    $st = $this->pdo->prepare(
      'SELECT TOP 1 ISNULL(RetIrpf, 0) FROM Clientes WHERE Codigo = :cliente'
    );
    $st->execute(['cliente' => $cliente]);
    if (empty($st->fetchColumn())) {
      return $cero;
    }

    $preciosIvaIncluido = !empty($empresa['PreciosIvaIncluido']);
    $lineas = $this->pdo->prepare(
      "SELECT l.Articulo, l.Cantidad, l.Precio, l.PjeIva, l.PjeDto
       FROM AlbaranesVentasLin l
       INNER JOIN Articulos a ON a.Codigo = l.Articulo
       WHERE l.Empresa = :empresa AND l.Tipo = :tipo AND l.Albaran = :albaran
         AND ISNULL(a.RetIrpf, 0) <> 0
         AND UPPER(RTRIM(ISNULL(l.Articulo, ''))) <> 'NO'"
    );

    $base = 0.0;
    foreach ($albaranes as $albaran) {
      $lineas->execute([
        'empresa' => trim((string) ($albaran['empresa'] ?? '')),
        'tipo' => trim((string) ($albaran['tipo'] ?? 'A')) ?: 'A',
        'albaran' => (int) ($albaran['albaran'] ?? 0),
      ]);
      foreach ($lineas->fetchAll(PDO::FETCH_ASSOC) ?: [] as $linea) {
        $precio = (float) ($linea['Precio'] ?? 0);
        $iva = (float) ($linea['PjeIva'] ?? 0);
        if ($preciosIvaIncluido && abs(100.0 + $iva) > 0.000001) {
          $precio /= (100.0 + $iva) / 100.0;
        }

        $importe = $precio * (float) ($linea['Cantidad'] ?? 0);
        $importe -= $importe * ((float) ($linea['PjeDto'] ?? 0) / 100.0);
        // Paridad con CreaFactura: sin IVA incluido redondea cada línea.
        $base += $preciosIvaIncluido ? $importe : round($importe, 2);
      }
    }

    $base = round($base, 2);
    if (abs($base) < 0.005) {
      return $cero;
    }
    return [
      'pjeRetIrpf' => $pje,
      'basRetIrpf' => $base,
      'impRetIrpf' => round($base * $pje / 100.0, 2),
    ];
  }
}
