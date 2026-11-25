<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Ventas;

/**
 * Reparte los pagos a cuenta de un pedido entre los albaranes que se generan,
 * en orden y sin pasar del importe de cada albarán. Lo que sobra sigue en el pedido.
 */
final class RepartoPagosPedido
{
  /**
   * @param list<array{nro: int, importe: float}> $pagos
   * @param list<array{importe: float}> $albaranes
   * @return array{
   *   asignaciones: list<array{indice: int, nro: int, importe: float, completo: bool}>,
   *   resto: list<array{nro: int, importe: float}>
   * }
   */
  public static function repartir(array $pagos, array $albaranes): array
  {
    $cola = [];
    foreach ($pagos as $pago) {
      $importe = round((float) ($pago['importe'] ?? 0), 2);
      $nro = (int) ($pago['nro'] ?? 0);
      if ($nro <= 0 || $importe <= 0.004) {
        continue;
      }
      $cola[] = ['nro' => $nro, 'importe' => $importe];
    }

    $asignaciones = [];
    foreach ($albaranes as $indice => $albaran) {
      $falta = round((float) ($albaran['importe'] ?? 0), 2);
      if ($falta <= 0.004) {
        continue;
      }
      while ($falta > 0.004 && $cola !== []) {
        $tomar = round(min($falta, $cola[0]['importe']), 2);
        if ($tomar <= 0.004) {
          array_shift($cola);
          continue;
        }
        $completo = abs($tomar - $cola[0]['importe']) < 0.005;
        $asignaciones[] = [
          'indice' => (int) $indice,
          'nro' => $cola[0]['nro'],
          'importe' => $tomar,
          'completo' => $completo,
        ];
        $cola[0]['importe'] = round($cola[0]['importe'] - $tomar, 2);
        $falta = round($falta - $tomar, 2);
        if ($cola[0]['importe'] <= 0.004) {
          array_shift($cola);
        }
      }
    }

    $resto = [];
    foreach ($cola as $pago) {
      if ($pago['importe'] <= 0.004) {
        continue;
      }
      $resto[] = ['nro' => $pago['nro'], 'importe' => round($pago['importe'], 2)];
    }

    return ['asignaciones' => $asignaciones, 'resto' => $resto];
  }
}
