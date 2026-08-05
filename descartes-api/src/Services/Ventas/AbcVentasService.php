<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Ventas;

use InvalidArgumentException;
use PDO;

/**
 * Listado ABC Ventas � dimension vendedores (formato Crystal).
 * Por cada vendedor: articulos ordenados por margen/importe/cantidad
 * con % sobre total del grupo (como VentasABC.RPT).
 */
final class AbcVentasService
{
  private const DIMENSIONES = ['vendedores'];
  private const ORDENES = ['margen', 'importe', 'cantidad'];
  private const VALORES = ['precioMedio', 'precioUltimo'];

  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  /** @param array<string, mixed> $query */
  public function generar(array $query): array
  {
    $dimension = strtolower(trim((string) ($query['dimension'] ?? 'vendedores')));
    if (!in_array($dimension, self::DIMENSIONES, true)) {
      throw new InvalidArgumentException('Dimension no soportada. Disponible: vendedores');
    }

    $orden = strtolower(trim((string) ($query['orden'] ?? 'margen')));
    if (!in_array($orden, self::ORDENES, true)) {
      $orden = 'margen';
    }

    $valor = strtolower(trim((string) ($query['valor'] ?? 'precioMedio')));
    if (!in_array($valor, self::VALORES, true)) {
      $valor = 'precioMedio';
    }

    $iva = strtolower(trim((string) ($query['iva'] ?? 'incluido')));
    $ivaIncluido = $iva !== 'excluido';

    $imArticulos = filter_var($query['imArticulos'] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    $imArticulos = $imArticulos !== false;

    $fechaDesdeDia = $this->fechaDia($query['fechaDesde'] ?? null);
    $fechaHastaDia = $this->fechaDia($query['fechaHasta'] ?? null);
    if ($fechaDesdeDia === null || $fechaHastaDia === null) {
      throw new InvalidArgumentException('Fecha desde y fecha hasta son obligatorias (YYYY-MM-DD)');
    }
    $fechaDesde = $fechaDesdeDia . ' 00:00:00';
    $fechaHasta = $fechaHastaDia . ' 23:59:59';

    [$where, $params] = $this->buildWhere($query, $fechaDesde, $fechaHasta);
    $factorIva = $ivaIncluido
      ? '1.0'
      : '(1.0 / NULLIF(1.0 + ISNULL(l.[PjeIva], 0) / 100.0, 0))';

    // Coste: PrecioMedio/Ultimo * cantidad.
    // Con Iva: se le ANIADE el IVA (no se quita). Sin Iva: se deja el PrecioMedio tal cual.
    $costeBase = $this->costeExpression($valor);
    $pjeIvaCoste = 'COALESCE(NULLIF(l.[PjeIva], 0), ISNULL(i.[PjeIVA], 0), 0)';
    $costeUnit = $ivaIncluido
      ? "({$costeBase} * (1.0 + ({$pjeIvaCoste}) / 100.0))"
      : $costeBase;
    $costeExpr = "({$costeUnit} * ISNULL(l.[Cantidad], 0))";

    // Dto: formula Crystal (PjeDto linea + PjeDto cabecera).
    $cant = 'ISNULL(l.[Cantidad], 0)';
    $precio = 'ISNULL(l.[Precio], 0)';
    $impLin = 'ISNULL(l.[Importe], 0)';
    $pjeLin = 'ISNULL(l.[PjeDto], 0)';
    $pjeCab = 'ISNULL(c.[PjeDto], 0)';
    $dtoSinIva = "CASE
              WHEN {$pjeLin} <> 0 THEN
                CASE
                  WHEN {$pjeCab} <> 0 THEN
                    (({$cant} * {$precio}) - {$impLin}) + (({$impLin} / 100.0) * {$pjeCab})
                  ELSE
                    ({$cant} * {$precio}) - {$impLin}
                END
              ELSE
                CASE
                  WHEN {$pjeCab} <> 0 THEN
                    ({$impLin} / 100.0) * {$pjeCab}
                  ELSE
                    0
                END
            END";
    $dtoExpr = "({$factorIva} * ({$dtoSinIva}))";
    // Importe neto tras todos los descuentos (= bruto linea - Dto).
    $importeExpr = "({$factorIva} * (({$cant} * {$precio}) - ({$dtoSinIva})))";

    // M.Agr. Crystal: Sum(@ImporLin, Articulo) / DistinctCount(Albaran, Articulo)
    $albaranKey = "RTRIM(c.[Empresa]) + N'|' + RTRIM(c.[Tipo]) + N'|' + CAST(c.[Albaran] AS nvarchar(20))";

    $sql = "SELECT
              RTRIM(ISNULL(c.[Vendedor], '')) AS vendedor,
              MAX(RTRIM(ISNULL(v.[Nombre], ''))) AS vendedorNombre,
              RTRIM(ISNULL(l.[Articulo], '')) AS articulo,
              MAX(RTRIM(ISNULL(a.[Descripcion], ''))) AS descripcion,
              SUM(ISNULL(l.[Cantidad], 0)) AS unidades,
              SUM({$dtoExpr}) AS dto,
              SUM({$importeExpr}) AS importe,
              SUM({$costeExpr}) AS coste,
              COUNT(DISTINCT {$albaranKey}) AS numAlbaranes
            FROM [AlbaranesVentasCab] c
            INNER JOIN [AlbaranesVentasLin] l
              ON c.[Empresa] = l.[Empresa] AND c.[Albaran] = l.[Albaran] AND c.[Tipo] = l.[Tipo]
            LEFT JOIN [Articulos] a ON a.[Codigo] = l.[Articulo]
            LEFT JOIN [Impuestos] i ON i.[Codigo] = COALESCE(NULLIF(RTRIM(a.[Impuesto]), ''), 'NO')
            LEFT JOIN [Vendedores] v ON RTRIM(v.[Codigo]) = RTRIM(c.[Vendedor])
            LEFT JOIN [Clientes] cl ON cl.[Codigo] = c.[Cliente]
            LEFT JOIN [Familias] f ON f.[Codigo] = a.[Familia]
            WHERE {$where}
            GROUP BY RTRIM(ISNULL(c.[Vendedor], '')), RTRIM(ISNULL(l.[Articulo], ''))";

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);

    /** @var array<string, array{codigo: string, nombre: string, articulos: list<array<string, mixed>>}> $byVendor */
    $byVendor = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $vend = (string) ($row['vendedor'] ?? '');
      if (!isset($byVendor[$vend])) {
        $byVendor[$vend] = [
          'codigo' => $vend,
          'nombre' => (string) ($row['vendedorNombre'] ?? ''),
          'articulos' => [],
        ];
      }

      $unidades = (float) ($row['unidades'] ?? 0);
      $dto = (float) ($row['dto'] ?? 0);
      $importe = (float) ($row['importe'] ?? 0);
      $coste = (float) ($row['coste'] ?? 0);
      $numAlbaranes = (int) ($row['numAlbaranes'] ?? 0);
      $margen = $importe - $coste;
      $pjeMargen = $importe != 0.0 ? (100.0 * $margen / $importe) : 0.0;
      $mAgr = $numAlbaranes > 0 ? ($importe / $numAlbaranes) : 0.0;

      $byVendor[$vend]['articulos'][] = [
        'codigo' => (string) ($row['articulo'] ?? ''),
        'descripcion' => (string) ($row['descripcion'] ?? ''),
        'unidades' => round($unidades, 3),
        'dto' => round($dto, 2),
        'importe' => round($importe, 2),
        'coste' => round($coste, 2),
        'margen' => round($margen, 2),
        'pjeMargen' => round($pjeMargen, 2),
        'mAgr' => round($mAgr, 2),
        '_ordenValor' => match ($orden) {
          'cantidad' => $unidades,
          'importe' => $importe,
          default => $margen,
        },
      ];
    }

