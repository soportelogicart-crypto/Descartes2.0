<?php

declare(strict_types=1);

namespace Descartes\Api\Repositories;

use PDO;

/**
 * Estadistica y consumo por cliente (legacy frmEstClientes / frmEstClientesSubFamilias).
 *
 * La estadistica lee [CliImpVentas] tal cual, igual que el legacy: son acumulados
 * grabados por el proceso de ventas, no un recuento de albaranes. El consumo si se
 * calcula sobre las lineas de AlbaranesVentas.
 */
final class ClientesEstadisticaRepository
{
  /** Agrupaciones de consumo: expresion de agrupado + catalogo de descripciones. */
  private const AGRUPACIONES = [
    // Los codigos son numericos en BD y el ORDER BY debe caer sobre la columna, no
    // sobre el alias del SELECT: de ahi que el alias no pueda llamarse igual que la
    // columna (si no ordena como texto: 1,2,3,30,4... en vez de 1,2,3,4,...,30).
    'macrofamilia' => [
      'expr' => 'f.MacroFamilia',
      'catalogo' => 'SELECT LTRIM(RTRIM([Codigo])) AS cod, [Descripcion] AS des FROM [MacroFamilias] ORDER BY [Codigo]',
    ],
    'familia' => [
      'expr' => 'a.Familia',
      'catalogo' => 'SELECT LTRIM(RTRIM([Codigo])) AS cod, [Descripcion] AS des FROM [Familias] ORDER BY [Codigo]',
    ],
    'subfamilia' => [
      'expr' => 'a.Subfamilia',
      'catalogo' => 'SELECT LTRIM(RTRIM([Subfamilia])) AS cod, [Descripción] AS des FROM [Subfamilias] ORDER BY [Subfamilia]',
    ],
    'agrupacion' => [
      'expr' => 'a.Agrupacion',
      'catalogo' => 'SELECT LTRIM(RTRIM([Codigo])) AS cod, [Descripcion] AS des FROM [Agrupaciones] ORDER BY [Codigo]',
    ],
  ];

  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  public function clienteExiste(string $codigo): bool
  {
    $stmt = $this->pdo->prepare('SELECT 1 FROM [Clientes] WHERE [Codigo] = :codigo');
    $stmt->execute(['codigo' => $codigo]);
    return (bool) $stmt->fetchColumn();
  }

  /** @return list<string> */
  public static function agrupacionesValidas(): array
  {
    return array_keys(self::AGRUPACIONES);
  }

  /**
   * Doce meses del ejercicio con numero de ventas, importe, prevision y fidelizacion.
   *
   * @return array<string, mixed>
   */
  public function estadisticaAnual(string $codigo, int $anio): array
  {
    $acumulados = $this->acumuladosCliImpVentas($codigo, $anio);

    $meses = [];
    $totales = ['numVentas' => 0, 'importe' => 0.0, 'prevision' => 0.0, 'puntos' => 0.0, 'fidelizacion' => 0.0];
    for ($mes = 1; $mes <= 12; $mes++) {
      $fila = [
        'mes' => $mes,
        'numVentas' => (int) ($acumulados[$mes]['numVentas'] ?? 0),
        'importe' => round((float) ($acumulados[$mes]['importe'] ?? 0.0), 2),
        'prevision' => round((float) ($acumulados[$mes]['prevision'] ?? 0.0), 2),
        'puntos' => round((float) ($acumulados[$mes]['puntos'] ?? 0.0), 2),
        'fidelizacion' => round((float) ($acumulados[$mes]['fidelizacion'] ?? 0.0), 2),
      ];
      $meses[] = $fila;
      $totales['numVentas'] += $fila['numVentas'];
      $totales['importe'] += $fila['importe'];
      $totales['prevision'] += $fila['prevision'];
      $totales['puntos'] += $fila['puntos'];
      $totales['fidelizacion'] += $fila['fidelizacion'];
    }
    foreach (['importe', 'prevision', 'puntos', 'fidelizacion'] as $key) {
      $totales[$key] = round($totales[$key], 2);
    }

    return ['anio' => $anio, 'meses' => $meses, 'totales' => $totales];
  }

