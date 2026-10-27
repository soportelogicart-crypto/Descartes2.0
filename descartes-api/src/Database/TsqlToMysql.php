<?php

declare(strict_types=1);

namespace Descartes\Api\Database;

/**
 * Traduce el T-SQL que ya escriben los servicios al dialecto de MySQL 8.
 * No se usa cuando el motor es SQL Server.
 */
final class TsqlToMysql
{
  private const FUNCIONES = 'ISNULL|DATEADD|DATEDIFF|DATEPART|DATENAME|CONVERT|CHARINDEX|LEN|GETDATE|SCOPE_IDENTITY|NEWID|DB_NAME|STR|TRY_CAST';

  public static function rewrite(string $sql): string
  {
    if (preg_match('/^\s*SET\s+IDENTITY_INSERT\b/i', $sql) === 1) {
      return '';
    }
    if (preg_match('/^\s*(CREATE|ALTER|DROP|SET|USE)\b/i', $sql) === 1) {
      return $sql;
    }

    $sql = preg_replace("/TABLE_SCHEMA\s*=\s*N?'dbo'/i", 'TABLE_SCHEMA = DATABASE()', $sql) ?? $sql;
    $sql = self::rewriteColLength($sql);
    $sql = self::rewriteObjectId($sql);

    $strings = [];
    $sql = self::protectStrings($sql, $strings);
    $sql = self::rewriteBrackets($sql);
    $sql = preg_replace('/\s+OUTPUT\s+INSERTED\s*\.\s*(?:`[^`]+`|\w+)/i', '', $sql) ?? $sql;
    $sql = preg_replace('/`dbo`\s*\.\s*/i', '', $sql) ?? $sql;
    $sql = preg_replace('/\bdbo\s*\.\s*/i', '', $sql) ?? $sql;
    $sql = preg_replace(
      '/\s+WITH\s*\(\s*(?:NOLOCK|UPDLOCK|ROWLOCK|HOLDLOCK|READPAST|XLOCK|TABLOCK)(?:\s*,\s*(?:NOLOCK|UPDLOCK|ROWLOCK|HOLDLOCK|READPAST|XLOCK|TABLOCK))*\s*\)/i',
      '',
      $sql
    ) ?? $sql;
    $sql = self::rewriteFunctions($sql);
    $sql = self::rewriteCastTypes($sql);
    $sql = self::rewriteTop($sql);
    $sql = preg_replace(
      '/\s+OFFSET\s+(\d+)\s+ROWS\s+FETCH\s+NEXT\s+(\d+)\s+ROWS\s+ONLY\s*;?\s*$/i',
      ' LIMIT $2 OFFSET $1',
      $sql
    ) ?? $sql;
    $sql = str_ireplace('@@SERVERNAME', '@@hostname', $sql);
    return self::restoreStrings($sql, $strings);
  }

  /** @param list<string> $strings */
  private static function protectStrings(string $sql, array &$strings): string
  {
    $out = preg_replace_callback("/N?'(?:''|[^'])*'/i", static function (array $m) use (&$strings): string {
      $lit = $m[0];
      if ($lit[0] === 'N' || $lit[0] === 'n') {
        $lit = substr($lit, 1);
      }
      $id = count($strings);
      $strings[] = $lit;
      return '___S' . $id . '___';
    }, $sql);

    return $out ?? $sql;
  }

  /** @param list<string> $strings */
  private static function restoreStrings(string $sql, array $strings): string
  {
    foreach ($strings as $id => $lit) {
      $sql = str_replace('___S' . $id . '___', $lit, $sql);
    }
    return $sql;
  }

  private static function rewriteBrackets(string $sql): string
  {
    $out = preg_replace_callback('/\[((?:[^\]]|\]\])+)\]/', static function (array $m): string {
      $name = str_replace(']]', ']', $m[1]);
      $name = str_replace('`', '``', $name);
      return '`' . $name . '`';
    }, $sql);

    return $out ?? $sql;
  }

  private static function rewriteColLength(string $sql): string
  {
    $out = preg_replace_callback(
      "/COL_LENGTH\s*\(\s*N?'(?:dbo\.)?([^']*)'\s*,\s*N?'([^']*)'\s*\)/i",
      static function (array $m): string {
        $table = str_replace("''", "'", $m[1]);
        $column = str_replace("''", "'", $m[2]);
        return '(SELECT IF(COUNT(*)>0,1,NULL) FROM information_schema.COLUMNS'
          . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . self::quote($table)
          . ' AND COLUMN_NAME = ' . self::quote($column) . ')';
      },
      $sql
    );

    return $out ?? $sql;
  }

