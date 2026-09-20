<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Ventas;

use Descartes\Api\Services\Listados\ListadosFiltrosSql;
use InvalidArgumentException;
use PDO;

/**
 * Listado ABC Ventas (formato Crystal VentasABC.RPT).
 * Por cada valor de la dimensión elegida: artículos ordenados por importe/cantidad/coste/vendedor
 * con % sobre total del grupo.
 */
final class AbcVentasService
{
  /** @var list<string> */
  private const DIMENSIONES = [
    'dias-semana',
    'semanal',
    'horas',
    'macrofamilias',
    'subfamilias',
    'familias',
    'articulos',
    'agrupaciones',
    'clientes',
    'proveedores',
    'secciones',
    'subsecciones',
    'perfiles',
    'vendedores',
  ];
  private const ORDENES = ['margen', 'importe', 'cantidad', 'coste', 'vendedor'];
  private const ORDENES_SIN_MARGEN = ['importe', 'cantidad', 'coste', 'vendedor'];

  /** @var list<string> */
  private const DIMENSIONES_ORDEN_MARGEN = [
    'vendedores',
    'secciones',
    'subsecciones',
    'proveedores',
    'clientes',
    'agrupaciones',
    'articulos',
    'macrofamilias',
    'subfamilias',
    'familias',
  ];

  /** VentasABC Fam / MacroFam / SubFam — mismo formulario. */
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
    $dimension = strtolower(trim((string) ($query['dimension'] ?? 'vendedores')));
    if (!in_array($dimension, self::DIMENSIONES, true)) {
      throw new InvalidArgumentException(
        'Dimension no soportada. Disponibles: ' . implode(', ', self::DIMENSIONES)
      );
    }

    if ($dimension === 'dias-semana') {
      $ordenesValidas = ['diasemana', 'diaSemana', 'margen', 'importe', 'cantidad', 'coste'];
      $ordenDefault = 'diaSemana';
      $orden = strtolower(trim((string) ($query['orden'] ?? $ordenDefault)));
      if ($orden === 'diasemana') {
        $orden = 'diaSemana';
      }
      if (!in_array($orden, $ordenesValidas, true)) {
        $orden = $ordenDefault;
      }
    } elseif ($dimension === 'horas' || $dimension === 'semanal') {
      $ordenesValidas = ['horas', 'margen', 'importe', 'cantidad', 'coste'];
      $ordenDefault = 'horas';
      $orden = strtolower(trim((string) ($query['orden'] ?? $ordenDefault)));
      if (!in_array($orden, $ordenesValidas, true)) {
        $orden = $ordenDefault;
      }
    } else {
      $permiteMargen = in_array($dimension, self::DIMENSIONES_ORDEN_MARGEN, true);
      $ordenesValidas = $permiteMargen ? self::ORDENES : self::ORDENES_SIN_MARGEN;
      $ordenDefault = $permiteMargen ? 'margen' : 'importe';
      $orden = strtolower(trim((string) ($query['orden'] ?? $ordenDefault)));
      if ($orden === 'margen' && !$permiteMargen) {
        $orden = 'importe';
      }
      if (!in_array($orden, $ordenesValidas, true)) {
        $orden = $ordenDefault;
      }
    }

    $valor = $this->normalizarValorCoste($query['valor'] ?? 'precioMedio');

    $iva = strtolower(trim((string) ($query['iva'] ?? 'incluido')));
    $ivaDesglosado = $iva === 'desglosado' || $iva === 'excluido';
    $ivaIncluido = !$ivaDesglosado;

    $imArticulos = filter_var($query['imArticulos'] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    $imArticulos = $imArticulos !== false;
    if ($dimension === 'articulos') {
      $imArticulos = true;
    }

    $formatoArticulos = null;
    $agrupacionArticulos = null;
    $usaComision = false;
    $detalleComisionVendedor = false;
    if ($dimension === 'articulos') {
      $formatoArticulos = $this->normalizarFormatoArticulos($query['formatoArticulos'] ?? $query['formato'] ?? 'normal');
      $agrupacionArticulos = $this->normalizarAgrupacionArticulos($query['agrupacionArticulos'] ?? 'sinAgrupacion');
      $usaComision = in_array($formatoArticulos, ['comisiones', 'detalleComision', 'extendido'], true);
      $detalleComisionVendedor = $formatoArticulos === 'detalleComision';
    }

    $formatoJerarquia = null;
    if ($dimension === 'familias') {
      $formatoJerarquia = $this->normalizarFormatoJerarquia($query['formatoJerarquia'] ?? 'normal');
    }

    $fechaDesdeDia = $this->fechaDia($query['fechaDesde'] ?? null);
    $fechaHastaDia = $this->fechaDia($query['fechaHasta'] ?? null);

    [$where, $params] = $this->buildWhere($query);
    // Legacy Crystal: Importe/Precio en linea van sin IVA; «Incluido» recarga el % de la linea.
    // «Desglosado» deja importes netos (sin multiplicar).
    // Importe/Dto: solo el % grabado en la linea (Crystal no sustituye por el del articulo).
    $pjeIvaImporte = 'ISNULL(l.[PjeIva], 0)';
    $factorIva = $ivaIncluido
      ? "(1.0 + ({$pjeIvaImporte}) / 100.0)"
      : '1.0';

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

    $whereImporteLinea = $this->sqlFiltroImporteLinea($query, $importeExpr);
    if ($whereImporteLinea !== '') {
      $where .= ' AND ' . $whereImporteLinea;
    }

    // M.Agr. Crystal: Sum(@ImporLin, Articulo) / DistinctCount(Albaran, Articulo)
    $albaranKey = "RTRIM(c.[Empresa]) + N'|' + RTRIM(c.[Tipo]) + N'|' + CAST(c.[Albaran] AS nvarchar(20))";

    $unidadesExpr = 'SUM(ISNULL(l.[Cantidad], 0))';
    if ($formatoJerarquia === 'pesoKilogramos') {
      $unidadesExpr = 'SUM(ISNULL(l.[Cantidad], 0) * ISNULL(a.[PesoEnKilos], 0))';
    }

    if ($dimension === 'dias-semana') {
      return $this->generarInformeDiasSemanaPlano(
        $query,
        $where,
        $params,
        $importeExpr,
        $costeExpr,
        $dtoExpr,
        $unidadesExpr,
        $albaranKey,
        $fechaDesdeDia,
        $fechaHastaDia,
        $valor,
        $ivaIncluido,
        $imArticulos,
        $orden,
      );
    }

    if ($dimension === 'horas') {
      return $this->generarInformeHoras(
        $query,
        $where,
        $params,
        $importeExpr,
        $costeExpr,
        $dtoExpr,
        $unidadesExpr,
        $albaranKey,
        $fechaDesdeDia,
        $fechaHastaDia,
        $valor,
        $ivaIncluido,
        $imArticulos,
        $orden,
      );
    }

    if ($dimension === 'semanal') {
      return $this->generarInformeSemanalMatriz(
        $query,
        $where,
        $params,
        $importeExpr,
        $costeExpr,
        $unidadesExpr,
        $fechaDesdeDia,
        $fechaHastaDia,
        $valor,
        $ivaIncluido,
        $imArticulos,
        $orden,
      );
    }

    $dim = $this->dimensionSql($dimension);
    if ($dimension === 'articulos' && $detalleComisionVendedor) {
      $groupByParts = [
        "RTRIM(ISNULL(l.[Articulo], ''))",
        "RTRIM(ISNULL(c.[Vendedor], ''))",
      ];
    } elseif ($dimension === 'articulos') {
      $groupByParts = $dim['groupBy'];
    } else {
      $groupByParts = [...$dim['groupBy'], 'RTRIM(ISNULL(l.[Articulo], \'\'))'];
    }
    $groupBy = implode(', ', $groupByParts);

    $selectClientesMeta = $dimension === 'clientes'
      ? ",
              MAX(RTRIM(ISNULL(cl.[Provincia], ''))) AS clienteProvincia,
              MAX(RTRIM(ISNULL(cl.[CodigoPostal], ''))) AS clienteCodigoPostal"
      : '';

    $selectArticulosMeta = $dimension === 'articulos'
      ? ",
              MAX(RTRIM(ISNULL(a.[Familia], ''))) AS metaFamilia,
              MAX(RTRIM(ISNULL(f.[Descripcion], ''))) AS metaFamiliaNombre,
              MAX(RTRIM(ISNULL(f.[MacroFamilia], ''))) AS metaMacroFamilia,
              MAX(RTRIM(ISNULL(mf.[Descripcion], ''))) AS metaMacroFamiliaNombre,
              MAX(RTRIM(ISNULL(a.[Subfamilia], ''))) AS metaSubfamilia,
              MAX(RTRIM(ISNULL(sf.[Descripción], ''))) AS metaSubfamiliaNombre,
              MAX(RTRIM(ISNULL(a.[Agrupacion], ''))) AS metaAgrupacion,
              MAX(RTRIM(ISNULL(ag.[Descripcion], ''))) AS metaAgrupacionNombre"
      : '';

    $selectDetalleVendedor = $detalleComisionVendedor
      ? ",
              RTRIM(ISNULL(c.[Vendedor], '')) AS detCodigo,
              MAX(RTRIM(ISNULL(v.[Nombre], ''))) AS detNombre"
      : '';

    $pjeComision = 'COALESCE(NULLIF(vc.[Comision], 0), NULLIF(v.[ComisionVenta], 0), 0)';
    $margenLinea = "(({$importeExpr}) - ({$costeExpr}))";
    $selectComision = $usaComision
      ? ", SUM(({$margenLinea}) * ({$pjeComision}) / 100.0) AS comision"
      : '';

    $selectExtendido = $formatoArticulos === 'extendido'
      ? ",
              MAX(RTRIM(ISNULL(a.[Familia], ''))) AS extFamilia,
              MAX(RTRIM(ISNULL(f.[Descripcion], ''))) AS extFamiliaNombre,
              MAX(RTRIM(ISNULL(a.[UltProveedor], ''))) AS extProveedor,
              MAX(RTRIM(ISNULL(p.[RazonSocial], ''))) AS extProveedorNombre"
      : '';

    $joinComision = $usaComision
      ? "
            LEFT JOIN [VendComi] vc
              ON RTRIM(vc.[Codigo]) = RTRIM(c.[Vendedor])
              AND RTRIM(vc.[Familia]) = RTRIM(a.[Familia])
              AND RTRIM(vc.[Tipo]) = N'F'"
      : '';

    $sql = "SELECT
              {$dim['selectCodigo']} AS grupoCodigo,
              {$dim['selectNombre']} AS grupoNombre{$selectClientesMeta}{$selectArticulosMeta}{$selectDetalleVendedor},
              RTRIM(ISNULL(l.[Articulo], '')) AS articulo,
              MAX(RTRIM(ISNULL(a.[Descripcion], ''))) AS descripcion,
              {$unidadesExpr} AS unidades,
              SUM({$dtoExpr}) AS dto,
              SUM({$importeExpr}) AS importe,
              SUM({$costeExpr}) AS coste,
              COUNT(DISTINCT {$albaranKey}) AS numAlbaranes{$selectComision}{$selectExtendido}
            FROM [AlbaranesVentasCab] c
            INNER JOIN [AlbaranesVentasLin] l
              ON c.[Empresa] = l.[Empresa] AND c.[Albaran] = l.[Albaran] AND c.[Tipo] = l.[Tipo]
            LEFT JOIN [Articulos] a ON a.[Codigo] = l.[Articulo]
            LEFT JOIN [Impuestos] i ON i.[Codigo] = COALESCE(NULLIF(RTRIM(a.[Impuesto]), ''), 'NO')
            LEFT JOIN [Clientes] cl ON cl.[Codigo] = c.[Cliente]
            LEFT JOIN [Familias] f ON RTRIM(f.[Codigo]) = RTRIM(a.[Familia])
            LEFT JOIN [MacroFamilias] mf ON RTRIM(mf.[Codigo]) = RTRIM(f.[MacroFamilia])
            LEFT JOIN [Subfamilias] sf ON RTRIM(sf.[Subfamilia]) = RTRIM(a.[Subfamilia])
            LEFT JOIN [Agrupaciones] ag ON RTRIM(ag.[Codigo]) = RTRIM(a.[Agrupacion])
            LEFT JOIN [Proveedores] p ON RTRIM(p.[Codigo]) = RTRIM(a.[UltProveedor])
            LEFT JOIN [Vendedores] v ON RTRIM(v.[Codigo]) = RTRIM(c.[Vendedor]){$joinComision}
            WHERE {$where}
            GROUP BY {$groupBy}";

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);