  /**
   * Graba la prevision del mes creando la fila de [CliImpVentas] si no existe.
   */
  public function guardarPrevision(string $codigo, int $anio, int $mes, float $prevision): void
  {
    $prevision = round($prevision, 2);
    $stmt = $this->pdo->prepare(
      'UPDATE [CliImpVentas] SET [Prevision] = :prevision
       WHERE [Codigo] = :codigo AND [Año] = :anio AND [Mes] = :mes'
    );
    $stmt->execute([
      'prevision' => $prevision,
      'codigo' => $codigo,
      'anio' => $anio,
      'mes' => $mes,
    ]);
    if ($stmt->rowCount() > 0) {
      return;
    }

    $stmt = $this->pdo->prepare(
      'INSERT INTO [CliImpVentas]
        ([Codigo], [Año], [Mes], [NumVentas], [ImpVentas], [Prevision],
         [AcumuladoPuntos], [AcumuladoFidelizacion], [LiquidadoPuntos], [LiquidadoFidelizacion])
       VALUES (:codigo, :anio, :mes, 0, 0, :prevision, 0, 0, 0, 0)'
    );
    $stmt->execute([
      'codigo' => $codigo,
      'anio' => $anio,
      'mes' => $mes,
      'prevision' => $prevision,
    ]);
  }

  /**
   * Consumo del cliente: matriz mes x agrupacion para el ejercicio anterior y el
   * seleccionado, mas el porcentaje de variacion entre ambos (legacy: dos bloques
   * de 12 meses con su total y una fila final de %).
   *
   * @return array<string, mixed>
   */
  public function consumo(string $codigo, int $anio, string $agrupacion, string $medida): array
  {
    $config = self::AGRUPACIONES[$agrupacion] ?? null;
    if ($config === null) {
      throw new \InvalidArgumentException('Agrupacion no valida');
    }
    $expr = $config['expr'];
    $valor = $medida === 'cantidad'
      ? 'SUM(l.[Cantidad])'
      : 'SUM(l.[Importe] - ((l.[Importe] / 100) * ISNULL(c.[PjeDto], 0)))';
    $joinFamilias = $agrupacion === 'macrofamilia'
      ? 'LEFT JOIN [Familias] f ON LTRIM(RTRIM(f.[Codigo])) = LTRIM(RTRIM(a.[Familia]))'
      : '';

    // Excluye rectificativas/anulaciones y la linea de comentario legacy ('NO').
    $sql = "SELECT YEAR(c.[Fecha]) AS Anio, MONTH(c.[Fecha]) AS Mes,
                   LTRIM(RTRIM({$expr})) AS Codigo, {$valor} AS Valor
            FROM [AlbaranesVentasLin] l
            INNER JOIN [AlbaranesVentasCab] c
              ON c.[Empresa] = l.[Empresa] AND c.[Tipo] = l.[Tipo] AND c.[Albaran] = l.[Albaran]
            INNER JOIN [Articulos] a ON a.[Codigo] = l.[Articulo]
            {$joinFamilias}
            WHERE c.[Cliente] = :codigo
              AND c.[Fecha] >= CONVERT(datetime, :desde, 120)
              AND c.[Fecha] < CONVERT(datetime, :hasta, 120)
              AND (c.[FacturaTipo] IS NULL OR c.[FacturaTipo] NOT IN ('R', 'M', 'Z'))
              AND l.[Articulo] <> 'NO'
            GROUP BY YEAR(c.[Fecha]), MONTH(c.[Fecha]), LTRIM(RTRIM({$expr}))";

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute([
      'codigo' => $codigo,
      'desde' => sprintf('%04d-01-01 00:00:00', $anio - 1),
      'hasta' => sprintf('%04d-01-01 00:00:00', $anio + 1),
    ]);

    $columnas = $this->columnasCatalogo($config['catalogo']);
    $codigosValidos = array_column($columnas, 'codigo');

    $valores = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $cod = trim((string) $row['Codigo']);
      // Como el legacy: lo que no esta en el catalogo no entra en la rejilla.
      if (!in_array($cod, $codigosValidos, true)) {
        continue;
      }
      $ejercicio = (int) $row['Anio'];
      $mes = (int) $row['Mes'];
      $valores[$ejercicio][$mes][$cod] = round(
        (float) ($valores[$ejercicio][$mes][$cod] ?? 0) + (float) $row['Valor'],
        2
      );
    }

