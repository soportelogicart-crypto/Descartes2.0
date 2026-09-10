<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Facturacion;

use Descartes\Api\Database\SqlPagination;
use PDO;

final class AlbaranesPeriodicosConsultaService
{
  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  /**
   * @param array<string, mixed> $query
   * @return array{items: list<array<string, mixed>>, total: int, page: int, pageSize: int}
   */
  public function listar(array $query): array
  {
    $page = max(1, (int) ($query['page'] ?? 1));
    $pageSize = min(5000, max(1, (int) ($query['pageSize'] ?? 25)));
    $offset = ($page - 1) * $pageSize;

    $where = ['1=1'];
    $params = [];

    $empresa = trim((string) ($query['empresa'] ?? ''));
    if ($empresa !== '') {
      $where[] = 'RTRIM(p.Empresa) = :empresa';
      $params['empresa'] = $empresa;
    }

    $cliente = trim((string) ($query['cliente'] ?? ''));
    if ($cliente !== '') {
      $where[] = 'RTRIM(a.Cliente) LIKE :cliente';
      $params['cliente'] = '%' . $cliente . '%';
    }

    $q = trim((string) ($query['q'] ?? ''));
    if ($q !== '') {
      $like = '%' . $q . '%';
      $where[] = '(a.Cliente LIKE :qCliente OR a.RazonSocial LIKE :qRazon OR CAST(p.Albaran AS varchar(20)) LIKE :qAlbaran)';
      $params['qCliente'] = $like;
      $params['qRazon'] = $like;
      $params['qAlbaran'] = $like;
    }

    $whereSql = implode(' AND ', $where);
    $fromJoin = 'FROM AlbaranesPeriodicos p
      LEFT JOIN AlbaranesVentasCab a
        ON a.Empresa = p.Empresa AND a.Tipo = p.Tipo AND a.Albaran = p.Albaran';

    $countStmt = $this->pdo->prepare("SELECT COUNT(*) {$fromJoin} WHERE {$whereSql}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $innerSql = "SELECT p.Empresa, p.Tipo, p.Albaran, p.Periodicidad, p.UltimaGeneracion,
        a.Cliente, a.RazonSocial, a.Importe, a.Referencia1,
        CASE WHEN a.Albaran IS NULL THEN 0 ELSE 1 END AS PlantillaEncontrada
      {$fromJoin}
      WHERE {$whereSql}";

    $orderBy = 'Empresa ASC, Albaran DESC, Tipo ASC';
    $sql = SqlPagination::wrap($innerSql, $orderBy, $offset, $pageSize);
    $stmt = $this->pdo->prepare($sql);
    foreach ($params as $k => $v) {
      $stmt->bindValue(':' . $k, $v);
    }
    SqlPagination::bind($stmt, $offset, $pageSize);
    $stmt->execute();

    $items = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $items[] = $this->mapListItem($row);
    }

    return [
      'items' => $items,
      'total' => $total,
      'page' => $page,
      'pageSize' => $pageSize,
    ];
  }

  public function obtener(string $empresa, string $tipo, int $albaran): ?array
  {
    $stmt = $this->pdo->prepare(
      'SELECT p.Empresa, p.Tipo, p.Albaran, p.Periodicidad, p.UltimaGeneracion,
        a.Cliente, a.RazonSocial, a.Importe, a.Referencia1,
        CASE WHEN a.Albaran IS NULL THEN 0 ELSE 1 END AS PlantillaEncontrada
       FROM AlbaranesPeriodicos p
       LEFT JOIN AlbaranesVentasCab a
         ON a.Empresa = p.Empresa AND a.Tipo = p.Tipo AND a.Albaran = p.Albaran
       WHERE RTRIM(p.Empresa) = :e AND RTRIM(p.Tipo) = :t AND p.Albaran = :a'
    );
    $stmt->execute([
      'e' => trim($empresa),
      't' => trim($tipo),
      'a' => $albaran,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      return null;
    }

    return $this->mapListItem($row);
  }

  /**
   * @param array<string, mixed> $row
   * @return array<string, mixed>
   */
  public function mapListItem(array $row): array
  {
    $periodicidad = (int) ($row['Periodicidad'] ?? 0);
    $ultimaRaw = $row['UltimaGeneracion'] ?? null;
    $ultimaIso = $this->formatDateTime($ultimaRaw);

    return [
      'empresa' => trim((string) ($row['Empresa'] ?? '')),
      'tipo' => trim((string) ($row['Tipo'] ?? '')),
      'albaran' => (int) ($row['Albaran'] ?? 0),
      'periodicidad' => $periodicidad,
      'periodicidadLabel' => self::periodicidadLabel($periodicidad),
      'ultimaGeneracion' => $ultimaIso,
      'proximaGeneracion' => self::calcularProximaGeneracion($ultimaIso, $periodicidad),
      'plantillaEncontrada' => ((int) ($row['PlantillaEncontrada'] ?? 0)) === 1,
      'cliente' => trim((string) ($row['Cliente'] ?? '')),
      'razonSocial' => trim((string) ($row['RazonSocial'] ?? '')),
      'importePlantilla' => round((float) ($row['Importe'] ?? 0), 2),
      'referencia1' => $this->nullableString($row['Referencia1'] ?? null),
    ];
  }

  public static function periodicidadLabel(int $periodicidad): string
  {
    if ($periodicidad <= 0) {
      return '';
    }
    if ($periodicidad % 30 === 0) {
      $meses = (int) ($periodicidad / 30);
      return match ($meses) {
        1 => 'Mensual',
        2 => 'Bimestral',
        3 => 'Trimestral',
        6 => 'Semestral',
        12 => 'Anual',
        default => $meses . ' meses',
      };
    }

    return $periodicidad . ' días';
  }

  public static function calcularProximaGeneracion(?string $ultimaGeneracion, int $periodicidad): ?string
  {
    if ($ultimaGeneracion === null || $periodicidad <= 0) {
      return null;
    }

    try {
      $ultima = new \DateTimeImmutable($ultimaGeneracion);
    } catch (\Throwable $e) {
      return null;
    }

    if ($periodicidad % 30 === 0) {
      $meses = (int) ($periodicidad / 30);
      $proxima = $ultima->modify("+{$meses} months");
    } else {
      $proxima = $ultima->modify("+{$periodicidad} days");
    }

    return $proxima->format('Y-m-d H:i:s');
  }

  private function formatDateTime(mixed $value): ?string
  {
    if ($value === null || $value === '') {
      return null;
    }
    try {
      return (new \DateTimeImmutable(is_string($value) ? $value : (string) $value))
        ->format('Y-m-d H:i:s');
    } catch (\Throwable $e) {
      return null;
    }
  }

  private function nullableString(mixed $value): ?string
  {
    if ($value === null) {
      return null;
    }
    $s = trim((string) $value);

    return $s === '' ? null : $s;
  }
}
