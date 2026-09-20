<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Listados;

/**
 * Filtros SQL compartidos entre informes de listados (legacy Empresa nvarchar(3), etc.).
 */
final class ListadosFiltrosSql
{
  /**
   * @param list<string> $where
   * @param array<string, mixed> $params
   */
  public static function filtroEmpresaTienda(array &$where, array &$params, string $empresa, string $alias = 'c'): void
  {
    $empresa = trim($empresa);
    if ($empresa === '') {
      return;
    }
    $col = "{$alias}.[Empresa]";
    if (preg_match('/^\d+$/', $empresa)) {
      $n = (int) $empresa;
      $plain = (string) $n;
      $padded = str_pad($plain, 3, '0', STR_PAD_LEFT);
      $where[] = "RTRIM({$col}) IN (:empresaNum, :empresaPad)";
      $params['empresaNum'] = $plain;
      $params['empresaPad'] = $padded;

      return;
    }
    $where[] = "RTRIM({$col}) = :empresaStr";
    $params['empresaStr'] = strtoupper($empresa);
  }

  public static function normalizarEmpresaInput(string $raw): string
  {
    $t = trim($raw);
    if ($t === '') {
      return '';
    }
    if (preg_match('/^\d+$/', $t)) {
      return (string) (int) $t;
    }

    return strtoupper($t);
  }

  /**
   * Ventas tipificadas con IVA en cabecera (legacy informe IVA / diario fiscal).
   *
   * @return list<string>
   */
  public static function whereVentasDocumentoIva(string $alias = 'c', bool $soloNumerados = true): array
  {
    $p = $alias . '.';
    $w = [
      'ISNULL(' . $p . '[Anulado], 0) = 0',
      "RTRIM(ISNULL({$p}[Estado], '')) <> 'B'",
      "RTRIM(ISNULL({$p}[FacturaTipo], '')) IN ('T', 'F', 'A')",
    ];
    if ($soloNumerados) {
      $w[] = 'ISNULL(' . $p . '[Factura], 0) > 0';
    }

    return $w;
  }

  /**
   * Rango lexicográfico en columna RTRIM (legacy desde/hasta en listados).
   *
   * @param list<string> $where
   * @param array<string, mixed> $params
   * @param array<string, mixed> $query
   */
  public static function filtroRangoTexto(
    array &$where,
    array &$params,
    string $columnSql,
    array $query,
    string $desdeKey,
    string $hastaKey,
    ?string $singleKey = null,
    ?string $paramPrefix = null
  ): void {
    $desde = trim((string) ($query[$desdeKey] ?? ($singleKey !== null ? ($query[$singleKey] ?? '') : '')));
    $hasta = trim((string) ($query[$hastaKey] ?? ($singleKey !== null ? ($query[$singleKey] ?? '') : '')));
    if ($desde === '' && $hasta === '') {
      return;
    }
    $col = "RTRIM({$columnSql})";
    $pfx = $paramPrefix ?? preg_replace('/[^a-zA-Z0-9_]/', '_', $columnSql);
    if ($desde !== '' && $hasta !== '' && strcasecmp($desde, $hasta) !== 0) {
      $where[] = "{$col} >= :{$pfx}_d AND {$col} <= :{$pfx}_h";
      $params["{$pfx}_d"] = $desde;
      $params["{$pfx}_h"] = $hasta;

      return;
    }
    $v = $desde !== '' ? $desde : $hasta;
    $where[] = "{$col} = :{$pfx}_eq";
    $params["{$pfx}_eq"] = $v;
  }

