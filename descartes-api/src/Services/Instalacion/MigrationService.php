<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Instalacion;

use Descartes\Api\Config\Database;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Aplica migraciones SQL idempotentes y registra el estado en SchemaMigrations.
 */
final class MigrationService
{
  /** Orden fijo (evita ambiguedad con dos archivos 007-*). */
  private const MIGRATION_ORDER = [
    '001-mantenimiento-extensiones.sql',
    '002-config-equipo.sql',
    '003-documento-plantillas.sql',
    '004-permisos-submenu.sql',
    '005-rolpermisos-modulo-len.sql',
    '006-permisos-compras.sql',
    '007-permisos-tpv.sql',
    '007-permisos-etiquetas.sql',
    '008-puestos-formato-etiquetas.sql',
    '009-tipos-calculo-fidelizacion.sql',
    '010-permisos-albaranes-periodicos.sql',
    '015-albaranes-periodicos-activo.sql',
  ];

  /**
   * @param array{server: string, database: string, user: string, password: string, trust_cert: bool} $config
   * @return array{
   *   aplicadas: list<string>,
   *   pendientes: list<string>,
   *   errores: list<string>,
   *   ok: bool
   * }
   */
  public function aplicarPendientes(array $config): array
  {
    $pdo = Database::createPdo($config);
    $this->ensureMigrationTable($pdo);

    $aplicadas = [];
    $pendientes = [];
    $errores = [];

    foreach (self::MIGRATION_ORDER as $filename) {
      if ($this->isApplied($pdo, $filename)) {
        if ($filename === '001-mantenimiento-extensiones.sql' && !$this->extensiones001Ok($pdo)) {
          $pendientes[] = $filename . ' (reparar)';
        }
        continue;
      }
      $pendientes[] = $filename;
    }

    foreach ($pendientes as $filename) {
      $file = str_replace(' (reparar)', '', $filename);
      try {
        $this->applyFile($pdo, $file);
        if (!$this->isApplied($pdo, $file)) {
          $this->markApplied($pdo, $file);
        }
        $aplicadas[] = $file;
      } catch (\Throwable $e) {
        $errores[] = "{$file}: " . $e->getMessage();
        break;
      }
    }

    return [
      'aplicadas' => $aplicadas,
      'pendientes' => array_values(array_diff($pendientes, $aplicadas)),
      'errores' => $errores,
      'ok' => $errores === [],
    ];
  }

  /**
   * Ejecuta todos los scripts idempotentes (siempre, al preparar instalacion).
   *
   * @param array{server: string, database: string, user: string, password: string, trust_cert: bool} $config
   * @return array{aplicadas: list<string>, errores: list<string>, ok: bool}
   */
  public function aplicarTodasIdempotentes(array $config): array
  {
    $pdo = Database::createPdo($config);
    $this->ensureMigrationTable($pdo);

    $aplicadas = [];
    $errores = [];

    foreach (self::MIGRATION_ORDER as $filename) {
      try {
        $this->applyFile($pdo, $filename);
        if (!$this->isApplied($pdo, $filename)) {
          $this->markApplied($pdo, $filename);
        }
        $aplicadas[] = $filename;
      } catch (\Throwable $e) {
        $errores[] = "{$filename}: " . $e->getMessage();
        break;
      }
    }

    return [
      'aplicadas' => $aplicadas,
      'errores' => $errores,
      'ok' => $errores === [],
    ];
  }

  /**
   * @param array{server: string, database: string, user: string, password: string, trust_cert: bool} $config
   * @return array{
   *   aplicadas: list<string>,
   *   pendientes: list<string>,
   *   total: int,
   *   conexionOk: bool,
   *   errorConexion: string|null
   * }
   */
  public function estado(array $config): array
  {
    try {
      $pdo = Database::createPdo($config);
    } catch (\Throwable $e) {
      return [
        'aplicadas' => [],
        'pendientes' => self::MIGRATION_ORDER,
        'total' => count(self::MIGRATION_ORDER),
        'conexionOk' => false,
        'errorConexion' => $e->getMessage(),
      ];
    }

    try {
      $this->ensureMigrationTable($pdo);
    } catch (\Throwable $e) {
      return [
        'aplicadas' => [],
        'pendientes' => self::MIGRATION_ORDER,
        'total' => count(self::MIGRATION_ORDER),
        'conexionOk' => true,
        'errorConexion' => $e->getMessage(),
      ];
    }

    $aplicadas = [];
    $pendientes = [];
    foreach (self::MIGRATION_ORDER as $filename) {
      if ($this->isApplied($pdo, $filename)) {
        if ($filename === '001-mantenimiento-extensiones.sql' && !$this->extensiones001Ok($pdo)) {
          $pendientes[] = $filename;
        } else {
          $aplicadas[] = $filename;
        }
      } else {
        $pendientes[] = $filename;
      }
    }

    return [
      'aplicadas' => $aplicadas,
      'pendientes' => $pendientes,
      'total' => count(self::MIGRATION_ORDER),
      'conexionOk' => true,
      'errorConexion' => null,
    ];
  }

