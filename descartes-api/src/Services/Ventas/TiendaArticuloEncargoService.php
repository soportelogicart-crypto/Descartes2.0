<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Ventas;

use InvalidArgumentException;
use RuntimeException;

/** Código de artículo opcional. Si existe, el albarán del encargo nace con una línea. */
final class TiendaArticuloEncargoService
{
  public static function directorio(): string
  {
    return dirname(__DIR__, 3) . '/var/articulos-encargos';
  }

  /** @return array{configurado: bool, articulo: string} */
  public function info(string $codigo): array
  {
    $articulo = $this->codigoArticulo($codigo);
    return [
      'configurado' => $articulo !== '',
      'articulo' => $articulo,
    ];
  }

  public function codigoArticulo(string $codigo): string
  {
    $codigo = $this->codigoTienda($codigo);
    $ruta = $this->ruta($codigo);
    if (!is_file($ruta)) {
      return '';
    }
    $data = json_decode((string) file_get_contents($ruta), true);
    if (!is_array($data)) {
      return '';
    }
    return $this->codigoArticuloValido((string) ($data['articulo'] ?? ''));
  }

  /** @return array{configurado: bool, articulo: string} */
  public function guardar(string $codigo, string $articulo): array
  {
    $codigo = $this->codigoTienda($codigo);
    $articulo = trim($articulo);
    if ($articulo === '') {
      $this->borrar($codigo);
      return ['configurado' => false, 'articulo' => ''];
    }
    $articulo = $this->codigoArticuloValido($articulo);
    if ($articulo === '') {
      throw new InvalidArgumentException('El código de artículo no es válido');
    }
    $dir = self::directorio();
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
      throw new RuntimeException('No se pudo guardar el artículo de la tienda');
    }
    $json = json_encode(['articulo' => $articulo], JSON_UNESCAPED_UNICODE);
    if ($json === false || file_put_contents($this->ruta($codigo), $json) === false) {
      throw new RuntimeException('No se pudo guardar el artículo de la tienda');
    }
    return ['configurado' => true, 'articulo' => $articulo];
  }

  public function borrar(string $codigo): void
  {
    $ruta = $this->ruta($this->codigoTienda($codigo));
    if (is_file($ruta)) {
      unlink($ruta);
    }
  }

  private function codigoTienda(string $codigo): string
  {
    $codigo = trim($codigo);
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,19}$/', $codigo)) {
      throw new InvalidArgumentException('Código de tienda no válido');
    }
    return $codigo;
  }

  private function codigoArticuloValido(string $articulo): string
  {
    $articulo = trim($articulo);
    if ($articulo === '' || strlen($articulo) > 18) {
      return '';
    }
    return $articulo;
  }

  private function ruta(string $codigo): string
  {
    return self::directorio() . DIRECTORY_SEPARATOR . $codigo . '.json';
  }
}
