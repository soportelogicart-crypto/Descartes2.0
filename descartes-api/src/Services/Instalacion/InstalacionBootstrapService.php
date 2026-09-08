<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Instalacion;

use PDO;
use PDOException;

/**
 * Crea o repara el usuario administrador inicial (ADM / admin123).
 */
final class InstalacionBootstrapService
{
  public const USUARIO_ADMIN = 'ADM';
  public const PASSWORD_ADMIN = 'admin123';
  public const NOMBRE_ADMIN = 'Administrador';

  /**
   * @return array{usuario: string, password: string, creado: bool, actualizado: bool}
   */
  public function asegurarUsuarioAdministrador(PDO $pdo): array
  {
    $codigo = self::USUARIO_ADMIN;
    $creado = false;
    $actualizado = false;

    $stmt = $pdo->prepare('SELECT TOP 1 [Codigo] FROM [Usuarios_Ges] WHERE RTRIM([Codigo]) = :codigo');
    $stmt->execute(['codigo' => $codigo]);
    $existe = $stmt->fetch(PDO::FETCH_ASSOC) !== false;

    if (!$existe) {
      $this->insertarAdministrador($pdo);
      $creado = true;
      return [
        'usuario' => $codigo,
        'password' => self::PASSWORD_ADMIN,
        'creado' => true,
        'actualizado' => false,
      ];
    }

    $actualizado = $this->actualizarAdministrador($pdo);
    return [
      'usuario' => $codigo,
      'password' => self::PASSWORD_ADMIN,
      'creado' => false,
      'actualizado' => $actualizado,
    ];
  }

  public function existeAdministrador(PDO $pdo): bool
  {
    $stmt = $pdo->prepare(
      'SELECT TOP 1 1 FROM [Usuarios_Ges] WHERE RTRIM([Codigo]) = :codigo AND ISNULL([Baja], 0) = 0'
    );
    $stmt->execute(['codigo' => self::USUARIO_ADMIN]);
    return (bool) $stmt->fetchColumn();
  }

  private function insertarAdministrador(PDO $pdo): void
  {
    $columnas = $this->columnasUsuarios($pdo);
    $campos = ['Codigo', 'Nombre', 'PassWord'];
    $valores = [':codigo', ':nombre', ':password'];
    $params = [
      'codigo' => self::USUARIO_ADMIN,
      'nombre' => self::NOMBRE_ADMIN,
      'password' => self::PASSWORD_ADMIN,
    ];

    if (in_array('Rol', $columnas, true)) {
      $campos[] = 'Rol';
      $valores[] = ':rol';
      $params['rol'] = 'ADMIN';
    }
    if (in_array('Baja', $columnas, true)) {
      $campos[] = 'Baja';
      $valores[] = '0';
    }

    $sql = sprintf(
      'INSERT INTO [Usuarios_Ges] (%s) VALUES (%s)',
      implode(', ', array_map(static fn ($c) => "[{$c}]", $campos)),
      implode(', ', $valores)
    );
    $pdo->prepare($sql)->execute($params);
  }

  private function actualizarAdministrador(PDO $pdo): bool
  {
    $sets = ['[PassWord] = :password', '[Nombre] = :nombre'];
    $params = [
      'password' => self::PASSWORD_ADMIN,
      'nombre' => self::NOMBRE_ADMIN,
      'codigo' => self::USUARIO_ADMIN,
    ];
    $columnas = $this->columnasUsuarios($pdo);
    if (in_array('Rol', $columnas, true)) {
      $sets[] = "[Rol] = 'ADMIN'";
    }
    if (in_array('Baja', $columnas, true)) {
      $sets[] = '[Baja] = 0';
    }

    $sql = 'UPDATE [Usuarios_Ges] SET ' . implode(', ', $sets) . ' WHERE RTRIM([Codigo]) = :codigo';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount() > 0;
  }

  /** @return list<string> */
  private function columnasUsuarios(PDO $pdo): array
  {
    try {
      $stmt = $pdo->query(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'Usuarios_Ges'"
      );
      $cols = [];
      while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $cols[] = (string) ($row['COLUMN_NAME'] ?? '');
      }
      return $cols;
    } catch (PDOException $e) {
      return ['Codigo', 'Nombre', 'PassWord'];
    }
  }
}
