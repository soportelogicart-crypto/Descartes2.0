<?php

declare(strict_types=1);

namespace Descartes\Api\Repositories;

use PDO;

final class CodigoPostalRepository
{
  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  /**
   * @return array{
   *   codigoPostal: string,
   *   poblacion: ?string,
   *   provincia: ?string,
   *   provinciaCodigo: ?string,
   *   poblaciones: list<string>
   * }
   */
  public function lookup(string $codigoPostal): array
  {
    $cp = $this->normalizeCp($codigoPostal);
    $poblaciones = $cp === '' ? [] : $this->findPoblaciones($cp);
    $provinciaCodigo = $this->provinciaCodigoFromCp($cp);
    $provincia = $provinciaCodigo !== null ? $this->findProvinciaNombre($provinciaCodigo) : null;

    return [
      'codigoPostal' => $cp,
      'poblacion' => $poblaciones[0] ?? null,
      'provincia' => $provincia,
      'provinciaCodigo' => $provinciaCodigo,
      'poblaciones' => $poblaciones,
    ];
  }

  /** @return list<string> */
  private function findPoblaciones(string $cp): array
  {
    $candidates = $this->cpCandidates($cp);
    if ($candidates === []) {
      return [];
    }

    $placeholders = [];
    $params = [];
    foreach ($candidates as $i => $candidate) {
      $key = 'cp' . $i;
      $placeholders[] = ':' . $key;
      $params[$key] = $candidate;
    }

    $sql = 'SELECT DISTINCT LTRIM(RTRIM([Nombre])) AS Nombre
            FROM [Poblaciones]
            WHERE LTRIM(RTRIM([Codigo])) IN (' . implode(', ', $placeholders) . ')
              AND [Nombre] IS NOT NULL
              AND LTRIM(RTRIM([Nombre])) <> \'\'
            ORDER BY Nombre';
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);

    $names = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $name = (string) ($row['Nombre'] ?? '');
      if ($name !== '' && !in_array($name, $names, true)) {
        $names[] = $name;
      }
    }
    return $names;
  }

  private function findProvinciaNombre(string $codigo): ?string
  {
    $stmt = $this->pdo->prepare(
      'SELECT LTRIM(RTRIM([Nombre])) AS Nombre
       FROM [Provincias]
       WHERE LTRIM(RTRIM([Codigo])) = :codigo'
    );
    $stmt->execute(['codigo' => $codigo]);
    $nombre = $stmt->fetchColumn();
    if ($nombre === false || $nombre === null || trim((string) $nombre) === '') {
      return null;
    }
    return trim((string) $nombre);
  }

  private function normalizeCp(string $codigoPostal): string
  {
    return preg_replace('/\s+/', '', trim($codigoPostal)) ?? '';
  }

  /** @return list<string> */
  private function cpCandidates(string $cp): array
  {
    if ($cp === '') {
      return [];
    }
    $candidates = [$cp];
    if (ctype_digit($cp) && strlen($cp) < 5) {
      $candidates[] = str_pad($cp, 5, '0', STR_PAD_LEFT);
    }
    if (ctype_digit($cp) && strlen($cp) === 5 && str_starts_with($cp, '0')) {
      $candidates[] = ltrim($cp, '0');
      if ($candidates[count($candidates) - 1] === '') {
        array_pop($candidates);
      }
    }
    return array_values(array_unique($candidates));
  }

  private function provinciaCodigoFromCp(string $cp): ?string
  {
    $digits = preg_replace('/\D+/', '', $cp) ?? '';
    if (strlen($digits) < 2) {
      return null;
    }
    if (strlen($digits) < 5) {
      $digits = str_pad($digits, 5, '0', STR_PAD_LEFT);
    }
    return substr($digits, 0, 2);
  }
}