  private static function rewriteObjectId(string $sql): string
  {
    $out = preg_replace_callback(
      "/OBJECT_ID\s*\(\s*N?'(?:dbo\.)?([^']+)'\s*,\s*N?'U'\s*\)\s+IS\s+NOT\s+NULL/i",
      static function (array $m): string {
        return 'EXISTS (' . self::tablaExisteSql($m[1]) . ')';
      },
      $sql
    );
    $out = preg_replace_callback(
      "/OBJECT_ID\s*\(\s*N?'(?:dbo\.)?([^']+)'\s*,\s*N?'U'\s*\)\s+IS\s+NULL/i",
      static function (array $m): string {
        return 'NOT EXISTS (' . self::tablaExisteSql($m[1]) . ')';
      },
      $out ?? $sql
    );
    $out = preg_replace_callback(
      "/OBJECT_ID\s*\(\s*N?'(?:dbo\.)?([^']+)'\s*,\s*N?'U'\s*\)/i",
      static function (array $m): string {
        return '(SELECT COUNT(*) FROM (' . self::tablaExisteSql($m[1]) . ') object_id_hit)';
      },
      $out ?? $sql
    );

    return $out ?? $sql;
  }

  private static function tablaExisteSql(string $table): string
  {
    $table = str_replace("''", "'", $table);
    return 'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '
      . self::quote($table);
  }

  private static function quote(string $value): string
  {
    return "'" . str_replace("'", "''", $value) . "'";
  }

  private static function rewriteFunctions(string $sql): string
  {
    $pattern = '/\b(' . self::FUNCIONES . ')\s*\(/i';
    for ($guard = 0; $guard < 5000; $guard++) {
      $found = preg_match_all($pattern, $sql, $all, PREG_OFFSET_CAPTURE);
      if ($found === false || $found < 1) {
        break;
      }
      $replaced = false;
      foreach ($all[0] as $i => $match) {
        $start = $match[1];
        $name = $all[1][$i][0];
        $paren = $start + strlen($match[0]) - 1;
        $end = self::matchingParen($sql, $paren);
        if ($end < 0) {
          return $sql;
        }
        $inner = substr($sql, $paren + 1, $end - $paren - 1);
        if (preg_match($pattern, $inner) === 1) {
          continue;
        }
        $replacement = self::transformFunction($name, $inner);
        $sql = substr($sql, 0, $start) . $replacement . substr($sql, $end + 1);
        $replaced = true;
        break;
      }
      if (!$replaced) {
        break;
      }
    }

    return $sql;
  }

  private static function transformFunction(string $name, string $inner): string
  {
    $name = strtoupper($name);
    $args = self::splitArgs($inner);
    switch ($name) {
      case 'ISNULL':
        return 'IFNULL(' . ($args[0] ?? 'NULL') . ', ' . ($args[1] ?? 'NULL') . ')';
      case 'GETDATE':
      case 'NEWID':
        return $name === 'NEWID' ? 'UUID()' : 'NOW()';
      case 'SCOPE_IDENTITY':
        return 'LAST_INSERT_ID()';
      case 'DB_NAME':
        return 'DATABASE()';
      case 'STR':
        $expr = $args[0] ?? '0';
        $decimales = trim($args[2] ?? '0');
        if ($decimales === '' || $decimales === '0') {
          return 'CAST(CAST(ROUND(' . $expr . ', 0) AS DECIMAL(20,0)) AS CHAR)';
        }
        return 'TRIM(CAST(ROUND(' . $expr . ', ' . $decimales . ') AS CHAR))';
      case 'LEN':
        return 'CHAR_LENGTH(' . ($args[0] ?? "''") . ')';
      case 'CHARINDEX':
        $haystack = $args[1] ?? "''";
        $needle = $args[0] ?? "''";
        $pos = isset($args[2]) ? ', ' . $args[2] : '';
        return 'LOCATE(' . $needle . ', ' . $haystack . $pos . ')';
      case 'DATEADD':
        $part = self::datePart($args[0] ?? 'day');
        $number = $args[1] ?? '0';
        $date = $args[2] ?? 'NOW()';
        return 'DATE_ADD(' . $date . ', INTERVAL ' . $number . ' ' . $part . ')';
      case 'DATEDIFF':
        $part = self::datePart($args[0] ?? 'day');
        $start = $args[1] ?? 'NOW()';
        $end = $args[2] ?? 'NOW()';
        return 'TIMESTAMPDIFF(' . $part . ', ' . $start . ', ' . $end . ')';
      case 'DATEPART':
        return self::extraerParteFecha($args[0] ?? 'day', $args[1] ?? 'NULL');
      case 'DATENAME':
        $parte = strtolower(trim($args[0] ?? 'weekday', " \t'\""));
        $expr = $args[1] ?? 'NULL';
        if ($parte === 'weekday' || $parte === 'dw') {
          return 'DAYNAME(' . $expr . ')';
        }
        return self::extraerParteFecha($parte, $expr);
      case 'TRY_CAST':
        if (preg_match('/^(.*?)\s+AS\s+int\s*$/i', trim($inner), $m) === 1) {
          $expr = trim($m[1]);
          return "CASE WHEN {$expr} REGEXP '^[0-9]+$' THEN CAST({$expr} AS SIGNED) ELSE NULL END";
        }
        return 'NULL';
      case 'CONVERT':
        return self::rewriteConvert($args);
      default:
        return $name . '(' . $inner . ')';
    }
  }