    /** @var array<string, array{codigo: string, nombre: string, articulos: list<array<string, mixed>>}> $byGrupo */
    $byGrupo = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $key = (string) ($row['grupoCodigo'] ?? '');
      if (!isset($byGrupo[$key])) {
        $byGrupo[$key] = [
          'codigo' => $key,
          'nombre' => (string) ($row['grupoNombre'] ?? ''),
          'provincia' => (string) ($row['clienteProvincia'] ?? ''),
          'codigoPostal' => (string) ($row['clienteCodigoPostal'] ?? ''),
          'metaFamilia' => (string) ($row['metaFamilia'] ?? ''),
          'metaFamiliaNombre' => (string) ($row['metaFamiliaNombre'] ?? ''),
          'metaMacroFamilia' => (string) ($row['metaMacroFamilia'] ?? ''),
          'metaMacroFamiliaNombre' => (string) ($row['metaMacroFamiliaNombre'] ?? ''),
          'metaSubfamilia' => (string) ($row['metaSubfamilia'] ?? ''),
          'metaSubfamiliaNombre' => (string) ($row['metaSubfamiliaNombre'] ?? ''),
          'metaAgrupacion' => (string) ($row['metaAgrupacion'] ?? ''),
          'metaAgrupacionNombre' => (string) ($row['metaAgrupacionNombre'] ?? ''),
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

      $lineCodigo = $detalleComisionVendedor
        ? (string) ($row['detCodigo'] ?? '')
        : (string) ($row['articulo'] ?? '');
      $lineDesc = $detalleComisionVendedor
        ? (string) ($row['detNombre'] ?? '')
        : (string) ($row['descripcion'] ?? '');

      $unidadesLinea = $unidades;
      if ($formatoJerarquia === 'mediaImporte') {
        $unidadesLinea = $numAlbaranes > 0 ? ($importe / $numAlbaranes) : 0.0;
      }

      $linea = [
        'codigo' => $lineCodigo,
        'descripcion' => $lineDesc,
        'unidades' => round($unidadesLinea, $formatoJerarquia === 'mediaImporte' ? 2 : 3),
        'dto' => round($dto, 2),
        'importe' => round($importe, 2),
        'coste' => round($coste, 2),
        'margen' => round($margen, 2),
        'pjeMargen' => round($pjeMargen, 2),
        'mAgr' => round($mAgr, 2),
        '_ordenValor' => $this->ordenValorArticulo($orden, $unidades, $importe, $coste, $margen),
      ];
      if ($usaComision) {
        $linea['comision'] = round((float) ($row['comision'] ?? 0), 2);
      }
      if ($formatoArticulos === 'extendido') {
        $linea['familia'] = (string) ($row['extFamilia'] ?? '');
        $linea['familiaNombre'] = (string) ($row['extFamiliaNombre'] ?? '');
        $linea['proveedor'] = (string) ($row['extProveedor'] ?? '');
        $linea['proveedorNombre'] = (string) ($row['extProveedorNombre'] ?? '');
      }
      $byGrupo[$key]['articulos'][] = $linea;
    }

    $grupos = [];
    $totGeneral = $this->totalesVacios();

    foreach ($byGrupo as $grupo) {
      $arts = $grupo['articulos'];
      usort($arts, function (array $a, array $b) use ($orden): int {
        if ($orden === 'vendedor') {
          return strcmp((string) $a['codigo'], (string) $b['codigo']);
        }
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
        if ($usaComision) {
          $totGrupo['comision'] = ($totGrupo['comision'] ?? 0.0) + (float) ($a['comision'] ?? 0);
        }
      }

      $lineas = [];
      foreach ($arts as $a) {
        unset($a['_ordenValor']);
        if ($imArticulos) {
          $lineas[] = $a;
        }
      }

      $totGrupo = $this->redondearTotales($totGrupo);
      if (isset($totGrupo['comision'])) {
        $totGrupo['comision'] = round((float) $totGrupo['comision'], 2);
      }
      $totGrupo['pjeMargen'] = $totGrupo['importe'] != 0.0
        ? round(100.0 * $totGrupo['margen'] / $totGrupo['importe'], 2)
        : 0.0;

      $grupos[] = [
        'codigo' => $grupo['codigo'],
        'nombre' => $grupo['nombre'],
        'provincia' => (string) ($grupo['provincia'] ?? ''),
        'codigoPostal' => (string) ($grupo['codigoPostal'] ?? ''),
        'metaFamilia' => (string) ($grupo['metaFamilia'] ?? ''),
        'metaFamiliaNombre' => (string) ($grupo['metaFamiliaNombre'] ?? ''),
        'metaMacroFamilia' => (string) ($grupo['metaMacroFamilia'] ?? ''),
        'metaMacroFamiliaNombre' => (string) ($grupo['metaMacroFamiliaNombre'] ?? ''),
        'metaSubfamilia' => (string) ($grupo['metaSubfamilia'] ?? ''),
        'metaSubfamiliaNombre' => (string) ($grupo['metaSubfamiliaNombre'] ?? ''),
        'metaAgrupacion' => (string) ($grupo['metaAgrupacion'] ?? ''),
        'metaAgrupacionNombre' => (string) ($grupo['metaAgrupacionNombre'] ?? ''),
        'totales' => $totGrupo,
        'articulos' => $lineas,
        '_ordenValor' => match ($orden) {
          'cantidad' => $totGrupo['unidades'],
          'importe' => $totGrupo['importe'],
          'coste' => $totGrupo['coste'],
          'vendedor' => 0.0,
          default => $totGrupo['margen'],
        },
        '_ordenCodigo' => $grupo['codigo'],
      ];

      $totGeneral['unidades'] += $totGrupo['unidades'];
      $totGeneral['dto'] += $totGrupo['dto'];
      $totGeneral['importe'] += $totGrupo['importe'];
      $totGeneral['coste'] += $totGrupo['coste'];
      $totGeneral['margen'] += $totGrupo['margen'];
    }

    usort($grupos, function (array $a, array $b) use ($orden): int {
      if ($orden === 'vendedor') {
        return strcmp((string) ($a['_ordenCodigo'] ?? ''), (string) ($b['_ordenCodigo'] ?? ''));
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

    $agrupacionClientes = null;
    $bloques = null;
    $formato = null;
    if ($dimension === 'clientes') {
      $agrKey = $this->normalizarAgrupacionClientes($query['agrupacionClientes'] ?? 'normal');
      $agrupacionClientes = $agrKey;
      $formato = $this->normalizarFormatoAbc($query['formato'] ?? 'abcVentas');
      [$grupos, $bloques] = $this->aplicarAgrupacionClientesInforme($grupos, $agrKey);
      if ($bloques !== null) {
        $this->aplicarPjeSobreTotalBloques($bloques, $totGeneral, $orden);
      }
    }
    if ($dimension === 'articulos' && $agrupacionArticulos !== null) {
      [$grupos, $bloquesArt] = $this->aplicarAgrupacionArticulosInforme($grupos, $agrupacionArticulos);
      if ($bloquesArt !== null) {
        $bloques = $bloquesArt;
        $this->aplicarPjeSobreTotalBloques($bloques, $totGeneral, $orden);
      }
    }

    return [
      'dimension' => $dimension,
      'orden' => $orden,
      'valor' => $valor,
      'iva' => $ivaIncluido ? 'incluido' : 'desglosado',
      'imArticulos' => $imArticulos,
      'tipoVenta' => $this->tipoVentaLabel($this->normalizarTipoVenta($query['tipoVenta'] ?? 'todos')),
      'divisa' => (string) ($query['divisa'] ?? 'EU'),
      'imprimir' => $dimension === 'proveedores'
        ? $this->normalizarImprimirProveedor($query['imprimir'] ?? 'codigo')
        : null,
      'agrupacionClientes' => $agrupacionClientes,
      'formato' => $formato,
      'agrupacionArticulos' => $agrupacionArticulos,
      'formatoArticulos' => $formatoArticulos,
      'formatoJerarquia' => $formatoJerarquia,
      'mostrarTotalGrupo' => $formatoJerarquia === null || $formatoJerarquia !== 'agrSinTotales',
      'fechaDesde' => $fechaDesdeDia ?? '',
      'fechaHasta' => $fechaHastaDia ?? '',
      'totales' => $totGeneral,
      'grupos' => $grupos,
      'bloques' => $bloques,
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
    $out = [
      'unidades' => round($t['unidades'], 3),
      'dto' => round($t['dto'], 2),
      'importe' => round($t['importe'], 2),
      'coste' => round($t['coste'], 2),
      'margen' => round($t['margen'], 2),
    ];
    if (array_key_exists('tickets', $t)) {
      $out['tickets'] = (int) round((float) $t['tickets']);
    }

    return $out;
  }

  /**
   * @param array<string, mixed> $query
   * @return array{0: string, 1: array<string, mixed>}
   */
  private function buildWhere(array $query): array
  {
    $where = [
      "l.[Articulo] <> 'NO'",
      'ISNULL(c.[Anulado], 0) = 0',
      "RTRIM(ISNULL(c.[Estado], '')) <> 'B'",
    ];
    $params = [];

    $fechaDesdeDia = $this->fechaDia($query['fechaDesde'] ?? null);
    $fechaHastaDia = $this->fechaDia($query['fechaHasta'] ?? null);
    if ($fechaDesdeDia !== null) {
      $where[] = 'CONVERT(date, c.[Fecha]) >= CONVERT(date, :fechaDesde, 23)';
      $params['fechaDesde'] = $fechaDesdeDia;
    }
    if ($fechaHastaDia !== null) {
      $where[] = 'CONVERT(date, c.[Fecha]) <= CONVERT(date, :fechaHasta, 23)';
      $params['fechaHasta'] = $fechaHastaDia;
    }

    $fechaFactDesde = $this->fechaDia($query['fechaFacturacionDesde'] ?? null);
    $fechaFactHasta = $this->fechaDia($query['fechaFacturacionHasta'] ?? null);
    if ($fechaFactDesde !== null || $fechaFactHasta !== null) {
      $facMatch = [
        'ISNULL(c.[Factura], 0) <> 0',
        'fac.[Empresa] = c.[Empresa]',
        'fac.[FacturaTipo] = c.[FacturaTipo]',
        'fac.[Factura] = c.[Factura]',
      ];
      if ($fechaFactDesde !== null) {
        $facMatch[] = 'CONVERT(date, fac.[Fecha]) >= CONVERT(date, :fechaFactDesde, 23)';
        $params['fechaFactDesde'] = $fechaFactDesde;
      }
      if ($fechaFactHasta !== null) {
        $facMatch[] = 'CONVERT(date, fac.[Fecha]) <= CONVERT(date, :fechaFactHasta, 23)';
        $params['fechaFactHasta'] = $fechaFactHasta;
      }
      $where[] = 'EXISTS (SELECT 1 FROM [Facturas] fac WHERE ' . implode(' AND ', $facMatch) . ')';
    }

    $tipoKey = $this->normalizarTipoVenta($query['tipoVenta'] ?? 'todos');
    $this->aplicarFiltroTipoVenta($where, $params, $tipoKey);

    ListadosFiltrosSql::filtroDivisaAbcVentas($where, $params, 'c.[Empresa]', $query['divisa'] ?? 'EU');

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
    $this->addRange($where, $params, 'RTRIM(c.[Puesto])', $query, 'puestoDesde', 'puestoHasta', 'puesto');
    ListadosFiltrosSql::filtroRangoEntero($where, $params, 'c.[Sesion]', $query, 'sesionDesde', 'sesionHasta');
    ListadosFiltrosSql::filtroRangoEntero(
      $where,
      $params,
      'DATEPART(iso_week, c.[Fecha])',
      $query,
      'semanaDesde',
      'semanaHasta',
    );
    ListadosFiltrosSql::filtroRangoEntero($where, $params, 'c.[Perfil]', $query, 'perfilDesde', 'perfilHasta');
    $this->addRange($where, $params, 'a.[Seccion]', $query, 'seccionDesde', 'seccionHasta', 'seccion');
    $this->addRange($where, $params, 'a.[SubSeccion]', $query, 'subSeccionDesde', 'subSeccionHasta', 'subSeccion');
    $this->addRange($where, $params, 'cl.[Actividad]', $query, 'actividadDesde', 'actividadHasta', 'actividad');
    $this->addRange($where, $params, 'cl.[TipoDescuento]', $query, 'tipoDescuentoDesde', 'tipoDescuentoHasta', 'tipoDescuento');

    if ($this->filled($query['tarifa'] ?? null)) {
      $where[] = 'cl.[Tarifa] = :tarifa';
      $params['tarifa'] = (int) $query['tarifa'];
    }
    ListadosFiltrosSql::filtroRangoEntero($where, $params, 'cl.[Tarifa]', $query, 'tarifaDesde', 'tarifaHasta');
    ListadosFiltrosSql::filtroRangoEntero($where, $params, 'c.[Factura]', $query, 'facturaDesde', 'facturaHasta');

    return [implode(' AND ', $where), $params];
  }

  /**
   * Filtro importe unitario de línea (VentasABCCli), mismo criterio neto que el informe sin recargar IVA extra.
   *
   * @param array<string, mixed> $query
   */
  private function sqlFiltroImporteLinea(array $query, string $importeExpr): string
  {
    $desde = trim((string) ($query['importeDesde'] ?? ''));
    $hasta = trim((string) ($query['importeHasta'] ?? ''));
    if ($desde === '' && $hasta === '') {
      return '';
    }
    $parts = [];
    if ($desde !== '' && is_numeric($desde)) {
      $parts[] = "({$importeExpr}) >= " . (float) $desde;
    }
    if ($hasta !== '' && is_numeric($hasta)) {
      $parts[] = "({$importeExpr}) <= " . (float) $hasta;
    }

    return $parts === [] ? '' : implode(' AND ', $parts);
  }

  private function normalizarFormatoAbc(mixed $raw): string
  {
    $s = strtolower(preg_replace('/[\s_-]+/', '', trim((string) $raw)) ?? '');
    return $s === 'abcventas' || $s === '' ? 'abcVentas' : 'abcVentas';
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
      'agrsintotal' => 'agrSinTotales',
      'agrcontotales' => 'agrConTotales',
      'agrupacioncontotales' => 'agrConTotales',
      'agrcontotal' => 'agrConTotales',
    ];

    return $map[$key] ?? 'normal';
  }

  private function normalizarFormatoArticulos(mixed $raw): string
  {
    $key = strtolower(preg_replace('/[\s_-]+/', '', trim((string) $raw)) ?? '');
    $map = [
      'normal' => 'normal',
      'comisiones' => 'comisiones',
      'comision' => 'comisiones',
      'detallecomision' => 'detalleComision',
      'detallesdecomision' => 'detalleComision',
      'detalledecomision' => 'detalleComision',
      'extendido' => 'extendido',
    ];

    return $map[$key] ?? 'normal';
  }

  private function normalizarAgrupacionArticulos(mixed $raw): string
  {
    $key = strtolower(preg_replace('/[\s_-]+/', '', trim((string) $raw)) ?? '');
    $map = [
      'sinagrupacion' => 'sinAgrupacion',
      'ninguna' => 'sinAgrupacion',
      'familia' => 'familia',
      'macrofamilia' => 'macrofamilia',
      'subfamilia' => 'subfamilia',
      'agrupacion' => 'agrupacionArticulo',
      'agrupacionarticulo' => 'agrupacionArticulo',
    ];

    return $map[$key] ?? 'sinAgrupacion';
  }

  private function normalizarAgrupacionClientes(mixed $raw): string
  {
    $key = strtolower(preg_replace('/[\s_-]+/', '', trim((string) $raw)) ?? '');
    $map = [
      'normal' => 'normal',
      'provincia' => 'provincia',
      'codigopostal' => 'codigoPostal',
      'postal' => 'codigoPostal',
      'normalsaltoxcliente' => 'normalSaltoCliente',
      'normalsaltocliente' => 'normalSaltoCliente',
      'cpostalxcliente' => 'codigoPostalSaltoCliente',
      'codigopostalsaltoxcliente' => 'codigoPostalSaltoCliente',
      'codigopostalsaltocliente' => 'codigoPostalSaltoCliente',
    ];

    return $map[$key] ?? 'normal';
  }

  /**
   * @param list<array<string, mixed>> $grupos
   * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>|null}
   */
  private function aplicarAgrupacionClientesInforme(array $grupos, string $agrKey): array
  {
    $salto = in_array($agrKey, ['normalSaltoCliente', 'codigoPostalSaltoCliente'], true);
    $porCp = in_array($agrKey, ['codigoPostal', 'codigoPostalSaltoCliente'], true);
    $porProvincia = $agrKey === 'provincia';

    if ($salto) {
      foreach ($grupos as &$g) {
        $g['saltoPagina'] = true;
      }
      unset($g);
    }

    if (!$porProvincia && !$porCp) {
      return [$grupos, null];
    }

    /** @var array<string, array{codigo: string, nombre: string, grupos: list<array<string, mixed>>}> $byBloque */
    $byBloque = [];
    foreach ($grupos as $g) {
      $codigo = $porProvincia
        ? trim((string) ($g['provincia'] ?? ''))
        : trim((string) ($g['codigoPostal'] ?? ''));
      if ($codigo === '') {
        $codigo = '(sin)';
      }
      $nombre = $porProvincia
        ? (trim((string) ($g['provincia'] ?? '')) ?: '(sin provincia)')
        : (trim((string) ($g['codigoPostal'] ?? '')) ?: '(sin CP)');
      if (!isset($byBloque[$codigo])) {
        $byBloque[$codigo] = [
          'codigo' => $codigo,
          'nombre' => $nombre,
          'grupos' => [],
        ];
      }
      $byBloque[$codigo]['grupos'][] = $g;
    }

    $bloques = [];
    foreach ($byBloque as $bloque) {
      $tot = $this->totalesVacios();
      foreach ($bloque['grupos'] as $g) {
        $t = $g['totales'];
        $tot['unidades'] += (float) $t['unidades'];
        $tot['dto'] += (float) $t['dto'];
        $tot['importe'] += (float) $t['importe'];
        $tot['coste'] += (float) $t['coste'];
        $tot['margen'] += (float) $t['margen'];
      }
      $tot = $this->redondearTotales($tot);
      $tot['pjeMargen'] = $tot['importe'] != 0.0
        ? round(100.0 * $tot['margen'] / $tot['importe'], 2)
        : 0.0;
      $tot['pjeSobreTotal'] = 100.0;
      $bloques[] = [
        'codigo' => $bloque['codigo'],
        'nombre' => $bloque['nombre'],
        'totales' => $tot,
        'grupos' => $bloque['grupos'],
        '_ordenNombre' => $bloque['nombre'],
      ];
    }

    usort($bloques, fn (array $a, array $b): int => strcmp((string) $a['_ordenNombre'], (string) $b['_ordenNombre']));
    foreach ($bloques as &$b) {
      unset($b['_ordenNombre']);
    }
    unset($b);

    return [$grupos, $bloques];
  }

  /**
   * @param list<array<string, mixed>> $grupos
   * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>|null}
   */
  private function aplicarAgrupacionArticulosInforme(array $grupos, string $agrKey): array
  {
    if ($agrKey === 'sinAgrupacion') {
      return [$grupos, null];
    }

    $codigoField = match ($agrKey) {
      'macrofamilia' => 'metaMacroFamilia',
      'subfamilia' => 'metaSubfamilia',
      'agrupacionArticulo' => 'metaAgrupacion',
      default => 'metaFamilia',
    };
    $nombreField = match ($agrKey) {
      'macrofamilia' => 'metaMacroFamiliaNombre',
      'subfamilia' => 'metaSubfamiliaNombre',
      'agrupacionArticulo' => 'metaAgrupacionNombre',
      default => 'metaFamiliaNombre',
    };

    /** @var array<string, array{codigo: string, nombre: string, grupos: list<array<string, mixed>>}> $byBloque */
    $byBloque = [];
    foreach ($grupos as $g) {
      $codigo = trim((string) ($g[$codigoField] ?? ''));
      if ($codigo === '') {
        $codigo = '(sin)';
      }
      $nombre = trim((string) ($g[$nombreField] ?? ''));
      if ($nombre === '') {
        $nombre = $codigo === '(sin)' ? '(sin clasificar)' : $codigo;
      }
      if (!isset($byBloque[$codigo])) {
        $byBloque[$codigo] = [
          'codigo' => $codigo,
          'nombre' => $nombre,
          'grupos' => [],
        ];
      }
      $byBloque[$codigo]['grupos'][] = $g;
    }

    $bloques = [];
    foreach ($byBloque as $bloque) {
      $tot = $this->totalesVacios();
      foreach ($bloque['grupos'] as $g) {
        $t = $g['totales'];
        $tot['unidades'] += (float) $t['unidades'];
        $tot['dto'] += (float) $t['dto'];
        $tot['importe'] += (float) $t['importe'];
        $tot['coste'] += (float) $t['coste'];
        $tot['margen'] += (float) $t['margen'];
        if (isset($t['comision'])) {
          $tot['comision'] = ($tot['comision'] ?? 0.0) + (float) $t['comision'];
        }
      }
      $tot = $this->redondearTotales($tot);
      if (isset($tot['comision'])) {
        $tot['comision'] = round((float) $tot['comision'], 2);
      }
      $tot['pjeMargen'] = $tot['importe'] != 0.0
        ? round(100.0 * $tot['margen'] / $tot['importe'], 2)
        : 0.0;
      $tot['pjeSobreTotal'] = 100.0;
      $bloques[] = [
        'codigo' => $bloque['codigo'],
        'nombre' => $bloque['nombre'],
        'totales' => $tot,
        'grupos' => $bloque['grupos'],
        '_ordenNombre' => $bloque['nombre'],
      ];
    }

    usort($bloques, fn (array $a, array $b): int => strcmp((string) $a['_ordenNombre'], (string) $b['_ordenNombre']));
    foreach ($bloques as &$b) {
      unset($b['_ordenNombre']);
    }
    unset($b);

    return [$grupos, $bloques];
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
    // precioMedio (documento / linea)
    return 'COALESCE(NULLIF(l.[PrecioMedio], 0), NULLIF(a.[PrecioMedio], 0), 0)';
  }

  private function normalizarImprimirProveedor(mixed $raw): string
  {
    $s = strtolower(trim((string) $raw));
    return $s === 'descripcion' ? 'descripcion' : 'codigo';
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

  private function normalizarTipoVenta(mixed $raw): string
  {
    $key = strtolower(preg_replace('/[\s+_]+/', '', trim((string) $raw)) ?? '');
    $aliases = [
      'todos' => 'todos',
      'ticket' => 'ticket',
      't' => 'ticket',
      'facturas' => 'facturas',
      'factura' => 'facturas',
      'f' => 'facturas',
      'ticketfacturas' => 'ticketFacturas',
      'ticketfactura' => 'ticketFacturas',
      'albaranes' => 'albaranes',
      'albaran' => 'albaranes',
      'a' => 'albaranes',
      'ticketsfacturascontado' => 'ticketsFacturasContado',
      'ticketfacturascontado' => 'ticketsFacturasContado',
    ];
    return $aliases[$key] ?? 'todos';
  }

  /**
   * @param list<string> $where
   * @param array<string, mixed> $params
   */
  private function aplicarFiltroTipoVenta(array &$where, array &$params, string $tipoKey): void
  {
    if ($tipoKey === 'todos') {
      $where[] = "(c.[FacturaTipo] IS NULL OR LTRIM(RTRIM(c.[FacturaTipo])) = '' OR LTRIM(RTRIM(c.[FacturaTipo])) IN ('F', 'A', 'T'))";
      return;
    }
    if ($tipoKey === 'albaranes') {
      $where[] = "(c.[FacturaTipo] IS NULL OR LTRIM(RTRIM(c.[FacturaTipo])) = '' OR LTRIM(RTRIM(c.[FacturaTipo])) = 'A')";
      return;
    }
    $values = match ($tipoKey) {
      'ticket' => ['T'],
      'facturas' => ['F'],
      'ticketFacturas', 'ticketsFacturasContado' => ['T', 'F'],
      default => [],
    };
    if ($values === []) {
      return;
    }
    if (count($values) === 1) {
      $where[] = 'LTRIM(RTRIM(c.[FacturaTipo])) = :tipoVentaFt';
      $params['tipoVentaFt'] = $values[0];
      return;
    }
    $parts = [];
    foreach ($values as $i => $t) {
      $pk = 'tipoVentaFt' . $i;
      $parts[] = ':' . $pk;
      $params[$pk] = $t;
    }
    $where[] = 'LTRIM(RTRIM(c.[FacturaTipo])) IN (' . implode(', ', $parts) . ')';
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
      'vendedor' => $importe,
      default => $margen,
    };
  }

  /**
   * %Sob.Tot Crystal: porcentaje sobre el TOTAL GENERAL del informe (no sobre el bloque).
   *
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
   * @param list<array<string, mixed>> $bloques
   * @param array{unidades: float, dto: float, importe: float, coste: float, margen: float} $totGeneral
   */
  private function aplicarPjeSobreTotalBloques(array &$bloques, array $totGeneral, string $orden): void
  {
    $baseGeneral = $this->baseOrdenInforme($orden, $totGeneral);
    foreach ($bloques as &$b) {
      if (isset($b['grupos']) && is_array($b['grupos'])) {
        foreach ($b['grupos'] as &$g) {
          $this->aplicarPjeSobreTotalGrupo($g, $orden, $baseGeneral);
        }
        unset($g);
      }
      if (isset($b['totales']) && is_array($b['totales'])) {
        $b['totales']['pjeSobreTotal'] = $this->pjeSobreTotal(
          $this->baseOrdenInforme($orden, $b['totales']),
          $baseGeneral
        );
      }
    }
    unset($b);
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
      'vendedor' => (float) ($totales['importe'] ?? 0),
      default => (float) ($totales['margen'] ?? 0),
    };

    return $base == 0.0 ? 1.0 : $base;
  }

  private function pjeSobreTotal(float $baseLinea, float $baseGeneral): float
  {
    return round(100.0 * $baseLinea / $baseGeneral, 2);
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

  private function tipoVentaLabel(string $tipoKey): string
  {
    return match ($tipoKey) {
      'ticket' => 'TICKET',
      'facturas' => 'FACTURAS',
      'ticketFacturas' => 'TICKET+FACTURAS',
      'albaranes' => 'ALBARANES',
      'ticketsFacturasContado' => 'TICKETS+FACTURAS CONTADO',
      default => 'TODOS',
    };
  }

  /**
   * Expresiones SQL de agrupación (cabecera de bloque ABC + GROUP BY).
   *
   * @return array{selectCodigo: string, selectNombre: string, groupBy: list<string>}
   */
  private function dimensionSql(string $dimension): array
  {
    return match ($dimension) {
      'dias-semana' => [
        'selectCodigo' => 'CAST(DATEPART(weekday, c.[Fecha]) AS nvarchar(10))',
        'selectNombre' => 'MAX(DATENAME(weekday, c.[Fecha]))',
        'groupBy' => ['DATEPART(weekday, c.[Fecha])'],
      ],
      'semanal' => [
        'selectCodigo' => "CONVERT(varchar(10), CAST(c.[Fecha] AS date), 23)",
        'selectNombre' => "MAX(CONVERT(varchar(10), CAST(c.[Fecha] AS date), 103))",
        'groupBy' => ['CAST(c.[Fecha] AS date)'],
      ],
      'horas' => [
        'selectCodigo' => 'CAST(DATEPART(hour, c.[Fecha]) AS nvarchar(2))',
        'selectNombre' => "MAX(RIGHT(N'0' + CAST(DATEPART(hour, c.[Fecha]) AS nvarchar(2)), 2) + N':00')",
        'groupBy' => ['DATEPART(hour, c.[Fecha])'],
      ],
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
      'clientes' => [
        'selectCodigo' => "RTRIM(ISNULL(c.[Cliente], ''))",
        'selectNombre' => "MAX(RTRIM(ISNULL(cl.[RazonSocial], '')))",
        'groupBy' => ["RTRIM(ISNULL(c.[Cliente], ''))"],
      ],
      'proveedores' => [
        'selectCodigo' => "RTRIM(ISNULL(a.[UltProveedor], ''))",
        'selectNombre' => "MAX(RTRIM(ISNULL(p.[RazonSocial], '')))",
        'groupBy' => ["RTRIM(ISNULL(a.[UltProveedor], ''))"],
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
      'perfiles' => [
        'selectCodigo' => "RTRIM(ISNULL(CAST(c.[Perfil] AS nvarchar(20)), ''))",
        'selectNombre' => "MAX(RTRIM(ISNULL(CAST(c.[Perfil] AS nvarchar(20)), '')))",
        'groupBy' => ["RTRIM(ISNULL(CAST(c.[Perfil] AS nvarchar(20)), ''))"],
      ],
      default => [
        'selectCodigo' => "RTRIM(ISNULL(c.[Vendedor], ''))",
        'selectNombre' => "MAX(RTRIM(ISNULL(v.[Nombre], '')))",
        'groupBy' => ["RTRIM(ISNULL(c.[Vendedor], ''))"],
      ],
    };
  }

  /**
   * VentasABC Horas — franja horaria + agrupación secundaria (familia, cliente…).
   *
   * @param array<string, mixed> $query
   * @param array<string, mixed> $params
   */
  private function generarInformeHoras(
    array $query,
    string $where,
    array $params,
    string $importeExpr,
    string $costeExpr,
    string $dtoExpr,
    string $unidadesExpr,
    string $albaranKey,
    ?string $fechaDesdeDia,
    ?string $fechaHastaDia,
    string $valor,
    bool $ivaIncluido,
    bool $imArticulos,
    string $orden,
  ): array {
    if (is_array($where)) {
      $where = implode(' AND ', $where);
    }

    $intervalo = $this->normalizarIntervaloHoras($query['intervaloHoras'] ?? 'hora');
    $graficoPor = $this->normalizarGraficoPorHoras($query['graficoPor'] ?? 'importe');
    $tipoGestion = $this->normalizarTipoGestionHoras($query['tipoGestionHoras'] ?? 'abcVentasHoras');
    $agrupacionHoras = $this->normalizarAgrupacionHoras($query['agrupacionHoras'] ?? 'familia');
    $ventaHoraria = $tipoGestion === 'ventaHoraria';

    if ($ventaHoraria) {
      return $this->generarInformeVentaHorariaMatriz(
        $query,
        $where,
        $params,
        $importeExpr,
        $unidadesExpr,
        $fechaDesdeDia,
        $fechaHastaDia,
        $valor,
        $ivaIncluido,
        $imArticulos,
        $orden,
        $intervalo,
        $graficoPor,
        $tipoGestion,
      );
    }

    return $this->generarInformeAbcHorasPlano(
      $query,
      $where,
      $params,
      $importeExpr,
      $costeExpr,
      $dtoExpr,
      $unidadesExpr,
      $albaranKey,
      $fechaDesdeDia,
      $fechaHastaDia,
      $valor,
      $ivaIncluido,
      $imArticulos,
      $orden,
      $intervalo,
      $graficoPor,
      $tipoGestion,
      $agrupacionHoras,
    );
  }

  /**
   * VentasABC Días de la semana — listado plano (una fila por día + tickets + gráfico).
   *
   * @param array<string, mixed> $query
   * @param array<string, mixed> $params
   */
  private function generarInformeDiasSemanaPlano(
    array $query,
    string $where,
    array $params,
    string $importeExpr,
    string $costeExpr,
    string $dtoExpr,
    string $unidadesExpr,
    string $albaranKey,
    ?string $fechaDesdeDia,
    ?string $fechaHastaDia,
    string $valor,
    bool $ivaIncluido,
    bool $imArticulos,
    string $orden,
  ): array {
    if (is_array($where)) {
      $where = implode(' AND ', $where);
    }

    $diaSql = "(DATEDIFF(day, '19000101', c.[Fecha]) % 7)";
    $diaFiltro = $this->normalizarDiaSemanaAbc($query['diaSemanaAbc'] ?? 'todos');
    if ($diaFiltro !== null) {
      $where .= " AND {$diaSql} = :diaSemIdx";
      $params['diaSemIdx'] = $diaFiltro;
    }

    $graficoPor = strtolower(trim((string) ($query['graficoPor'] ?? 'importe')));
    if ($graficoPor === 'unidad') {
      $graficoPor = 'unidades';
    }
    if (!in_array($graficoPor, ['importe', 'unidades'], true)) {
      $graficoPor = 'importe';
    }

    $sql = "SELECT
              {$diaSql} AS diaIdx,
              {$unidadesExpr} AS unidades,
              SUM({$dtoExpr}) AS dto,
              SUM({$importeExpr}) AS importe,
              SUM({$costeExpr}) AS coste,
              COUNT(DISTINCT {$albaranKey}) AS numAlbaranes
            FROM [AlbaranesVentasCab] c
            INNER JOIN [AlbaranesVentasLin] l
              ON c.[Empresa] = l.[Empresa] AND c.[Albaran] = l.[Albaran] AND c.[Tipo] = l.[Tipo]
            LEFT JOIN [Articulos] a ON a.[Codigo] = l.[Articulo]
            LEFT JOIN [Impuestos] i ON i.[Codigo] = COALESCE(NULLIF(RTRIM(a.[Impuesto]), ''), 'NO')
            LEFT JOIN [Clientes] cl ON cl.[Codigo] = c.[Cliente]
            LEFT JOIN [Familias] f ON RTRIM(f.[Codigo]) = RTRIM(a.[Familia])
            LEFT JOIN [MacroFamilias] mf ON RTRIM(mf.[Codigo]) = RTRIM(f.[MacroFamilia])
            LEFT JOIN [Subfamilias] sf ON RTRIM(sf.[Subfamilia]) = RTRIM(a.[Subfamilia])
            LEFT JOIN [Agrupaciones] ag ON RTRIM(ag.[Codigo]) = RTRIM(a.[Agrupacion])
            LEFT JOIN [Proveedores] p ON RTRIM(p.[Codigo]) = RTRIM(a.[UltProveedor])
            LEFT JOIN [Vendedores] v ON RTRIM(v.[Codigo]) = RTRIM(c.[Vendedor])
            WHERE {$where}
            GROUP BY {$diaSql}";

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);

    $grupos = [];
    $totGeneral = $this->totalesVacios();
    $totGeneral['tickets'] = 0.0;

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $diaIdx = (int) ($row['diaIdx'] ?? -1);
      if ($diaIdx < 0 || $diaIdx > 6) {
        continue;
      }
      $etiqueta = $this->etiquetaDiaSemanaAbcInforme($diaIdx);
      if ($etiqueta === '') {
        continue;
      }

      $unidades = (float) ($row['unidades'] ?? 0);
      $dto = (float) ($row['dto'] ?? 0);
      $importe = (float) ($row['importe'] ?? 0);
      $coste = (float) ($row['coste'] ?? 0);
      $numAlbaranes = (int) ($row['numAlbaranes'] ?? 0);
      $margen = $importe - $coste;
      $pjeMargen = $importe != 0.0 ? round(100.0 * $margen / $importe, 2) : 0.0;

      $totales = $this->redondearTotales([
        'unidades' => $unidades,
        'dto' => $dto,
        'importe' => $importe,
        'coste' => $coste,
        'margen' => $margen,
        'tickets' => $numAlbaranes,
      ]);
      $totales['pjeMargen'] = $pjeMargen;

      $grupos[] = [
        'codigo' => $etiqueta,
        'nombre' => '',
        'totales' => $totales,
        'articulos' => [],
        '_diaIdx' => $diaIdx,
        '_ordenValor' => match ($orden) {
          'cantidad' => $unidades,
          'importe' => $importe,
          'coste' => $coste,
          'diaSemana' => (float) $diaIdx,
          default => $margen,
        },
      ];

      $totGeneral['unidades'] += $unidades;
      $totGeneral['dto'] += $dto;
      $totGeneral['importe'] += $importe;
      $totGeneral['coste'] += $coste;
      $totGeneral['margen'] += $margen;
      $totGeneral['tickets'] += $numAlbaranes;
    }

    if ($orden === 'diaSemana') {
      usort(
        $grupos,
        static fn (array $a, array $b): int => ((int) ($a['_diaIdx'] ?? 0)) <=> ((int) ($b['_diaIdx'] ?? 0)),
      );
    } else {
      usort(
        $grupos,
        static fn (array $a, array $b): int => ((float) ($b['_ordenValor'] ?? 0)) <=> ((float) ($a['_ordenValor'] ?? 0)),
      );
    }
    foreach ($grupos as &$g) {
      unset($g['_diaIdx'], $g['_ordenValor']);
    }
    unset($g);

    $this->aplicarPjeSobreTotalGeneral($grupos, $totGeneral, $orden === 'diaSemana' ? 'importe' : $orden);

    $totGeneral = $this->redondearTotales($totGeneral);
    $totGeneral['pjeMargen'] = $totGeneral['importe'] != 0.0
      ? round(100.0 * $totGeneral['margen'] / $totGeneral['importe'], 2)
      : 0.0;
    $totGeneral['pjeSobreTotal'] = 100.0;

    return [
      'dimension' => 'dias-semana',
      'orden' => $orden,
      'valor' => $valor,
      'iva' => $ivaIncluido ? 'incluido' : 'desglosado',
      'imArticulos' => $imArticulos,
      'tipoVenta' => $this->tipoVentaLabel($this->normalizarTipoVenta($query['tipoVenta'] ?? 'todos')),
      'divisa' => (string) ($query['divisa'] ?? 'EU'),
      'diaSemanaAbc' => $query['diaSemanaAbc'] ?? 'todos',
      'graficoPor' => $graficoPor,
      'informePlanoDiasSemanaAbc' => true,
      'fechaDesde' => $fechaDesdeDia ?? '',
      'fechaHasta' => $fechaHastaDia ?? '',
      'totales' => $totGeneral,
      'grupos' => $grupos,
      'bloques' => null,
    ];
  }

  private function etiquetaDiaSemanaAbcInforme(int $diaIdx): string
  {
    return match ($diaIdx) {
      0 => '(1) Lunes',
      1 => '(2) Martes',
      2 => '(3) Miercoles',
      3 => '(4) Jueves',
      4 => '(5) Viernes',
      5 => '(6) Sabado',
      6 => '(7) Domingo',
      default => '',
    };
  }

  /**
   * VentasABC Horas — listado plano legacy (una fila por franja + tickets).
   *
   * @param array<string, mixed> $query
   * @param array<string, mixed> $params
   */
  private function generarInformeAbcHorasPlano(
    array $query,
    string $where,
    array $params,
    string $importeExpr,
    string $costeExpr,
    string $dtoExpr,
    string $unidadesExpr,
    string $albaranKey,
    ?string $fechaDesdeDia,
    ?string $fechaHastaDia,
    string $valor,
    bool $ivaIncluido,
    bool $imArticulos,
    string $orden,
    string $intervalo,
    string $graficoPor,
    string $tipoGestion,
    string $agrupacionHoras,
  ): array {
    $slot = $this->sqlFranjaHoraria($intervalo);
    $slotBucket = $slot['bucketExpr'];
    $groupBy = implode(', ', $slot['groupBy']);

    $sql = "SELECT
              MAX({$slot['selectCodigo']}) AS slotCodigo,
              {$slot['selectNombre']} AS slotNombre,
              MIN({$slotBucket}) AS slotOrden,
              {$unidadesExpr} AS unidades,
              SUM({$dtoExpr}) AS dto,
              SUM({$importeExpr}) AS importe,
              SUM({$costeExpr}) AS coste,
              COUNT(DISTINCT {$albaranKey}) AS numAlbaranes
            FROM [AlbaranesVentasCab] c
            INNER JOIN [AlbaranesVentasLin] l
              ON c.[Empresa] = l.[Empresa] AND c.[Albaran] = l.[Albaran] AND c.[Tipo] = l.[Tipo]
            LEFT JOIN [Articulos] a ON a.[Codigo] = l.[Articulo]
            LEFT JOIN [Impuestos] i ON i.[Codigo] = COALESCE(NULLIF(RTRIM(a.[Impuesto]), ''), 'NO')
            LEFT JOIN [Clientes] cl ON cl.[Codigo] = c.[Cliente]
            LEFT JOIN [Familias] f ON RTRIM(f.[Codigo]) = RTRIM(a.[Familia])
            LEFT JOIN [MacroFamilias] mf ON RTRIM(mf.[Codigo]) = RTRIM(f.[MacroFamilia])
            LEFT JOIN [Subfamilias] sf ON RTRIM(sf.[Subfamilia]) = RTRIM(a.[Subfamilia])
            LEFT JOIN [Agrupaciones] ag ON RTRIM(ag.[Codigo]) = RTRIM(a.[Agrupacion])
            LEFT JOIN [Proveedores] p ON RTRIM(p.[Codigo]) = RTRIM(a.[UltProveedor])
            LEFT JOIN [Vendedores] v ON RTRIM(v.[Codigo]) = RTRIM(c.[Vendedor])
            WHERE {$where}
            GROUP BY {$groupBy}";

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);

    $grupos = [];
    $totGeneral = $this->totalesVacios();
    $totGeneral['tickets'] = 0.0;

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $slotOrden = (int) ($row['slotOrden'] ?? 0);
      $slotCodigo = (string) ($row['slotCodigo'] ?? '');
      $slotNombre = (string) ($row['slotNombre'] ?? '');
      $etiqueta = $this->etiquetaFranjaAbcHoras($intervalo, $slotOrden, $slotCodigo, $slotNombre);
      if ($etiqueta === '') {
        continue;
      }

      $unidades = (float) ($row['unidades'] ?? 0);
      $dto = (float) ($row['dto'] ?? 0);
      $importe = (float) ($row['importe'] ?? 0);
      $coste = (float) ($row['coste'] ?? 0);
      $numAlbaranes = (int) ($row['numAlbaranes'] ?? 0);
      $margen = $importe - $coste;
      $pjeMargen = $importe != 0.0 ? round(100.0 * $margen / $importe, 2) : 0.0;

      $totales = $this->redondearTotales([
        'unidades' => $unidades,
        'dto' => $dto,
        'importe' => $importe,
        'coste' => $coste,
        'margen' => $margen,
        'tickets' => $numAlbaranes,
      ]);
      $totales['pjeMargen'] = $pjeMargen;

      $grupos[] = [
        'codigo' => $etiqueta,
        'nombre' => '',
        'totales' => $totales,
        'articulos' => [],
        '_slotOrden' => $slotOrden,
        '_ordenValor' => match ($orden) {
          'cantidad' => $unidades,
          'importe' => $importe,
          'coste' => $coste,
          'horas' => (float) $slotOrden,
          default => $margen,
        },
      ];

      $totGeneral['unidades'] += $unidades;
      $totGeneral['dto'] += $dto;
      $totGeneral['importe'] += $importe;
      $totGeneral['coste'] += $coste;
      $totGeneral['margen'] += $margen;
      $totGeneral['tickets'] += $numAlbaranes;
    }

    // Legacy VentasABC Horas: siempre cronológico por franja.
    usort(
      $grupos,
      static fn (array $a, array $b): int => ((int) ($a['_slotOrden'] ?? 0)) <=> ((int) ($b['_slotOrden'] ?? 0)),
    );
    foreach ($grupos as &$g) {
      unset($g['_slotOrden'], $g['_ordenValor']);
    }
    unset($g);

    $this->aplicarPjeSobreTotalGeneral($grupos, $totGeneral, 'margen');

    $totGeneral = $this->redondearTotales($totGeneral);
    $totGeneral['pjeMargen'] = $totGeneral['importe'] != 0.0
      ? round(100.0 * $totGeneral['margen'] / $totGeneral['importe'], 2)
      : 0.0;
    $totGeneral['pjeSobreTotal'] = 100.0;

    return [
      'dimension' => 'horas',
      'orden' => 'horas',
      'valor' => $valor,
      'iva' => $ivaIncluido ? 'incluido' : 'desglosado',
      'imArticulos' => $imArticulos,
      'tipoVenta' => $this->tipoVentaLabel($this->normalizarTipoVenta($query['tipoVenta'] ?? 'todos')),
      'divisa' => (string) ($query['divisa'] ?? 'EU'),
      'intervaloHoras' => $intervalo,
      'graficoPor' => $graficoPor,
      'tipoGestionHoras' => $tipoGestion,
      'agrupacionHoras' => $agrupacionHoras,
      'informePlanoHorasAbc' => true,
      'fechaDesde' => $fechaDesdeDia ?? '',
      'fechaHasta' => $fechaHastaDia ?? '',
      'totales' => $totGeneral,
      'grupos' => $grupos,
      'bloques' => null,
    ];
  }

  private function etiquetaFranjaAbcHoras(
    string $intervalo,
    int $slotOrden,
    string $slotCodigo,
    string $slotNombre,
  ): string {
    if ($intervalo === 'hora') {
      $hour = (int) floor($slotOrden / 60);
      if ($hour < 0 || $hour > 23) {
        return $slotNombre !== '' ? $slotNombre : $slotCodigo;
      }

      return sprintf('%02d:00-%02d:59', $hour, $hour);
    }

    $step = match ($intervalo) {
      'mediaHora' => 30,
      'cuartoHora' => 15,
      'cincoMinutos' => 5,
      default => 60,
    };
    $start = max(0, $slotOrden);
    $end = min(24 * 60 - 1, $start + $step - 1);

    return sprintf(
      '%02d:%02d-%02d:%02d',
      (int) floor($start / 60),
      $start % 60,
      (int) floor($end / 60),
      $end % 60,
    );
  }

  /**
   * Venta horaria legacy: matriz filas = familia, columnas = franja horaria.
   *
   * @param array<string, mixed> $query
   * @param array<string, mixed> $params
   */
  private function generarInformeVentaHorariaMatriz(
    array $query,
    string $where,
    array $params,
    string $importeExpr,
    string $unidadesExpr,
    ?string $fechaDesdeDia,
    ?string $fechaHastaDia,
    string $valor,
    bool $ivaIncluido,
    bool $imArticulos,
    string $orden,
    string $intervalo,
    string $graficoPor,
    string $tipoGestion,
  ): array {
    $slot = $this->sqlFranjaHoraria($intervalo);
    $slotBucket = $slot['bucketExpr'];
    $famDim = $this->dimensionSql('familias');
    $groupBy = implode(', ', [...$slot['groupBy'], ...$famDim['groupBy']]);
    $metrica = $graficoPor === 'unidades' ? 'unidades' : 'importe';
    $valorExpr = $metrica === 'unidades' ? $unidadesExpr : $importeExpr;

    $sql = "SELECT
              MAX({$slot['selectCodigo']}) AS slotCodigo,
              {$slot['selectNombre']} AS slotNombre,
              MIN({$slotBucket}) AS slotOrden,
              {$famDim['selectCodigo']} AS filaCodigo,
              {$famDim['selectNombre']} AS filaNombre,
              SUM({$valorExpr}) AS valor
            FROM [AlbaranesVentasCab] c
            INNER JOIN [AlbaranesVentasLin] l
              ON c.[Empresa] = l.[Empresa] AND c.[Albaran] = l.[Albaran] AND c.[Tipo] = l.[Tipo]
            LEFT JOIN [Articulos] a ON a.[Codigo] = l.[Articulo]
            LEFT JOIN [Impuestos] i ON i.[Codigo] = COALESCE(NULLIF(RTRIM(a.[Impuesto]), ''), 'NO')
            LEFT JOIN [Clientes] cl ON cl.[Codigo] = c.[Cliente]
            LEFT JOIN [Familias] f ON RTRIM(f.[Codigo]) = RTRIM(a.[Familia])
            LEFT JOIN [MacroFamilias] mf ON RTRIM(mf.[Codigo]) = RTRIM(f.[MacroFamilia])
            LEFT JOIN [Subfamilias] sf ON RTRIM(sf.[Subfamilia]) = RTRIM(a.[Subfamilia])
            LEFT JOIN [Agrupaciones] ag ON RTRIM(ag.[Codigo]) = RTRIM(a.[Agrupacion])
            LEFT JOIN [Proveedores] p ON RTRIM(p.[Codigo]) = RTRIM(a.[UltProveedor])
            LEFT JOIN [Vendedores] v ON RTRIM(v.[Codigo]) = RTRIM(c.[Vendedor])
            WHERE {$where}
            GROUP BY {$groupBy}";

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);

    /** @var array<string, array{codigo: string, nombre: string, slotOrden: int, valores: array<string, float>}> $filasRaw */
    $filasRaw = [];
    /** @var array<string, array{id: string, nombre: string, slotOrden: int}> $colsRaw */
    $colsRaw = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $slotOrden = (int) ($row['slotOrden'] ?? 0);
      $slotCodigo = (string) ($row['slotCodigo'] ?? '');
      $colId = $this->idColumnaVentaHoraria($intervalo, $slotOrden, $slotCodigo);
      if (!isset($colsRaw[$colId])) {
        $colsRaw[$colId] = [
          'id' => $colId,
          'nombre' => $this->etiquetaColumnaVentaHoraria($intervalo, $slotOrden, $slotCodigo, (string) ($row['slotNombre'] ?? '')),
          'slotOrden' => $this->ordenColumnaVentaHoraria($intervalo, $slotOrden),
        ];
      }

      $filaCodigo = (string) ($row['filaCodigo'] ?? '');
      $filaKey = $filaCodigo !== '' ? $filaCodigo : '(sin)';
      if (!isset($filasRaw[$filaKey])) {
        $filasRaw[$filaKey] = [
          'codigo' => $filaCodigo,
          'nombre' => (string) ($row['filaNombre'] ?? ''),
          'valores' => [],
        ];
      }
      $v = (float) ($row['valor'] ?? 0);
      $filasRaw[$filaKey]['valores'][$colId] = ($filasRaw[$filaKey]['valores'][$colId] ?? 0.0) + $v;
    }

