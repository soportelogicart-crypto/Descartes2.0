<?php

declare(strict_types=1);

namespace Descartes\Api\Services;

use Descartes\Api\Repositories\DocumentoPlantillasRepository;

final class DocumentoPlantillasService
{
  private DocumentoPlantillasRepository $repository;

  public function __construct(DocumentoPlantillasRepository $repository)
  {
    $this->repository = $repository;
  }

  /**
   * @return list<array<string, mixed>>
   */
  public function listar(?string $empresaCodigo, ?string $tipo): array
  {
    $empresa = $empresaCodigo !== null ? $this->normEmpresa($empresaCodigo) : null;
    $items = $this->repository->list($empresa, $tipo);
    return array_map([$this, 'mapListItem'], $items);
  }

  public function obtener(int $id): ?array
  {
    $row = $this->repository->find($id);
    return $row === null ? null : $this->mapFull($row);
  }

  /**
   * @param array<string, mixed> $body
   */
  public function crear(array $body): array
  {
    $empresa = $this->normEmpresa((string) ($body['empresaCodigo'] ?? ''));
    $tipo = trim((string) ($body['tipo'] ?? ''));
    $nombre = trim((string) ($body['nombre'] ?? ''));
    $definicion = $body['definicion'] ?? null;

    if ($tipo === '') {
      throw new \InvalidArgumentException('tipo es obligatorio');
    }
    if ($nombre === '') {
      throw new \InvalidArgumentException('nombre es obligatorio');
    }
    if (!is_array($definicion)) {
      throw new \InvalidArgumentException('definicion debe ser un objeto JSON');
    }

    $json = json_encode($definicion, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
      throw new \InvalidArgumentException('definicion no es JSON valido');
    }

    $activa = !empty($body['activa']);
    if ($activa || $this->repository->countByEmpresaTipo($empresa, $tipo) === 0) {
      $this->repository->clearActiva($empresa, $tipo);
      $activa = true;
    }

    $id = $this->repository->insert([
      'empresaCodigo' => $empresa,
      'tipo' => $tipo,
      'nombre' => $nombre,
      'descripcion' => $this->nullIfEmpty((string) ($body['descripcion'] ?? '')),
      'version' => max(1, (int) ($body['version'] ?? 1)),
      'activa' => $activa,
      'definicion' => $json,
    ]);

    $item = $this->obtener($id);
    if ($item === null) {
      throw new \RuntimeException('No se pudo leer la plantilla creada');
    }
    return $item;
  }

  /**
   * @param array<string, mixed> $body
   */
  public function actualizar(int $id, array $body): array
  {
    $actual = $this->repository->find($id);
    if ($actual === false || $actual === null) {
      throw new \RuntimeException('Plantilla no encontrada');
    }

    $nombre = array_key_exists('nombre', $body)
      ? trim((string) $body['nombre'])
      : trim((string) $actual['Nombre']);
    if ($nombre === '') {
      throw new \InvalidArgumentException('nombre es obligatorio');
    }

    $descripcion = array_key_exists('descripcion', $body)
      ? $this->nullIfEmpty((string) $body['descripcion'])
      : ($actual['Descripcion'] !== null ? (string) $actual['Descripcion'] : null);

    $version = array_key_exists('version', $body)
      ? max(1, (int) $body['version'])
      : ((int) $actual['Version'] + 1);

    $definicionJson = (string) $actual['Definicion'];
    if (array_key_exists('definicion', $body)) {
      if (!is_array($body['definicion'])) {
        throw new \InvalidArgumentException('definicion debe ser un objeto JSON');
      }
      $encoded = json_encode($body['definicion'], JSON_UNESCAPED_UNICODE);
      if ($encoded === false) {
        throw new \InvalidArgumentException('definicion no es JSON valido');
      }
      $definicionJson = $encoded;
    }

    $activa = array_key_exists('activa', $body)
      ? !empty($body['activa'])
      : !empty($actual['Activa']);

    if ($activa) {
      $this->repository->clearActiva((string) $actual['EmpresaCodigo'], (string) $actual['Tipo']);
    }

    $this->repository->update($id, [
      'nombre' => $nombre,
      'descripcion' => $descripcion,
      'version' => $version,
      'activa' => $activa,
      'definicion' => $definicionJson,
    ]);

    $item = $this->obtener($id);
    if ($item === null) {
      throw new \RuntimeException('Plantilla no encontrada tras actualizar');
    }
    return $item;
  }

  public function eliminar(int $id): void
  {
    $actual = $this->repository->find($id);
    if ($actual === null) {
      throw new \RuntimeException('Plantilla no encontrada');
    }
    $this->repository->delete($id);
  }

  public function activar(int $id): array
  {
    $actual = $this->repository->find($id);
    if ($actual === null) {
      throw new \RuntimeException('Plantilla no encontrada');
    }
    $this->repository->setActiva($id);
    $item = $this->obtener($id);
    if ($item === null) {
      throw new \RuntimeException('Plantilla no encontrada');
    }
    return $item;
  }

  /**
   * Si no hay plantillas para la empresa, inserta esqueletos base.
   *
   * @param list<array<string, mixed>> $skeletons
   * @return list<array<string, mixed>>
   */
  public function sembrar(string $empresaCodigo, array $skeletons): array
  {
    $empresa = $this->normEmpresa($empresaCodigo);
    $existentes = $this->repository->list($empresa, null);
    if ($existentes !== []) {
      return $this->listar($empresa, null);
    }

    foreach ($skeletons as $sk) {
      if (!is_array($sk)) {
        continue;
      }
      $this->crear([
        'empresaCodigo' => $empresa,
        'tipo' => (string) ($sk['tipo'] ?? ''),
        'nombre' => (string) ($sk['nombre'] ?? 'Plantilla'),
        'descripcion' => (string) ($sk['descripcion'] ?? ''),
        'version' => (int) ($sk['version'] ?? 1),
        'activa' => true,
        'definicion' => $sk,
      ]);
    }

    return $this->listar($empresa, null);
  }

  /**
   * @param array<string, mixed> $row
   * @return array<string, mixed>
   */
  private function mapListItem(array $row): array
  {
    return $this->mapFull($row);
  }

  /**
   * @param array<string, mixed> $row
   * @return array<string, mixed>
   */
  private function mapFull(array $row): array
  {
    $definicion = json_decode((string) $row['Definicion'], true);
    if (!is_array($definicion)) {
      $definicion = [];
    }
    return [
      'id' => (int) $row['Id'],
      'empresaCodigo' => trim((string) $row['EmpresaCodigo']),
      'tipo' => (string) $row['Tipo'],
      'nombre' => (string) $row['Nombre'],
      'descripcion' => $row['Descripcion'] !== null ? (string) $row['Descripcion'] : null,
      'version' => (int) $row['Version'],
      'activa' => !empty($row['Activa']),
      'definicion' => $definicion,
      'creado' => $row['Creado'] ?? null,
      'actualizado' => $row['Actualizado'] ?? null,
    ];
  }

  private function normEmpresa(string $codigo): string
  {
    return strtoupper(trim($codigo));
  }

  private function nullIfEmpty(string $value): ?string
  {
    $v = trim($value);
    return $v === '' ? null : $v;
  }
}
