<?php

declare(strict_types=1);

namespace Descartes\Api\Repositories;

use PDO;

final class ArtBarrasRepository
{
  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  public function articuloExiste(string $codigo): bool
  {
    $stmt = $this->pdo->prepare('SELECT 1 FROM [Articulos] WHERE RTRIM([Codigo]) = :codigo');
    $stmt->execute(['codigo' => $codigo]);
    return (bool) $stmt->fetchColumn();
  }

  /**
   * Localiza artículo por código, Alternativo o EAN (ArtBarras).
   * Orden: Codigo → Alternativo → EAN.
   *
   * @return array{codigo: string, matchPor: string, unidadesPaquete: float}|null
   */
  public function resolverReferencia(string $query): ?array
  {
    $q = trim($query);
    if ($q === '') {
      return null;
    }

    $stmt = $this->pdo->prepare(
      'SELECT TOP 1 RTRIM([Codigo]) AS Codigo
       FROM [Articulos]
       WHERE RTRIM([Codigo]) = :q'
    );
    $stmt->execute(['q' => $q]);
    $codigo = $stmt->fetchColumn();
    if ($codigo !== false && trim((string) $codigo) !== '') {
      return [
        'codigo' => trim((string) $codigo),
        'matchPor' => 'codigo',
        'unidadesPaquete' => 1.0,
      ];
    }

    $stmt = $this->pdo->prepare(
      'SELECT TOP 1 RTRIM([Codigo]) AS Codigo
       FROM [Articulos]
       WHERE RTRIM(ISNULL([Alternativo], \'\')) = :q
         AND RTRIM(ISNULL([Alternativo], \'\')) <> \'\''
    );
    $stmt->execute(['q' => $q]);
    $codigo = $stmt->fetchColumn();
    if ($codigo !== false && trim((string) $codigo) !== '') {
      return [
        'codigo' => trim((string) $codigo),
        'matchPor' => 'alternativo',
        'unidadesPaquete' => 1.0,
      ];
    }

    $eanDigits = $this->normalizeEanLookup($q);
    if ($eanDigits !== null) {
      $stmt = $this->pdo->prepare(
        "SELECT TOP 1 RTRIM([Codigo]) AS Codigo, ISNULL([Unidades], 0) AS Unidades
         FROM [ArtBarras]
         WHERE LTRIM(RTRIM(STR([Ean], 18, 0))) = :ean"
      );
      $stmt->execute(['ean' => $eanDigits]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      if ($row && trim((string) ($row['Codigo'] ?? '')) !== '') {
        $unidades = (float) ($row['Unidades'] ?? 0);
        return [
          'codigo' => trim((string) $row['Codigo']),
          'matchPor' => 'ean',
          'unidadesPaquete' => $unidades > 0 ? $unidades : 1.0,
        ];
      }
    }

    return null;
  }

  /** Digits-only EAN for lookup; null if not a plausible barcode. */
  private function normalizeEanLookup(string $value): ?string
  {
    $s = trim($value);
    if (preg_match('/^\d+\.0+$/', $s)) {
      $s = explode('.', $s, 2)[0];
    }
    if (!preg_match('/^\d{4,18}$/', $s)) {
      return null;
    }
    return $s;
  }

  /** @return list<array{ean: string, tipo: string, unidades: float}> */
  public function findByArticulo(string $codigo): array
  {
    $stmt = $this->pdo->prepare(
      "SELECT
          CASE
            WHEN [Ean] IS NULL THEN ''
            ELSE LTRIM(RTRIM(STR([Ean], 18, 0)))
          END AS Ean,
          [Tipo],
          [Unidades]
       FROM [ArtBarras]
       WHERE RTRIM([Codigo]) = :codigo
       ORDER BY [Ean]"
    );
    $stmt->execute(['codigo' => $codigo]);

    $items = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $ean = trim((string) ($row['Ean'] ?? ''));
      if ($ean === '' || $ean === '0') {
        continue;
      }
      $items[] = [
        'ean' => $ean,
        'tipo' => trim((string) ($row['Tipo'] ?? '')),
        'unidades' => (float) ($row['Unidades'] ?? 0),
      ];
    }

    return $items;
  }

  /**
   * Sustituye todos los EAN del articulo.
   * Rechaza si algún EAN ya pertenece a otro artículo.
   *
   * @param list<array<string, mixed>> $items
   */
  public function replaceForArticulo(string $codigo, array $items): array
  {
    $codigo = trim($codigo);
    $normalizados = [];
    $vistos = [];
    foreach ($items as $item) {
      $ean = $this->normalizeEanInput($item['ean'] ?? '');
      if ($ean === '') {
        continue;
      }
      if (isset($vistos[$ean])) {
        throw new \InvalidArgumentException("EAN duplicado en la lista: {$ean}");
      }
      $vistos[$ean] = true;
      $normalizados[] = [
        'ean' => $ean,
        'eanSql' => $this->eanToSql($ean),
        'tipo' => substr(trim((string) ($item['tipo'] ?? '')), 0, 1),
        'unidades' => (float) ($item['unidades'] ?? 0),
      ];
    }

    foreach ($normalizados as $n) {
      $otro = $this->codigoPorEan($n['ean']);
      if ($otro !== null && $otro !== $codigo) {
        throw new \InvalidArgumentException(
          "El EAN {$n['ean']} ya está asignado al artículo {$otro}"
        );
      }
    }

    $this->pdo->beginTransaction();
    try {
      $delete = $this->pdo->prepare('DELETE FROM [ArtBarras] WHERE RTRIM([Codigo]) = :codigo');
      $delete->execute(['codigo' => $codigo]);

      if ($normalizados !== []) {
        // GETDATE() evita fallo de conversión nvarchar→datetime con locale es-ES.
        // CONVERT garantiza valor float estable desde dígitos (PK legacy float).
        $insert = $this->pdo->prepare(
          'INSERT INTO [ArtBarras] ([Ean], [Codigo], [Tipo], [Unidades], [LUpdate])
           VALUES (CONVERT(float, :ean), :codigo, :tipo, :unidades, GETDATE())'
        );
        foreach ($normalizados as $n) {
          $insert->execute([
            'ean' => $n['ean'],
            'codigo' => $codigo,
            'tipo' => $n['tipo'] === '' ? ' ' : $n['tipo'],
            'unidades' => $n['unidades'],
          ]);
        }
      }

      $this->pdo->commit();
    } catch (\Throwable $e) {
      if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      throw $e;
    }

    return $this->findByArticulo($codigo);
  }

  /**
   * Quién tiene este EAN, o null si libre.
   */
  public function codigoPorEan(string $ean): ?string
  {
    $digits = $this->normalizeEanLookup(trim($ean));
    if ($digits === null) {
      return null;
    }
    $stmt = $this->pdo->prepare(
      "SELECT TOP 1 RTRIM([Codigo]) AS Codigo FROM [ArtBarras]
       WHERE LTRIM(RTRIM(STR([Ean], 18, 0))) = :ean"
    );
    $stmt->execute(['ean' => $digits]);
    $codigo = $stmt->fetchColumn();
    if ($codigo === false || trim((string) $codigo) === '') {
      return null;
    }
    return trim((string) $codigo);
  }

  private function normalizeEanInput(mixed $value): string
  {
    $s = trim((string) $value);
    if ($s === '') {
      return '';
    }
    // Quitar decimales basura tipo "971....0"
    if (preg_match('/^\d+\.0+$/', $s)) {
      $s = explode('.', $s, 2)[0];
    }
    // Pegados con espacios / guiones
    if (!preg_match('/^\d{4,18}$/', $s) && preg_match('/\d/', $s)) {
      $digits = preg_replace('/\D+/', '', $s) ?? '';
      if (preg_match('/^\d{4,18}$/', $digits)) {
        $s = $digits;
      }
    }
    if (!preg_match('/^\d{4,18}$/', $s)) {
      throw new \InvalidArgumentException("EAN inválido: {$s} (solo dígitos, 4–18)");
    }
    return $s;
  }

  /** Bind como float legacy (columna ArtBarras.Ean es float). */
  private function eanToSql(string $ean): float
  {
    return (float) $ean;
  }

  private function eanFromSql(mixed $value): string
  {
    if ($value === null || $value === '') {
      return '';
    }
    if (is_string($value)) {
      $s = trim($value);
      if (preg_match('/^\d+\.0+$/', $s)) {
        return explode('.', $s, 2)[0];
      }
      if (preg_match('/^\d+$/', $s)) {
        return $s;
      }
    }
    // Evitar notación científica; preferir enteros exactos.
    $f = (float) $value;
    if ($f == 0.0) {
      return '';
    }
    return sprintf('%.0f', $f);
  }
}
