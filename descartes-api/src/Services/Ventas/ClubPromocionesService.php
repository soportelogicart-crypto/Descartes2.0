<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Ventas;

use PDO;

final class ClubPromocionesService
{
  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  /**
   * @return list<array<string, mixed>>
   */
  public function listar(string $empresa): array
  {
    $st = $this->pdo->prepare(
      'SELECT Id, Nombre, PjeDescuento, FechaInicio, FechaFin, FiltrosJson, Activa
       FROM ClubPromociones WHERE RTRIM(Empresa) = :e ORDER BY FechaInicio DESC'
    );
    $st->execute(['e' => trim($empresa)]);
    $out = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $out[] = $this->fila($row);
    }

    return $out;
  }

  /**
   * @param array<string, mixed> $body
   * @return array<string, mixed>
   */
  public function guardar(string $empresa, array $body): array
  {
    $empresa = trim($empresa);
    if ($empresa === '') {
      throw new \InvalidArgumentException('La empresa es obligatoria');
    }
    $nombre = trim((string) ($body['nombre'] ?? ''));
    if ($nombre === '') {
      throw new \InvalidArgumentException('El nombre es obligatorio');
    }
    $pje = (float) ($body['pjeDescuento'] ?? 0);
    if ($pje <= 0 || $pje > 100) {
      throw new \InvalidArgumentException('El porcentaje debe estar entre 0 y 100');
    }
    $inicio = trim((string) ($body['fechaInicio'] ?? ''));
    $fin = trim((string) ($body['fechaFin'] ?? ''));
    if ($inicio === '' || $fin === '') {
      throw new \InvalidArgumentException('Las fechas son obligatorias');
    }
    $activa = !empty($body['activa']) ? 1 : 0;
    $filtros = $body['filtros'] ?? null;
    $filtrosJson = $filtros === null ? null : json_encode($filtros, JSON_UNESCAPED_UNICODE);
    $id = (int) ($body['id'] ?? 0);
    if ($id > 0) {
      $this->pdo->prepare(
        'UPDATE ClubPromociones SET Nombre = :n, PjeDescuento = :p, FechaInicio = :i, FechaFin = :f,
         FiltrosJson = :fj, Activa = :a WHERE Id = :id AND RTRIM(Empresa) = :e'
      )->execute([
        'n' => $nombre,
        'p' => $pje,
        'i' => $inicio,
        'f' => $fin,
        'fj' => $filtrosJson,
        'a' => $activa,
        'id' => $id,
        'e' => $empresa,
      ]);
    } else {
      $this->pdo->prepare(
        'INSERT INTO ClubPromociones (Empresa, Nombre, PjeDescuento, FechaInicio, FechaFin, FiltrosJson, Activa)
         VALUES (:e, :n, :p, :i, :f, :fj, :a)'
      )->execute([
        'e' => $empresa,
        'n' => $nombre,
        'p' => $pje,
        'i' => $inicio,
        'f' => $fin,
        'fj' => $filtrosJson,
        'a' => $activa,
      ]);
      $id = (int) $this->pdo->lastInsertId();
    }
    $st = $this->pdo->prepare('SELECT * FROM ClubPromociones WHERE Id = :id');
    $st->execute(['id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row !== false ? $this->fila($row) : ['id' => $id];
  }

  public function eliminar(string $empresa, int $id): void
  {
    $this->pdo->prepare(
      'DELETE FROM ClubPromociones WHERE Id = :id AND RTRIM(Empresa) = :e'
    )->execute(['id' => $id, 'e' => trim($empresa)]);
  }

  /**
   * @param array<string, mixed> $row
   * @return array<string, mixed>
   */
  private function fila(array $row): array
  {
    $fj = $row['FiltrosJson'] ?? null;
    $filtros = is_string($fj) ? json_decode($fj, true) : null;

    return [
      'id' => (int) ($row['Id'] ?? 0),
      'nombre' => (string) ($row['Nombre'] ?? ''),
      'pjeDescuento' => (float) ($row['PjeDescuento'] ?? 0),
      'fechaInicio' => (string) ($row['FechaInicio'] ?? ''),
      'fechaFin' => (string) ($row['FechaFin'] ?? ''),
      'filtros' => is_array($filtros) ? $filtros : null,
      'activa' => (int) ($row['Activa'] ?? 0) !== 0,
    ];
  }
}
