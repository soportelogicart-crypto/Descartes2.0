<?php

declare(strict_types=1);

namespace Descartes\Api\Config;

use Descartes\Api\Database\MysqlCompatPdo;
use PDO;
use PDOException;
use RuntimeException;

final class Database
{
  /**
   * Configuracion activa: var/instalacion.json tiene prioridad sobre .env.
   *
   * @return array{server: string, database: string, user: string, password: string, trust_cert: bool, tipo: string, encrypt: bool, motor: string}
   */
  public static function resolveConfig(): array
  {
    $instalacion = InstalacionConfig::load();
    if ($instalacion !== null) {
      return self::prepareConfig($instalacion);
    }
    return self::prepareConfig([
      'motor' => $_ENV['DB_MOTOR'] ?? getenv('DB_MOTOR') ?: 'sqlsrv',
      'tipo' => 'local',
      'server' => $_ENV['DB_SERVER'] ?? getenv('DB_SERVER') ?: 'localhost',
      'database' => $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'LOGIA',
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
   * @return array{server: string, database: string, user: string, password: string, trust_cert: bool, tipo: string, encrypt: bool, motor: string}
   */
  public static function prepareConfig(array $config): array
  {
    $motor = strtolower(trim((string) ($config['motor'] ?? 'sqlsrv')));
    if (!in_array($motor, ['sqlsrv', 'mysql'], true)) {
      $motor = 'sqlsrv';
    }
    $tipo = trim((string) ($config['tipo'] ?? 'local')) ?: 'local';
    if ($motor === 'mysql') {
      $server = trim((string) ($config['server'] ?? 'localhost')) ?: 'localhost';
      $server = preg_replace('#^(?:mysql|https?)://#i', '', $server) ?? $server;
      $server = rtrim($server, '/');
      $encrypt = false;
    } else {
      $parsed = DatabaseConnectionParser::normalizeServer((string) ($config['server'] ?? 'localhost'), $tipo);
      $server = $parsed['server'];
      $encrypt = filter_var($config['encrypt'] ?? $parsed['encrypt'], FILTER_VALIDATE_BOOL);
    }

    return [
      'motor' => $motor,
      'tipo' => $tipo,
      'server' => $server,
      'database' => trim((string) ($config['database'] ?? 'LOGIA')),
      'user' => (string) ($config['user'] ?? ''),
      'password' => (string) ($config['password'] ?? ''),
      'trust_cert' => filter_var($config['trust_cert'] ?? true, FILTER_VALIDATE_BOOL),
      'encrypt' => $encrypt,
    ];
  }

  public static function createPdo(array $config): PDO
  {
    $prepared = self::prepareConfig($config);
    if ($prepared['motor'] === 'mysql') {
      return self::createMysqlPdo($prepared);
    }

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

    $pdo = new PDO($dsn, $user, $password, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // El servidor suele estar en español (DATEFORMAT dmy): sin esto, un texto
    // 'YYYY-MM-DD' con dia > 12 se lee como mes y falla al convertir a datetime.
    $pdo->exec('SET DATEFORMAT ymd');

    return $pdo;
  }

  /**
   * @param array{server: string, database: string, user: string, password: string} $prepared
   */
  private static function createMysqlPdo(array $prepared): PDO
  {
    if (!extension_loaded('pdo_mysql')) {
      throw new RuntimeException('Falta la extensión pdo_mysql de PHP');
    }

    $server = $prepared['server'];
    $port = 3306;
    if (preg_match('/^(.+):(\d+)$/', $server, $m) === 1) {
      $server = $m[1];
      $port = (int) $m[2];
    }
    $database = $prepared['database'];
    if (!preg_match('/^[A-Za-z0-9_]+$/', $database)) {
      throw new RuntimeException('El nombre de la base MySQL solo puede tener letras, números y _');
    }

    $options = [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => true,
      PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'",
    ];
    $dsn = 'mysql:host=' . $server . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4';

    try {
      return new MysqlCompatPdo($dsn, $prepared['user'], $prepared['password'], $options);
    } catch (PDOException $e) {
      if (!self::esBaseDesconocida($e)) {
        throw $e;
      }
      $serverDsn = 'mysql:host=' . $server . ';port=' . $port . ';charset=utf8mb4';
      $root = new MysqlCompatPdo($serverDsn, $prepared['user'], $prepared['password'], $options);
      $root->exec(
        'CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
      );
      return new MysqlCompatPdo($dsn, $prepared['user'], $prepared['password'], $options);
    }
  }

  private static function esBaseDesconocida(PDOException $e): bool
  {
    $info = $e->errorInfo ?? [];
    if (($info[1] ?? null) === 1049) {
      return true;
    }
    $texto = $e->getMessage();
    return str_contains($texto, '1049') || str_contains($texto, 'Unknown database');
  }

  public static function fromEnv(): PDO
  {
    return self::createPdo(self::resolveConfig());
  }
}
