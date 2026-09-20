<?php

declare(strict_types=1);

namespace Descartes\Api\Services;

use PDO;

final class PermissionService
{
  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  public function puede(array $usuario, string $modulo, string $accion): bool
  {
    $rol = $usuario['rolCodigo'] ?? null;
    if ($rol === null || $rol === '') {
      return false;
    }

    $column = match ($accion) {
      'ver' => 'Ver',
      'crear' => 'Crear',
      'editar' => 'Editar',
      'eliminar' => 'Eliminar',
      default => null,
    };

    if ($column === null) {
      return false;
    }

    if ($this->leerPermisoColumna($rol, $modulo, $column)) {
      return true;
    }

    $padre = RolService::moduloPadre()[$modulo] ?? null;
    if ($padre === null) {
      return false;
    }

    // Solo hereda del padre si el hijo no tiene fila propia (roles antiguos).
    if ($this->existeFila($rol, $modulo)) {
      return false;
    }

    return $this->leerPermisoColumna($rol, $padre, $column);
  }

  public function permisosPorRol(string $rolCodigo): array
  {
    $stmt = $this->pdo->prepare(
      'SELECT [Modulo], [Ver], [Crear], [Editar], [Eliminar] FROM [RolPermisos] WHERE [Rol] = :rol'
    );
    $stmt->execute(['rol' => $rolCodigo]);
    $permisos = [];

    while ($row = $stmt->fetch()) {
      $modulo = $row['Modulo'];
      $permisos[$modulo] = [
        'ver' => (bool) $row['Ver'],
        'crear' => (bool) $row['Crear'],
        'editar' => (bool) $row['Editar'],
        'eliminar' => (bool) $row['Eliminar'],
      ];
    }

    foreach (RolService::modulos() as $modulo) {
      if (isset($permisos[$modulo])) {
        continue;
      }
      $padre = RolService::moduloPadre()[$modulo] ?? null;
      if ($padre !== null && isset($permisos[$padre])) {
        $permisos[$modulo] = $permisos[$padre];
      }
    }

    return $permisos;
  }

  private function existeFila(string $rol, string $modulo): bool
  {
    $stmt = $this->pdo->prepare(
      'SELECT TOP 1 1 AS ok FROM [RolPermisos] WHERE [Rol] = :rol AND [Modulo] = :modulo'
    );
    $stmt->execute(['rol' => $rol, 'modulo' => $modulo]);
    return (bool) $stmt->fetchColumn();
  }

  private function leerPermisoColumna(string $rol, string $modulo, string $column): bool
  {
    $stmt = $this->pdo->prepare(
      "SELECT TOP 1 [{$column}] AS permitido FROM [RolPermisos] WHERE [Rol] = :rol AND [Modulo] = :modulo"
    );
    $stmt->execute(['rol' => $rol, 'modulo' => $modulo]);
    $row = $stmt->fetch();

    return (bool) ($row['permitido'] ?? false);
  }
}
