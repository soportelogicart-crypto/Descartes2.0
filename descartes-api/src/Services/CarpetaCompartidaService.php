<?php

declare(strict_types=1);

namespace Descartes\Api\Services;

use Descartes\Api\Services\Instalacion\MejorasGestionSchema;
use PDO;

/**
 * Ruta UNC de la carpeta compartida de la instalación (`\\EQUIPO\Carpeta`).
 * Es una sola para todos los PCs del cliente. La carpeta la publica Windows
 * en el PC anfitrión; aquí solo se guarda la ruta.
 */
final class CarpetaCompartidaService
{
  private const CLAVE = 'carpeta_compartida';
  private const LONGITUD_MAX = 500;

  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  public function obtener(): array
  {
    return ['ruta' => $this->leer()];
  }

  public function guardar(array $data): array
  {
    $ruta = $this->normalizarRuta((string) ($data['ruta'] ?? ''));
    $this->asegurarEsquema();

    $existe = $this->pdo->prepare('SELECT 1 FROM [ConfigInstalacion] WHERE [Clave] = :clave');
    $existe->execute(['clave' => self::CLAVE]);
    $ahora = $this->esMysql() ? 'NOW()' : 'GETDATE()';

    if ($existe->fetchColumn()) {
      $stmt = $this->pdo->prepare(
        "UPDATE [ConfigInstalacion] SET [Valor] = :valor, [Actualizado] = {$ahora} WHERE [Clave] = :clave"
      );
    } else {
      $stmt = $this->pdo->prepare(
        "INSERT INTO [ConfigInstalacion] ([Clave], [Valor], [Actualizado]) VALUES (:clave, :valor, {$ahora})"
      );
    }
    $stmt->execute(['clave' => self::CLAVE, 'valor' => $ruta]);

    return ['ruta' => $ruta];
  }

  private function leer(): string
  {
    $this->asegurarEsquema();
    $stmt = $this->pdo->prepare('SELECT [Valor] FROM [ConfigInstalacion] WHERE [Clave] = :clave');
    $stmt->execute(['clave' => self::CLAVE]);
    $valor = $stmt->fetchColumn();

    return $valor === false || $valor === null ? '' : trim((string) $valor);
  }

  /**
   * Vacío = quitar la carpeta compartida. Si no, solo UNC: \\equipo\recurso[\subcarpeta].
   * No se admiten letras de unidad (`Z:\`): solo existen en un PC.
   */
  private function normalizarRuta(string $raw): string
  {
    $ruta = rtrim(trim($raw), '\\');
    if ($ruta === '') {
      return '';
    }
    if (mb_strlen($ruta) > self::LONGITUD_MAX) {
      throw new \InvalidArgumentException('La ruta es demasiado larga');
    }
    if (str_contains($ruta, '..')) {
      throw new \InvalidArgumentException('La ruta no puede contener ".."');
    }
    $patron = '/^\\\\\\\\[\p{L}\p{N}._ -]{1,63}\\\\[^\\\\\/:*?"<>|\x00-\x1f]{1,120}(\\\\[^\\\\\/:*?"<>|\x00-\x1f]{1,120})*$/u';
    if (!preg_match($patron, $ruta)) {
      throw new \InvalidArgumentException(
        'Indique una ruta de red con el formato \\\\EQUIPO\\Carpeta (no una letra de unidad)'
      );
    }

    return $ruta;
  }

  private function asegurarEsquema(): void
  {
    try {
      MejorasGestionSchema::asegurar($this->pdo);
    } catch (\Throwable $e) {
      throw new \RuntimeException('No se pudo preparar la tabla de configuración de la instalación', 500);
    }
  }

  private function esMysql(): bool
  {
    return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
  }
}
