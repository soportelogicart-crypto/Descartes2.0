<?php

declare(strict_types=1);

namespace Descartes\Api\Services;

use Descartes\Api\Repositories\ConfigEquipoRepository;
use PDO;

final class ConfigEquipoService
{
  private ConfigEquipoRepository $repository;
  private PDO $pdo;

  public function __construct(ConfigEquipoRepository $repository, PDO $pdo)
  {
    $this->repository = $repository;
    $this->pdo = $pdo;
  }

  public function obtener(string $equipoId): ?array
  {
    $id = $this->normalizarEquipoId($equipoId);
    if ($id === null) {
      throw new \InvalidArgumentException('El identificador de equipo no es valido');
    }

    $row = $this->repository->find($id);
    if ($row === null) {
      return null;
    }

    return $this->mapRow($row);
  }

  public function guardar(string $equipoId, array $data): array
  {
    $id = $this->normalizarEquipoId($equipoId);
    if ($id === null) {
      throw new \InvalidArgumentException('El identificador de equipo no es valido');
    }

    $empresa = trim((string) ($data['empresaCodigo'] ?? ''));
    $puesto = trim((string) ($data['puestoCodigo'] ?? ''));

    if ($empresa === '') {
      throw new \InvalidArgumentException('La empresa es obligatoria');
    }
    if ($puesto === '') {
      throw new \InvalidArgumentException('El puesto es obligatorio');
    }

    if (!$this->existeEmpresa($empresa)) {
      throw new \InvalidArgumentException("No existe la empresa {$empresa}");
    }
    if (!$this->existePuesto($puesto)) {
      throw new \InvalidArgumentException("No existe el puesto {$puesto}");
    }

    $this->repository->upsert($id, $empresa, $puesto);

    return $this->obtener($id) ?? [
      'equipoId' => $id,
      'empresaCodigo' => $empresa,
      'puestoCodigo' => $puesto,
    ];
  }

  private function mapRow(array $row): array
  {
    return [
      'equipoId' => trim((string) $row['EquipoId']),
      'empresaCodigo' => trim((string) $row['EmpresaCodigo']),
      'puestoCodigo' => trim((string) $row['PuestoCodigo']),
      'actualizado' => $row['Actualizado'] ?? null,
    ];
  }

  private function normalizarEquipoId(string $equipoId): ?string
  {
    $id = strtoupper(trim($equipoId));
    if ($id === '' || mb_strlen($id) > 50) {
      return null;
    }
    if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{0,49}$/', $id)) {
      return null;
    }
    return $id;
  }

  private function existeEmpresa(string $codigo): bool
  {
    $stmt = $this->pdo->prepare(
      'SELECT 1 FROM [Empresas]
       WHERE RTRIM([Codigo]) = RTRIM(:codigo)
         AND EXISTS (
           SELECT 1 FROM [Parametros] p
           WHERE RTRIM(p.[Empresa]) = RTRIM([Empresas].[Codigo])
         )'
    );
    $stmt->execute(['codigo' => $codigo]);
    return (bool) $stmt->fetchColumn();
  }

  private function existePuesto(string $codigo): bool
  {
    $stmt = $this->pdo->prepare(
      'SELECT 1 FROM [Puestos] WHERE RTRIM([Puesto]) = RTRIM(:codigo)'
    );
    $stmt->execute(['codigo' => $codigo]);
    return (bool) $stmt->fetchColumn();
  }
}