    uasort($colsRaw, static fn (array $a, array $b): int => $a['slotOrden'] <=> $b['slotOrden']);
    $columnas = array_values(array_map(static fn (array $c): array => [
      'id' => $c['id'],
      'nombre' => $c['nombre'],
    ], $colsRaw));

    $totalesColumna = [];
    $totalGeneral = 0.0;
    $filas = [];
    foreach ($filasRaw as $f) {
      $totalFila = 0.0;
      $celdas = [];
      foreach ($columnas as $col) {
        $id = $col['id'];
        $val = round((float) ($f['valores'][$id] ?? 0.0), $metrica === 'unidades' ? 3 : 2);
        $celdas[$id] = $val;
        $totalFila += $val;
        $totalesColumna[$id] = ($totalesColumna[$id] ?? 0.0) + $val;
      }
      $totalFila = round($totalFila, $metrica === 'unidades' ? 3 : 2);
      $totalGeneral += $totalFila;
      $filas[] = [
        'codigo' => $f['codigo'],
        'nombre' => $f['nombre'],
        'celdas' => $celdas,
        'total' => $totalFila,
      ];
    }

    usort($filas, static function (array $a, array $b): int {
      $cmp = strcasecmp((string) ($a['nombre'] ?? ''), (string) ($b['nombre'] ?? ''));
      if ($cmp !== 0) {
        return $cmp;
      }
      return strcmp((string) ($a['codigo'] ?? ''), (string) ($b['codigo'] ?? ''));
    });

