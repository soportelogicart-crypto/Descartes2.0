<?php

declare(strict_types=1);

namespace Descartes\Api\Repositories;

use PDO;

final class RolPermisoRepository
{
  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  public function findByRol(string $rol): array
  {
    $stmt = $this->pdo->prepare(
      'SELECT [Modulo], [Ver], [Crear], [Editar], [Eliminar] FROM [RolPermisos] WHERE [Rol] = :rol ORDER BY [Modulo]'
    );
    $stmt->execute(['rol' => $rol]);

    $rows = [];
    while ($row = $stmt->fetch()) {
      $rows[] = [
        'modulo' => $row['Modulo'],
        'ver' => (bool) $row['Ver'],
        'crear' => (bool) $row['Crear'],
        'editar' => (bool) $row['Editar'],
        'eliminar' => (bool) $row['Eliminar'],
      ];
    }

    return $rows;
  }

  public function replaceForRol(string $rol, array $permisos): void
  {
    $this->pdo->beginTransaction();

    try {
      $delete = $this->pdo->prepare('DELETE FROM [RolPermisos] WHERE [Rol] = :rol');
      $delete->execute(['rol' => $rol]);

      $insert = $this->pdo->prepare(
        'INSERT INTO [RolPermisos] ([Rol], [Modulo], [Ver], [Crear], [Editar], [Eliminar])
         VALUES (:rol, :modulo, :ver, :crear, :editar, :eliminar)'
      );

      foreach ($permisos as $permiso) {
        $insert->execute([
          'rol' => $rol,
          'modulo' => $permiso['modulo'],
          'ver' => !empty($permiso['ver']) ? 1 : 0,
          'crear' => !empty($permiso['crear']) ? 1 : 0,
          'editar' => !empty($permiso['editar']) ? 1 : 0,
          'eliminar' => !empty($permiso['eliminar']) ? 1 : 0,
        ]);
      }

      $this->pdo->commit();
    } catch (\Throwable $e) {
      $this->pdo->rollBack();
      throw $e;
    }
  }
}
