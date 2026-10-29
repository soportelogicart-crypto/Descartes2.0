<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Offline;

use PDO;

/**
 * Ping del modo sin conexión: hora del servidor (para detectar relojes
 * desviados) y versión del catálogo (para refrescar la foto en cuanto cambian
 * precios, clientes o descuentos).
 */
final class OfflineEstadoService
{
  /** Sube si cambia el formato de la foto: el puesto la descarga entera. */
  public const VERSION_DATOS = 1;

  private const CACHE_SEGUNDOS = 60;

  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  /** @return array<string, mixed> */
  public function estado(): array
  {
    return [
      'ok' => true,
      'hora' => date('c'),
      'versionDatos' => self::VERSION_DATOS,
      'versionCatalogo' => $this->versionCatalogo(),
    ];
  }

  private function versionCatalogo(): string
  {
    $archivo = self::dirVar() . '/offline-version.json';
    if (is_file($archivo) && (time() - (int) filemtime($archivo)) < self::CACHE_SEGUNDOS) {
      $guardado = json_decode((string) file_get_contents($archivo), true);
      if (is_array($guardado) && isset($guardado['version'])) {
        return (string) $guardado['version'];
      }
    }

    $partes = [];
    try {
      $partes[] = json_encode($this->pdo->query(
        'SELECT COUNT(*) AS N, SUM(ISNULL(PrecioVen1, 0)) AS P1, SUM(ISNULL(PrecioVen2, 0)) AS P2,
                SUM(ISNULL(PrecioVen3, 0)) AS P3
         FROM Articulos'
      )->fetch(PDO::FETCH_ASSOC));
      $partes[] = json_encode($this->pdo->query(
        'SELECT COUNT(*) AS N, SUM(ISNULL(Dto1, 0)) AS D, SUM(ISNULL(Tarifa, 0)) AS T FROM Clientes'
      )->fetch(PDO::FETCH_ASSOC));
      $partes[] = json_encode($this->pdo->query(
        'SELECT COUNT(*) AS N, MAX(LUpdate) AS U, SUM(ISNULL(Descuento, 0)) AS D FROM TiposDescuentos'
      )->fetch(PDO::FETCH_ASSOC));
    } catch (\Throwable $e) {
      return 'desconocida';
    }
    $version = substr(md5(implode('|', $partes)), 0, 16);
    @file_put_contents($archivo, json_encode(['version' => $version]));

    return $version;
  }

  /**
   * Clave HMAC del puesto. Sale de un secreto del servidor (var/offline.key):
   * una venta pendiente modificada a mano en el PC no se acepta.
   */
  public static function clavePuesto(string $empresa, string $puesto): string
  {
    return hash_hmac('sha256', strtoupper(trim($empresa)) . '|' . strtoupper(trim($puesto)), self::secreto());
  }

  private static function secreto(): string
  {
    $archivo = self::dirVar() . '/offline.key';
    if (is_file($archivo)) {
      $s = trim((string) file_get_contents($archivo));
      if (strlen($s) >= 64) {
        return $s;
      }
    }
    // 'x': si dos peticiones crean el secreto a la vez, gana la primera y la otra lo lee.
    $fh = @fopen($archivo, 'x');
    if ($fh === false) {
      clearstatcache(true, $archivo);
      $s = is_file($archivo) ? trim((string) file_get_contents($archivo)) : '';
      if (strlen($s) >= 64) {
        return $s;
      }
      throw new \RuntimeException('No se puede guardar var/offline.key: revise permisos de la carpeta var');
    }
    $s = bin2hex(random_bytes(32));
    fwrite($fh, $s);
    fclose($fh);

    return $s;
  }

  private static function dirVar(): string
  {
    $dir = dirname(__DIR__, 3) . '/var';
    if (!is_dir($dir)) {
      @mkdir($dir, 0775, true);
    }

    return $dir;
  }
}