    foreach ($totalesColumna as $id => $t) {
      $totalesColumna[$id] = round($t, $metrica === 'unidades' ? 3 : 2);
    }
    $totalGeneral = round($totalGeneral, $metrica === 'unidades' ? 3 : 2);
    foreach ($columnas as $col) {
      $id = $col['id'];
      if (!isset($totalesColumna[$id])) {
        $totalesColumna[$id] = 0.0;
      }
    }

    $totGeneral = $this->totalesVacios();
    $totGeneral['importe'] = $metrica === 'importe' ? $totalGeneral : 0.0;
    $totGeneral['unidades'] = $metrica === 'unidades' ? $totalGeneral : 0.0;

    return [
      'dimension' => 'horas',
      'orden' => $orden,
      'valor' => $valor,
      'iva' => $ivaIncluido ? 'incluido' : 'desglosado',
      'imArticulos' => $imArticulos,
      'tipoVenta' => $this->tipoVentaLabel($this->normalizarTipoVenta($query['tipoVenta'] ?? 'todos')),
      'divisa' => (string) ($query['divisa'] ?? 'EU'),
      'intervaloHoras' => $intervalo,
      'graficoPor' => $graficoPor,
      'tipoGestionHoras' => $tipoGestion,
      'agrupacionHoras' => null,
      'fechaDesde' => $fechaDesdeDia ?? '',
      'fechaHasta' => $fechaHastaDia ?? '',
      'totales' => $totGeneral,
      'grupos' => [],
      'bloques' => null,
      'matrizVentaHoraria' => [
        'metrica' => $metrica,
        'columnas' => $columnas,
        'filas' => $filas,
        'totalesColumna' => $totalesColumna,
        'totalGeneral' => $totalGeneral,
      ],
    ];
  }

  private function idColumnaVentaHoraria(string $intervalo, int $slotOrden, string $slotCodigo): string
  {
    if ($intervalo === 'hora') {
      $hour = (int) floor($slotOrden / 60);
      if ($hour >= 0 && $hour <= 6) {
        return 'h0-6';
      }

      return 'h' . $hour;
    }

    return 's' . $slotOrden;
  }

  private function ordenColumnaVentaHoraria(string $intervalo, int $slotOrden): int
  {
    if ($intervalo === 'hora') {
      $hour = (int) floor($slotOrden / 60);
      if ($hour >= 0 && $hour <= 6) {
        return 0;
      }

      return ($hour + 1) * 60;
    }

    return $slotOrden;
  }

  private function etiquetaColumnaVentaHoraria(
    string $intervalo,
    int $slotOrden,
    string $slotCodigo,
    string $slotNombre,
  ): string {
    if ($intervalo === 'hora') {
      $hour = (int) floor($slotOrden / 60);
      if ($hour >= 0 && $hour <= 6) {
        return '00:00-06:59';
      }

      return sprintf('%02d:00-%02d:59', $hour, $hour);
    }

    if ($slotNombre !== '') {
      return $slotNombre;
    }

    return $slotCodigo;
  }

  /** @return array{selectCodigo: string, selectNombre: string, groupBy: list<string>, bucketExpr: string} */
  private function sqlFranjaHoraria(string $intervalo): array
  {
    if ($intervalo === 'hora') {
      $bucket = 'DATEPART(hour, c.[Fecha]) * 60';
      return [
        'bucketExpr' => $bucket,
        'selectCodigo' => "RIGHT(N'0' + CAST(DATEPART(hour, c.[Fecha]) AS nvarchar(2)), 2)",
        'selectNombre' => "MAX(RIGHT(N'0' + CAST(DATEPART(hour, c.[Fecha]) AS nvarchar(2)), 2) + N':00')",
        'groupBy' => ['DATEPART(hour, c.[Fecha])'],
      ];
    }

    $mins = 'DATEDIFF(minute, CAST(CAST(c.[Fecha] AS date) AS datetime), c.[Fecha])';
    $step = match ($intervalo) {
      'mediaHora' => 30,
      'cuartoHora' => 15,
      'cincoMinutos' => 5,
      default => 60,
    };
    $bucket = "(({$mins}) / {$step}) * {$step}";

    return [
      'bucketExpr' => $bucket,
      'selectCodigo' => "CAST({$bucket} AS nvarchar(10))",
      'selectNombre' => "MAX(RIGHT(N'0' + CAST(({$bucket}) / 60 AS nvarchar(2)), 2) + N':' + RIGHT(N'0' + CAST(({$bucket}) % 60 AS nvarchar(2)), 2))",
      'groupBy' => [$bucket],
    ];
  }

  /** @return array{selectCodigo: string, selectNombre: string, groupBy: list<string>} */
  private function dimensionSqlAgrupacionHoras(string $agr): array
  {
    return match ($agr) {
      'subfamilia' => $this->dimensionSql('subfamilias'),
      'agrupaciones' => $this->dimensionSql('agrupaciones'),
      'macrofamilias' => $this->dimensionSql('macrofamilias'),
      'clientes' => $this->dimensionSql('clientes'),
      'proveedores' => $this->dimensionSql('proveedores'),
      'vendedores' => $this->dimensionSql('vendedores'),
      'diaSemana' => $this->dimensionSql('dias-semana'),
      default => $this->dimensionSql('familias'),
    };
  }

  private function normalizarIntervaloHoras(mixed $raw): string
  {
    $key = strtolower(preg_replace('/[\s_.\\/]+/', '', trim((string) $raw)) ?? '');
    $map = [
      'hora' => 'hora',
      '1hora' => 'hora',
      'mediahora' => 'mediaHora',
      '12hora' => 'mediaHora',
      'medihora' => 'mediaHora',
      'cuartohora' => 'cuartoHora',
      '14hora' => 'cuartoHora',
      '5minutos' => 'cincoMinutos',
      'cincominutos' => 'cincoMinutos',
    ];

    return $map[$key] ?? 'hora';
  }

  private function normalizarGraficoPorHoras(mixed $raw): string
  {
    $key = strtolower(preg_replace('/[\s_]+/', '', trim((string) $raw)) ?? '');
    return $key === 'unidades' || $key === 'cantidad' ? 'unidades' : 'importe';
  }

  private function normalizarTipoGestionHoras(mixed $raw): string
  {
    $key = strtolower(preg_replace('/[\s_]+/', '', trim((string) $raw)) ?? '');
    if (str_contains($key, 'ventahoraria') || $key === 'ventahoraria') {
      return 'ventaHoraria';
    }

    return 'abcVentasHoras';
  }

  private function normalizarAgrupacionHoras(mixed $raw): string
  {
    $key = strtolower(preg_replace('/[\s_]+/', '', trim((string) $raw)) ?? '');
    $map = [
      'familia' => 'familia',
      'familias' => 'familia',
      'subfamilia' => 'subfamilia',
      'subfamilias' => 'subfamilia',
      'agrupacion' => 'agrupaciones',
      'agrupaciones' => 'agrupaciones',
      'macrofamilia' => 'macrofamilias',
      'macrofamilias' => 'macrofamilias',
      'cliente' => 'clientes',
      'clientes' => 'clientes',
      'proveedor' => 'proveedores',
      'proveedores' => 'proveedores',
      'vendedor' => 'vendedores',
      'vendedores' => 'vendedores',
      'diasemana' => 'diaSemana',
      'diasdelasemana' => 'diaSemana',
    ];

    return $map[$key] ?? 'familia';
  }

  /**
   * VentasABC Semanal — matriz semanas × días (desglose importe/unidades/coste).
   *
   * @param array<string, mixed> $query
   * @param array<string, mixed> $params
   */
  private function generarInformeSemanalMatriz(
    array $query,
    string $where,
    array $params,
    string $importeExpr,
    string $costeExpr,
    string $unidadesExpr,
    ?string $fechaDesdeDia,
    ?string $fechaHastaDia,
    string $valor,
    bool $ivaIncluido,
    bool $imArticulos,
    string $orden,
  ): array {
    if (is_array($where)) {
      $where = implode(' AND ', $where);
    }

    $diaFiltro = $this->normalizarDiaSemanaAbc($query['diaSemanaAbc'] ?? 'todos');
    $desglose = $this->normalizarDesgloseSemanal($query['desgloseSemanal'] ?? 'importe');
    $diaSql = "(DATEDIFF(day, '19000101', c.[Fecha]) % 7)";

    if ($diaFiltro !== null) {
      $where .= " AND {$diaSql} = :diaSemIdx";
      $params['diaSemIdx'] = $diaFiltro;
    }

    $valorExpr = match ($desglose) {
      'unidades' => $unidadesExpr,
      'coste' => "SUM({$costeExpr})",
      default => "SUM({$importeExpr})",
    };

    $sql = "SELECT
              CAST(c.[Fecha] AS date) AS diaVenta,
              {$diaSql} AS diaIdx,
              {$valorExpr} AS valor
            FROM [AlbaranesVentasCab] c
            INNER JOIN [AlbaranesVentasLin] l
              ON c.[Empresa] = l.[Empresa] AND c.[Albaran] = l.[Albaran] AND c.[Tipo] = l.[Tipo]
            LEFT JOIN [Articulos] a ON a.[Codigo] = l.[Articulo]
            LEFT JOIN [Impuestos] i ON i.[Codigo] = COALESCE(NULLIF(RTRIM(a.[Impuesto]), ''), 'NO')
            LEFT JOIN [Clientes] cl ON cl.[Codigo] = c.[Cliente]
            LEFT JOIN [Familias] f ON RTRIM(f.[Codigo]) = RTRIM(a.[Familia])
            LEFT JOIN [MacroFamilias] mf ON RTRIM(mf.[Codigo]) = RTRIM(f.[MacroFamilia])
            LEFT JOIN [Subfamilias] sf ON RTRIM(sf.[Subfamilia]) = RTRIM(a.[Subfamilia])
            LEFT JOIN [Agrupaciones] ag ON RTRIM(ag.[Codigo]) = RTRIM(a.[Agrupacion])
            LEFT JOIN [Proveedores] p ON RTRIM(p.[Codigo]) = RTRIM(a.[UltProveedor])
            LEFT JOIN [Vendedores] v ON RTRIM(v.[Codigo]) = RTRIM(c.[Vendedor])
            WHERE {$where}
            GROUP BY CAST(c.[Fecha] AS date), {$diaSql}
            ORDER BY CAST(c.[Fecha] AS date)";

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);

    $columnas = $this->columnasMatrizSemanal($diaFiltro);
    /** @var array<string, array{codigo: string, nombre: string, orden: int, valores: array<string, float>}> $semanasRaw */
    $semanasRaw = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $diaRaw = $row['diaVenta'] ?? null;
      if ($diaRaw === null || $diaRaw === '') {
        continue;
      }
      try {
        $fecha = new \DateTimeImmutable(is_string($diaRaw) ? substr($diaRaw, 0, 10) : (string) $diaRaw);
      } catch (\Throwable) {
        continue;
      }
      $diaIdx = (int) ($row['diaIdx'] ?? 0);
      $bucket = $this->semanaLegacyMesBucketVenta($fecha, $diaIdx);
      $semKey = $bucket['codigo'];
      $colId = 'd' . $diaIdx;
      if (!isset($semanasRaw[$semKey])) {
        $semanasRaw[$semKey] = [
          'codigo' => $semKey,
          'nombre' => $bucket['nombre'],
          'orden' => $bucket['orden'],
          'valores' => [],
        ];
      }
      $v = (float) ($row['valor'] ?? 0);
      $semanasRaw[$semKey]['valores'][$colId] = ($semanasRaw[$semKey]['valores'][$colId] ?? 0.0) + $v;
    }

    $totalesColumna = [];
    $totalGeneral = 0.0;
    $filas = [];
    foreach ($semanasRaw as $s) {
      $totalFila = 0.0;
      $celdas = [];
      foreach ($columnas as $col) {
        $id = $col['id'];
        $val = round((float) ($s['valores'][$id] ?? 0.0), $desglose === 'unidades' ? 3 : 2);
        $celdas[$id] = $val;
        $totalFila += $val;
        $totalesColumna[$id] = ($totalesColumna[$id] ?? 0.0) + $val;
      }
      $totalFila = round($totalFila, $desglose === 'unidades' ? 3 : 2);
      $totalGeneral += $totalFila;
      $filas[] = [
        'codigo' => $s['codigo'],
        'nombre' => $s['nombre'],
        'celdas' => $celdas,
        'total' => $totalFila,
        '_orden' => $s['orden'],
      ];
    }

    usort($filas, static fn (array $a, array $b): int => ((int) ($a['_orden'] ?? 0)) <=> ((int) ($b['_orden'] ?? 0)));
    foreach ($filas as &$f) {
      unset($f['_orden']);
    }
    unset($f);

    foreach ($totalesColumna as $id => $t) {
      $totalesColumna[$id] = round($t, $desglose === 'unidades' ? 3 : 2);
    }
    $totalGeneral = round($totalGeneral, $desglose === 'unidades' ? 3 : 2);
    foreach ($columnas as $col) {
      if (!isset($totalesColumna[$col['id']])) {
        $totalesColumna[$col['id']] = 0.0;
      }
    }

    $totGeneral = $this->totalesVacios();
    if ($desglose === 'importe') {
      $totGeneral['importe'] = $totalGeneral;
    } elseif ($desglose === 'unidades') {
      $totGeneral['unidades'] = $totalGeneral;
    } else {
      $totGeneral['coste'] = $totalGeneral;
    }

    return [
      'dimension' => 'semanal',
      'orden' => 'horas',
      'valor' => $valor,
      'iva' => $ivaIncluido ? 'incluido' : 'desglosado',
      'imArticulos' => $imArticulos,
      'tipoVenta' => $this->tipoVentaLabel($this->normalizarTipoVenta($query['tipoVenta'] ?? 'todos')),
      'divisa' => (string) ($query['divisa'] ?? 'EU'),
      'diaSemanaAbc' => $query['diaSemanaAbc'] ?? 'todos',
      'desgloseSemanal' => $desglose,
      'fechaDesde' => $fechaDesdeDia ?? '',
      'fechaHasta' => $fechaHastaDia ?? '',
      'totales' => $totGeneral,
      'grupos' => [],
      'bloques' => null,
      'matrizSemanal' => [
        'metrica' => $desglose,
        'columnas' => $columnas,
        'filas' => $filas,
        'totalesColumna' => $totalesColumna,
        'totalGeneral' => $totalGeneral,
      ],
    ];
  }

  /**
   * Bucket legacy para una venta concreta (VentasABC Semanal / Crystal).
   *
   * @return array{codigo: string, nombre: string, orden: int}
   */
  private function semanaLegacyMesBucketVenta(\DateTimeImmutable $fecha, int $diaIdx): array
  {
    $mesInicio = $fecha->modify('first day of this month')->setTime(0, 0);
    $lunesSemana = $fecha->modify('-' . $diaIdx . ' days');

    if ($lunesSemana < $mesInicio) {
      return $this->semanaLegacyMesParcialInicial($mesInicio);
    }

    return $this->semanaLegacyMesParaLunes($lunesSemana->modify('-7 days'), $mesInicio);
  }

  /** Semana 01: desde el día 1 del mes hasta el domingo anterior al primer lunes. */
  private function semanaLegacyMesParcialInicial(\DateTimeImmutable $mesInicio): array
  {
    $n = (int) $mesInicio->format('N');
    $anioMes = (int) $mesInicio->format('Y');
    $mesFin = $mesInicio->modify('last day of this month');

    if ($n === 1) {
      $fin = $mesInicio->modify('+6 days');
      if ($fin > $mesFin) {
        $fin = $mesFin;
      }

      return $this->semanaLegacyMesEtiqueta(1, $anioMes, $mesInicio, $fin);
    }

    $primerLunes = $mesInicio->modify('+' . (8 - $n) . ' days');
    $fin = $primerLunes->modify('-1 day');

    return $this->semanaLegacyMesEtiqueta(1, $anioMes, $mesInicio, $fin);
  }

  /**
   * Semana del mes a partir del lunes de referencia (dentro del mes en la etiqueta).
   *
   * @return array{codigo: string, nombre: string, orden: int}
   */
  private function semanaLegacyMesParaLunes(\DateTimeImmutable $lunesRef, \DateTimeImmutable $mesInicio): array
  {
    $mesFin = $mesInicio->modify('last day of this month');
    $anioMes = (int) $mesInicio->format('Y');

    $n = (int) $mesInicio->format('N');
    $primerLunes = $n === 1
      ? $mesInicio
      : $mesInicio->modify('+' . (8 - $n) . ' days');

    if ($lunesRef < $primerLunes) {
      return $this->semanaLegacyMesParcialInicial($mesInicio);
    }

    $dias = (int) $primerLunes->diff($lunesRef)->days;
    $idx = intdiv($dias, 7);
    $num = 2 + $idx;
    $inicio = $lunesRef;
    $fin = $inicio->modify('+6 days');
    if ($fin > $mesFin) {
      $fin = $mesFin;
    }
    if ($inicio < $mesInicio) {
      $inicio = $mesInicio;
    }

    return $this->semanaLegacyMesEtiqueta($num, $anioMes, $inicio, $fin);
  }

  /**
   * @return array{codigo: string, nombre: string, orden: int}
   */
  private function semanaLegacyMesEtiqueta(
    int $num,
    int $anioMes,
    \DateTimeImmutable $inicio,
    \DateTimeImmutable $fin,
  ): array {
    $codigo = sprintf('%04d-%02d', $anioMes, $num);

    return [
      'codigo' => $codigo,
      'nombre' => sprintf(
        '%02d-%04d %s %s',
        $num,
        $anioMes,
        $inicio->format('d/m/Y'),
        $fin->format('d/m/Y'),
      ),
      'orden' => $anioMes * 100 + $num,
    ];
  }

  /** @return list<array{id: string, nombre: string}> */
  private function columnasMatrizSemanal(?int $diaFiltro): array
  {
    $dias = [
      0 => ['id' => 'd0', 'nombre' => 'Lunes'],
      1 => ['id' => 'd1', 'nombre' => 'Martes'],
      2 => ['id' => 'd2', 'nombre' => 'Miércoles'],
      3 => ['id' => 'd3', 'nombre' => 'Jueves'],
      4 => ['id' => 'd4', 'nombre' => 'Viernes'],
      5 => ['id' => 'd5', 'nombre' => 'Sabado'],
      6 => ['id' => 'd6', 'nombre' => 'Domingo'],
    ];
    if ($diaFiltro !== null) {
      return [$dias[$diaFiltro]];
    }

    return array_values($dias);
  }

  /** Lunes=0 … Domingo=6; null = todos. */
  private function normalizarDiaSemanaAbc(mixed $raw): ?int
  {
    $key = strtolower(preg_replace('/[\s_áéíóú]+/', '', trim((string) $raw)) ?? '');
    $key = str_replace(['á', 'é', 'í', 'ó', 'ú'], ['a', 'e', 'i', 'o', 'u'], $key);

    return match ($key) {
      '', 'todos', 'todas' => null,
      'lunes' => 0,
      'martes' => 1,
      'miercoles' => 2,
      'jueves' => 3,
      'viernes' => 4,
      'sabado' => 5,
      'domingo' => 6,
      default => null,
    };
  }

  private function normalizarDesgloseSemanal(mixed $raw): string
  {
    $key = strtolower(preg_replace('/[\s_]+/', '', trim((string) $raw)) ?? '');
    return match ($key) {
      'unidades', 'cantidad' => 'unidades',
      'coste' => 'coste',
      default => 'importe',
    };
  }
}
