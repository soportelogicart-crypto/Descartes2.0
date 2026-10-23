<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Ventas;

/** Clasificación legacy `Vales.Tipo` (char 1). */
final class ValesTipoLegacy
{
  public const ENTRADA_EFECTIVO = 'I';
  public const VALE_REGALO = 'R';
  public const PAGOS = 'P';
  public const SALIDA_EFECTIVO = 'S';
  public const VALE_COMPRA = 'V';
  public const FIDELIZACION = 'M';

  /** Tipos canjeables en TPV/venta por número (no fidelización automática). */
  public const CANJE_COBRO = [self::VALE_REGALO, self::VALE_COMPRA];

  /** Pantalla Liquidación de vales (excluye movimientos de caja I/S/P por defecto). */
  public const LIQUIDACION_PANTALLA = [self::VALE_REGALO, self::VALE_COMPRA, self::FIDELIZACION];

  public const ETIQUETAS = [
    self::ENTRADA_EFECTIVO => 'Entrada efectivo',
    self::VALE_REGALO => 'Vale regalo',
    self::PAGOS => 'Pagos',
    self::SALIDA_EFECTIVO => 'Salida efectivo',
    self::VALE_COMPRA => 'Vale de compra',
    self::FIDELIZACION => 'Vale fidelización',
  ];

  public static function etiqueta(?string $tipo): string
  {
    $t = strtoupper(trim((string) $tipo));
    if ($t === '') {
      return '—';
    }

    return self::ETIQUETAS[$t] ?? $t;
  }

  public static function desdeTipoVale(string $tipoVale): string
  {
    $tv = strtoupper(trim($tipoVale));
    return match ($tv) {
      'FIDELIZACION' => self::FIDELIZACION,
      'DEVOLUCION' => self::VALE_COMPRA,
      default => self::VALE_REGALO,
    };
  }

  public static function tipoValeDesdeLegacy(?string $tipo): string
  {
    $t = strtoupper(trim((string) $tipo));
    return match ($t) {
      self::FIDELIZACION => 'FIDELIZACION',
      self::VALE_COMPRA => 'DEVOLUCION',
      self::VALE_REGALO => 'REGALO',
      default => 'REGALO',
    };
  }

  /** SQL fragment: vale pendiente canjeable por código (R/V o datos antiguos sin Tipo). */
  public static function sqlCanjePorCodigo(): string
  {
    $r = self::VALE_REGALO;
    $v = self::VALE_COMPRA;

    return "(RTRIM(ISNULL(Tipo, '')) IN ('{$r}', '{$v}')
      OR (RTRIM(ISNULL(Tipo, '')) = '' AND RTRIM(ISNULL(TipoVale, 'REGALO')) IN ('REGALO', 'DEVOLUCION')))";
  }

  /** SQL fragment for list filter alcance=liquidacion. */
  public static function sqlAlcanceLiquidacion(string $alias = 'v'): string
  {
    $r = self::VALE_REGALO;
    $v = self::VALE_COMPRA;
    $m = self::FIDELIZACION;

    return "(
      RTRIM(ISNULL({$alias}.Tipo, '')) IN ('{$r}', '{$v}', '{$m}')
      OR (
        RTRIM(ISNULL({$alias}.Tipo, '')) = ''
        AND RTRIM(ISNULL({$alias}.TipoVale, 'REGALO')) IN ('REGALO', 'DEVOLUCION', 'FIDELIZACION')
      )
    )";
  }

  public static function sqlAlcanceCaja(string $alias = 'v'): string
  {
    $i = self::ENTRADA_EFECTIVO;
    $s = self::SALIDA_EFECTIVO;
    $p = self::PAGOS;

    return "RTRIM(ISNULL({$alias}.Tipo, '')) IN ('{$i}', '{$s}', '{$p}')";
  }
}
