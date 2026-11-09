<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Ventas;

use InvalidArgumentException;
use RuntimeException;

/** Logo que se imprime al principio de la etiqueta de un encargo. Uno por tienda. */
final class TiendaLogoEncargoService
{
  private const MIMES = [
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'webp' => 'image/webp',
    'gif' => 'image/gif',
  ];

  public static function directorio(): string
  {
    return dirname(__DIR__, 3) . '/var/logos-encargos';
  }

  /** @return array{configurado: bool, archivo: string, actualizado: string} */
  public function info(string $codigo): array
  {
    $codigo = $this->codigo($codigo);
    $meta = $this->leerMeta($codigo);
    $ruta = $this->rutaImagen($codigo);
    return [
      'configurado' => $ruta !== null,
      'archivo' => $ruta !== null ? (string) ($meta['archivo'] ?? basename($ruta)) : '',
      'actualizado' => $ruta !== null ? (string) ($meta['actualizado'] ?? '') : '',
    ];
  }

  /** @return array{cuerpo: string, mime: string}|null */
  public function imagen(string $codigo): ?array
  {
    $codigo = $this->codigo($codigo);
    $ruta = $this->rutaImagen($codigo);
    if ($ruta === null) {
      return null;
    }
    $cuerpo = file_get_contents($ruta);
    if (!is_string($cuerpo) || $cuerpo === '') {
      return null;
    }
    $ext = strtolower(pathinfo($ruta, PATHINFO_EXTENSION));
    return [
      'cuerpo' => $cuerpo,
      'mime' => self::MIMES[$ext] ?? 'application/octet-stream',
    ];
  }

  public function guardar(string $codigo, string $contenido, string $nombreOriginal): array
  {
    $codigo = $this->codigo($codigo);
    if ($contenido === '') {
      throw new InvalidArgumentException('No se recibió el archivo del logo');
    }
    if (strlen($contenido) > 2_000_000) {
      throw new InvalidArgumentException('El logo no puede superar 2 MB');
    }
    $ext = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
    if (!isset(self::MIMES[$ext])) {
      throw new InvalidArgumentException('El logo debe ser PNG, JPG, WEBP o GIF');
    }
    $dir = self::directorio();
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
      throw new RuntimeException('No se pudo crear la carpeta de logos');
    }
    $this->borrarArchivos($codigo);
    $destino = $dir . DIRECTORY_SEPARATOR . $codigo . '.' . $ext;
    if (file_put_contents($destino, $contenido) === false) {
      throw new RuntimeException('No se pudo guardar el logo');
    }
    $meta = [
      'archivo' => $this->nombreVisible($nombreOriginal),
      'actualizado' => date('c'),
    ];
    file_put_contents(
      $dir . DIRECTORY_SEPARATOR . $codigo . '.json',
      json_encode($meta, JSON_UNESCAPED_UNICODE)
    );
    return [
      'configurado' => true,
      'archivo' => $meta['archivo'],
      'actualizado' => $meta['actualizado'],
    ];
  }

  public function borrar(string $codigo): void
  {
    $this->borrarArchivos($this->codigo($codigo));
  }

  private function codigo(string $codigo): string
  {
    $codigo = trim($codigo);
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,19}$/', $codigo)) {
      throw new InvalidArgumentException('Código de tienda no válido');
    }
    return $codigo;
  }

  private function rutaImagen(string $codigo): ?string
  {
    foreach (array_keys(self::MIMES) as $ext) {
      $ruta = self::directorio() . DIRECTORY_SEPARATOR . $codigo . '.' . $ext;
      if (is_file($ruta)) {
        return $ruta;
      }
    }
    return null;
  }

  /** @return array{archivo?: string, actualizado?: string} */
  private function leerMeta(string $codigo): array
  {
    $ruta = self::directorio() . DIRECTORY_SEPARATOR . $codigo . '.json';
    if (!is_file($ruta)) {
      return [];
    }
    $data = json_decode((string) file_get_contents($ruta), true);
    return is_array($data) ? $data : [];
  }

  private function borrarArchivos(string $codigo): void
  {
    $dir = self::directorio();
    foreach (array_merge(array_keys(self::MIMES), ['json']) as $ext) {
      $ruta = $dir . DIRECTORY_SEPARATOR . $codigo . '.' . $ext;
      if (is_file($ruta)) {
        unlink($ruta);
      }
    }
  }

  private function nombreVisible(string $nombre): string
  {
    $base = basename(str_replace('\\', '/', $nombre));
    $base = preg_replace('/[^\w.\- ]+/u', '', $base) ?? '';
    $base = trim($base);
    return $base !== '' ? $base : 'logo.png';
  }
}
