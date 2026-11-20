<?php

declare(strict_types=1);

namespace Descartes\Api\Support;

/**
 * Preferencias de interfaz del usuario de gestion
 * (Usuarios_Ges.Idioma / ColorTema / AyudasMenu).
 * Los ids de tema deben coincidir con descartes-gestion/src/config/temas.ts.
 * AyudasMenu vacio o ausente = ayudas del menu activadas.
 */
final class PreferenciasUsuario
{
  public const IDIOMA_DEFECTO = 'es';
  public const TEMA_DEFECTO = 'azul';

  /** @var list<string> */
  public const IDIOMAS = ['ca', 'es', 'en'];

  /** @var list<string> */
  public const TEMAS = ['azul', 'verde', 'granate', 'violeta', 'ambar', 'grafito'];

  /** '' = sin elegir (se usa el valor por defecto). Devuelve null si no es valido. */
  public static function idiomaValido(mixed $valor): ?string
  {
    $v = strtolower(trim((string) $valor));
    if ($v === '') {
      return '';
    }
    return in_array($v, self::IDIOMAS, true) ? $v : null;
  }

  /** '' = sin elegir (se usa el valor por defecto). Devuelve null si no es valido. */
  public static function temaValido(mixed $valor): ?string
  {
    $v = strtolower(trim((string) $valor));
    if ($v === '') {
      return '';
    }
    return in_array($v, self::TEMAS, true) ? $v : null;
  }
}