    $ejercicios = [];
    foreach ([$anio - 1, $anio] as $ejercicio) {
      $ejercicios[] = $this->matrizEjercicio($ejercicio, $codigosValidos, $valores[$ejercicio] ?? []);
    }

    [$anterior, $actual] = $ejercicios;
    $porcentajes = [];
    foreach ($codigosValidos as $cod) {
      $porcentajes[$cod] = $this->variacion($anterior['totales'][$cod], $actual['totales'][$cod]);
    }

    return [
      'anio' => $anio,
      'anioAnterior' => $anio - 1,
      'agrupacion' => $agrupacion,
      'medida' => $medida === 'cantidad' ? 'cantidad' : 'importe',
      'columnas' => $columnas,
      'ejercicios' => $ejercicios,
      'porcentajes' => $porcentajes,
      'porcentajeTotal' => $this->variacion($anterior['total'], $actual['total']),
    ];
  }

  /**
   * La rejilla legacy va sin decimales y suma los valores ya redondeados de cada
   * celda, asi que los totales se calculan igual para que cuadren con el legacy.
   *
   * @param list<string> $codigos
   * @param array<int, array<string, float>> $valores
   * @return array<string, mixed>
   */
  private function matrizEjercicio(int $anio, array $codigos, array $valores): array
  {
    $filas = [];
    $totales = array_fill_keys($codigos, 0.0);
    $total = 0.0;
    for ($mes = 1; $mes <= 12; $mes++) {
      $fila = ['mes' => $mes, 'valores' => [], 'total' => 0.0];
      foreach ($codigos as $cod) {
        $v = round((float) ($valores[$mes][$cod] ?? 0));
        $fila['valores'][$cod] = $v;
        $fila['total'] += $v;
        $totales[$cod] += $v;
      }
      $total += $fila['total'];
      $filas[] = $fila;
    }

    return ['anio' => $anio, 'filas' => $filas, 'totales' => $totales, 'total' => $total];
  }

  /** Variacion porcentual; null cuando el ejercicio anterior no tiene consumo. */
  private function variacion(float $anterior, float $actual): ?float
  {
    if ($anterior == 0.0) {
      return null;
    }
    return round((($actual - $anterior) * 100) / $anterior, 2);
  }

  /**
   * Columnas de la rejilla: todo el catalogo en su orden natural, como el legacy.
   *
   * @return list<array{codigo: string, descripcion: string}>
   */
  private function columnasCatalogo(string $sql): array
  {
    $columnas = [];
    $stmt = $this->pdo->query($sql);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $columnas[] = [
        'codigo' => trim((string) $row['cod']),
        'descripcion' => trim((string) ($row['des'] ?? '')),
      ];
    }
    return $columnas;
  }

  /** @return array<int, array{numVentas: int, importe: float, prevision: float, puntos: float, fidelizacion: float}> */
  private function acumuladosCliImpVentas(string $codigo, int $anio): array
  {
    $stmt = $this->pdo->prepare(
      'SELECT [Mes], [NumVentas], [ImpVentas], [Prevision], [AcumuladoPuntos], [AcumuladoFidelizacion]
       FROM [CliImpVentas]
       WHERE [Codigo] = :codigo AND [Año] = :anio'
    );
    $stmt->execute(['codigo' => $codigo, 'anio' => $anio]);

    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $out[(int) $row['Mes']] = [
        'numVentas' => (int) ($row['NumVentas'] ?? 0),
        'importe' => (float) ($row['ImpVentas'] ?? 0),
        'prevision' => (float) ($row['Prevision'] ?? 0),
        'puntos' => (float) ($row['AcumuladoPuntos'] ?? 0),
        'fidelizacion' => (float) ($row['AcumuladoFidelizacion'] ?? 0),
      ];
    }
    return $out;
  }
}
