<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Compras;

use Descartes\Api\Services\Listados\ListadosFiltrosSql;
use InvalidArgumentException;
use PDO;

/**
 * Listado ABC Compras (Crystal ComprasAbc* / DVCOMPRASABC).
 * Albaranes de compra agregados por dimensión + detalle de artículos.
 */
final class AbcComprasService
{
  /** @var list<string> */
  private const DIMENSIONES = [
    'macrofamilias',
    'subfamilias',
    'familias',
    'articulos',
    'agrupaciones',
    'proveedores',
    'secciones',
    'subsecciones',
    'almacenes',
  ];

  private const ORDENES = ['margen', 'importe', 'cantidad', 'coste'];

  /** @var list<string> */
  private const DIMENSIONES_ORDEN_MARGEN = [
    'secciones',
    'subsecciones',
    'proveedores',
    'agrupaciones',
    'articulos',
    'almacenes',
  ];

  /** @var list<string> */
  private const DIMENSIONES_JERARQUIA = ['macrofamilias', 'subfamilias', 'familias'];

  private const VALORES = ['precioMedio', 'precioMedioActual', 'ultimoPrecio', 'sinValorTarifa'];

  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  /** @param array<string, mixed> $query */
  public function generar(array $query): array
  {
    $dimension = strtolower(trim((string) ($query['dimension'] ?? 'familias')));
    if (!in_array($dimension, self::DIMENSIONES, true)) {
      throw new InvalidArgumentException(
        'Dimension no soportada. Disponibles: ' . implode(', ', self::DIMENSIONES)
      );
    }

    $ordenesValidas = $this->ordenesValidasParaDimension($dimension);
    $ordenDefault = $this->ordenDefectoParaDimension($dimension);
    $orden = strtolower(trim((string) ($query['orden'] ?? $ordenDefault)));
    if (!in_array($orden, $ordenesValidas, true)) {
      $orden = $ordenDefault;
    }

    $valor = $this->normalizarValorCoste($query['valor'] ?? 'precioMedio');

    $imArticulos = $this->normalizarImArticulos($query['imArticulos'] ?? 'si');
    if ($dimension === 'articulos') {
      $imArticulos = 'si';
    }
    $imprimeLineasArticulo = $imArticulos !== 'no';

    $formatoJerarquia = null;
    if ($dimension === 'familias') {
      $formatoJerarquia = $this->normalizarFormatoJerarquia($query['formatoJerarquia'] ?? 'normal');
    } elseif ($dimension === 'subfamilias') {
      $formatoJerarquia = $this->normalizarFormatoSubfamilias($query['formatoJerarquia'] ?? 'normal');
    }

    $fechaDesdeDia = $this->fechaDia($query['fechaDesde'] ?? null);
    $fechaHastaDia = $this->fechaDia($query['fechaHasta'] ?? null);

    [$where, $params] = $this->buildWhere($query);

    $sign = '(CASE WHEN ISNULL(c.[AlbaranDevolucion], 0) = 0 THEN 1.0 ELSE -1.0 END)';
    $qty = "(ISNULL(l.[Cantidad], 0) * {$sign})";
    $precio = 'ISNULL(l.[Precio], 0)';
    $pjeDto = 'ISNULL(l.[PjeDto], 0)';
    $bruto = "({$qty} * {$precio})";
    $dtoExpr = "({$bruto} * {$pjeDto} / 100.0)";
    $importeExpr = "({$bruto} - ({$bruto} * {$pjeDto} / 100.0))";

    $costeBase = $this->costeExpression($valor);
    $costeExpr = "({$costeBase} * {$qty})";

    $albaranKey = "RTRIM(c.[Empresa]) + N'|' + CAST(c.[Albaran] AS nvarchar(20))";

    $unidadesExpr = "SUM({$qty})";
    if ($formatoJerarquia === 'pesoKilogramos') {
      $unidadesExpr = "SUM({$qty} * ISNULL(a.[PesoEnKilos], 0))";
    }

    $dim = $this->dimensionSql($dimension);
    if ($dimension === 'articulos') {
      $groupByParts = $dim['groupBy'];
    } else {
      $groupByParts = [...$dim['groupBy'], "RTRIM(ISNULL(l.[Articulo], ''))"];
    }
    $groupBy = implode(', ', $groupByParts);

    $metaSubfamiliaSelect = $dimension === 'subfamilias'
      ? ",
              MAX(RTRIM(ISNULL(a.[Familia], ''))) AS metaFamiliaCodigo,
              MAX(RTRIM(ISNULL(f.[Descripcion], ''))) AS metaFamiliaNombre,
              MAX(RTRIM(ISNULL(f.[MacroFamilia], ''))) AS metaMacroCodigo,
              MAX(RTRIM(ISNULL(mf.[Descripcion], ''))) AS metaMacroNombre"
      : '';

    $sql = "SELECT
              {$dim['selectCodigo']} AS grupoCodigo,
              {$dim['selectNombre']} AS grupoNombre,
              RTRIM(ISNULL(l.[Articulo], '')) AS articulo,
              MAX(RTRIM(ISNULL(a.[Descripcion], ''))) AS descripcion,
              {$unidadesExpr} AS unidades,
              SUM({$dtoExpr}) AS dto,
              SUM({$importeExpr}) AS importe,
              SUM({$costeExpr}) AS coste,
              COUNT(DISTINCT {$albaranKey}) AS numAlbaranes{$metaSubfamiliaSelect}
            FROM [AlbaranesCompraCab] c
            INNER JOIN [AlbaranesComprasLin] l
              ON c.[Empresa] = l.[Empresa] AND c.[Albaran] = l.[Albaran]
            LEFT JOIN [Articulos] a ON RTRIM(a.[Codigo]) = RTRIM(l.[Articulo])
            LEFT JOIN [Familias] f ON RTRIM(f.[Codigo]) = RTRIM(a.[Familia])
            LEFT JOIN [MacroFamilias] mf ON RTRIM(mf.[Codigo]) = RTRIM(f.[MacroFamilia])
            LEFT JOIN [Subfamilias] sf ON RTRIM(sf.[Subfamilia]) = RTRIM(a.[Subfamilia])
            LEFT JOIN [Agrupaciones] ag ON RTRIM(ag.[Codigo]) = RTRIM(a.[Agrupacion])
            LEFT JOIN [Proveedores] cp ON RTRIM(cp.[Codigo]) = RTRIM(c.[Proveedor])
            LEFT JOIN [Almacenes] alm ON alm.[Codigo] = c.[Almacen]
            WHERE {$where}
            GROUP BY {$groupBy}";

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);

