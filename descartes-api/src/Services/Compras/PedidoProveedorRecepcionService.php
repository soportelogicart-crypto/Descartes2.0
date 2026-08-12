<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Compras;

use PDO;

/**
 * Recepción parcial/total de pedido a proveedor → albarán de compra (004 US4 / T031).
 */
final class PedidoProveedorRecepcionService
{
  private const EPS = 0.0000001;

  private PDO $pdo;
  private PedidoProveedorConsultaService $pedidos;
  private PedidoProveedorEscrituraService $pedidosEscritura;
  private AlbaranCompraEscrituraService $albaranes;

  public function __construct(
    PDO $pdo,
    PedidoProveedorConsultaService $pedidos,
    PedidoProveedorEscrituraService $pedidosEscritura,
    AlbaranCompraEscrituraService $albaranes
  ) {
    $this->pdo = $pdo;
    $this->pedidos = $pedidos;
    $this->pedidosEscritura = $pedidosEscritura;
    $this->albaranes = $albaranes;
  }

  /**
   * @param array<string, mixed> $body
   * @return array{albaran: array<string, mixed>, pedido: array<string, mixed>}
   */
  public function recibir(string $empresa, int $pedido, array $body): array
  {
    $empresa = trim($empresa);
    $pedidoActual = $this->pedidos->obtener($empresa, $pedido);
    if ($pedidoActual === null) {
      throw new \RuntimeException('Pedido a proveedor no encontrado', 404);
    }
    if (($pedidoActual['situacionLabel'] ?? '') === 'servido') {
      throw new \RuntimeException(
        'No se puede recibir: el pedido ya está completamente servido.',
        409
      );
    }

    $lineasBody = $body['lineas'] ?? [];
    if (!is_array($lineasBody) || $lineasBody === []) {
      throw new \InvalidArgumentException('Indique al menos una línea con cantidad a recibir');
    }

    /** @var array<int, array<string, mixed>> $porNumLin */
    $porNumLin = [];
    foreach ($pedidoActual['lineas'] ?? [] as $lin) {
      if (!is_array($lin)) {
        continue;
      }
      $n = (int) ($lin['numLin'] ?? 0);
      if ($n > 0) {
        $porNumLin[$n] = $lin;
      }
    }

    $albaranLineas = [];
    /** @var list<array{numLin: int, cantidad: float, articulo: string}> $incrementos */
    $incrementos = [];

    foreach ($lineasBody as $item) {
      if (!is_array($item)) {
        continue;
      }
      $numLin = (int) ($item['numLin'] ?? 0);
      $cantidad = (float) ($item['cantidad'] ?? 0);
      if ($numLin <= 0) {
        throw new \InvalidArgumentException('Cada línea de recepción requiere numLin válido');
      }
      if ($cantidad <= self::EPS) {
        continue;
      }
      if (!isset($porNumLin[$numLin])) {
        throw new \InvalidArgumentException("Línea de pedido no encontrada: numLin {$numLin}");
      }

      $origen = $porNumLin[$numLin];
      $ped = (float) ($origen['cantidadPed'] ?? 0);
      $ser = (float) ($origen['cantidadSer'] ?? 0);
      $pendiente = $ped - $ser;
      if ($pendiente <= self::EPS) {
        throw new \InvalidArgumentException(
          "La línea {$numLin} ({$origen['articulo']}) no tiene cantidad pendiente"
        );
      }
      if ($cantidad > $pendiente + self::EPS) {
        throw new \InvalidArgumentException(
          "Cantidad a recibir ({$cantidad}) supera el pendiente ({$pendiente}) en línea {$numLin}"
        );
      }

      $articulo = trim((string) ($origen['articulo'] ?? ''));
      $precio = (float) ($origen['precioPed'] ?? 0);
      $almacenLin = isset($origen['almacen']) && (int) $origen['almacen'] > 0
        ? (int) $origen['almacen']
        : null;

      $albaranLineas[] = [
        'articulo' => $articulo,
        'descripcion' => $origen['descripcion'] ?? null,
        'cantidad' => $cantidad,
        'precio' => $precio,
        'pjeDto' => (float) ($origen['pjeDto'] ?? 0),
        'dto1' => (float) ($origen['dto1'] ?? 0),
        'dto2' => (float) ($origen['dto2'] ?? 0),
        'dto3' => (float) ($origen['dto3'] ?? 0),
        'pedido' => $pedido,
        'almacen' => $almacenLin,
      ];
      $incrementos[] = [
        'numLin' => $numLin,
        'cantidad' => $cantidad,
        'articulo' => $articulo,
        'precio' => $precio,
      ];
    }

    if ($albaranLineas === []) {
      throw new \InvalidArgumentException('Indique cantidades > 0 a recibir');
    }

    $almacenCab = isset($body['almacen']) && $body['almacen'] !== '' && $body['almacen'] !== null
      ? (int) $body['almacen']
      : (int) ($pedidoActual['almacen'] ?? 0);
    if ($almacenCab <= 0) {
      $almacenCab = null;
    }

    $obs = 'Recepción pedido ' . $pedido;
    if (!empty($body['observaciones'])) {
      $obs = trim((string) $body['observaciones']);
    }

    $this->pdo->beginTransaction();
    try {
      $albaran = $this->albaranes->crearEnTransaccion([
        'empresa' => $empresa,
        'proveedor' => $pedidoActual['proveedor'] ?? null,
        'fechaAlbaran' => $body['fechaAlbaran'] ?? date('Y-m-d'),
        'suAlbaran' => $body['suAlbaran'] ?? null,
        'almacen' => $almacenCab,
        'observaciones' => $obs,
        'vendedor' => $pedidoActual['vendedor'] ?? null,
        'lineas' => $albaranLineas,
      ]);

      $upd = $this->pdo->prepare(
        'UPDATE PedidosLin SET
           CantidadSer = ISNULL(CantidadSer, 0) + :q,
           PrecioRec = CASE
             WHEN ISNULL(PrecioRec, 0) = 0 THEN :precio
             ELSE PrecioRec
           END
         WHERE Empresa = :e AND Pedido = :p AND NumLin = :n AND Articulo = :a'
      );
      foreach ($incrementos as $inc) {
        $upd->execute([
          'q' => $inc['cantidad'],
          'precio' => $inc['precio'],
          'e' => $empresa,
          'p' => $pedido,
          'n' => $inc['numLin'],
          'a' => $inc['articulo'],
        ]);
        if ($upd->rowCount() < 1) {
          throw new \RuntimeException(
            'No se pudo actualizar CantidadSer en línea ' . $inc['numLin']
          );
        }
      }

      $this->pedidosEscritura->refrescarSituacion($empresa, $pedido);
      $this->pdo->commit();
    } catch (\Throwable $e) {
      if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      throw $e;
    }

    $pedidoActualizado = $this->pedidos->obtener($empresa, $pedido);
    if ($pedidoActualizado === null) {
      throw new \RuntimeException('Recepción OK pero no se pudo releer el pedido');
    }

    return [
      'albaran' => $albaran,
      'pedido' => $pedidoActualizado,
    ];
  }
}
