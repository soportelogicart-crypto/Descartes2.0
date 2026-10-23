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
  private const INNER_ALIAS = 'inner_pag';

  /**
   * Envuelve un SELECT (sin ORDER BY) con ROW_NUMBER() y filtro BETWEEN.
   */
  public static function wrap(string $selectSql, string $orderBy, int $offset, int $limit): string
  {
    $selectSql = trim($selectSql);
    $order = self::normalizarOrderBy($orderBy);
    return "SELECT * FROM (
      SELECT inner_pag.*, ROW_NUMBER() OVER (ORDER BY {$order}) AS __rn
      FROM ({$selectSql}) inner_pag
    ) pag WHERE pag.__rn BETWEEN :pagStart AND :pagEnd";
  }

  /**
   * El ORDER BY se evalua fuera del SELECT interior, donde sus alias de tabla
   * ya no existen: "ORDER BY c.Fecha" produce el error 4104 ("El identificador
   * formado por varias partes no se pudo enlazar"). Cada columna simple se
   * reescribe contra la tabla derivada; las expresiones se dejan intactas.
   */
  private static function normalizarOrderBy(string $orderBy): string
  {
    $terminos = [];
    foreach (explode(',', $orderBy) as $termino) {
      $termino = trim($termino);
      if ($termino === '') {
        continue;
      }
      if (preg_match('/^(?:\[?\w+\]?\.)?\[?(\w+)\]?(?:\s+(ASC|DESC))?$/i', $termino, $m) === 1) {
        $sentido = isset($m[2]) && $m[2] !== '' ? ' ' . strtoupper($m[2]) : '';
        $terminos[] = self::INNER_ALIAS . '.[' . $m[1] . ']' . $sentido;
        continue;
      }
      $terminos[] = $termino;
    }

    return $terminos === [] ? '(SELECT NULL)' : implode(', ', $terminos);
  }

  public static function bind(PDOStatement $stmt, int $offset, int $limit): void
  {
    $stmt->bindValue(':pagStart', $offset + 1, PDO::PARAM_INT);
    $stmt->bindValue(':pagEnd', $offset + $limit, PDO::PARAM_INT);
  }
}
