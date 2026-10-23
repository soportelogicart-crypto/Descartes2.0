<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Tpv;

use PDO;

/**
 * Búsqueda de clientes para la caja (006 US6).
 *
 * Endpoint propio del TPV para no exigir el permiso `clientes.ver` de
 * mantenimiento a un rol de caja.
 */
final class TpvClienteService
{
  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  /**
   * Busca por código, NIF, razón social o teléfono.
   *
   * @return list<array<string, mixed>>
   */
  public function buscar(string $query, int $limite = 30): array
  {
    $q = trim($query);
    if ($q === '') {
      throw new \InvalidArgumentException('Indique código, NIF, nombre o teléfono');
    }

    $limite = min(100, max(1, $limite));
    $like = '%' . $q . '%';

    // El driver ODBC no admite reutilizar un parámetro con nombre: uno por posición.
    $st = $this->pdo->prepare(
      "SELECT TOP {$limite}
              RTRIM(Codigo) AS Codigo,
              RTRIM(ISNULL(RazonSocial, '')) AS RazonSocial,
              RTRIM(ISNULL(RazonSocial2, '')) AS RazonSocial2,
              RTRIM(ISNULL(NIF, '')) AS NIF,
              RTRIM(ISNULL(Direccion, '')) AS Direccion,
              RTRIM(ISNULL(Poblacion, '')) AS Poblacion,
              RTRIM(ISNULL(CodigoPostal, '')) AS CodigoPostal,
              RTRIM(ISNULL(Provincia, '')) AS Provincia,
              RTRIM(ISNULL(Pais, '')) AS Pais,
              RTRIM(ISNULL(Telefono1, '')) AS Telefono1,
              RTRIM(ISNULL(NULLIF(EmailFacturacion, ''), ISNULL(Email, ''))) AS Email,
              RTRIM(ISNULL(FormaPago, '')) AS FormaPago,
              ISNULL(Tarifa, 0) AS Tarifa
       FROM Clientes
       WHERE ISNULL(Baja, 0) = 0
         AND (RTRIM(Codigo) = :exacto1
              OR RTRIM(ISNULL(NIF, '')) LIKE :like1
              OR ISNULL(RazonSocial, '') LIKE :like2
              OR ISNULL(RazonSocial2, '') LIKE :like3
              OR ISNULL(Telefono1, '') LIKE :like4)
       ORDER BY CASE WHEN RTRIM(Codigo) = :exacto2 THEN 0 ELSE 1 END, RazonSocial"
    );
    $st->execute([
      'exacto1' => $q,
      'exacto2' => $q,
      'like1' => $like,
      'like2' => $like,
      'like3' => $like,
      'like4' => $like,
    ]);

    $items = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
      $items[] = [
        'codigo' => (string) ($row['Codigo'] ?? ''),
        'razonSocial' => (string) ($row['RazonSocial'] ?? ''),
        'razonSocial2' => (string) ($row['RazonSocial2'] ?? ''),
        'nif' => (string) ($row['NIF'] ?? ''),
        'direccion' => (string) ($row['Direccion'] ?? ''),
        'poblacion' => (string) ($row['Poblacion'] ?? ''),
        'codigoPostal' => (string) ($row['CodigoPostal'] ?? ''),
        'provincia' => (string) ($row['Provincia'] ?? ''),
        'pais' => (string) ($row['Pais'] ?? ''),
        'telefono' => (string) ($row['Telefono1'] ?? ''),
        'email' => (string) ($row['Email'] ?? ''),
        'formaPago' => (string) ($row['FormaPago'] ?? ''),
        'tarifa' => (int) ($row['Tarifa'] ?? 0),
      ];
    }

    return $items;
  }
}