    $grupos = [];
    $totGeneral = $this->totalesVacios();

    foreach ($byVendor as $grupo) {
      $arts = $grupo['articulos'];
      usort($arts, static function (array $a, array $b): int {
        $cmp = ((float) $b['_ordenValor']) <=> ((float) $a['_ordenValor']);
        if ($cmp !== 0) {
          return $cmp;
        }
        return strcmp((string) $a['codigo'], (string) $b['codigo']);
      });

      $totGrupo = $this->totalesVacios();
      foreach ($arts as $a) {
        $totGrupo['unidades'] += (float) $a['unidades'];
        $totGrupo['dto'] += (float) $a['dto'];
        $totGrupo['importe'] += (float) $a['importe'];
        $totGrupo['coste'] += (float) $a['coste'];
        $totGrupo['margen'] += (float) $a['margen'];
      }

      $baseSob = match ($orden) {
        'cantidad' => $totGrupo['unidades'],
        'importe' => $totGrupo['importe'],
        default => $totGrupo['margen'],
      };
      if ($baseSob == 0.0) {
        $baseSob = 1.0;
      }

      $lineas = [];
      foreach ($arts as $a) {
        $sob = 100.0 * ((float) $a['_ordenValor']) / $baseSob;
        unset($a['_ordenValor']);
        $a['pjeSobreTotal'] = round($sob, 2);
        if ($imArticulos) {
          $lineas[] = $a;
        }
      }

      $totGrupo = $this->redondearTotales($totGrupo);
      $totGrupo['pjeMargen'] = $totGrupo['importe'] != 0.0
        ? round(100.0 * $totGrupo['margen'] / $totGrupo['importe'], 2)
        : 0.0;
      $totGrupo['pjeSobreTotal'] = 100.0;

      $grupos[] = [
        'codigo' => $grupo['codigo'],
        'nombre' => $grupo['nombre'],
        'totales' => $totGrupo,
        'articulos' => $lineas,
        '_ordenValor' => match ($orden) {
          'cantidad' => $totGrupo['unidades'],
          'importe' => $totGrupo['importe'],
          default => $totGrupo['margen'],
        },
      ];

      $totGeneral['unidades'] += $totGrupo['unidades'];
      $totGeneral['dto'] += $totGrupo['dto'];
      $totGeneral['importe'] += $totGrupo['importe'];
      $totGeneral['coste'] += $totGrupo['coste'];
      $totGeneral['margen'] += $totGrupo['margen'];
    }

