<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Facturacion;

use Descartes\Api\Config\Database;
use PDO;

/**
 * Resuelve la base contable configurada por tienda en Parametros.FicheroCtb.
 *
 * Para TipoBD=2 el legacy guarda "servidor\base". Se reutilizan las
 * credenciales de la instalación, pero nunca se fija una base contable en código.
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
      'SELECT ContaPropia, FicheroCtb, TipoBD, Serie, SerieA, SerieDif, SerieADif, ApunteDiario
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
    if ((int) ($row['TipoBD'] ?? 0) !== 2) {
      throw new \RuntimeException(
        "El destino contable de la tienda {$empresa} no es SQL Server (TipoBD=2)",
        409
      );
    }

    [$server, $database] = $this->parseDestino((string) ($row['FicheroCtb'] ?? ''));
    $config = Database::resolveConfig();
    if ($server !== '') {
      $config['server'] = $server;
    }
    $config['database'] = $database;

    return [
      'pdo' => Database::createPdo($config),
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

  /** @return array{0: string, 1: string} */
  private function parseDestino(string $valor): array
  {
    $valor = trim(str_replace('/', '\\', $valor));
    if ($valor === '') {
      throw new \RuntimeException('FicheroCtb no está configurado', 409);
    }

    $partes = array_values(array_filter(explode('\\', $valor), static fn ($p) => $p !== ''));
    $database = (string) array_pop($partes);
    $server = $partes !== [] ? implode('\\', $partes) : '';
    if (!preg_match('/^[A-Za-z0-9_.-]+$/', $database)) {
      throw new \RuntimeException('El nombre de la base contable configurada no es válido', 409);
    }
    return [$server, $database];
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
