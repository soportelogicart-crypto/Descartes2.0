<?php

declare(strict_types=1);

namespace Descartes\Api\Config;

use PDO;

final class Database
{
  public static function createPdo(array $config): PDO
  {
    $server = $config['server'] ?? 'localhost';
    $database = $config['database'] ?? 'larasa';
    $user = $config['user'] ?? '';
    $password = $config['password'] ?? '';
    $trustCert = filter_var($config['trust_cert'] ?? true, FILTER_VALIDATE_BOOL);

    $dsn = sprintf(
      'sqlsrv:Server=%s;Database=%s;TrustServerCertificate=%s',
      $server,
      $database,
      $trustCert ? 'yes' : 'no'
    );

    return new PDO($dsn, $user, $password, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
  }

  public static function fromEnv(): PDO
  {
    return self::createPdo([
      'server' => $_ENV['DB_SERVER'] ?? getenv('DB_SERVER') ?: 'localhost',
      'database' => $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'larasa',
      'user' => $_ENV['DB_USER'] ?? getenv('DB_USER') ?: '',
      'password' => $_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '',
      'trust_cert' => $_ENV['DB_TRUST_CERT'] ?? getenv('DB_TRUST_CERT') ?: 'true',
    ]);
  }
}
