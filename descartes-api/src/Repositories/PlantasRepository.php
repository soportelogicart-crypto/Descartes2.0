<?php

declare(strict_types=1);

namespace Descartes\Api\Repositories;

use PDO;

/**
 * Fichas de planta en BD GardenDocumental (misma instancia SQL Server).
 */
final class PlantasRepository
{
  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  /** @return list<array{tipo: string, codigo: int, descripcion: string}> */
  public function listGrupos(?string $tipo = null): array
  {
    $sql = 'SELECT [Tipo], [Codigo], [Descripcion]
            FROM [GardenDocumental].[dbo].[Grupos]
            WHERE [Codigo] IS NOT NULL';
    $params = [];
    if ($tipo !== null && $tipo !== '') {
      $sql .= ' AND RTRIM([Tipo]) = :tipo';
      $params['tipo'] = $tipo;
    }
    $sql .= ' ORDER BY [Tipo], [Codigo]';
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);

    $items = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $items[] = [
        'tipo' => trim((string) ($row['Tipo'] ?? '')),
        'codigo' => (int) ($row['Codigo'] ?? 0),
        'descripcion' => trim((string) ($row['Descripcion'] ?? '')),
      ];
    }
    return $items;
  }

  public function findByCodigo(int $codigo): ?array
  {
    $stmt = $this->pdo->prepare(
      'SELECT * FROM [GardenDocumental].[dbo].[Plantas] WHERE [codigo] = :codigo'
    );
    $stmt->execute(['codigo' => $codigo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $this->mapRow($row) : null;
  }

  /** @return list<array<string, mixed>> */
  public function search(string $q, int $limit = 50): array
  {
    $limit = max(1, min(200, $limit));
    $like = '%' . $q . '%';
    $stmt = $this->pdo->prepare(
      "SELECT TOP ({$limit}) [codigo], [NombreBotanico], [comun_cas], [sinonimos], [NombreFormatoFicha]
       FROM [GardenDocumental].[dbo].[Plantas]
       WHERE CAST([codigo] AS nvarchar(20)) LIKE :q
          OR [NombreBotanico] LIKE :q2
          OR [comun_cas] LIKE :q3
          OR [sinonimos] LIKE :q4
       ORDER BY [NombreBotanico]"
    );
    $stmt->execute(['q' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like]);
    $items = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $items[] = [
        'codigo' => (int) $row['codigo'],
        'nombreBotanico' => trim((string) ($row['NombreBotanico'] ?? '')),
        'nombreComun' => trim((string) ($row['comun_cas'] ?? '')),
        'sinonimos' => trim((string) ($row['sinonimos'] ?? '')),
        'nombreFormatoFicha' => trim((string) ($row['NombreFormatoFicha'] ?? '')),
      ];
    }
    return $items;
  }

  public function nextCodigo(): int
  {
    $max = (int) $this->pdo->query(
      'SELECT ISNULL(MAX([codigo]), 0) FROM [GardenDocumental].[dbo].[Plantas]'
    )->fetchColumn();
    return $max + 1;
  }

  public function create(array $data): array
  {
    $codigo = isset($data['codigo']) && (int) $data['codigo'] > 0
      ? (int) $data['codigo']
      : $this->nextCodigo();

    if ($this->findByCodigo($codigo) !== null) {
      throw new \InvalidArgumentException("Ya existe una ficha con codigo {$codigo}");
    }

    $mapped = $this->mapApiToRow($data);
    $mapped['codigo'] = $codigo;

    $cols = array_keys($mapped);
    $sql = sprintf(
      'INSERT INTO [GardenDocumental].[dbo].[Plantas] (%s) VALUES (%s)',
      implode(', ', array_map(static fn ($c) => "[{$c}]", $cols)),
      implode(', ', array_map(static fn ($c) => ':' . $c, $cols))
    );
    $stmt = $this->pdo->prepare($sql);
    foreach ($mapped as $col => $val) {
      $stmt->bindValue(':' . $col, $val);
    }
    $stmt->execute();

    return $this->findByCodigo($codigo) ?? ['codigo' => $codigo];
  }

  public function update(int $codigo, array $data): ?array
  {
    if ($this->findByCodigo($codigo) === null) {
      return null;
    }
    $mapped = $this->mapApiToRow($data);
    unset($mapped['codigo']);
    if ($mapped === []) {
      return $this->findByCodigo($codigo);
    }

    $sets = [];
    foreach (array_keys($mapped) as $col) {
      $sets[] = "[{$col}] = :{$col}";
    }
    $sql = 'UPDATE [GardenDocumental].[dbo].[Plantas] SET ' . implode(', ', $sets)
      . ' WHERE [codigo] = :pk';
    $stmt = $this->pdo->prepare($sql);
    foreach ($mapped as $col => $val) {
      $stmt->bindValue(':' . $col, $val);
    }
    $stmt->bindValue(':pk', $codigo, PDO::PARAM_INT);
    $stmt->execute();

    return $this->findByCodigo($codigo);
  }

  public function delete(int $codigo): bool
  {
    $stmt = $this->pdo->prepare(
      'DELETE FROM [GardenDocumental].[dbo].[Plantas] WHERE [codigo] = :codigo'
    );
    $stmt->execute(['codigo' => $codigo]);
    return $stmt->rowCount() > 0;
  }

  private function mapRow(array $row): array
  {
    return [
      'codigo' => (int) ($row['codigo'] ?? 0),
      'grupo' => $row['grupo'] === null ? null : (int) $row['grupo'],
      'nombreFormatoFicha' => trim((string) ($row['NombreFormatoFicha'] ?? '')),
      'nombreBotanico' => trim((string) ($row['NombreBotanico'] ?? '')),
      'nombreComun' => trim((string) ($row['comun_cas'] ?? '')),
      'sinonimos' => trim((string) ($row['sinonimos'] ?? '')),
      'descripcion' => (string) ($row['Descripcion'] ?? ''),
      'consejo' => trim((string) ($row['Consejo'] ?? '')),
      'imagen' => $this->cleanPath($row['Imagen'] ?? null),
      'imagen2' => $this->cleanPath($row['Imagen2'] ?? null),
      'exposicion' => $row['exposicion'] === null ? null : (int) $row['exposicion'],
      'exposicion2' => $row['Exposicion2'] === null ? null : (int) $row['Exposicion2'],
      'exposicion3' => $row['Exposicion3'] === null ? null : (int) $row['Exposicion3'],
      'riego' => $row['riego'] === null ? null : (int) $row['riego'],
      'riego2' => $row['Riego2'] === null ? null : (int) $row['Riego2'],
      'porte' => $row['porte'] === null ? null : (int) $row['porte'],
      'hoja' => $row['hoja'] === null ? null : (int) $row['hoja'],
      'hoja2' => $row['Hoja2'] === null ? null : (int) $row['Hoja2'],
      'aromaticas' => $row['aromaticas'] === null ? null : (int) $row['aromaticas'],
      'crecimiento' => $row['Creixement'] === null ? null : (int) $row['Creixement'],
      'floracion' => trim((string) ($row['floracion'] ?? '')),
      'poda' => trim((string) ($row['Poda'] ?? '')),
      'altura' => trim((string) ($row['altura'] ?? '')),
      'anchura' => trim((string) ($row['anchura'] ?? '')),
      'profundidad' => trim((string) ($row['profundidad'] ?? '')),
      'temperatura' => trim((string) ($row['Temperatura'] ?? '')),
    ];
  }

  private function mapApiToRow(array $data): array
  {
    $map = [
      'grupo' => 'grupo',
      'nombreFormatoFicha' => 'NombreFormatoFicha',
      'nombreBotanico' => 'NombreBotanico',
      'nombreComun' => 'comun_cas',
      'sinonimos' => 'sinonimos',
      'descripcion' => 'Descripcion',
      'consejo' => 'Consejo',
      'imagen' => 'Imagen',
      'imagen2' => 'Imagen2',
      'exposicion' => 'exposicion',
      'exposicion2' => 'Exposicion2',
      'exposicion3' => 'Exposicion3',
      'riego' => 'riego',
      'riego2' => 'Riego2',
      'porte' => 'porte',
      'hoja' => 'hoja',
      'hoja2' => 'Hoja2',
      'aromaticas' => 'aromaticas',
      'crecimiento' => 'Creixement',
      'floracion' => 'floracion',
      'poda' => 'Poda',
      'altura' => 'altura',
      'anchura' => 'anchura',
      'profundidad' => 'profundidad',
      'temperatura' => 'Temperatura',
    ];

    $out = [];
    foreach ($map as $api => $col) {
      if (!array_key_exists($api, $data)) {
        continue;
      }
      $val = $data[$api];
      if ($val === '') {
        $val = null;
      }
      if (in_array($api, [
        'grupo', 'exposicion', 'exposicion2', 'exposicion3', 'riego', 'riego2',
        'porte', 'hoja', 'hoja2', 'aromaticas', 'crecimiento',
      ], true) && $val !== null) {
        $val = (int) $val;
      }
      $out[$col] = $val;
    }
    return $out;
  }

  private function cleanPath(mixed $value): string
  {
    $s = trim((string) ($value ?? ''));
    if ($s === '' || strcasecmp($s, 'NULL') === 0) {
      return '';
    }
    return $s;
  }
}
