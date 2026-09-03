<?php

declare(strict_types=1);

namespace Descartes\Api\Config;

use RuntimeException;

/**
 * Configuración persistente de la instalación (servidor SQL, nombre BD, credenciales).
 * Se guarda en var/instalacion.json y tiene prioridad sobre .env.
 */
final class InstalacionConfig
{
  /** @return array{server: string, database: string, user: string, password: string, trust_cert: bool, tipo?: string, encrypt?: bool}|null */
  public static function load(): ?array
  {
    $path = self::path();
    if (!is_file($path)) {
      return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
      return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
      return null;
    }
    $database = trim((string) ($data['database'] ?? ''));
    if ($database === '') {
      return null;
    }
    $tipo = trim((string) ($data['tipo'] ?? 'local'));
    return [
      'tipo' => $tipo !== '' ? $tipo : 'local',
      'server' => trim((string) ($data['server'] ?? 'localhost')) ?: 'localhost',
      'database' => $database,
      'user' => (string) ($data['user'] ?? ''),
      'password' => (string) ($data['password'] ?? ''),
      'trust_cert' => filter_var($data['trust_cert'] ?? true, FILTER_VALIDATE_BOOL),
      'encrypt' => filter_var($data['encrypt'] ?? ($tipo === 'nube'), FILTER_VALIDATE_BOOL),
    ];
  }

  /** @param array<string, mixed> $config */
  public static function save(array $config): void
  {
    $database = trim((string) ($config['database'] ?? ''));
    if ($database === '') {
      throw new RuntimeException('El nombre de la base de datos es obligatorio');
    }
    $tipo = trim((string) ($config['tipo'] ?? 'local')) ?: 'local';
    $payload = [
      'tipo' => $tipo,
      'server' => trim((string) ($config['server'] ?? 'localhost')) ?: 'localhost',
      'database' => $database,
      'user' => (string) ($config['user'] ?? ''),
      'password' => (string) ($config['password'] ?? ''),
      'trust_cert' => filter_var($config['trust_cert'] ?? true, FILTER_VALIDATE_BOOL),
      'encrypt' => filter_var($config['encrypt'] ?? ($tipo === 'nube'), FILTER_VALIDATE_BOOL),
      'updated_at' => date('c'),
    ];
    $dir = dirname(self::path());
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
      throw new RuntimeException('No se pudo crear el directorio var/ de la instalacion');
    }
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
      throw new RuntimeException('No se pudo serializar la configuracion de instalacion');
    }
    if (file_put_contents(self::path(), $json, LOCK_EX) === false) {
      throw new RuntimeException('No se pudo guardar var/instalacion.json');
    }
  }

  public static function path(): string
  {
    return dirname(__DIR__, 2) . '/var/instalacion.json';
  }

  public static function isConfigured(): bool
  {
    return self::load() !== null;
  }
}
