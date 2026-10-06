<?php

declare(strict_types=1);

namespace Descartes\Api\Config;

use InvalidArgumentException;
use RuntimeException;

/**
 * Instalaciones adicionales de la API. Cada una es un fichero en var/clientes.
 * La instalación por defecto sigue siendo var/instalacion.json y no se toca aquí.
 * La clave de la instalación se guarda como hash; la contraseña SQL, igual que
 * en instalacion.json (el fichero no se sirve por HTTP).
 */
final class CatalogoInstalaciones
{
  public const DEFECTO = 'default';

  public static function directorio(): string
  {
    return dirname(InstalacionConfig::path()) . '/clientes';
  }

  /**
   * @param array<string, mixed> $body
   * @return array{id: string, clave: string, tipo: string, server: string, database: string}
   */
  public static function crear(string $id, array $body): array
  {
    $id = self::normalizarId($id);
    if (is_file(self::ruta($id))) {
      throw new InvalidArgumentException('Ya existe una instalación con ese identificador');
    }

    $config = self::configDesdeBody($body);
    self::probar($config);

    $clave = bin2hex(random_bytes(16));
    self::escribir($id, $config, password_hash($clave, PASSWORD_DEFAULT));

    return [
      'id' => $id,
      'clave' => $clave,
      'tipo' => $config['tipo'],
      'server' => $config['server'],
      'database' => $config['database'],
    ];
  }

  /**
   * Cambia el SQL de una instalación ya creada. La clave del PC no cambia.
   *
   * @param array{tipo: string, server: string, database: string, user: string, password: string, trust_cert: bool, encrypt: bool} $config
   */
  public static function actualizarConexion(string $id, array $config): void
  {
    $id = self::normalizarId($id);
    $data = self::leer($id);
    if ($data === null) {
      throw new InvalidArgumentException('No existe esa instalación');
    }
    self::probar($config);
    $data['tipo'] = $config['tipo'];
    $data['server'] = $config['server'];
    $data['database'] = $config['database'];
    $data['user'] = $config['user'];
    $data['password'] = $config['password'];
    $data['trust_cert'] = $config['trust_cert'];
    $data['encrypt'] = $config['encrypt'];
    $data['updated_at'] = date('c');
    self::volcar($id, $data);
  }

  /**
   * @return array{id: string, clave: string, tipo: string, server: string, database: string}
   */
  public static function regenerarClave(string $id): array
  {
    $id = self::normalizarId($id);
    $data = self::leer($id);
    if ($data === null) {
      throw new InvalidArgumentException('No existe esa instalación');
    }

    $clave = bin2hex(random_bytes(16));
    $data['claveHash'] = password_hash($clave, PASSWORD_DEFAULT);
    $data['updated_at'] = date('c');
    self::volcar($id, $data);

    return [
      'id' => $id,
      'clave' => $clave,
      'tipo' => (string) ($data['tipo'] ?? 'local'),
      'server' => (string) ($data['server'] ?? ''),
      'database' => (string) ($data['database'] ?? ''),
    ];
  }

  /**
   * Configuración SQL si el identificador y la clave coinciden.
   *
   * @return array{tipo: string, server: string, database: string, user: string, password: string, trust_cert: bool, encrypt: bool}|null
   */
  public static function autenticar(string $id, string $clave): ?array
  {
    try {
      $id = self::normalizarId($id);
    } catch (InvalidArgumentException $e) {
      return null;
    }
    if ($clave === '') {
      return null;
    }
    $data = self::leer($id);
    if ($data === null || empty($data['activa'])) {
      return null;
    }
    $hash = (string) ($data['claveHash'] ?? '');
    if ($hash === '' || !password_verify($clave, $hash)) {
      return null;
    }

    return Database::prepareConfig($data);
  }

  /**
   * @return list<array{id: string, tipo: string, server: string, database: string, defecto: bool, activa: bool}>
   */
  public static function listar(): array
  {
    $lista = [];
    $defecto = Database::resolveConfig();
    $lista[] = [
      'id' => self::DEFECTO,
      'tipo' => (string) ($defecto['tipo'] ?? 'local'),
      'server' => (string) ($defecto['server'] ?? ''),
      'database' => (string) ($defecto['database'] ?? ''),
      'defecto' => true,
      'activa' => true,
    ];

    $dir = self::directorio();
    if (!is_dir($dir)) {
      return $lista;
    }
    $paths = glob($dir . '/*.json') ?: [];
    sort($paths);
    foreach ($paths as $path) {
      $data = self::leerArchivo($path);
      if ($data === null) {
        continue;
      }
      $id = (string) ($data['id'] ?? pathinfo($path, PATHINFO_FILENAME));
      $lista[] = [
        'id' => $id,
        'tipo' => (string) ($data['tipo'] ?? 'local'),
        'server' => (string) ($data['server'] ?? ''),
        'database' => (string) ($data['database'] ?? ''),
        'defecto' => false,
        'activa' => !empty($data['activa']),
      ];
    }

    return $lista;
  }