  /**
   * Última venta del artículo: MAX(fecha cabecera venta) o [Articulos].[FechaUltimaVenta] si no hay ventas.
   */
  public static function sqlFechaUltimaVentaEfectivaArticulo(string $aliasArticulo = 'a'): string
  {
    $cod = 'RTRIM(' . $aliasArticulo . '.[Codigo])';

    return 'COALESCE(
      (SELECT MAX(c.[Fecha])
       FROM [AlbaranesVentasLin] l
       INNER JOIN [AlbaranesVentasCab] c
         ON c.[Empresa] = l.[Empresa] AND c.[Tipo] = l.[Tipo] AND c.[Albaran] = l.[Albaran]
       WHERE RTRIM(l.[Articulo]) = ' . $cod . "
         AND ISNULL(c.[Anulado], 0) = 0
         AND RTRIM(ISNULL(c.[Estado], '')) <> 'B'
         AND RTRIM(UPPER(l.[Articulo])) <> 'NO'),
      {$aliasArticulo}.[FechaUltimaVenta]
    )";
  }

  /**
   * Rango sobre la última venta efectiva (ventas + maestro), alineado con legacy cuando el maestro está al día.
   *
   * @param list<string> $where
   * @param array<string, mixed> $params
   * @param array<string, mixed> $query
   */
  public static function filtroRangoFechaUltimaVentaArticulo(
    array &$where,
    array &$params,
    array $query,
    string $desdeKey = 'ultimaVentaDesde',
    string $hastaKey = 'ultimaVentaHasta'
  ): void {
    self::filtroRangoFecha(
      $where,
      $params,
      self::sqlFechaUltimaVentaEfectivaArticulo('a'),
      $query,
      $desdeKey,
      $hastaKey,
      'ultVenta'
    );
  }

  /**
   * Rango de fechas (día) sobre columna datetime; compara solo la parte fecha.
   *
   * @param list<string> $where
   * @param array<string, mixed> $params
   * @param array<string, mixed> $query
   */
  public static function filtroRangoFecha(
    array &$where,
    array &$params,
    string $columnSql,
    array $query,
    string $desdeKey,
    string $hastaKey,
    ?string $paramPrefix = null
  ): void {
    $desde = self::fechaDiaInput($query[$desdeKey] ?? null);
    $hasta = self::fechaDiaInput($query[$hastaKey] ?? null);
    if ($desde === null && $hasta === null) {
      return;
    }
    if ($desde !== null && $hasta !== null && $desde > $hasta) {
      throw new \InvalidArgumentException("{$desdeKey} no puede ser posterior a {$hastaKey}");
    }
    $col = "CONVERT(date, {$columnSql})";
    $pfx = $paramPrefix ?? preg_replace('/[^a-zA-Z0-9_]/', '_', $columnSql);
    if ($desde !== null && $hasta !== null && $desde !== $hasta) {
      $where[] = "{$col} >= CONVERT(date, :{$pfx}_d, 120) AND {$col} <= CONVERT(date, :{$pfx}_h, 120)";
      $params["{$pfx}_d"] = $desde;
      $params["{$pfx}_h"] = $hasta;

      return;
    }
    $v = $desde ?? $hasta;
    $where[] = "{$col} = CONVERT(date, :{$pfx}_eq, 120)";
    $params["{$pfx}_eq"] = $v;
  }

  /** @param mixed $raw */
  public static function fechaDiaInput($raw): ?string
  {
    if ($raw === null || $raw === '') {
      return null;
    }
    $t = trim((string) $raw);
    if ($t === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $t)) {
      return null;
    }

    return $t;
  }

  /**
   * @param list<string> $where
   * @param array<string, mixed> $params
   * @param array<string, mixed> $query
   */
  public static function filtroRangoEntero(
    array &$where,
    array &$params,
    string $columnSql,
    array $query,
    string $desdeKey,
    string $hastaKey,
    ?string $singleKey = null
  ): void {
    $desde = (int) ($query[$desdeKey] ?? ($singleKey !== null ? ($query[$singleKey] ?? 0) : 0));
    $hasta = (int) ($query[$hastaKey] ?? ($singleKey !== null ? ($query[$singleKey] ?? 0) : 0));
    if ($desde <= 0 && $hasta <= 0) {
      return;
    }
    $pfx = preg_replace('/[^a-zA-Z0-9_]/', '_', $columnSql);
    if ($desde > 0 && $hasta > 0 && $desde !== $hasta) {
      $where[] = "{$columnSql} >= :{$pfx}_d AND {$columnSql} <= :{$pfx}_h";
      $params["{$pfx}_d"] = $desde;
      $params["{$pfx}_h"] = $hasta;

      return;
    }
    $v = $desde > 0 ? $desde : $hasta;
    $where[] = "{$columnSql} = :{$pfx}_eq";
    $params["{$pfx}_eq"] = $v;
  }

  /**
   * Estado contable / LROD sobre alias de [Facturas].
   *
   * @param list<string> $where
   */
  public static function filtroEstadoInformeIva(array &$where, string $estado, string $alias = 'f'): void
  {
    $estado = strtolower(trim($estado));
    if ($estado === '' || $estado === 'todos') {
      return;
    }
    $p = $alias . '.';
    switch ($estado) {
      case 'contabilizados':
        $where[] = 'ISNULL(' . $p . '[TrasCtb], 0) <> 0';
        break;
      case 'no_contabilizados':
        $where[] = 'ISNULL(' . $p . '[TrasCtb], 0) = 0';
        break;
      case 'no_enviadas_lrod':
        $where[] = 'ISNULL(' . $p . '[TrasModem], 0) = 0';
        break;
      case 'menos':
        $where[] = 'ISNULL(' . $p . '[Importe], 0) < 0';
        break;
      case 'mas':
        $where[] = 'ISNULL(' . $p . '[Importe], 0) > 0';
        break;
    }
  }

  /**
   * Combo «Divisa EU» legacy: moneda de la tienda ([Empresas_Ges].Divisa), no forma de pago.
   *
   * @param list<string> $where
   */
  public static function filtroDivisaEuEmpresa(array &$where, string $empresaColumnSql): void
  {
    $col = 'RTRIM(' . $empresaColumnSql . ')';
    $where[] = 'EXISTS (
      SELECT 1 FROM [Empresas_Ges] eg
      WHERE RTRIM(eg.[Codigo]) = ' . $col . "
        AND RTRIM(ISNULL(eg.[Divisa], 'EU')) = 'EU'
    )";
  }

  /**
   * Combo Divisa del informe ABC ventas (EU / PES sobre [Empresas_Ges].Divisa de la tienda).
   *
   * @param list<string> $where
   * @param array<string, mixed> $params
   */
  public static function filtroDivisaAbcVentas(array &$where, array &$params, string $empresaColumnSql, mixed $raw): void
  {
    $divisa = strtoupper(trim((string) ($raw ?? 'EU')));
    if ($divisa === 'EU') {
      self::filtroDivisaEuEmpresa($where, $empresaColumnSql);

      return;
    }
    if ($divisa !== 'PES') {
      return;
    }
    $col = 'RTRIM(' . $empresaColumnSql . ')';
    $where[] = 'EXISTS (
      SELECT 1 FROM [Empresas_Ges] eg
      WHERE RTRIM(eg.[Codigo]) = ' . $col . "
        AND RTRIM(ISNULL(eg.[Divisa], 'EU')) = :abcDivisaTienda
    )";
    $params['abcDivisaTienda'] = 'PES';
  }
}