  /** @return list<string> */
  public function listMigrationFiles(): array
  {
    return self::MIGRATION_ORDER;
  }

  private function migrationsDir(): string
  {
    $apiRoot = dirname(__DIR__, 3);
    $candidates = [
      $apiRoot . '/database/migrations',
      $apiRoot . '/../db/migrations',
    ];
    foreach ($candidates as $dir) {
      $real = realpath($dir);
      if ($real !== false && is_dir($real)) {
        return $real;
      }
    }
    throw new RuntimeException('No se encontro el directorio database/migrations');
  }

  private function ensureMigrationTable(PDO $pdo): void
  {
    $pdo->exec(
      'IF OBJECT_ID(\'dbo.SchemaMigrations\', \'U\') IS NULL
       BEGIN
         CREATE TABLE [dbo].[SchemaMigrations] (
           [Id] nvarchar(80) NOT NULL,
           [AppliedAt] datetime NOT NULL CONSTRAINT [DF_SchemaMigrations_AppliedAt] DEFAULT (GETDATE()),
           CONSTRAINT [PK_SchemaMigrations] PRIMARY KEY CLUSTERED ([Id])
         );
       END'
    );
  }

  private function isApplied(PDO $pdo, string $filename): bool
  {
    $stmt = $pdo->prepare('SELECT 1 FROM [SchemaMigrations] WHERE [Id] = :id');
    $stmt->execute(['id' => $filename]);
    return (bool) $stmt->fetchColumn();
  }

  private function markApplied(PDO $pdo, string $filename): void
  {
    $stmt = $pdo->prepare(
      'INSERT INTO [SchemaMigrations] ([Id], [AppliedAt]) VALUES (:id, GETDATE())'
    );
    $stmt->execute(['id' => $filename]);
  }

  /** Ejecuta un .sql idempotente sin tocar SchemaMigrations (reparacion). */
  public function aplicarArchivoIdempotente(PDO $pdo, string $filename): void
  {
    $this->applyFile($pdo, $filename);
  }

  private function applyFile(PDO $pdo, string $filename): void
  {
    $path = $this->migrationsDir() . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($path)) {
      throw new RuntimeException("Archivo de migracion no encontrado: {$filename}");
    }
    $sql = file_get_contents($path);
    if ($sql === false || trim($sql) === '') {
      throw new RuntimeException("Migracion vacia: {$filename}");
    }
    foreach ($this->splitBatches($sql) as $batch) {
      try {
        $pdo->exec($batch);
      } catch (PDOException $e) {
        throw new RuntimeException($e->getMessage(), (int) $e->getCode(), $e);
      }
    }
  }

  /** @return list<string> */
  private function splitBatches(string $sql): array
  {
    $parts = preg_split('/^\s*GO\s*$/mi', $sql) ?: [];
    $batches = [];
    foreach ($parts as $part) {
      $trimmed = trim($part);
      if ($trimmed !== '') {
        $batches[] = $trimmed;
      }
    }
    return $batches;
  }

  private function extensiones001Ok(PDO $pdo): bool
  {
    foreach (['Rol', 'Baja'] as $column) {
      $stmt = $pdo->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = \'dbo\' AND TABLE_NAME = \'Usuarios_Ges\' AND COLUMN_NAME = :column'
      );
      $stmt->execute(['column' => $column]);
      if (!$stmt->fetchColumn()) {
        return false;
      }
    }
    $roles = $pdo->query("SELECT CASE WHEN OBJECT_ID('dbo.Roles', 'U') IS NULL THEN 0 ELSE 1 END")->fetchColumn();
    $permisos = $pdo->query("SELECT CASE WHEN OBJECT_ID('dbo.RolPermisos', 'U') IS NULL THEN 0 ELSE 1 END")->fetchColumn();
    return (bool) $roles && (bool) $permisos;
  }
}
