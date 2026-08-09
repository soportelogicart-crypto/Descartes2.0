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
