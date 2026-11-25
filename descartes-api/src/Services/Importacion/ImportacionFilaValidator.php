<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Importacion;

/**
 * Validacion de una fila importada (sin base de datos): obligatorias, longitudes y tipos.
 * Las reglas de negocio y referencias las comprueba ImportacionService.
 */
final class ImportacionFilaValidator
{
  /**
   * @return array<string, mixed>
   */
  public static function config(): array
  {
    /** @var array<string, mixed> $cfg */
    $cfg = require __DIR__ . '/../../Config/importacion.php';
    return $cfg;
  }

  /**
   * @return array<string, mixed>|null
   */
  public static function hoja(string $entidad): ?array
  {
    $cfg = self::config();
    return $cfg['hojas'][$entidad] ?? null;
  }

  /**
   * Quita tildes y signos para comparar cabeceras ("Razón social *" = "razon social").
   */
  public static function normalizarCabecera(string $texto): string
  {
    $t = trim($texto);
    $t = rtrim($t, " *\t");
    $mapa = [
      'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
      'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u', 'Ñ' => 'n',
    ];
    $t = strtr($t, $mapa);
    $t = mb_strtolower($t, 'UTF-8');
    return (string) preg_replace('/\s+/', ' ', $t);
  }

  /**
   * Normaliza un valor para comparar codigos: sin espacios, sin ceros a la izquierda si es numerico,
   * sin distinguir mayusculas.
   */
  public static function normalizarCodigo(string $codigo): string
  {
    $c = trim($codigo);
    if ($c !== '' && ctype_digit($c)) {
      $sinCeros = ltrim($c, '0');
      return $sinCeros === '' ? '0' : $sinCeros;
    }
    return mb_strtolower($c, 'UTF-8');
  }

  /**
   * @param array<string, mixed> $hoja            definicion de la hoja (Config/importacion.php)
   * @param array<string, mixed> $datos           valores por clave de columna
   * @param array<string, array{tipo?: string, min?: int, max?: int}> $limitesBd limites reales de la base de datos
   * @return array{datos: array<string, mixed>, errores: list<array{campo: string, mensaje: string}>}
   */
  public static function validar(array $hoja, array $datos, array $limitesBd = []): array
  {
    $out = [];
    $errores = [];

    foreach ($hoja['columnas'] as $col) {
      $clave = (string) $col['clave'];
      $cabecera = (string) $col['cabecera'];
      $valor = self::textoCelda($datos[$clave] ?? null);

      if ($valor === '') {
        if (!empty($col['obligatoria'])) {
          $errores[] = ['campo' => $clave, 'mensaje' => "{$cabecera} es obligatorio"];
        }
        continue;
      }

      $tipo = (string) ($col['tipo'] ?? 'texto');
      $limite = $limitesBd[$clave] ?? [];

      if ($tipo === 'booleano') {
        $si = self::booleanoDeTexto($valor);
        if ($si === null) {
          $errores[] = ['campo' => $clave, 'mensaje' => "{$cabecera} debe ser Si o No (valor: {$valor})"];
          continue;
        }
        $out[$clave] = $si;
        continue;
      }

      if ($tipo === 'decimal') {
        $numero = self::numeroDecimal($valor);
        if ($numero === null) {
          $errores[] = ['campo' => $clave, 'mensaje' => "{$cabecera} debe ser un numero"];
          continue;
        }
        if ($numero < 0) {
          $errores[] = ['campo' => $clave, 'mensaje' => "{$cabecera} no puede ser negativo"];
          continue;
        }
        $out[$clave] = $numero;
        continue;
      }

      if ($tipo === 'entero' || ($limite['tipo'] ?? '') === 'int') {
        if (!preg_match('/^-?\d+$/', $valor)) {
          $errores[] = ['campo' => $clave, 'mensaje' => "{$cabecera} debe ser un numero entero (valor: {$valor})"];
          continue;
        }
        $n = (int) $valor;
        if (isset($limite['max']) && ($limite['tipo'] ?? '') === 'int' && $n > $limite['max']) {
          $errores[] = ['campo' => $clave, 'mensaje' => "{$cabecera} no puede ser mayor que {$limite['max']}"];
          continue;
        }
        if (isset($limite['min']) && ($limite['tipo'] ?? '') === 'int' && $n < $limite['min']) {
          $errores[] = ['campo' => $clave, 'mensaje' => "{$cabecera} no puede ser menor que {$limite['min']}"];
          continue;
        }
        // Se conserva el texto (ceros a la izquierda); la base lo convierte si la columna es numerica.
        $out[$clave] = ($tipo === 'entero') ? $n : $valor;
        continue;
      }

      $max = isset($col['max']) ? (int) $col['max'] : null;
      if (isset($limite['max']) && ($limite['tipo'] ?? 'texto') !== 'int') {
        $max = $max === null ? (int) $limite['max'] : min($max, (int) $limite['max']);
      }
      if ($max !== null && $max > 0 && mb_strlen($valor, 'UTF-8') > $max) {
        $errores[] = [
          'campo' => $clave,
          'mensaje' => "{$cabecera} admite como maximo {$max} caracteres (tiene " . mb_strlen($valor, 'UTF-8') . ')',
        ];
        continue;
      }

      $out[$clave] = $valor;
    }

    return ['datos' => $out, 'errores' => $errores];
  }

  /**
   * Si/No de una celda: Si, S, X, 1, true, verdadero (si) / No, N, 0, false, falso (no).
   * Devuelve null si el texto no se entiende.
   */
  public static function booleanoDeTexto(string $texto): ?bool
  {
    $t = self::normalizarCabecera($texto);
    if (in_array($t, ['si', 's', 'x', '1', 'true', 'verdadero', 'yes', 'y'], true)) {
      return true;
    }
    if (in_array($t, ['no', 'n', '0', 'false', 'falso'], true)) {
      return false;
    }
    return null;
  }

  /** Texto limpio de una celda (numeros enteros de Excel sin ".0"). */
  public static function textoCelda(mixed $valor): string
  {
    if ($valor === null) {
      return '';
    }
    if (is_bool($valor)) {
      return $valor ? '1' : '0';
    }
    if (is_int($valor)) {
      return (string) $valor;
    }
    if (is_float($valor)) {
      if (is_finite($valor) && floor($valor) === $valor && abs($valor) < 1e15) {
        return (string) (int) $valor;
      }
      return rtrim(rtrim(sprintf('%.10F', $valor), '0'), '.');
    }
    if (is_scalar($valor)) {
      return trim((string) $valor);
    }
    return '';
  }

  /** Acepta 12,5 / 12.5 / 1.234,56 / 1,234.56. */
  public static function numeroDecimal(string $texto): ?float
  {
    $t = str_replace([' ', "\u{00A0}"], '', trim($texto));
    if ($t === '') {
      return null;
    }
    $coma = strrpos($t, ',');
    $punto = strrpos($t, '.');
    if ($coma !== false && $punto !== false) {
      if ($coma > $punto) {
        $t = str_replace('.', '', $t);
        $t = str_replace(',', '.', $t);
      } else {
        $t = str_replace(',', '', $t);
      }
    } elseif ($coma !== false) {
      $t = str_replace(',', '.', $t);
    }
    if (!preg_match('/^-?\d+(\.\d+)?$/', $t)) {
      return null;
    }
    return (float) $t;
  }
}
