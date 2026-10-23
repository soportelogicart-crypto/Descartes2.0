<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Facturacion;

use PDO;

/**
 * Resuelve la configuración contable dentro de la base unificada activa.
 * Gestión y contabilidad comparten conexión y transacción en LOGIA.
 */
final class ConexionContableService
{
  private PDO $gestion;

  public function __construct(PDO $gestion)
  {
    $this->gestion = $gestion;
  }

  /**
   * @return array{pdo: PDO, serie: string, serieAbono: string, serieDiferida: string,
   *   serieAbonoDiferida: string, apunteDiario: bool}
   */
  public function paraEmpresa(string $empresa): array
  {
    $stmt = $this->gestion->prepare(
      'SELECT ContaPropia, Serie, SerieA, SerieDif, SerieADif, ApunteDiario
       FROM Parametros WHERE Empresa = :empresa'
    );
    $stmt->execute(['empresa' => $empresa]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      throw new \RuntimeException("No existe configuración contable para la tienda {$empresa}", 409);
    }
    if (empty($row['ContaPropia'])) {
      throw new \RuntimeException("La tienda {$empresa} no tiene contabilidad propia activada", 409);
    }
    $faltan = $this->faltanTablasContabilidad();
    if ($faltan !== 0) {
      throw new \RuntimeException(
        'La base de datos activa no contiene todas las tablas de contabilidad integradas',
        409
      );
    }

    return [
      'pdo' => $this->gestion,
      'serie' => $this->serie($row['Serie'] ?? null, 'facturas'),
      'serieAbono' => $this->serie($row['SerieA'] ?? null, 'abonos'),
      'serieDiferida' => $this->serie($row['SerieDif'] ?? $row['Serie'] ?? null, 'facturas diferidas'),
      'serieAbonoDiferida' => $this->serie(
        $row['SerieADif'] ?? $row['SerieA'] ?? null,
        'abonos diferidos'
      ),
      'apunteDiario' => !empty($row['ApunteDiario']),
    ];
  }

  private function faltanTablasContabilidad(): int
  {
    if ($this->gestion->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql') {
      $faltan = $this->gestion->query(
        "SELECT COUNT(*) FROM (
           SELECT 'AsientosCab' AS nombre
           UNION ALL SELECT 'AsientosLin'
           UNION ALL SELECT 'FactEmitidasCab'
           UNION ALL SELECT 'FactEmitidasLin'
           UNION ALL SELECT 'EfectosCobro'
           UNION ALL SELECT 'Cuentas'
           UNION ALL SELECT 'Series'
         ) requeridas
         WHERE NOT EXISTS (
           SELECT 1 FROM information_schema.TABLES t
           WHERE t.TABLE_SCHEMA = DATABASE()
             AND LOWER(t.TABLE_NAME) = LOWER(requeridas.nombre)
         )"
      )->fetchColumn();

      return (int) $faltan;
    }

    $faltan = $this->gestion->query(
      "SELECT COUNT(*) FROM (VALUES
         ('AsientosCab'), ('AsientosLin'), ('FactEmitidasCab'),
         ('FactEmitidasLin'), ('EfectosCobro'), ('Cuentas'), ('Series')
       ) AS requeridas(nombre)
       WHERE OBJECT_ID('dbo.' + requeridas.nombre, 'U') IS NULL"
    )->fetchColumn();

    return (int) $faltan;
  }

  private function serie(mixed $valor, string $tipo): string
  {
    $serie = trim((string) $valor);
    if ($serie === '' || mb_strlen($serie) > 3) {
      throw new \RuntimeException("Serie contable de {$tipo} no configurada", 409);
    }
    return $serie;
  }
}
