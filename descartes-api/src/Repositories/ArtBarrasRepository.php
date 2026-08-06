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

  /** @return list<array{ean: string, tipo: string, unidades: float}> */
  public function findByArticulo(string $codigo): array
  {
    $stmt = $this->pdo->prepare(
      'SELECT [Ean], [Tipo], [Unidades] FROM [ArtBarras]
       WHERE RTRIM([Codigo]) = :codigo
       ORDER BY [Ean]'
    );
    $stmt->execute(['codigo' => $codigo]);

    $items = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $items[] = [
        'ean' => $this->eanFromSql($row['Ean']),
        'tipo' => trim((string) ($row['Tipo'] ?? '')),
        'unidades' => (float) ($row['Unidades'] ?? 0),
      ];
    }

    return $items;
  }

  /**
   * Sustituye todos los EAN del articulo.
   *
   * @param list<array<string, mixed>> $items
   */
  public function replaceForArticulo(string $codigo, array $items): array
  {
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
      $check = $this->pdo->prepare(
        'SELECT RTRIM([Codigo]) FROM [ArtBarras] WHERE [Ean] = :ean'
      );
      $check->execute(['ean' => $n['eanSql']]);
      $otro = $check->fetchColumn();
      if ($otro !== false && trim((string) $otro) !== $codigo) {
        throw new \InvalidArgumentException(
          "El EAN {$n['ean']} ya pertenece al articulo " . trim((string) $otro)
        );
      }
    }

    $this->pdo->beginTransaction();
    try {
      $delete = $this->pdo->prepare('DELETE FROM [ArtBarras] WHERE RTRIM([Codigo]) = :codigo');
      $delete->execute(['codigo' => $codigo]);

      if ($normalizados !== []) {
        $insert = $this->pdo->prepare(
          'INSERT INTO [ArtBarras] ([Ean], [Codigo], [Tipo], [Unidades], [LUpdate])
           VALUES (:ean, :codigo, :tipo, :unidades, :lupdate)'
        );
        $now = date('Y-m-d H:i:s');
        foreach ($normalizados as $n) {
          $insert->execute([
            'ean' => $n['eanSql'],
            'codigo' => $codigo,
            'tipo' => $n['tipo'] === '' ? ' ' : $n['tipo'],
            'unidades' => $n['unidades'],
            'lupdate' => $now,
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
    if (!preg_match('/^\d{4,18}$/', $s)) {
      throw new \InvalidArgumentException("EAN invalido: {$s}");
    }
    return $s;
  }

  private function eanToSql(string $ean): float
  {
    return (float) $ean;
  }

  private function eanFromSql(mixed $value): string
  {
    if ($value === null || $value === '') {
      return '';
    }
    // Evitar notacion cientifica; EAN caben en float64 enteros.
    return sprintf('%.0f', (float) $value);
  }
}
