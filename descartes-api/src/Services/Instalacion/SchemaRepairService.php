<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Instalacion;

use PDO;

/**
 * Comprueba y aplica extensiones idempotentes de esquema (001 y similares)
 * aunque SchemaMigrations ya las marque como aplicadas.
 */
final class SchemaRepairService
{
  private MigrationService $migrations;

  public function __construct(MigrationService $migrations)
  {
    $this->migrations = $migrations;
  }

  /**
   * @return array{
   *   ok: bool,
   *   reparado: bool,
   *   faltantes: list<string>,
   *   errores: list<string>
   * }
   */
  public function asegurarExtensionesBase(PDO $pdo): array
  {
    $faltantesAntes = $this->extensionesFaltantes($pdo);

    try {
      $this->migrations->aplicarArchivoIdempotente($pdo, '001-mantenimiento-extensiones.sql');
      $this->migrations->aplicarArchivoIdempotente($pdo, '005-rolpermisos-modulo-len.sql');
    } catch (\Throwable $e) {
      return [
        'ok' => false,
        'reparado' => false,
        'faltantes' => $faltantesAntes,
        'errores' => [$e->getMessage()],
      ];
    }

    $restantes = $this->extensionesFaltantes($pdo);
    return [
      'ok' => $restantes === [],
      'reparado' => $faltantesAntes !== [] || $restantes === [],
      'faltantes' => $restantes,
      'errores' => $restantes !== []
        ? ['Tras actualizar siguen faltando: ' . implode(', ', $restantes)]
        : [],
    ];
  }

  /**
   * @return list<string>
   */
  public function extensionesFaltantes(PDO $pdo): array
  {
    $faltantes = [];
    if (!$this->columnExists($pdo, 'Usuarios_Ges', 'Rol')) {
      $faltantes[] = 'Usuarios_Ges.Rol';
    }
    if (!$this->columnExists($pdo, 'Usuarios_Ges', 'Baja')) {
      $faltantes[] = 'Usuarios_Ges.Baja';
    }
    if (!$this->tableExists($pdo, 'Roles')) {
      $faltantes[] = 'Roles';
    }
    if (!$this->tableExists($pdo, 'RolPermisos')) {
      $faltantes[] = 'RolPermisos';
    }
    return $faltantes;
  }

  private function columnExists(PDO $pdo, string $table, string $column): bool
  {
    $stmt = $pdo->prepare(
      'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
       WHERE TABLE_SCHEMA = \'dbo\' AND TABLE_NAME = :table AND COLUMN_NAME = :column'
    );
    $stmt->execute(['table' => $table, 'column' => $column]);
    return (bool) $stmt->fetchColumn();
  }

  private function tableExists(PDO $pdo, string $table): bool
  {
    $stmt = $pdo->prepare(
      'SELECT CASE WHEN OBJECT_ID(:fullName, \'U\') IS NULL THEN 0 ELSE 1 END'
    );
    $stmt->execute(['fullName' => 'dbo.' . $table]);
    return (bool) $stmt->fetchColumn();
  }
}
