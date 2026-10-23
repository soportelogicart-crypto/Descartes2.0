<?php

declare(strict_types=1);

namespace Descartes\Api\Services;

use Descartes\Api\Repositories\ArtPreciosRepository;
use Descartes\Api\Repositories\ArticuloStockRepository;
use PDO;

final class ArticuloService
{
  private PDO $pdo;
  private ArtPreciosRepository $artPreciosRepository;
  private ArticuloStockRepository $articuloStockRepository;

  public function __construct(
    PDO $pdo,
    ArtPreciosRepository $artPreciosRepository,
    ArticuloStockRepository $articuloStockRepository
  ) {
    $this->pdo = $pdo;
    $this->artPreciosRepository = $artPreciosRepository;
    $this->articuloStockRepository = $articuloStockRepository;
  }

  public function enrich(array $item): array
  {
    if (!isset($item['codigo'])) {
      return $item;
    }

    $codigo = (string) $item['codigo'];
    $item['precios'] = $this->artPreciosRepository->findByArticulo($codigo);
    $item['stock'] = $this->articuloStockRepository->findByArticulo($codigo);

    $familia = trim((string) ($item['familia'] ?? ''));
    if ($familia !== '' && trim((string) ($item['macroFamilia'] ?? '')) === '') {
      $stmt = $this->pdo->prepare(
        'SELECT RTRIM(ISNULL([MacroFamilia], \'\')) FROM [Familias] WHERE RTRIM([Codigo]) = :codigo'
      );
      $stmt->execute(['codigo' => $familia]);
      $macro = $stmt->fetchColumn();
      if ($macro !== false && $macro !== null && trim((string) $macro) !== '') {
        $item['macroFamilia'] = trim((string) $macro);
      }
    }

    return $item;
  }

  /**
   * Enriquece un listado con las mismas consultas que enrich(), agrupadas.
   *
   * @param list<array<string, mixed>> $items
   * @return list<array<string, mixed>>
   */
  public function enrichMany(array $items): array
  {
    $codigos = [];
    $familias = [];
    foreach ($items as $item) {
      if (isset($item['codigo']) && (string) $item['codigo'] !== '') {
        $codigos[] = (string) $item['codigo'];
      }
      $familia = trim((string) ($item['familia'] ?? ''));
      if ($familia !== '' && trim((string) ($item['macroFamilia'] ?? '')) === '') {
        $familias[$familia] = $familia;
      }
    }

    $precios = $this->artPreciosRepository->findByArticulos($codigos);
    $stock = $this->articuloStockRepository->findByArticulos($codigos);
    $macros = $this->macroFamilias($familias);

    foreach ($items as $i => $item) {
      if (!isset($item['codigo'])) {
        continue;
      }
      $codigo = (string) $item['codigo'];
      $item['precios'] = $precios[$codigo] ?? [];
      $item['stock'] = $stock[$codigo] ?? [];
      $familia = trim((string) ($item['familia'] ?? ''));
      if ($familia !== '' && trim((string) ($item['macroFamilia'] ?? '')) === '') {
        $macro = $macros[$familia] ?? '';
        if ($macro !== '') {
          $item['macroFamilia'] = $macro;
        }
      }
      $items[$i] = $item;
    }

    return $items;
  }

  /**
   * @param array<string, string> $familias
   * @return array<string, string>
   */
  private function macroFamilias(array $familias): array
  {
    $codigos = array_values($familias);
    $out = [];
    foreach (array_chunk($codigos, 400) as $chunk) {
      if ($chunk === []) {
        continue;
      }
      $placeholders = [];
      $params = [];
      foreach ($chunk as $i => $codigo) {
        $clave = 'f' . $i;
        $placeholders[] = ':' . $clave;
        $params[$clave] = $codigo;
      }
      $stmt = $this->pdo->prepare(
        'SELECT RTRIM([Codigo]) AS Codigo, RTRIM(ISNULL([MacroFamilia], \'\')) AS MacroFamilia
         FROM [Familias]
         WHERE RTRIM([Codigo]) IN (' . implode(', ', $placeholders) . ')'
      );
      $stmt->execute($params);
      while ($row = $stmt->fetch()) {
        $codigo = trim((string) ($row['Codigo'] ?? ''));
        $macro = trim((string) ($row['MacroFamilia'] ?? ''));
        if ($codigo !== '' && $macro !== '' && !isset($out[$codigo])) {
          $out[$codigo] = $macro;
        }
      }
    }
    return $out;
  }

  public function saveExtras(string $codigo, array $data): void
  {
    if (array_key_exists('precios', $data) && is_array($data['precios'])) {
      $this->artPreciosRepository->replaceForArticulo($codigo, $data['precios']);
    }
  }

  public function assertCodigoUnico(string $codigo, ?string $excluir = null): void
  {
    $sql = 'SELECT 1 FROM [Articulos] WHERE [Codigo] = :codigo';
    $params = ['codigo' => $codigo];
    if ($excluir !== null) {
      $sql .= ' AND [Codigo] <> :excluir';
      $params['excluir'] = $excluir;
    }
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);
    if ($stmt->fetch()) {
      throw new \InvalidArgumentException('Ya existe un articulo con ese codigo');
    }
  }
}
