<?php

declare(strict_types=1);

namespace Descartes\Api\Repositories;

use PDO;

final class DocumentoPlantillasRepository
{
  private PDO $pdo;
  private bool $tableChecked = false;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  public function ensureTable(): void
  {
    if ($this->tableChecked) {
      return;
    }
    $this->pdo->exec(
      "IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = 'DocumentoPlantillas' AND schema_id = SCHEMA_ID('dbo'))
       BEGIN
         CREATE TABLE [dbo].[DocumentoPlantillas] (
           [Id] int IDENTITY(1,1) NOT NULL,
           [EmpresaCodigo] nvarchar(3) NOT NULL CONSTRAINT DF_DocumentoPlantillas_Empresa DEFAULT (N''),
           [Tipo] nvarchar(40) NOT NULL,
           [Nombre] nvarchar(100) NOT NULL,
           [Descripcion] nvarchar(250) NULL,
           [Version] int NOT NULL CONSTRAINT DF_DocumentoPlantillas_Version DEFAULT (1),
           [Activa] bit NOT NULL CONSTRAINT DF_DocumentoPlantillas_Activa DEFAULT (0),
           [Definicion] nvarchar(max) NOT NULL,
           [Creado] datetime NOT NULL CONSTRAINT DF_DocumentoPlantillas_Creado DEFAULT (GETDATE()),
           [Actualizado] datetime NOT NULL CONSTRAINT DF_DocumentoPlantillas_Actualizado DEFAULT (GETDATE()),
           CONSTRAINT [PK_DocumentoPlantillas] PRIMARY KEY CLUSTERED ([Id])
         );
         CREATE INDEX [IX_DocumentoPlantillas_Empresa_Tipo]
           ON [dbo].[DocumentoPlantillas] ([EmpresaCodigo], [Tipo], [Activa]);
       END"
    );
    $this->tableChecked = true;
  }

  /**
   * @return list<array<string, mixed>>
   */
  public function list(?string $empresaCodigo, ?string $tipo): array
  {
    $this->ensureTable();
    $sql = 'SELECT [Id], [EmpresaCodigo], [Tipo], [Nombre], [Descripcion], [Version], [Activa],
                   [Definicion], [Actualizado]
            FROM [DocumentoPlantillas] WHERE 1=1';
    $params = [];
    if ($empresaCodigo !== null) {
      $sql .= ' AND [EmpresaCodigo] = :empresa';
      $params['empresa'] = $empresaCodigo;
    }
    if ($tipo !== null && $tipo !== '') {
      $sql .= ' AND [Tipo] = :tipo';
      $params['tipo'] = $tipo;
    }
    $sql .= ' ORDER BY [Tipo], [Activa] DESC, [Nombre]';
    $st = $this->pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }

  public function find(int $id): ?array
  {
    $this->ensureTable();
    $st = $this->pdo->prepare(
      'SELECT [Id], [EmpresaCodigo], [Tipo], [Nombre], [Descripcion], [Version], [Activa],
              [Definicion], [Creado], [Actualizado]
       FROM [DocumentoPlantillas] WHERE [Id] = :id'
    );
    $st->execute(['id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
  }

  public function countByEmpresaTipo(string $empresaCodigo, string $tipo): int
  {
    $this->ensureTable();
    $st = $this->pdo->prepare(
      'SELECT COUNT(*) FROM [DocumentoPlantillas]
       WHERE [EmpresaCodigo] = :empresa AND [Tipo] = :tipo'
    );
    $st->execute(['empresa' => $empresaCodigo, 'tipo' => $tipo]);
    return (int) $st->fetchColumn();
  }

  /**
   * @param array<string, mixed> $data
   */
  public function insert(array $data): int
  {
    $this->ensureTable();
    $st = $this->pdo->prepare(
      'INSERT INTO [DocumentoPlantillas]
         ([EmpresaCodigo], [Tipo], [Nombre], [Descripcion], [Version], [Activa], [Definicion], [Creado], [Actualizado])
       OUTPUT INSERTED.[Id]
       VALUES
         (:empresa, :tipo, :nombre, :descripcion, :version, :activa, :definicion, GETDATE(), GETDATE())'
    );
    $st->execute([
      'empresa' => $data['empresaCodigo'],
      'tipo' => $data['tipo'],
      'nombre' => $data['nombre'],
      'descripcion' => $data['descripcion'],
      'version' => $data['version'],
      'activa' => !empty($data['activa']) ? 1 : 0,
      'definicion' => $data['definicion'],
    ]);
    $id = $st->fetchColumn();
    return (int) $id;
  }

  /**
   * @param array<string, mixed> $data
   */
  public function update(int $id, array $data): void
  {
    $this->ensureTable();
    $st = $this->pdo->prepare(
      'UPDATE [DocumentoPlantillas] SET
         [Nombre] = :nombre,
         [Descripcion] = :descripcion,
         [Version] = :version,
         [Activa] = :activa,
         [Definicion] = :definicion,
         [Actualizado] = GETDATE()
       WHERE [Id] = :id'
    );
    $st->execute([
      'id' => $id,
      'nombre' => $data['nombre'],
      'descripcion' => $data['descripcion'],
      'version' => $data['version'],
      'activa' => !empty($data['activa']) ? 1 : 0,
      'definicion' => $data['definicion'],
    ]);
  }

  public function delete(int $id): void
  {
    $this->ensureTable();
    $st = $this->pdo->prepare('DELETE FROM [DocumentoPlantillas] WHERE [Id] = :id');
    $st->execute(['id' => $id]);
  }

  public function clearActiva(string $empresaCodigo, string $tipo): void
  {
    $this->ensureTable();
    $st = $this->pdo->prepare(
      'UPDATE [DocumentoPlantillas] SET [Activa] = 0
       WHERE [EmpresaCodigo] = :empresa AND [Tipo] = :tipo'
    );
    $st->execute(['empresa' => $empresaCodigo, 'tipo' => $tipo]);
  }

  public function setActiva(int $id): void
  {
    $this->ensureTable();
    $row = $this->find($id);
    if ($row === null) {
      return;
    }
    $this->clearActiva((string) $row['EmpresaCodigo'], (string) $row['Tipo']);
    $st = $this->pdo->prepare(
      'UPDATE [DocumentoPlantillas] SET [Activa] = 1, [Actualizado] = GETDATE() WHERE [Id] = :id'
    );
    $st->execute(['id' => $id]);
  }
}