    usort($grupos, static function (array $a, array $b): int {
      $cmp = ((float) $b['_ordenValor']) <=> ((float) $a['_ordenValor']);
      if ($cmp !== 0) {
        return $cmp;
      }
      return strcmp((string) $a['codigo'], (string) $b['codigo']);
    });

    foreach ($grupos as &$g) {
      unset($g['_ordenValor']);
    }
    unset($g);

    $totGeneral = $this->redondearTotales($totGeneral);
    $totGeneral['pjeMargen'] = $totGeneral['importe'] != 0.0
      ? round(100.0 * $totGeneral['margen'] / $totGeneral['importe'], 2)
      : 0.0;
    $totGeneral['pjeSobreTotal'] = 100.0;

    return [
      'dimension' => $dimension,
      'orden' => $orden,
      'valor' => $valor,
      'iva' => $ivaIncluido ? 'incluido' : 'excluido',
      'imArticulos' => $imArticulos,
      'tipoVenta' => $this->tipoVentaLabel($query['tipoVenta'] ?? 'todos'),
      'divisa' => (string) ($query['divisa'] ?? 'EU'),
      'fechaDesde' => $fechaDesdeDia,
      'fechaHasta' => $fechaHastaDia,
      'totales' => $totGeneral,
      'grupos' => $grupos,
    ];
  }

  /** @return array{unidades: float, dto: float, importe: float, coste: float, margen: float, pjeMargen?: float, pjeSobreTotal?: float} */
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
  private function buildWhere(array $query, string $fechaDesde, string $fechaHasta): array
  {
    $where = [
      "l.[Articulo] <> 'NO'",
      'c.[Fecha] >= CONVERT(datetime, :fechaDesde, 120)',
      'c.[Fecha] <= CONVERT(datetime, :fechaHasta, 120)',
      // Solo documentos con FacturaTipo F / A / T o sin valor (legado Crystal).
      "(c.[FacturaTipo] IS NULL OR LTRIM(RTRIM(c.[FacturaTipo])) = '' OR LTRIM(RTRIM(c.[FacturaTipo])) IN ('F', 'A', 'T'))",
    ];
    $params = [
      'fechaDesde' => $fechaDesde,
      'fechaHasta' => $fechaHasta,
    ];

    $tipo = strtoupper(trim((string) ($query['tipoVenta'] ?? 'todos')));
    if ($tipo !== '' && $tipo !== 'TODOS' && in_array($tipo, ['T', 'A', 'P', 'F'], true)) {
      $where[] = 'c.[Tipo] = :tipoVenta';
      $params['tipoVenta'] = $tipo;
    }

    $this->addRange($where, $params, 'c.[Empresa]', $query, 'tiendaDesde', 'tiendaHasta', 'tienda');
    $this->addRange($where, $params, 'c.[Vendedor]', $query, 'vendedorDesde', 'vendedorHasta', 'vendedor');
    $this->addRange($where, $params, 'c.[Agente]', $query, 'agenteDesde', 'agenteHasta', 'agente');
    $this->addRange($where, $params, 'c.[Representante]', $query, 'representanteDesde', 'representanteHasta', 'representante');
    $this->addRange($where, $params, 'c.[Cliente]', $query, 'clienteDesde', 'clienteHasta', 'cliente');
    $this->addRange($where, $params, 'a.[Familia]', $query, 'familiaDesde', 'familiaHasta', 'familia');
    $this->addRange($where, $params, 'a.[Subfamilia]', $query, 'subfamiliaDesde', 'subfamiliaHasta', 'subfamilia');
    $this->addRange($where, $params, 'f.[MacroFamilia]', $query, 'macroFamiliaDesde', 'macroFamiliaHasta', 'macroFamilia');
    $this->addRange($where, $params, 'a.[Agrupacion]', $query, 'agrupacionDesde', 'agrupacionHasta', 'agrupacion');
    $this->addRange($where, $params, 'l.[Articulo]', $query, 'articuloDesde', 'articuloHasta', 'articulo');
    $this->addRange($where, $params, 'a.[UltProveedor]', $query, 'proveedorDesde', 'proveedorHasta', 'proveedor');
    $this->addRange($where, $params, 'a.[Seccion]', $query, 'seccionDesde', 'seccionHasta', 'seccion');
    $this->addRange($where, $params, 'a.[SubSeccion]', $query, 'subSeccionDesde', 'subSeccionHasta', 'subSeccion');
    $this->addRange($where, $params, 'cl.[Actividad]', $query, 'actividadDesde', 'actividadHasta', 'actividad');
    $this->addRange($where, $params, 'cl.[TipoDescuento]', $query, 'tipoDescuentoDesde', 'tipoDescuentoHasta', 'tipoDescuento');

    if ($this->filled($query['tarifa'] ?? null)) {
      $where[] = 'cl.[Tarifa] = :tarifa';
      $params['tarifa'] = (int) $query['tarifa'];
    }

    return [implode(' AND ', $where), $params];
  }

  private function costeExpression(string $valor): string
  {
    // Precio de coste: prioriza el de la linea (snapshot) y si no el del articulo.
    if ($valor === 'precioUltimo') {
      return 'COALESCE(NULLIF(l.[PrecioMedio], 0), NULLIF(a.[PrecioUltimo], 0), NULLIF(a.[PrecioMedio], 0), 0)';
    }
    return 'COALESCE(NULLIF(l.[PrecioMedio], 0), NULLIF(a.[PrecioMedio], 0), 0)';
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

  private function tipoVentaLabel(mixed $tipo): string
  {
    $t = strtoupper(trim((string) $tipo));
    return $t === '' ? 'TODOS' : $t;
  }
}
