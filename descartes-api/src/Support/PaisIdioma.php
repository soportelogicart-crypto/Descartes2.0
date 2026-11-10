<?php

declare(strict_types=1);

namespace Descartes\Api\Support;

/**
 * País por código postal cuando el formato no es ambiguo, y el idioma que propone.
 * 0 español, 1 francés, 2 inglés. El usuario puede cambiarlo en el documento.
 */
final class PaisIdioma
{
  /** @return array{pais: string, idioma: int}|null */
  public static function desdeCodigoPostal(string $cp): ?array
  {
    $cp = strtoupper(trim($cp));
    if ($cp === '') {
      return null;
    }
    if (preg_match('/^\d{4}-\d{3}$/', $cp)) {
      return ['pais' => 'Portugal', 'idioma' => 0];
    }
    if (preg_match('/^\d{4}\s?[A-Z]{2}$/', $cp)) {
      return ['pais' => 'Países Bajos', 'idioma' => 2];
    }
    if (preg_match('/^[A-Z]{1,2}\d[A-Z\d]?\s*\d[A-Z]{2}$/', $cp)) {
      return ['pais' => 'Reino Unido', 'idioma' => 2];
    }
    if (preg_match('/^[AC-Y]\d{2}\s?[A-Z0-9]{4}$/', $cp)) {
      return ['pais' => 'Irlanda', 'idioma' => 2];
    }

    return null;
  }

  public static function idiomaDePais(string $pais): int
  {
    $p = self::normalizar($pais);
    if (in_array($p, ['FR', 'FRANCIA', 'FRANCE', 'BE', 'BELGICA', 'BELGIQUE', 'LU', 'LUXEMBURGO', 'LUXEMBOURG'], true)) {
      return 1;
    }
    if (in_array($p, [
      'GB', 'UK', 'REINO UNIDO', 'UNITED KINGDOM', 'IE', 'IRLANDA', 'IRELAND',
      'US', 'USA', 'ESTADOS UNIDOS', 'UNITED STATES',
      'NL', 'PAISES BAJOS', 'NETHERLANDS', 'HOLANDA',
    ], true)) {
      return 2;
    }

    return 0;
  }

  private static function normalizar(string $pais): string
  {
    $p = strtoupper(trim($pais));
    $p = strtr($p, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U']);

    return $p;
  }
}
