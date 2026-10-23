<?php

declare(strict_types=1);

namespace Descartes\Api\Repositories;

use PDO;

final class CodigoPostalRepository
{
  /** @var array<string, string> */
  private const PROVINCIAS_ES = [
    '01' => 'Álava',
    '02' => 'Albacete',
    '03' => 'Alicante',
    '04' => 'Almería',
    '05' => 'Ávila',
    '06' => 'Badajoz',
    '07' => 'Illes Balears',
    '08' => 'Barcelona',
    '09' => 'Burgos',
    '10' => 'Cáceres',
    '11' => 'Cádiz',
    '12' => 'Castellón',
    '13' => 'Ciudad Real',
    '14' => 'Córdoba',
    '15' => 'A Coruña',
    '16' => 'Cuenca',
    '17' => 'Girona',
    '18' => 'Granada',
    '19' => 'Guadalajara',
    '20' => 'Gipuzkoa',
    '21' => 'Huelva',
    '22' => 'Huesca',
    '23' => 'Jaén',
    '24' => 'León',
    '25' => 'Lleida',
    '26' => 'La Rioja',
    '27' => 'Lugo',
    '28' => 'Madrid',
    '29' => 'Málaga',
    '30' => 'Murcia',
    '31' => 'Navarra',
    '32' => 'Ourense',
    '33' => 'Asturias',
    '34' => 'Palencia',
    '35' => 'Las Palmas',
    '36' => 'Pontevedra',
    '37' => 'Salamanca',
    '38' => 'Santa Cruz de Tenerife',
    '39' => 'Cantabria',
    '40' => 'Segovia',
    '41' => 'Sevilla',
    '42' => 'Soria',
    '43' => 'Tarragona',
    '44' => 'Teruel',
    '45' => 'Toledo',
    '46' => 'Valencia',
    '47' => 'Valladolid',
    '48' => 'Bizkaia',
    '49' => 'Zamora',
    '50' => 'Zaragoza',
    '51' => 'Ceuta',
    '52' => 'Melilla',
  ];

  private PDO $pdo;

  /** @var array<string, string>|null */
  private static ?array $poblacionesBundled = null;

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
    $fromDb = $this->findPoblacionesDb($cp);
    if ($fromDb !== []) {
      return $fromDb;
    }

    $bundled = $this->bundledPoblaciones();
    $names = [];
    foreach ($this->cpCandidates($cp) as $candidate) {
      $key = ctype_digit($candidate) ? str_pad($candidate, 5, '0', STR_PAD_LEFT) : $candidate;
      $nombre = $bundled[$key] ?? $bundled[$candidate] ?? null;
      if (is_string($nombre) && $nombre !== '' && !in_array($nombre, $names, true)) {
        $names[] = $nombre;
      }
    }
    return $names;
  }

  /** @return list<string> */
  private function findPoblacionesDb(string $cp): array
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

    try {
      $sql = 'SELECT DISTINCT LTRIM(RTRIM([Nombre])) AS Nombre
              FROM [Poblaciones]
              WHERE LTRIM(RTRIM([Codigo])) IN (' . implode(', ', $placeholders) . ')
                AND [Nombre] IS NOT NULL
                AND LTRIM(RTRIM([Nombre])) <> \'\'
              ORDER BY Nombre';
      $stmt = $this->pdo->prepare($sql);
      $stmt->execute($params);
    } catch (\Throwable) {
      return [];
    }

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
    $padded = strlen($codigo) < 2 ? str_pad($codigo, 2, '0', STR_PAD_LEFT) : $codigo;
    $candidates = array_values(array_unique(array_filter([
      $padded,
      ltrim($codigo, '0') !== '' ? ltrim($codigo, '0') : null,
    ])));
    foreach ($candidates as $candidate) {
      try {
        $stmt = $this->pdo->prepare(
          'SELECT LTRIM(RTRIM([Nombre])) AS Nombre
           FROM [Provincias]
           WHERE LTRIM(RTRIM([Codigo])) = :codigo'
        );
        $stmt->execute(['codigo' => $candidate]);
        $nombre = $stmt->fetchColumn();
        if ($nombre !== false && $nombre !== null && trim((string) $nombre) !== '') {
          return trim((string) $nombre);
        }
      } catch (\Throwable) {
        break;
      }
    }

    return self::PROVINCIAS_ES[$padded] ?? self::PROVINCIAS_ES[$codigo] ?? null;
  }

  /** @return array<string, string> */
  private function bundledPoblaciones(): array
  {
    if (self::$poblacionesBundled !== null) {
      return self::$poblacionesBundled;
    }
    $path = dirname(__DIR__) . '/Data/codigos-postales-es.json';
    if (!is_file($path)) {
      self::$poblacionesBundled = [];
      return self::$poblacionesBundled;
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    self::$poblacionesBundled = is_array($decoded) ? $decoded : [];
    return self::$poblacionesBundled;
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
    $digits = preg_replace('/\D+/', '', $cp) ?? '';
    $candidates = [$cp];
    if ($digits !== '') {
      $candidates[] = $digits;
    }
    if (ctype_digit($digits) && strlen($digits) < 5) {
      $candidates[] = str_pad($digits, 5, '0', STR_PAD_LEFT);
    }
    if (ctype_digit($digits) && strlen($digits) === 5 && str_starts_with($digits, '0')) {
      $trimmed = ltrim($digits, '0');
      if ($trimmed !== '') {
        $candidates[] = $trimmed;
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