  /** @param list<string> $args */
  private static function rewriteConvert(array $args): string
  {
    $type = strtolower((string) preg_replace('/\s+/', '', $args[0] ?? ''));
    $expr = $args[1] ?? 'NULL';
    $style = isset($args[2]) ? (int) trim($args[2]) : null;

    if (str_starts_with($type, 'datetime') || $type === 'smalldatetime') {
      return 'CAST(' . $expr . ' AS DATETIME)';
    }
    if ($type === 'date') {
      return 'CAST(' . $expr . ' AS DATE)';
    }
    if ($type === 'time') {
      return 'CAST(' . $expr . ' AS TIME)';
    }
    if (preg_match('/^n?varchar(?:\(\d+\))?$/', $type) === 1) {
      if ($style === 23) {
        return "DATE_FORMAT(" . $expr . ", '%Y-%m-%d')";
      }
      if ($style === 120 || $style === 121 || $style === 20) {
        $formatted = "DATE_FORMAT(" . $expr . ", '%Y-%m-%d %H:%i:%s')";
        // SQL Server recorta CONVERT(varchar(n), fecha, 120). varchar(10) queda en yyyy-mm-dd.
        if (preg_match('/^n?varchar\((\d+)\)$/', $type, $m) === 1 && (int) $m[1] < 19) {
          return 'LEFT(' . $formatted . ', ' . (int) $m[1] . ')';
        }
        return $formatted;
      }
      return 'CAST(' . $expr . ' AS CHAR)';
    }
    if (in_array($type, ['int', 'bigint', 'smallint', 'tinyint'], true)) {
      return 'CAST(' . $expr . ' AS SIGNED)';
    }
    if ($type === 'bit') {
      return 'CAST(' . $expr . ' AS UNSIGNED)';
    }
    if ($type === 'float' || $type === 'real' || $type === 'money' || $type === 'smallmoney') {
      return 'CAST(' . $expr . ' AS DECIMAL(19,4))';
    }
    if (preg_match('/^(?:decimal|numeric)\((\d+),(\d+)\)$/', $type, $m) === 1) {
      return 'CAST(' . $expr . ' AS DECIMAL(' . $m[1] . ',' . $m[2] . '))';
    }

    return 'CAST(' . $expr . ' AS CHAR)';
  }

  private static function extraerParteFecha(string $part, string $expr): string
  {
    $part = strtolower(trim($part, " \t'\""));
    return match ($part) {
      'year', 'yy', 'yyyy' => 'YEAR(' . $expr . ')',
      'quarter', 'qq', 'q' => 'QUARTER(' . $expr . ')',
      'month', 'mm', 'm' => 'MONTH(' . $expr . ')',
      'week', 'wk', 'ww', 'iso_week', 'isowk', 'isoww' => 'WEEK(' . $expr . ', 3)',
      'hour', 'hh' => 'HOUR(' . $expr . ')',
      'minute', 'mi', 'n' => 'MINUTE(' . $expr . ')',
      'second', 'ss', 's' => 'SECOND(' . $expr . ')',
      'weekday', 'dw' => 'DAYOFWEEK(' . $expr . ')',
      default => 'DAY(' . $expr . ')',
    };
  }

