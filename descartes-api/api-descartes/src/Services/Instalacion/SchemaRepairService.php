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
      ActividadesCentroCosteSchema::asegurar($pdo);
    } catch (\Throwable $e) {
      return [
        'ok' => false,
        'reparado' => false,
        'faltantes' => $faltantesAntes,
        'errores' => ['Actividades (centro de coste): ' . $e->getMessage()],
      ];
    }

    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
      if ($faltantesAntes !== []) {
        try {
          (new MysqlSchemaService())->asegurar($pdo);
        } catch (\Throwable $e) {
          return [
            'ok' => false,
            'reparado' => false,
            'faltantes' => $faltantesAntes,
            'errores' => [$e->getMessage()],
          ];
        }
      }
      $restantes = $this->extensionesFaltantes($pdo);
      $actividadesOk = ActividadesCentroCosteSchema::columnasOk($pdo);
      return [
        'ok' => $restantes === [] && $actividadesOk,
        'reparado' => $faltantesAntes !== [],
        'faltantes' => $restantes,
        'errores' => array_values(array_filter([
          $restantes !== [] ? 'Tras actualizar siguen faltando: ' . implode(', ', $restantes) : null,
          !$actividadesOk ? 'Actividades: falta CodigoCentroCoste o sobra DescripcionCentroCoste' : null,
        ])),
      ];
    }

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
    $actividadesOk = ActividadesCentroCosteSchema::columnasOk($pdo);
    return [
      'ok' => $restantes === [] && $actividadesOk,
      'reparado' => $faltantesAntes !== [] || $restantes === [],
      'faltantes' => $restantes,
      'errores' => array_values(array_filter([
        $restantes !== [] ? 'Tras actualizar siguen faltando: ' . implode(', ', $restantes) : null,
        !$actividadesOk ? 'Actividades: falta CodigoCentroCoste o sobra DescripcionCentroCoste' : null,
      ])),
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
       WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table AND COLUMN_NAME = :column'
    );
    $stmt->execute([
      'schema' => $this->schemaActual($pdo),
      'table' => $table,
      'column' => $column,
    ]);
    return (bool) $stmt->fetchColumn();
  }

  private function tableExists(PDO $pdo, string $table): bool
  {
    $stmt = $pdo->prepare(
      'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
       WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table'
    );
    $stmt->execute([
      'schema' => $this->schemaActual($pdo),
      'table' => $table,
    ]);
    return (bool) $stmt->fetchColumn();
  }

  private function schemaActual(PDO $pdo): string
  {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
      $nombre = $pdo->query('SELECT DATABASE()')->fetchColumn();
      return trim((string) $nombre);
    }
    return 'dbo';
  }
}
