<?php

declare(strict_types=1);

namespace Descartes\Api\Repositories;

use PDO;

final class ClientesDireccionesRepository
{
  private const TIPOS = ['E', 'F'];

  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  public function clienteExiste(string $codigo): bool
  {
    $stmt = $this->pdo->prepare('SELECT 1 FROM [Clientes] WHERE [Codigo] = :codigo');
    $stmt->execute(['codigo' => $codigo]);
    return (bool) $stmt->fetchColumn();
  }

  /** @return list<array<string, mixed>> */
  public function findByCliente(string $codigo): array
  {
    $stmt = $this->pdo->prepare(
      'SELECT [Cliente], [Tipo], [NroLin], [PersonaContacto], [Direccion], [Poblacion],
              [CodigoPostal], [Provincia], [Pais], [Email], [Telefono1], [Telefono2],
              [Fax], [LUpdate], [Departamento], [Portes]
       FROM [ClientesDirecciones]
       WHERE [Cliente] = :codigo
       ORDER BY [Tipo], [NroLin]'
    );
    $stmt->execute(['codigo' => $codigo]);

    $items = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $items[] = $this->mapRow($row);
    }
    return $items;
  }

  /** @param array<string, mixed> $data */
  public function create(string $codigo, array $data): array
  {
    $tipo = $this->normalizeTipo($data['tipo'] ?? null);
    $lUpdate = date('Y-m-d H:i:s');

    $stmt = $this->pdo->prepare(
      'INSERT INTO [ClientesDirecciones]
        ([Cliente], [Tipo], [PersonaContacto], [Direccion], [Poblacion], [CodigoPostal],
         [Provincia], [Pais], [Email], [Telefono1], [Telefono2], [Fax], [LUpdate], [Departamento], [Portes])
       OUTPUT INSERTED.[NroLin]
       VALUES
        (:cliente, :tipo, :personaContacto, :direccion, :poblacion, :codigoPostal,
         :provincia, :pais, :email, :telefono1, :telefono2, :fax, CONVERT(datetime, :lUpdate, 120),
         :departamento, :portes)'
    );
    $stmt->execute([
      'cliente' => $codigo,
      'tipo' => $tipo,
      'personaContacto' => $this->str($data['contacto'] ?? null, 50),
      'direccion' => $this->str($data['direccion'] ?? null, 50),
      'poblacion' => $this->str($data['poblacion'] ?? null, 50),
      'codigoPostal' => $this->str($data['codigoPostal'] ?? null, 8),
      'provincia' => $this->str($data['provincia'] ?? null, 50),
      'pais' => $this->str($data['pais'] ?? null, 50),
      'email' => $this->str($data['email'] ?? null, 50),
      'telefono1' => $this->str($data['telefono1'] ?? null, 15),
      'telefono2' => $this->str($data['telefono2'] ?? null, 15),
      'fax' => $this->str($data['fax'] ?? null, 15),
      'lUpdate' => $lUpdate,
      'departamento' => $this->str($data['departamento'] ?? null, 50),
      'portes' => $this->str($data['portes'] ?? null, 1),
    ]);

    $nroLin = (int) $stmt->fetchColumn();
    $created = $this->findOne($codigo, $tipo, $nroLin);
    if ($created === null) {
      throw new \RuntimeException('No se pudo leer la direccion creada');
    }
    return $created;
  }

  /** @param array<string, mixed> $data */
  public function update(string $codigo, string $tipo, int $nroLin, array $data): ?array
  {
    $tipo = $this->normalizeTipo($tipo);
    $current = $this->findOne($codigo, $tipo, $nroLin);
    if ($current === null) {
      return null;
    }

    $nuevoTipo = array_key_exists('tipo', $data)
      ? $this->normalizeTipo($data['tipo'])
      : $tipo;
    $lUpdate = date('Y-m-d H:i:s');

    $stmt = $this->pdo->prepare(
      'UPDATE [ClientesDirecciones] SET
        [Tipo] = :nuevoTipo,
        [PersonaContacto] = :personaContacto,
        [Direccion] = :direccion,
        [Poblacion] = :poblacion,
        [CodigoPostal] = :codigoPostal,
        [Provincia] = :provincia,
        [Pais] = :pais,
        [Email] = :email,
        [Telefono1] = :telefono1,
        [Telefono2] = :telefono2,
        [Fax] = :fax,
        [LUpdate] = CONVERT(datetime, :lUpdate, 120),
        [Departamento] = :departamento,
        [Portes] = :portes
       WHERE [Cliente] = :cliente AND [Tipo] = :tipo AND [NroLin] = :nroLin'
    );

    $stmt->execute([
      'nuevoTipo' => $nuevoTipo,
      'personaContacto' => $this->str($data['contacto'] ?? $current['contacto'] ?? null, 50),
      'direccion' => $this->str($data['direccion'] ?? $current['direccion'] ?? null, 50),
      'poblacion' => $this->str($data['poblacion'] ?? $current['poblacion'] ?? null, 50),
      'codigoPostal' => $this->str($data['codigoPostal'] ?? $current['codigoPostal'] ?? null, 8),
      'provincia' => $this->str($data['provincia'] ?? $current['provincia'] ?? null, 50),
      'pais' => $this->str($data['pais'] ?? $current['pais'] ?? null, 50),
      'email' => $this->str($data['email'] ?? $current['email'] ?? null, 50),
      'telefono1' => $this->str($data['telefono1'] ?? $current['telefono1'] ?? null, 15),
      'telefono2' => $this->str($data['telefono2'] ?? $current['telefono2'] ?? null, 15),
      'fax' => $this->str($data['fax'] ?? $current['fax'] ?? null, 15),
      'lUpdate' => $lUpdate,
      'departamento' => $this->str($data['departamento'] ?? $current['departamento'] ?? null, 50),
      'portes' => $this->str($data['portes'] ?? $current['portes'] ?? null, 1),
      'cliente' => $codigo,
      'tipo' => $tipo,
      'nroLin' => $nroLin,
    ]);

    return $this->findOne($codigo, $nuevoTipo, $nroLin);
  }

  public function delete(string $codigo, string $tipo, int $nroLin): bool
  {
    $tipo = $this->normalizeTipo($tipo);
    $stmt = $this->pdo->prepare(
      'DELETE FROM [ClientesDirecciones]
       WHERE [Cliente] = :cliente AND [Tipo] = :tipo AND [NroLin] = :nroLin'
    );
    $stmt->execute([
      'cliente' => $codigo,
      'tipo' => $tipo,
      'nroLin' => $nroLin,
    ]);
    return $stmt->rowCount() > 0;
  }

  public function findOne(string $codigo, string $tipo, int $nroLin): ?array
  {
    $tipo = $this->normalizeTipo($tipo);
    $stmt = $this->pdo->prepare(
      'SELECT [Cliente], [Tipo], [NroLin], [PersonaContacto], [Direccion], [Poblacion],
              [CodigoPostal], [Provincia], [Pais], [Email], [Telefono1], [Telefono2],
              [Fax], [LUpdate], [Departamento], [Portes]
       FROM [ClientesDirecciones]
       WHERE [Cliente] = :cliente AND [Tipo] = :tipo AND [NroLin] = :nroLin'
    );
    $stmt->execute([
      'cliente' => $codigo,
      'tipo' => $tipo,
      'nroLin' => $nroLin,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $this->mapRow($row) : null;
  }

  /** @param array<string, mixed> $row */
  private function mapRow(array $row): array
  {
    $lUpdate = $row['LUpdate'] ?? null;
    if ($lUpdate instanceof \DateTimeInterface) {
      $lUpdate = $lUpdate->format('Y-m-d H:i:s');
    } elseif (is_string($lUpdate) && $lUpdate !== '') {
      $lUpdate = preg_replace('/\.\d+$/', '', str_replace('T', ' ', $lUpdate));
    } else {
      $lUpdate = null;
    }

    return [
      'cliente' => (string) $row['Cliente'],
      'tipo' => (string) $row['Tipo'],
      'nroLin' => (int) $row['NroLin'],
      'contacto' => $row['PersonaContacto'],
      'direccion' => $row['Direccion'],
      'poblacion' => $row['Poblacion'],
      'codigoPostal' => $row['CodigoPostal'],
      'provincia' => $row['Provincia'],
      'pais' => $row['Pais'],
      'email' => $row['Email'],
      'telefono1' => $row['Telefono1'],
      'telefono2' => $row['Telefono2'],
      'fax' => $row['Fax'],
      'lUpdate' => $lUpdate,
      'departamento' => $row['Departamento'],
      'portes' => $row['Portes'],
    ];
  }

  private function normalizeTipo(mixed $tipo): string
  {
    $t = strtoupper(trim((string) $tipo));
    if (!in_array($t, self::TIPOS, true)) {
      throw new \InvalidArgumentException('Tipo de direccion no valido (E=envio, F=facturas)');
    }
    return $t;
  }

  private function normalizeDateTime(mixed $value): ?string
  {
    if ($value === null || $value === '') {
      return null;
    }
    $s = trim((string) $value);
    $s = str_replace('T', ' ', $s);
    $s = preg_replace('/(Z|[+-]\d{2}:?\d{2})$/', '', $s) ?? $s;
    $s = preg_replace('/\.\d+$/', '', trim($s)) ?? $s;

    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:\s+(\d{2}):(\d{2})(?::(\d{2}))?)?$/', $s, $m)) {
      $h = isset($m[4]) ? (int) $m[4] : 0;
      $i = isset($m[5]) ? (int) $m[5] : 0;
      $sec = isset($m[6]) ? (int) $m[6] : 0;
      return sprintf('%04d-%02d-%02d %02d:%02d:%02d', (int) $m[1], (int) $m[2], (int) $m[3], $h, $i, $sec);
    }

    $ts = strtotime($s);
    return $ts === false ? null : date('Y-m-d H:i:s', $ts);
  }

  private function str(mixed $value, int $maxLen): ?string
  {
    if ($value === null) {
      return null;
    }
    $s = trim((string) $value);
    if ($s === '') {
      return null;
    }
    return mb_substr($s, 0, $maxLen);
  }
}