    /** @var array<string, array{codigo: string, nombre: string, articulos: list<array<string, mixed>>}> $byGrupo */
    $byGrupo = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $key = (string) ($row['grupoCodigo'] ?? '');
      if (!isset($byGrupo[$key])) {
        $grupoInit = [
          'codigo' => $key,
          'nombre' => (string) ($row['grupoNombre'] ?? ''),
          'articulos' => [],
        ];
        if ($dimension === 'subfamilias') {
          $grupoInit['metaFamiliaCodigo'] = (string) ($row['metaFamiliaCodigo'] ?? '');
          $grupoInit['metaFamiliaNombre'] = (string) ($row['metaFamiliaNombre'] ?? '');
          $grupoInit['metaMacroCodigo'] = (string) ($row['metaMacroCodigo'] ?? '');
          $grupoInit['metaMacroNombre'] = (string) ($row['metaMacroNombre'] ?? '');
        }
        $byGrupo[$key] = $grupoInit;
      }

      $unidades = (float) ($row['unidades'] ?? 0);
      $dto = (float) ($row['dto'] ?? 0);
      $importe = (float) ($row['importe'] ?? 0);
      $coste = (float) ($row['coste'] ?? 0);
      $numAlbaranes = (int) ($row['numAlbaranes'] ?? 0);
      $margen = $importe - $coste;
      $pjeMargen = $importe != 0.0 ? (100.0 * $margen / $importe) : 0.0;
      $mAgr = $numAlbaranes > 0 ? ($importe / $numAlbaranes) : 0.0;

      $unidadesLinea = $unidades;
      if ($formatoJerarquia === 'mediaImporte') {
        $unidadesLinea = $numAlbaranes > 0 ? ($importe / $numAlbaranes) : 0.0;
      }

