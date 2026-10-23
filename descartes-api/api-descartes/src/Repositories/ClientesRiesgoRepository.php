<?php

declare(strict_types=1);

namespace Descartes\Api\Repositories;

use PDO;

/**
 * Riesgo del cliente (legacy CalRiesgoCliente / CalRiesgoComercialCliente).
 *
 * No son columnas de [Clientes]: se calculan al abrir la ficha.
 * Rama Barea omitida. Contabilidad externa (ContaPropia + ControlSaldoCliente)
 * no se consulta: el default del INI es ControlSaldoCliente=N y se suman recibos.
 */
final class ClientesRiesgoRepository
{
  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  /**
   * @return array{riesgoAcumulado: float, riesgoComercial: float, riesgoPendiente: float|null}
   */
  public function calcular(string $codigo, float $limiteCredito): array
  {
    $acumulado = round(
      $this->albaranesSinFacturar($codigo)
      + $this->facturasDiferidasPendientes($codigo)
      + $this->recibosPendientes($codigo),
      2
    );
    $comercial = round($acumulado + $this->pedidosPendientes($codigo), 2);
    $pendiente = $limiteCredito != 0.0 ? round($limiteCredito - $acumulado, 2) : null;

    return [
      'riesgoAcumulado' => $acumulado,
      'riesgoComercial' => $comercial,
      'riesgoPendiente' => $pendiente,
    ];
  }

  private function albaranesSinFacturar(string $codigo): float
  {
    $stmt = $this->pdo->prepare(
      "SELECT SUM(ISNULL([Importe], 0)) AS ImportePendiente
       FROM [AlbaranesVentasCab]
       WHERE [Cliente] = :codigo
         AND ISNULL([Factura], 0) = 0
         AND ([FacturaTipo] IS NULL OR LTRIM(RTRIM(ISNULL([FacturaTipo], ''))) = '')"
    );
    $stmt->execute(['codigo' => $codigo]);
    return (float) ($stmt->fetchColumn() ?: 0);
  }

  private function facturasDiferidasPendientes(string $codigo): float
  {
    $stmt = $this->pdo->prepare(
      "SELECT SUM(ISNULL([Importe], 0) - ISNULL([ImporteLiquidado], 0)) AS ImportePendiente
       FROM [Facturas]
       WHERE [Cliente] = :codigo
         AND [FacturaTipo] IN ('F', 'A')
         AND ISNULL([Importe], 0) <> ISNULL([ImporteLiquidado], 0)
         AND (ISNULL([FacturaContadoDiferida], 0) <> 0 OR [Estado] = 'G')
         AND ISNULL([TrasCtb], 0) = 0
         AND NOT EXISTS (
           SELECT 1 FROM [Recibos] r
           WHERE r.[Empresa] = [Facturas].[Empresa]
             AND r.[FacturaTipo] = [Facturas].[FacturaTipo]
             AND r.[Factura] = [Facturas].[Factura]
         )"
    );
    $stmt->execute(['codigo' => $codigo]);
    return (float) ($stmt->fetchColumn() ?: 0);
  }

  private function recibosPendientes(string $codigo): float
  {
    $stmt = $this->pdo->prepare(
      "SELECT SUM(ISNULL(r.[Importe], 0)) AS ImportePendiente
       FROM [Facturas] f
       INNER JOIN [Recibos] r
         ON f.[Empresa] = r.[Empresa]
        AND f.[FacturaTipo] = r.[FacturaTipo]
        AND f.[Factura] = r.[Factura]
       WHERE f.[Cliente] = :codigo
         AND ISNULL(r.[Liquidado], 0) = 0
         AND ISNULL(r.[Remesado], 0) = 0"
    );
    $stmt->execute(['codigo' => $codigo]);
    return (float) ($stmt->fetchColumn() ?: 0);
  }

  /**
   * Pendiente de servir de pedidos no actualizados (aprox. ImportePendientePedido
   * con precio ya con IVA: remaining * Precio * (1 - dto linea)).
   */
  private function pedidosPendientes(string $codigo): float
  {
    try {
      $stmt = $this->pdo->prepare(
        "SELECT SUM(
           CASE
             WHEN ISNULL(l.[CantidadPedida], 0) - ISNULL(l.[CantidadServida], 0) < 0 THEN 0
             ELSE ISNULL(l.[CantidadPedida], 0) - ISNULL(l.[CantidadServida], 0)
           END
           * ISNULL(l.[Precio], 0)
           * (1 - ISNULL(l.[PjeDto], 0) / 100.0)
         )
         FROM [PedidosClientes] p
         INNER JOIN [PedidosClientesLin] l
           ON p.[Empresa] = l.[Empresa] AND p.[Pedido] = l.[Pedido]
         WHERE p.[Cliente] = :codigo
           AND ISNULL(p.[Actualizado], 0) = 0
           AND LTRIM(RTRIM(ISNULL(l.[Articulo], ''))) <> 'NO'"
      );
      $stmt->execute(['codigo' => $codigo]);
      return (float) ($stmt->fetchColumn() ?: 0);
    } catch (\Throwable $e) {
      return 0.0;
    }
  }
}
