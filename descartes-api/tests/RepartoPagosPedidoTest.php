<?php

declare(strict_types=1);

namespace Descartes\Api\Tests;

use Descartes\Api\Services\Ventas\RepartoPagosPedido;
use PHPUnit\Framework\TestCase;

final class RepartoPagosPedidoTest extends TestCase
{
  public function testUnAlbaranQueCubreTodoSeLlevaLosPagos(): void
  {
    $plan = RepartoPagosPedido::repartir(
      [
        ['nro' => 1, 'importe' => 40],
        ['nro' => 2, 'importe' => 25],
      ],
      [['importe' => 100]]
    );

    $this->assertSame([
      ['indice' => 0, 'nro' => 1, 'importe' => 40.0, 'completo' => true],
      ['indice' => 0, 'nro' => 2, 'importe' => 25.0, 'completo' => true],
    ], $plan['asignaciones']);
    $this->assertSame([], $plan['resto']);
  }

  public function testUnAlbaranParcialParteElPagoYDejaResto(): void
  {
    $plan = RepartoPagosPedido::repartir(
      [['nro' => 1, 'importe' => 100]],
      [['importe' => 40]]
    );

    $this->assertSame([
      ['indice' => 0, 'nro' => 1, 'importe' => 40.0, 'completo' => false],
    ], $plan['asignaciones']);
    $this->assertSame([
      ['nro' => 1, 'importe' => 60.0],
    ], $plan['resto']);
  }

  public function testVariosAlbaranesRepartenEnOrden(): void
  {
    $plan = RepartoPagosPedido::repartir(
      [
        ['nro' => 1, 'importe' => 30],
        ['nro' => 2, 'importe' => 80],
      ],
      [
        ['importe' => 50],
        ['importe' => 40],
      ]
    );

    $this->assertSame([
      ['indice' => 0, 'nro' => 1, 'importe' => 30.0, 'completo' => true],
      ['indice' => 0, 'nro' => 2, 'importe' => 20.0, 'completo' => false],
      ['indice' => 1, 'nro' => 2, 'importe' => 40.0, 'completo' => false],
    ], $plan['asignaciones']);
    $this->assertSame([
      ['nro' => 2, 'importe' => 20.0],
    ], $plan['resto']);
  }
}