  private static function datePart(string $part): string
  {
    $part = strtolower(trim($part));
    return match ($part) {
      'year', 'yy', 'yyyy' => 'YEAR',
      'quarter', 'qq', 'q' => 'QUARTER',
      'month', 'mm', 'm' => 'MONTH',
      'week', 'wk', 'ww' => 'WEEK',
      'hour', 'hh' => 'HOUR',
      'minute', 'mi', 'n' => 'MINUTE',
      'second', 'ss', 's' => 'SECOND',
      default => 'DAY',
    };
  }

  private static function rewriteCastTypes(string $sql): string
  {
    $sql = preg_replace('/\bAS\s+nvarchar\s*\(\s*\d+\s*\)/i', 'AS CHAR', $sql) ?? $sql;
    $sql = preg_replace('/\bAS\s+varchar\s*\(\s*\d+\s*\)/i', 'AS CHAR', $sql) ?? $sql;
    $sql = preg_replace('/\bAS\s+n?varchar\b/i', 'AS CHAR', $sql) ?? $sql;
    $sql = preg_replace('/\bAS\s+money\b/i', 'AS DECIMAL(19,4)', $sql) ?? $sql;
    $sql = preg_replace('/\bAS\s+int\b/i', 'AS SIGNED', $sql) ?? $sql;
    $sql = preg_replace('/\bAS\s+bigint\b/i', 'AS SIGNED', $sql) ?? $sql;
    return preg_replace('/\bAS\s+bit\b/i', 'AS UNSIGNED', $sql) ?? $sql;
  }

  private static function rewriteTop(string $sql): string
  {
    $offset = 0;
    $guard = 0;
    while ($guard++ < 200 && preg_match(
      '/\bSELECT\s+(DISTINCT\s+)?TOP\s*\(?\s*(\d+)\s*\)?(?!\s+PERCENT)/i',
      $sql,
      $m,
      PREG_OFFSET_CAPTURE,
      $offset
    ) === 1) {
      $start = $m[0][1];
      $distinct = $m[1][0] ?? '';
      $limit = $m[2][0];
      $replacement = 'SELECT ' . $distinct;
      $sql = substr($sql, 0, $start) . $replacement . substr($sql, $start + strlen($m[0][0]));
      $from = $start + strlen($replacement);
      $end = self::selectEnd($sql, $from);
      $tail = ' LIMIT ' . $limit;
      $sql = substr($sql, 0, $end) . $tail . substr($sql, $end);
      $offset = $end + strlen($tail);
    }

    return $sql;
  }

  private static function selectEnd(string $sql, int $from): int
  {
    $len = strlen($sql);
    $depth = 0;
    for ($i = $from; $i < $len; $i++) {
      $ch = $sql[$i];
      if ($ch === '(') {
        $depth++;
        continue;
      }
      if ($ch === ')') {
        if ($depth === 0) {
          return $i;
        }
        $depth--;
        continue;
      }
      if ($depth === 0 && preg_match('/\G\bUNION\b/i', $sql, $m, 0, $i) === 1) {
        return $i;
      }
    }

    return $len;
  }

  private static function matchingParen(string $sql, int $open): int
  {
    $len = strlen($sql);
    $depth = 0;
    for ($i = $open; $i < $len; $i++) {
      $ch = $sql[$i];
      if ($ch === '(') {
        $depth++;
      } elseif ($ch === ')') {
        $depth--;
        if ($depth === 0) {
          return $i;
        }
      }
    }

    return -1;
  }

  /** @return list<string> */
  private static function splitArgs(string $inner): array
  {
    $args = [];
    $depth = 0;
    $current = '';
    $len = strlen($inner);
    for ($i = 0; $i < $len; $i++) {
      $ch = $inner[$i];
      if ($ch === '(') {
        $depth++;
      } elseif ($ch === ')') {
        $depth--;
      }
      if ($ch === ',' && $depth === 0) {
        $args[] = trim($current);
        $current = '';
        continue;
      }
      $current .= $ch;
    }
    if (trim($current) !== '' || $args !== []) {
      $args[] = trim($current);
    }

    return $args;
  }
}
