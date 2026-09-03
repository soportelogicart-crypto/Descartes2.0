<?php

declare(strict_types=1);

namespace Descartes\Api\Config;

use PDO;

final class Database
{
  /**
   * Configuracion activa: var/instalacion.json tiene prioridad sobre .env.
   *
   * @return array{server: string, database: string, user: string, password: string, trust_cert: bool, tipo?: string, encrypt?: bool}
   */
  public static function resolveConfig(): array
  {
    $instalacion = InstalacionConfig::load();
    if ($instalacion !== null) {
      return self::prepareConfig($instalacion);
    }
    return self::prepareConfig([
      'tipo' => 'local',
      'server' => $_ENV['DB_SERVER'] ?? getenv('DB_SERVER') ?: 'localhost',
      'database' => $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'larasa',
      'user' => $_ENV['DB_USER'] ?? getenv('DB_USER') ?: '',
      'password' => $_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '',
      'trust_cert' => filter_var(
        $_ENV['DB_TRUST_CERT'] ?? getenv('DB_TRUST_CERT') ?: 'true',
        FILTER_VALIDATE_BOOL
      ),
      'encrypt' => filter_var($_ENV['DB_ENCRYPT'] ?? getenv('DB_ENCRYPT') ?: 'false', FILTER_VALIDATE_BOOL),
    ]);
  }

  /**
   * @param array<string, mixed> $config
   * @return array{server: string, database: string, user: string, password: string, trust_cert: bool, tipo: string, encrypt: bool}
   */
  public static function prepareConfig(array $config): array
  {
    $tipo = trim((string) ($config['tipo'] ?? 'local')) ?: 'local';
    $parsed = DatabaseConnectionParser::normalizeServer((string) ($config['server'] ?? 'localhost'), $tipo);
    return [
      'tipo' => $tipo,
      'server' => $parsed['server'],
      'database' => trim((string) ($config['database'] ?? 'larasa')),
      'user' => (string) ($config['user'] ?? ''),
      'password' => (string) ($config['password'] ?? ''),
      'trust_cert' => filter_var($config['trust_cert'] ?? true, FILTER_VALIDATE_BOOL),
      'encrypt' => filter_var($config['encrypt'] ?? $parsed['encrypt'], FILTER_VALIDATE_BOOL),
    ];
  }

  public static function createPdo(array $config): PDO
  {
    $prepared = self::prepareConfig($config);
    $server = $prepared['server'];
    $database = $prepared['database'];
    $user = $prepared['user'];
    $password = $prepared['password'];
    $trustCert = $prepared['trust_cert'];
    $encrypt = $prepared['encrypt'];

    $dsnParts = [
      'Server=' . $server,
      'Database=' . $database,
    ];
    if ($encrypt) {
      $dsnParts[] = 'Encrypt=yes';
    }
    $dsnParts[] = 'TrustServerCertificate=' . ($trustCert ? 'yes' : 'no');
    $dsn = 'sqlsrv:' . implode(';', $dsnParts);

    return new PDO($dsn, $user, $password, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
  }

  public static function fromEnv(): PDO
  {
    return self::createPdo(self::resolveConfig());
  }
}
