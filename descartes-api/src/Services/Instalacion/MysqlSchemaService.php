<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Instalacion;

use PDO;
use RuntimeException;

/**
 * Aplica el esquema MySQL vacío (estructura, sin datos) si aún no existe.
 */
final class MysqlSchemaService
{
  public function asegurar(PDO $pdo): void
  {
    if ($this->tablaExiste($pdo, 'Usuarios_Ges')) {
      return;
    }
    $this->aplicarArchivo($pdo);
    if (!$this->tablaExiste($pdo, 'Usuarios_Ges')) {
      throw new RuntimeException('El esquema MySQL no creó la tabla Usuarios_Ges');
    }
  }

  public function ruta(): string
  {
    $path = dirname(__DIR__, 3) . '/database/schema/mysql/001-esquema.sql';
    if (!is_file($path)) {
      throw new RuntimeException('No se encontró database/schema/mysql/001-esquema.sql');
    }
    return $path;
  }

  private function aplicarArchivo(PDO $pdo): void
  {
    $sql = file_get_contents($this->ruta());
    if ($sql === false || trim($sql) === '') {
      throw new RuntimeException('El esquema MySQL está vacío');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($this->sentencias($sql) as $sentencia) {
      try {
        $pdo->exec($sentencia);
      } catch (\Throwable $e) {
        $nombre = $this->nombreTabla($sentencia);
        throw new RuntimeException(
          ($nombre !== '' ? $nombre . ': ' : '') . $e->getMessage(),
          0,
          $e
        );
      }
    }
  }

  /** @return list<string> */
  private function sentencias(string $sql): array
  {
    $sinComentarios = preg_replace('/^--.*$/m', '', $sql) ?? $sql;
    $partes = preg_split('/;\s*(?:\r?\n|$)/', $sinComentarios) ?: [];
    $out = [];
    foreach ($partes as $parte) {
      $parte = trim($parte);
      if ($parte !== '') {
        $out[] = $parte;
      }
    }
    return $out;
  }

  private function nombreTabla(string $sql): string
  {
    if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`([^`]+)`/i', $sql, $m) === 1) {
      return $m[1];
    }
    return '';
  }

  private function tablaExiste(PDO $pdo, string $tabla): bool
  {
    $stmt = $pdo->prepare(
      'SELECT 1 FROM information_schema.TABLES
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tabla'
    );
    $stmt->execute(['tabla' => $tabla]);
    return (bool) $stmt->fetchColumn();
  }
}
