<?php

declare(strict_types=1);

namespace Descartes\Api\Repositories;

use PDO;

/**
 * Estadistica del proveedor (legacy frmEstProveedores).
 *
 * Compras: acumulados de [ProvCompras], igual que el legacy.
 * Ventas: albaranes de articulos cuyo UltProveedor es este codigo.
 */
final class ProveedoresEstadisticaRepository
{
  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  public function proveedorExiste(string $codigo): bool
  {
    $stmt = $this->pdo->prepare('SELECT 1 FROM [Proveedores] WHERE RTRIM([Codigo]) = :codigo');
    $stmt->execute(['codigo' => $codigo]);
    return (bool) $stmt->fetchColumn();
  }

  /**
   * @return array<string, mixed>
   */
  public function estadisticaAnual(string $codigo, int $anio): array
  {
    return [
      'anio' => $anio,
      'compras' => $this->serieMensual($this->comprasProvCompras($codigo, $anio)),
      'ventas' => $this->serieMensual($this->ventasPorUltProveedor($codigo, $anio)),
    ];
  }

  /** @param array<int, float> $porMes */
  private function serieMensual(array $porMes): array
  {
    $meses = [];
    $total = 0.0;
    for ($mes = 1; $mes <= 12; $mes++) {
      $importe = round((float) ($porMes[$mes] ?? 0.0), 2);
      $meses[] = ['mes' => $mes, 'importe' => $importe];
      $total = round($total + $importe, 2);
    }
    return ['meses' => $meses, 'total' => $total];
  }

  /** @return array<int, float> */
  private function comprasProvCompras(string $codigo, int $anio): array
  {
    $stmt = $this->pdo->prepare(
      'SELECT [Mes], [Importe]
       FROM [ProvCompras]
       WHERE RTRIM([Codigo]) = :codigo AND [Año] = :anio'
    );
    $stmt->execute(['codigo' => $codigo, 'anio' => $anio]);
    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $out[(int) $row['Mes']] = (float) ($row['Importe'] ?? 0);
    }
    return $out;
  }

  /** @return array<int, float> */
  private function ventasPorUltProveedor(string $codigo, int $anio): array
  {
    $stmt = $this->pdo->prepare(
      "SELECT MONTH(c.[Fecha]) AS Mes,
              SUM(ROUND(
                l.[Importe] / ((100 + ISNULL(l.[PjeIva], 0) + ISNULL(l.[PjeRec], 0)) / 100.0)
                * (100 - ISNULL(c.[PjeDto], 0)) / 100.0
              , 2)) AS Importe
       FROM [AlbaranesVentasLin] l
       INNER JOIN [AlbaranesVentasCab] c
         ON c.[Empresa] = l.[Empresa] AND c.[Tipo] = l.[Tipo] AND c.[Albaran] = l.[Albaran]
       INNER JOIN [Articulos] a ON a.[Codigo] = l.[Articulo]
       WHERE YEAR(c.[Fecha]) = :anio
         AND RTRIM(ISNULL(a.[UltProveedor], '')) = :codigo
         AND (c.[FacturaTipo] IS NULL OR c.[FacturaTipo] NOT IN ('R', 'M', 'Z'))
       GROUP BY MONTH(c.[Fecha])"
    );
    $stmt->execute(['codigo' => $codigo, 'anio' => $anio]);
    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $out[(int) $row['Mes']] = (float) ($row['Importe'] ?? 0);
    }
    return $out;
  }
}
