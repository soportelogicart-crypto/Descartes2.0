<?php

declare(strict_types=1);

namespace Descartes\Api\Repositories;

use PDO;

final class ProveedoresContactosRepository
{
  /** Valor legacy observado en Num para contactos de cliente. */
  private const NUM = 'C';

  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  public function proveedorExiste(string $codigo): bool
  {
    $stmt = $this->pdo->prepare('SELECT 1 FROM [Proveedores] WHERE [Codigo] = :codigo');
    $stmt->execute(['codigo' => $codigo]);
    return (bool) $stmt->fetchColumn();
  }

  /** @return array{nif: ?string}|null */
  public function datosProveedor(string $codigo): ?array
  {
    $stmt = $this->pdo->prepare(
      'SELECT LTRIM(RTRIM([NIF])) AS NIF
       FROM [Proveedores] WHERE [Codigo] = :codigo'
    );
    $stmt->execute(['codigo' => $codigo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
      return null;
    }
    $nif = isset($row['NIF']) && trim((string) $row['NIF']) !== '' ? trim((string) $row['NIF']) : null;
    return ['nif' => $nif];
  }

  /** @return list<array<string, mixed>> */
  public function findByProveedor(string $codigo): array
  {
    $stmt = $this->pdo->prepare(
      'SELECT [Proveedor], [Num], [NroLin], [PersonaContacto], [NIF], [Direccion], [LUpdate],
              [Departamento], [Mail], [Telefono], [Observaciones], [EnvioFacturas], [EnvioPropaganda]
       FROM [ProveedoresContactos]
       WHERE [Proveedor] = :codigo
       ORDER BY [NroLin]'
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
    $cliente = $this->datosProveedor($codigo);
    if ($cliente === null) {
      throw new \InvalidArgumentException('Proveedor no encontrado');
    }

    $lUpdate = date('Y-m-d H:i:s');
    $stmt = $this->pdo->prepare(
      'INSERT INTO [ProveedoresContactos]
        ([Proveedor], [Num], [PersonaContacto], [NIF], [Direccion], [LUpdate],
         [Departamento], [Mail], [Telefono], [Observaciones], [EnvioFacturas], [EnvioPropaganda])
       OUTPUT INSERTED.[NroLin]
       VALUES
        (:proveedor, :num, :personaContacto, :nif, :direccion, CONVERT(datetime, :lUpdate, 120),
         :departamento, :mail, :telefono, :observaciones, :envioFacturas, :envioPropaganda)'
    );
    $stmt->execute([
      'proveedor' => $codigo,
      'num' => self::NUM,
      'personaContacto' => $this->str($data['nombre'] ?? null, 50),
      'nif' => $this->str($cliente['nif'], 16),
      'direccion' => null,
      'lUpdate' => $lUpdate,
      'departamento' => $this->str($data['departamento'] ?? null, 30),
      'mail' => $this->str($data['email'] ?? null, 50),
      'telefono' => $this->str($data['telefono'] ?? null, 15),
      'observaciones' => $this->str($data['observaciones'] ?? null, 50),
      'envioFacturas' => !empty($data['facturas']) ? 1 : 0,
      'envioPropaganda' => !empty($data['comercial']) ? 1 : 0,
    ]);

    $nroLin = (int) $stmt->fetchColumn();
    $created = $this->findOne($codigo, self::NUM, $nroLin);
    if ($created === null) {
      throw new \RuntimeException('No se pudo leer el contacto creado');
    }
    return $created;
  }

  /** @param array<string, mixed> $data */
  public function update(string $codigo, string $num, int $nroLin, array $data): ?array
  {
    $num = $this->normalizeNum($num);
    $current = $this->findOne($codigo, $num, $nroLin);
    if ($current === null) {
      return null;
    }

    $cliente = $this->datosProveedor($codigo);
    $lUpdate = date('Y-m-d H:i:s');

    $stmt = $this->pdo->prepare(
      'UPDATE [ProveedoresContactos] SET
        [PersonaContacto] = :personaContacto,
        [NIF] = :nif,
        [Direccion] = :direccion,
        [LUpdate] = CONVERT(datetime, :lUpdate, 120),
        [Departamento] = :departamento,
        [Mail] = :mail,
        [Telefono] = :telefono,
        [Observaciones] = :observaciones,
        [EnvioFacturas] = :envioFacturas,
        [EnvioPropaganda] = :envioPropaganda
       WHERE [Proveedor] = :proveedor AND [Num] = :num AND [NroLin] = :nroLin'
    );
    $stmt->execute([
      'personaContacto' => $this->str($data['nombre'] ?? $current['nombre'] ?? null, 50),
      'nif' => $this->str($cliente['nif'] ?? $current['nif'] ?? null, 16),
      'direccion' => null,
      'lUpdate' => $lUpdate,
      'departamento' => $this->str($data['departamento'] ?? $current['departamento'] ?? null, 30),
      'mail' => $this->str($data['email'] ?? $current['email'] ?? null, 50),
      'telefono' => $this->str($data['telefono'] ?? $current['telefono'] ?? null, 15),
      'observaciones' => $this->str($data['observaciones'] ?? $current['observaciones'] ?? null, 50),
      'envioFacturas' => array_key_exists('facturas', $data)
        ? (!empty($data['facturas']) ? 1 : 0)
        : (!empty($current['facturas']) ? 1 : 0),
      'envioPropaganda' => array_key_exists('comercial', $data)
        ? (!empty($data['comercial']) ? 1 : 0)
        : (!empty($current['comercial']) ? 1 : 0),
      'proveedor' => $codigo,
      'num' => $num,
      'nroLin' => $nroLin,
    ]);

    return $this->findOne($codigo, $num, $nroLin);
  }

  public function delete(string $codigo, string $num, int $nroLin): bool
  {
    $num = $this->normalizeNum($num);
    $stmt = $this->pdo->prepare(
      'DELETE FROM [ProveedoresContactos]
       WHERE [Proveedor] = :proveedor AND [Num] = :num AND [NroLin] = :nroLin'
    );
    $stmt->execute([
      'proveedor' => $codigo,
      'num' => $num,
      'nroLin' => $nroLin,
    ]);
    return $stmt->rowCount() > 0;
  }

  public function findOne(string $codigo, string $num, int $nroLin): ?array
  {
    $num = $this->normalizeNum($num);
    $stmt = $this->pdo->prepare(
      'SELECT [Proveedor], [Num], [NroLin], [PersonaContacto], [NIF], [Direccion], [LUpdate],
              [Departamento], [Mail], [Telefono], [Observaciones], [EnvioFacturas], [EnvioPropaganda]
       FROM [ProveedoresContactos]
       WHERE [Proveedor] = :proveedor AND [Num] = :num AND [NroLin] = :nroLin'
    );
    $stmt->execute([
      'proveedor' => $codigo,
      'num' => $num,
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
      'proveedor' => (string) $row['Proveedor'],
      'num' => (string) $row['Num'],
      'nroLin' => (int) $row['NroLin'],
      'nombre' => $row['PersonaContacto'],
      'nif' => $row['NIF'],
      'direccion' => $row['Direccion'],
      'lUpdate' => $lUpdate,
      'departamento' => $row['Departamento'],
      'email' => $row['Mail'],
      'telefono' => $row['Telefono'],
      'observaciones' => $row['Observaciones'],
      'facturas' => (bool) $row['EnvioFacturas'],
      'comercial' => (bool) $row['EnvioPropaganda'],
    ];
  }

  private function normalizeNum(string $num): string
  {
    $n = strtoupper(trim($num));
    return $n !== '' ? $n : self::NUM;
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

