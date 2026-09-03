<?php

declare(strict_types=1);

namespace Descartes\Api\Database;

use PDO;
use PDOStatement;

/**
 * Paginacion compatible con SQL Server 2008 R2 (sin OFFSET/FETCH).
 */
final class SqlPagination
{
  /**
   * Envuelve un SELECT (sin ORDER BY) con ROW_NUMBER() y filtro BETWEEN.
   */
  public static function wrap(string $selectSql, string $orderBy, int $offset, int $limit): string
  {
    $selectSql = trim($selectSql);
    return "SELECT * FROM (
      SELECT inner_pag.*, ROW_NUMBER() OVER (ORDER BY {$orderBy}) AS __rn
      FROM ({$selectSql}) inner_pag
    ) pag WHERE pag.__rn BETWEEN :pagStart AND :pagEnd";
  }

  public static function bind(PDOStatement $stmt, int $offset, int $limit): void
  {
    $stmt->bindValue(':pagStart', $offset + 1, PDO::PARAM_INT);
    $stmt->bindValue(':pagEnd', $offset + $limit, PDO::PARAM_INT);
  }
}