      $byGrupo[$key]['articulos'][] = [
        'codigo' => (string) ($row['articulo'] ?? ''),
        'descripcion' => (string) ($row['descripcion'] ?? ''),
        'unidades' => round($unidadesLinea, $formatoJerarquia === 'mediaImporte' ? 2 : 3),
        'dto' => round($dto, 2),
        'importe' => round($importe, 2),
        'coste' => round($coste, 2),
        'margen' => round($margen, 2),
        'pjeMargen' => round($pjeMargen, 2),
        'mAgr' => round($mAgr, 2),
        '_ordenValor' => $this->ordenValorArticulo($orden, $unidades, $importe, $coste, $margen),
      ];
    }

    $grupos = [];
    $totGeneral = $this->totalesVacios();

    foreach ($byGrupo as $grupo) {
      $arts = $grupo['articulos'];
      usort($arts, function (array $a, array $b): int {
        $cmp = ((float) $b['_ordenValor']) <=> ((float) $a['_ordenValor']);
        if ($cmp !== 0) {
          return $cmp;
        }
        return strcmp((string) $a['codigo'], (string) $b['codigo']);
      });

      $totGrupo = $this->totalesVacios();
      $acumulaUnidadesGrupo = $formatoJerarquia !== 'mediaImporte';
      foreach ($arts as $a) {
        if ($acumulaUnidadesGrupo) {
          $totGrupo['unidades'] += (float) $a['unidades'];
        }
        $totGrupo['dto'] += (float) $a['dto'];
        $totGrupo['importe'] += (float) $a['importe'];
        $totGrupo['coste'] += (float) $a['coste'];
        $totGrupo['margen'] += (float) $a['margen'];
      }

      $lineas = [];
      foreach ($arts as $a) {
        unset($a['_ordenValor']);
        if ($imprimeLineasArticulo) {
          $lineas[] = $a;
        }
      }

      $totGrupo = $this->redondearTotales($totGrupo);
      $totGrupo['pjeMargen'] = $totGrupo['importe'] != 0.0
        ? round(100.0 * $totGrupo['margen'] / $totGrupo['importe'], 2)
        : 0.0;

      $grupoOut = [
        'codigo' => $grupo['codigo'],
        'nombre' => $grupo['nombre'],
        'totales' => $totGrupo,
        'articulos' => $lineas,
        '_ordenValor' => match ($orden) {
          'cantidad' => $totGrupo['unidades'],
          'importe' => $totGrupo['importe'],
          'coste' => $totGrupo['coste'],
          'macrofamilias', 'subfamilias', 'familias' => 0.0,
          default => $totGrupo['margen'],
        },
        '_ordenCodigo' => $grupo['codigo'],
      ];
      if ($dimension === 'subfamilias') {
        $grupoOut['metaFamiliaCodigo'] = (string) ($grupo['metaFamiliaCodigo'] ?? '');
        $grupoOut['metaFamiliaNombre'] = (string) ($grupo['metaFamiliaNombre'] ?? '');
        $grupoOut['metaMacroCodigo'] = (string) ($grupo['metaMacroCodigo'] ?? '');
        $grupoOut['metaMacroNombre'] = (string) ($grupo['metaMacroNombre'] ?? '');
      }
      $grupos[] = $grupoOut;

      $totGeneral['unidades'] += $totGrupo['unidades'];
      $totGeneral['dto'] += $totGrupo['dto'];
      $totGeneral['importe'] += $totGrupo['importe'];
      $totGeneral['coste'] += $totGrupo['coste'];
      $totGeneral['margen'] += $totGrupo['margen'];
    }

    usort($grupos, function (array $a, array $b) use ($orden): int {
      if ($orden === 'macrofamilias' || $orden === 'subfamilias' || $orden === 'familias') {
        return strcmp((string) $a['codigo'], (string) $b['codigo']);
      }
      $cmp = ((float) $b['_ordenValor']) <=> ((float) $a['_ordenValor']);
      if ($cmp !== 0) {
        return $cmp;
      }
      return strcmp((string) $a['codigo'], (string) $b['codigo']);
    });

    foreach ($grupos as &$g) {
      unset($g['_ordenValor'], $g['_ordenCodigo']);
    }
    unset($g);

    $totGeneral = $this->redondearTotales($totGeneral);
    $totGeneral['pjeMargen'] = $totGeneral['importe'] != 0.0
      ? round(100.0 * $totGeneral['margen'] / $totGeneral['importe'], 2)
      : 0.0;
    $totGeneral['pjeSobreTotal'] = 100.0;

    $this->aplicarPjeSobreTotalGeneral($grupos, $totGeneral, $orden);

    return [
      'dimension' => $dimension,
      'orden' => $orden,
      'valor' => $valor,
      'imArticulos' => $imArticulos,
      'divisa' => $this->normalizarDivisa($query['divisa'] ?? 'EU'),
      'formatoJerarquia' => $formatoJerarquia,
      'mostrarTotalGrupo' => $formatoJerarquia === null || $formatoJerarquia !== 'agrSinTotales',
      'fechaDesde' => $fechaDesdeDia ?? '',
      'fechaHasta' => $fechaHastaDia ?? '',
      'totales' => $totGeneral,
      'grupos' => $grupos,
      'bloques' => null,
    ];
  }

  /** @return array{unidades: float, dto: float, importe: float, coste: float, margen: float} */
  private function totalesVacios(): array
  {
    return [
      'unidades' => 0.0,
      'dto' => 0.0,
      'importe' => 0.0,
      'coste' => 0.0,
      'margen' => 0.0,
    ];
  }

  /**
   * @param array{unidades: float, dto: float, importe: float, coste: float, margen: float} $t
   * @return array{unidades: float, dto: float, importe: float, coste: float, margen: float}
   */
  private function redondearTotales(array $t): array
  {
    return [
      'unidades' => round($t['unidades'], 3),
      'dto' => round($t['dto'], 2),
      'importe' => round($t['importe'], 2),
      'coste' => round($t['coste'], 2),
      'margen' => round($t['margen'], 2),
    ];
  }

  /**
   * @param array<string, mixed> $query
   * @return array{0: string, 1: array<string, mixed>}
   */
  private function buildWhere(array $query): array
  {
    $where = [
      "l.[Articulo] <> 'NO'",
      "RTRIM(ISNULL(l.[Articulo], '')) <> ''",
    ];
    $params = [];

    $fechaDesdeDia = $this->fechaDia($query['fechaDesde'] ?? null);
    $fechaHastaDia = $this->fechaDia($query['fechaHasta'] ?? null);
    if ($fechaDesdeDia !== null) {
      $where[] = 'CONVERT(date, c.[FechaAlbaran]) >= CONVERT(date, :fechaDesde, 23)';
      $params['fechaDesde'] = $fechaDesdeDia;
    }
    if ($fechaHastaDia !== null) {
      $where[] = 'CONVERT(date, c.[FechaAlbaran]) <= CONVERT(date, :fechaHasta, 23)';
      $params['fechaHasta'] = $fechaHastaDia;
    }

    if ($this->filled($query['soloActualizado'] ?? null)) {
      $raw = $query['soloActualizado'];
      $si = $raw === true || $raw === 1 || $raw === '1' || $raw === 'true';
      if ($si) {
        $where[] = 'ISNULL(c.[Actualizado], 0) <> 0';
      }
    }

    $this->addRange($where, $params, 'c.[Empresa]', $query, 'tiendaDesde', 'tiendaHasta', 'tienda');
    $this->addRange($where, $params, 'c.[Proveedor]', $query, 'proveedorDesde', 'proveedorHasta', 'proveedor');
    $this->addRangeInt($where, $params, 'c.[Almacen]', $query, 'almacenDesde', 'almacenHasta', 'almacen');
    $this->addRange($where, $params, 'a.[Familia]', $query, 'familiaDesde', 'familiaHasta', 'familia');
    $this->addRange($where, $params, 'a.[Subfamilia]', $query, 'subfamiliaDesde', 'subfamiliaHasta', 'subfamilia');
    $this->addRange($where, $params, 'f.[MacroFamilia]', $query, 'macroFamiliaDesde', 'macroFamiliaHasta', 'macroFamilia');
    $this->addRange($where, $params, 'a.[Agrupacion]', $query, 'agrupacionDesde', 'agrupacionHasta', 'agrupacion');
    $this->addRange($where, $params, 'l.[Articulo]', $query, 'articuloDesde', 'articuloHasta', 'articulo');
    $this->addRange($where, $params, 'a.[Seccion]', $query, 'seccionDesde', 'seccionHasta', 'seccion');
    $this->addRange($where, $params, 'a.[SubSeccion]', $query, 'subSeccionDesde', 'subSeccionHasta', 'subSeccion');
    $this->addRange($where, $params, 'l.[Lote]', $query, 'loteDesde', 'loteHasta', 'lote');
    $this->addRangeInt(
      $where,
      $params,
      'ISNULL(alm.[Central], 0)',
      $query,
      'centralDesde',
      'centralHasta',
      'central'
    );

    ListadosFiltrosSql::filtroRangoFechaUltimaVentaArticulo($where, $params, $query);
    ListadosFiltrosSql::filtroDivisaAbcVentas($where, $params, 'c.[Empresa]', $query['divisa'] ?? 'EU');

    return [implode(' AND ', $where), $params];
  }

  /** @return list<string> */
  private function ordenesValidasParaDimension(string $dimension): array
  {
    if (in_array($dimension, self::DIMENSIONES_JERARQUIA, true)) {
      return ['importe', 'cantidad', $dimension];
    }
    if (in_array($dimension, self::DIMENSIONES_ORDEN_MARGEN, true)) {
      return self::ORDENES;
    }

    return ['importe', 'cantidad', 'coste'];
  }

  private function ordenDefectoParaDimension(string $dimension): string
  {
    if (in_array($dimension, self::DIMENSIONES_JERARQUIA, true)) {
      return 'importe';
    }
    if (in_array($dimension, self::DIMENSIONES_ORDEN_MARGEN, true)) {
      return 'margen';
    }

    return 'importe';
  }

  private function normalizarImArticulos(mixed $raw): string
  {
    if ($raw === false || $raw === 0 || $raw === '0' || $raw === 'false' || $raw === 'no') {
      return 'no';
    }
    $s = strtolower(trim((string) $raw));
    if ($s === 'desglosado') {
      return 'desglosado';
    }
    if ($s === 'no' || $s === 'false' || $s === '0') {
      return 'no';
    }

    return 'si';
  }

  private function normalizarDivisa(mixed $raw): string
  {
    $s = strtoupper(trim((string) $raw));
    return $s === 'PES' ? 'PES' : 'EU';
  }

  /**
   * @param list<string> $where
   * @param array<string, mixed> $params
   * @param array<string, mixed> $query
   */
  private function addRange(
    array &$where,
    array &$params,
    string $column,
    array $query,
    string $desdeKey,
    string $hastaKey,
    string $paramPrefix
  ): void {
    $desde = trim((string) ($query[$desdeKey] ?? ''));
    $hasta = trim((string) ($query[$hastaKey] ?? ''));
    if ($desde !== '') {
      $key = $paramPrefix . 'Desde';
      $where[] = "{$column} >= :{$key}";
      $params[$key] = $desde;
    }
    if ($hasta !== '') {
      $key = $paramPrefix . 'Hasta';
      $where[] = "{$column} <= :{$key}";
      $params[$key] = $hasta;
    }
  }

  /**
   * @param list<string> $where
   * @param array<string, mixed> $params
   * @param array<string, mixed> $query
   */
  private function addRangeInt(
    array &$where,
    array &$params,
    string $column,
    array $query,
    string $desdeKey,
    string $hastaKey,
    string $paramPrefix
  ): void {
    $desde = trim((string) ($query[$desdeKey] ?? ''));
    $hasta = trim((string) ($query[$hastaKey] ?? ''));
    if ($desde !== '' && is_numeric($desde)) {
      $key = $paramPrefix . 'Desde';
      $where[] = "{$column} >= :{$key}";
      $params[$key] = (int) $desde;
    }
    if ($hasta !== '' && is_numeric($hasta)) {
      $key = $paramPrefix . 'Hasta';
      $where[] = "{$column} <= :{$key}";
      $params[$key] = (int) $hasta;
    }
  }

  private function fechaDia(mixed $value): ?string
  {
    if ($value === null || $value === '') {
      return null;
    }
    $s = substr(trim((string) $value), 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
      return null;
    }
    return $s;
  }

  private function filled(mixed $value): bool
  {
    return $value !== null && $value !== '';
  }

  private function normalizarValorCoste(mixed $raw): string
  {
    $s = strtolower(trim((string) $raw));
    $map = [
      'preciomedio' => 'precioMedio',
      'preciomedioactual' => 'precioMedioActual',
      'ultimoprecio' => 'ultimoPrecio',
      'preciultimo' => 'ultimoPrecio',
      'sinvalortarifa' => 'sinValorTarifa',
    ];
    $norm = $map[$s] ?? $s;
    if (!in_array($norm, self::VALORES, true)) {
      return 'precioMedio';
    }
    return $norm;
  }

  private function normalizarFormatoJerarquia(mixed $raw): string
  {
    $key = strtolower(preg_replace('/[\s_.-]+/', '', trim((string) $raw)) ?? '');
    $map = [
      'normal' => 'normal',
      'pesokilogramos' => 'pesoKilogramos',
      'peso' => 'pesoKilogramos',
      'kilogramos' => 'pesoKilogramos',
      'mediaimporte' => 'mediaImporte',
      'agrsintotales' => 'agrSinTotales',
      'agrupacionsintotales' => 'agrSinTotales',
      'agrcontotales' => 'agrConTotales',
    ];

    return $map[$key] ?? 'normal';
  }

  private function normalizarFormatoSubfamilias(mixed $raw): string
  {
    $key = strtolower(preg_replace('/[\s_.-]+/', '', trim((string) $raw)) ?? '');

    return $key === 'extendido' ? 'extendido' : 'normal';
  }

  private function costeExpression(string $valor): string
  {
    if ($valor === 'ultimoPrecio') {
      return 'COALESCE(NULLIF(a.[PrecioUltimo], 0), NULLIF(a.[PrecioMedio], 0), 0)';
    }
    if ($valor === 'precioMedioActual') {
      return 'COALESCE(NULLIF(a.[PrecioMedio], 0), 0)';
    }
    if ($valor === 'sinValorTarifa') {
      return '0';
    }
    // Líneas de compra: Precio documento; artículo: PrecioMedio (no existe PrecioCompra).
    return 'COALESCE(NULLIF(a.[PrecioMedio], 0), NULLIF(l.[Precio], 0), 0)';
  }

  private function ordenValorArticulo(
    string $orden,
    float $unidades,
    float $importe,
    float $coste,
    float $margen
  ): float {
    return match ($orden) {
      'cantidad' => $unidades,
      'importe' => $importe,
      'coste' => $coste,
      'macrofamilias', 'subfamilias', 'familias' => $importe,
      default => $margen,
    };
  }

  /**
   * @param list<array<string, mixed>> $grupos
   * @param array{unidades: float, dto: float, importe: float, coste: float, margen: float} $totGeneral
   */
  private function aplicarPjeSobreTotalGeneral(array &$grupos, array $totGeneral, string $orden): void
  {
    $baseGeneral = $this->baseOrdenInforme($orden, $totGeneral);
    foreach ($grupos as &$g) {
      $this->aplicarPjeSobreTotalGrupo($g, $orden, $baseGeneral);
    }
    unset($g);
  }

  /**
   * @param array<string, mixed> $grupo
   */
  private function aplicarPjeSobreTotalGrupo(array &$grupo, string $orden, float $baseGeneral): void
  {
    if (!isset($grupo['articulos']) || !is_array($grupo['articulos'])) {
      return;
    }
    foreach ($grupo['articulos'] as &$a) {
      if (!is_array($a)) {
        continue;
      }
      $baseLinea = $this->ordenValorArticulo(
        $orden,
        (float) ($a['unidades'] ?? 0),
        (float) ($a['importe'] ?? 0),
        (float) ($a['coste'] ?? 0),
        (float) ($a['margen'] ?? 0)
      );
      $a['pjeSobreTotal'] = $this->pjeSobreTotal($baseLinea, $baseGeneral);
    }
    unset($a);
    if (isset($grupo['totales']) && is_array($grupo['totales'])) {
      $grupo['totales']['pjeSobreTotal'] = $this->pjeSobreTotal(
        $this->baseOrdenInforme($orden, $grupo['totales']),
        $baseGeneral
      );
    }
  }

  /**
   * @param array{unidades?: float, dto?: float, importe?: float, coste?: float, margen?: float} $totales
   */
  private function baseOrdenInforme(string $orden, array $totales): float
  {
    $base = match ($orden) {
      'cantidad' => (float) ($totales['unidades'] ?? 0),
      'importe' => (float) ($totales['importe'] ?? 0),
      'coste' => (float) ($totales['coste'] ?? 0),
      'macrofamilias', 'subfamilias', 'familias' => (float) ($totales['importe'] ?? 0),
      default => (float) ($totales['margen'] ?? 0),
    };

    return $base == 0.0 ? 1.0 : $base;
  }

  private function pjeSobreTotal(float $baseLinea, float $baseGeneral): float
  {
    return round(100.0 * $baseLinea / $baseGeneral, 2);
  }

  /**
   * @return array{selectCodigo: string, selectNombre: string, groupBy: list<string>}
   */
  private function dimensionSql(string $dimension): array
  {
    return match ($dimension) {
      'macrofamilias' => [
        'selectCodigo' => "RTRIM(ISNULL(f.[MacroFamilia], ''))",
        'selectNombre' => "MAX(RTRIM(ISNULL(mf.[Descripcion], '')))",
        'groupBy' => ["RTRIM(ISNULL(f.[MacroFamilia], ''))"],
      ],
      'subfamilias' => [
        'selectCodigo' => "RTRIM(ISNULL(a.[Subfamilia], ''))",
        'selectNombre' => "MAX(RTRIM(ISNULL(sf.[Descripción], '')))",
        'groupBy' => ["RTRIM(ISNULL(a.[Subfamilia], ''))"],
      ],
      'familias' => [
        'selectCodigo' => "RTRIM(ISNULL(a.[Familia], ''))",
        'selectNombre' => "MAX(RTRIM(ISNULL(f.[Descripcion], '')))",
        'groupBy' => ["RTRIM(ISNULL(a.[Familia], ''))"],
      ],
      'articulos' => [
        'selectCodigo' => "RTRIM(ISNULL(l.[Articulo], ''))",
        'selectNombre' => "MAX(RTRIM(ISNULL(a.[Descripcion], '')))",
        'groupBy' => ["RTRIM(ISNULL(l.[Articulo], ''))"],
      ],
      'agrupaciones' => [
        'selectCodigo' => "RTRIM(ISNULL(a.[Agrupacion], ''))",
        'selectNombre' => "MAX(RTRIM(ISNULL(ag.[Descripcion], '')))",
        'groupBy' => ["RTRIM(ISNULL(a.[Agrupacion], ''))"],
      ],
      'proveedores' => [
        'selectCodigo' => "RTRIM(ISNULL(c.[Proveedor], ''))",
        'selectNombre' => "MAX(RTRIM(ISNULL(cp.[RazonSocial], '')))",
        'groupBy' => ["RTRIM(ISNULL(c.[Proveedor], ''))"],
      ],
      'secciones' => [
        'selectCodigo' => "RTRIM(ISNULL(a.[Seccion], ''))",
        'selectNombre' => "MAX(RTRIM(ISNULL(a.[Seccion], '')))",
        'groupBy' => ["RTRIM(ISNULL(a.[Seccion], ''))"],
      ],
      'subsecciones' => [
        'selectCodigo' => "RTRIM(ISNULL(a.[SubSeccion], ''))",
        'selectNombre' => "MAX(RTRIM(ISNULL(a.[SubSeccion], '')))",
        'groupBy' => ["RTRIM(ISNULL(a.[SubSeccion], ''))"],
      ],
      'almacenes' => [
        'selectCodigo' => "CAST(ISNULL(c.[Almacen], 0) AS nvarchar(10))",
        'selectNombre' => "MAX(RTRIM(ISNULL(alm.[Descripcion], '')))",
        'groupBy' => ['ISNULL(c.[Almacen], 0)'],
      ],
      default => [
        'selectCodigo' => "RTRIM(ISNULL(a.[Familia], ''))",
        'selectNombre' => "MAX(RTRIM(ISNULL(f.[Descripcion], '')))",
        'groupBy' => ["RTRIM(ISNULL(a.[Familia], ''))"],
      ],
    };
  }
}
