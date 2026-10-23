<?php

declare(strict_types=1);

namespace Descartes\Api\Repositories;

use PDO;

final class ConfigEquipoRepository
{
  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  public function find(string $equipoId): ?array
  {
    $stmt = $this->pdo->prepare(
      'SELECT [EquipoId], [EmpresaCodigo], [PuestoCodigo], [Actualizado]
       FROM [ConfigEquipo]
       WHERE [EquipoId] = :id'
    );
    $stmt->execute(['id' => $equipoId]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
  }

  public function upsert(string $equipoId, string $empresaCodigo, string $puestoCodigo): void
  {
    if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
      $stmt = $this->pdo->prepare(
        'INSERT INTO [ConfigEquipo] ([EquipoId], [EmpresaCodigo], [PuestoCodigo], [Actualizado])
         VALUES (:id, :empresa, :puesto, GETDATE())
         ON DUPLICATE KEY UPDATE
           [EmpresaCodigo] = :empresa2,
           [PuestoCodigo] = :puesto2,
           [Actualizado] = GETDATE()'
      );
      $stmt->execute([
        'id' => $equipoId,
        'empresa' => $empresaCodigo,
        'puesto' => $puestoCodigo,
        'empresa2' => $empresaCodigo,
        'puesto2' => $puestoCodigo,
      ]);
      return;
    }

    $stmt = $this->pdo->prepare(
      'MERGE [ConfigEquipo] AS target
       USING (SELECT :id AS EquipoId) AS source
       ON target.[EquipoId] = source.EquipoId
       WHEN MATCHED THEN
         UPDATE SET
           [EmpresaCodigo] = :empresa,
           [PuestoCodigo] = :puesto,
           [Actualizado] = GETDATE()
       WHEN NOT MATCHED THEN
         INSERT ([EquipoId], [EmpresaCodigo], [PuestoCodigo], [Actualizado])
         VALUES (:id2, :empresa2, :puesto2, GETDATE());'
    );
    $stmt->execute([
      'id' => $equipoId,
      'empresa' => $empresaCodigo,
      'puesto' => $puestoCodigo,
      'id2' => $equipoId,
      'empresa2' => $empresaCodigo,
      'puesto2' => $puestoCodigo,
    ]);
  }
}