  /**
   * @param array<string, mixed> $body
   * @return array{tipo: string, server: string, database: string, user: string, password: string, trust_cert: bool, encrypt: bool}
   */
  private static function configDesdeBody(array $body): array
  {
    $database = trim((string) ($body['database'] ?? $body['dbName'] ?? ''));
    if ($database === '') {
      throw new InvalidArgumentException('Indique el nombre de la base de datos');
    }

    $tipo = strtolower(trim((string) ($body['tipo'] ?? 'local')));
    if (!in_array($tipo, ['local', 'nube'], true)) {
      $tipo = 'local';
    }

    $serverRaw = trim((string) ($body['server'] ?? $body['dbServer'] ?? ''));
    if ($serverRaw === '') {
      $serverRaw = $tipo === 'nube' ? '' : 'localhost';
    }
    if ($serverRaw === '') {
      throw new InvalidArgumentException('Indique el servidor o la URL de la base de datos');
    }

    $user = trim((string) ($body['user'] ?? $body['dbUser'] ?? ''));
    if ($user === '') {
      throw new InvalidArgumentException('Indique el usuario de SQL Server');
    }

    return Database::prepareConfig([
      'tipo' => $tipo,
      'server' => $serverRaw,
      'database' => $database,
      'user' => $user,
      'password' => (string) ($body['password'] ?? $body['dbPassword'] ?? ''),
      'trust_cert' => filter_var($body['trust_cert'] ?? $body['trustCert'] ?? true, FILTER_VALIDATE_BOOL),
      'encrypt' => filter_var($body['encrypt'] ?? ($tipo === 'nube'), FILTER_VALIDATE_BOOL),
    ]);
  }

  /** @param array{tipo: string, server: string, database: string, user: string, password: string, trust_cert: bool, encrypt: bool} $config */
  private static function probar(array $config): void
  {
    try {
      $pdo = Database::createPdo($config);
      $pdo->query('SELECT 1');
    } catch (\Throwable $e) {
      throw new InvalidArgumentException(
        'No se pudo conectar con esa base: ' . $e->getMessage()
      );
    }
  }

  /** @param array{tipo: string, server: string, database: string, user: string, password: string, trust_cert: bool, encrypt: bool} $config */
  private static function escribir(string $id, array $config, string $claveHash): void
  {
    $ahora = date('c');
    self::volcar($id, [
      'id' => $id,
      'claveHash' => $claveHash,
      'activa' => true,
      'tipo' => $config['tipo'],
      'server' => $config['server'],
      'database' => $config['database'],
      'user' => $config['user'],
      'password' => $config['password'],
      'trust_cert' => $config['trust_cert'],
      'encrypt' => $config['encrypt'],
      'created_at' => $ahora,
      'updated_at' => $ahora,
    ]);
  }

  /** @param array<string, mixed> $data */
  private static function volcar(string $id, array $data): void
  {
    $dir = self::directorio();
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
      throw new RuntimeException('No se pudo crear var/clientes');
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
      throw new RuntimeException('No se pudo serializar la instalación');
    }
    $ruta = self::ruta($id);
    $nueva = !is_file($ruta);
    $fp = fopen($ruta, $nueva ? 'x' : 'c');
    if ($fp === false) {
      throw new InvalidArgumentException('Ya existe una instalación con ese identificador');
    }
    $error = null;
    try {
      if (!flock($fp, LOCK_EX)) {
        throw new RuntimeException('No se pudo guardar la instalación');
      }
      ftruncate($fp, 0);
      rewind($fp);
      if (fwrite($fp, $json) === false) {
        throw new RuntimeException('No se pudo guardar la instalación');
      }
      fflush($fp);
    } catch (\Throwable $e) {
      $error = $e;
    } finally {
      flock($fp, LOCK_UN);
      fclose($fp);
    }
    if ($error !== null) {
      if ($nueva && is_file($ruta)) {
        unlink($ruta);
      }
      throw $error;
    }
  }

  public static function normalizarId(string $id): string
  {
    $id = strtolower(trim($id));
    if ($id === self::DEFECTO || !preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $id)) {
      throw new InvalidArgumentException(
        'El identificador solo puede tener letras minúsculas, números y guiones, y no puede llamarse default'
      );
    }
    return $id;
  }

  /** @return array<string, mixed>|null */
  private static function leer(string $id): ?array
  {
    return self::leerArchivo(self::ruta($id));
  }

  /** @return array<string, mixed>|null */
  private static function leerArchivo(string $ruta): ?array
  {
    if (!is_file($ruta)) {
      return null;
    }
    $raw = file_get_contents($ruta);
    if ($raw === false || trim($raw) === '') {
      return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
  }

  private static function ruta(string $id): string
  {
    return self::directorio() . '/' . $id . '.json';
  }
}
