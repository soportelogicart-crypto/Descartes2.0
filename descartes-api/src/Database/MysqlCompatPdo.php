<?php

declare(strict_types=1);

namespace Descartes\Api\Database;

use PDO;
use PDOStatement;

/**
 * PDO de MySQL que reescribe el T-SQL de los servicios antes de ejecutarlo.
 */
final class MysqlCompatPdo extends PDO
{
  public function prepare(string $query, array $options = []): PDOStatement|false
  {
    return parent::prepare(TsqlToMysql::rewrite($query), $options);
  }

  public function exec(string $statement): int|false
  {
    $rewritten = TsqlToMysql::rewrite($statement);
    if (trim($rewritten) === '') {
      return 0;
    }
    return parent::exec($rewritten);
  }

  public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
  {
    $query = TsqlToMysql::rewrite($query);
    if ($fetchMode === null) {
      return parent::query($query);
    }

    return parent::query($query, $fetchMode, ...$fetchModeArgs);
  }
}
