<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Listados;

use InvalidArgumentException;
use PDO;

/**
 * Informe de clientes: búsqueda por varios parámetros (datos, zona, comercial, fidelización).
 * "Con fidelización" = el cliente tiene número de tarjeta de fidelización.
 */
final class ClientesListadoService
{
  private const LIMITE_FILAS = 10000;
  /** Fecha centinela de legacy para "sin valor". */
  private const FECHA_VACIA = '1995-01-01';

  /** Filtros de texto "contiene": [columna SQL, nombre en query]. */
  private const CONTIENE = [
    ['c.[RazonSocial]', 'nombre'],
    ['c.[NIF]', 'nif'],
    ['c.[Poblacion]', 'poblacion'],
    ['c.[Provincia]', 'provincia'],
    ['c.[Pais]', 'pais'],
    ['c.[Tipologia]', 'tipologia'],
    ['c.[TarjetaFidelizacion]', 'tarjeta'],
  ];

  /** Filtros de igualdad exacta (códigos): [columna SQL, nombre en query]. */
  private const IGUAL = [
    ['c.[Vendedor]', 'vendedor'],
    ['c.[Actividad]', 'actividad'],
    ['c.[FormaPago]', 'formaPago'],
    ['c.[TipoDescuento]', 'tipoDescuento'],
    ['c.[ClasificacionComercial]', 'clasificacionComercial'],
  ];

  private const ORDEN = [
    'codigo' => 'RTRIM(c.[Codigo])',
    'nombre' => 'RTRIM(c.[RazonSocial]), RTRIM(c.[Codigo])',
    'poblacion' => 'RTRIM(c.[Poblacion]), RTRIM(c.[RazonSocial])',
    'ultimaCompra' => 'c.[UltimaCompra] DESC, RTRIM(c.[Codigo])',
    'puntos' => 'ISNULL(c.[AcumuladoPuntos], 0) DESC, RTRIM(c.[Codigo])',
  ];

  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  /** @param array<string, mixed> $query */
  public function generar(array $query): array
  {
    $where = ['1 = 1'];
    $params = [];

    $estado = $this->opcion($query['estado'] ?? 'activos', ['activos', 'baja', 'todos'], 'activos');
    if ($estado === 'activos') {
      $where[] = 'ISNULL(c.[Baja], 0) = 0';
    } elseif ($estado === 'baja') {
      $where[] = 'ISNULL(c.[Baja], 0) <> 0';
    }

    $texto = trim((string) ($query['texto'] ?? ''));
    if ($texto !== '') {
      $this->variasColumnasLike(
        $where,
        $params,
        'txt',
        [
          'RTRIM(c.[Codigo])',
          'c.[RazonSocial]',
          'c.[RazonSocial2]',
          'c.[NIF]',
          'c.[Telefono1]',
          'c.[Telefono2]',
          'c.[Email]',
          'c.[PersonaContacto]',
          'c.[TarjetaFidelizacion]',
        ],
        $texto
      );
    }

    foreach (self::CONTIENE as [$columna, $nombre]) {
      $valor = trim((string) ($query[$nombre] ?? ''));
      if ($valor !== '') {
        $where[] = "{$columna} LIKE :c_{$nombre}";
        $params['c_' . $nombre] = $this->like($valor);
      }
    }
    foreach (self::IGUAL as [$columna, $nombre]) {
      $valor = trim((string) ($query[$nombre] ?? ''));
      if ($valor !== '') {
        $where[] = "RTRIM({$columna}) = :e_{$nombre}";
        $params['e_' . $nombre] = $valor;
      }
    }

    $telefono = trim((string) ($query['telefono'] ?? ''));
    if ($telefono !== '') {
      $this->variasColumnasLike($where, $params, 'tel', ['c.[Telefono1]', 'c.[Telefono2]'], $telefono);
    }
    $email = trim((string) ($query['email'] ?? ''));
    if ($email !== '') {
      $this->variasColumnasLike($where, $params, 'mail', ['c.[Email]', 'c.[EmailComercial]'], $email);
    }
    $cp = trim((string) ($query['codigoPostal'] ?? ''));
    if ($cp !== '') {
      $where[] = 'RTRIM(c.[CodigoPostal]) LIKE :cp';
      $params['cp'] = $cp . '%';
    }

    $empresa = ListadosFiltrosSql::normalizarEmpresaInput((string) ($query['empresa'] ?? ''));
    ListadosFiltrosSql::filtroEmpresaTienda($where, $params, $empresa, 'c');

    $this->rangoFecha($where, $params, 'c.[FechaAlta]', $query, 'altaDesde', 'altaHasta', 'alta');
    $this->rangoFecha($where, $params, 'c.[UltimaCompra]', $query, 'ultimaCompraDesde', 'ultimaCompraHasta', 'ucompra');
    $this->rangoFecha($where, $params, 'c.[FechaAltaFidelizacion]', $query, 'fidelAltaDesde', 'fidelAltaHasta', 'fidalta');

    // Clientes que no compran desde una fecha (incluye los que nunca han comprado).
    $sinCompra = ListadosFiltrosSql::fechaDiaInput($query['sinCompraDesde'] ?? null);
    if ($sinCompra !== null) {
      $where[] = 'c.[UltimaCompra] < :sin_compra';
      $params['sin_compra'] = $sinCompra . ' 00:00:00';
    }

    $fidelizacion = $this->opcion($query['fidelizacion'] ?? 'todos', ['todos', 'con', 'sin'], 'todos');
    $tieneTarjeta = "RTRIM(ISNULL(c.[TarjetaFidelizacion], '')) <> ''";
    if ($fidelizacion === 'con') {
      $where[] = $tieneTarjeta;
    } elseif ($fidelizacion === 'sin') {
      $where[] = "NOT ({$tieneTarjeta})";
    }

    $puntosMin = trim((string) ($query['puntosMin'] ?? ''));
    if ($puntosMin !== '') {
      if (!is_numeric($puntosMin)) {
        throw new InvalidArgumentException('Puntos mínimos debe ser un número');
      }
      $where[] = 'ISNULL(c.[AcumuladoPuntos], 0) >= :puntos_min';
      $params['puntos_min'] = (float) $puntosMin;
    }

    $this->filtroSiNo($where, 'ISNULL(c.[Propaganda], 0) <> 0', $query['propaganda'] ?? 'todos');
    $this->filtroSiNo($where, "RTRIM(ISNULL(c.[Email], '')) <> ''", $query['conEmail'] ?? 'todos');
    $this->filtroSiNo(
      $where,
      "(RTRIM(ISNULL(c.[Telefono1], '')) <> '' OR RTRIM(ISNULL(c.[Telefono2], '')) <> '')",
      $query['conTelefono'] ?? 'todos'
    );

    $ordenClave = (string) ($query['orden'] ?? 'nombre');
    $orden = self::ORDEN[$ordenClave] ?? self::ORDEN['nombre'];

    $sql = 'SELECT TOP ' . (self::LIMITE_FILAS + 1) . "
              RTRIM(c.[Codigo]) AS codigo,
              RTRIM(ISNULL(c.[RazonSocial], '')) AS nombre,
              RTRIM(ISNULL(c.[NIF], '')) AS nif,
              RTRIM(ISNULL(c.[Telefono1], '')) AS telefono,
              RTRIM(ISNULL(c.[Email], '')) AS email,
              RTRIM(ISNULL(c.[Direccion], '')) AS direccion,
              RTRIM(ISNULL(c.[CodigoPostal], '')) AS codigoPostal,
              RTRIM(ISNULL(c.[Poblacion], '')) AS poblacion,
              RTRIM(ISNULL(c.[Provincia], '')) AS provincia,
              RTRIM(ISNULL(c.[Empresa], '')) AS empresa,
              RTRIM(ISNULL(c.[Vendedor], '')) AS vendedor,
              c.[FechaAlta] AS fechaAlta,
              c.[UltimaCompra] AS ultimaCompra,
              ISNULL(c.[Baja], 0) AS baja,
              ISNULL(c.[Propaganda], 0) AS propaganda,
              RTRIM(ISNULL(c.[TarjetaFidelizacion], '')) AS tarjeta,
              c.[FechaAltaFidelizacion] AS fidelAlta,
              ISNULL(c.[AcumuladoPuntos], 0) AS puntos,
              ISNULL(c.[AcumuladoFidelizacion], 0) AS acumuladoFidelizacion
            FROM [Clientes] c
            WHERE " . implode(' AND ', $where) . "
            ORDER BY {$orden}";

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);

    $items = [];
    $truncado = false;
    $conTarjeta = 0;
    $totalPuntos = 0.0;
    $totalAcumulado = 0.0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      if (count($items) >= self::LIMITE_FILAS) {
        $truncado = true;
        break;
      }
      $item = [
        'codigo' => (string) $row['codigo'],
        'nombre' => (string) $row['nombre'],
        'nif' => (string) $row['nif'],
        'telefono' => (string) $row['telefono'],
        'email' => (string) $row['email'],
        'direccion' => (string) $row['direccion'],
        'codigoPostal' => (string) $row['codigoPostal'],
        'poblacion' => (string) $row['poblacion'],
        'provincia' => (string) $row['provincia'],
        'empresa' => (string) $row['empresa'],
        'vendedor' => (string) $row['vendedor'],
        'fechaAlta' => $this->fecha($row['fechaAlta'] ?? null),
        'ultimaCompra' => $this->fecha($row['ultimaCompra'] ?? null),
        'baja' => (int) $row['baja'] !== 0,
        'propaganda' => (int) $row['propaganda'] !== 0,
        'tarjeta' => (string) $row['tarjeta'],
        'fidelizacion' => (string) $row['tarjeta'] !== '',
        'fidelAlta' => (string) $row['tarjeta'] !== ''
          ? ($this->fecha($row['fidelAlta'] ?? null) ?: self::FECHA_VACIA)
          : '',
        'puntos' => round((float) $row['puntos'], 2),
        'acumuladoFidelizacion' => round((float) $row['acumuladoFidelizacion'], 2),
      ];
      if ($item['fidelizacion']) {
        $conTarjeta++;
      }
      $totalPuntos += $item['puntos'];
      $totalAcumulado += $item['acumuladoFidelizacion'];
      $items[] = $item;
    }

    return [
      'items' => $items,
      'totales' => [
        'clientes' => count($items),
        'conFidelizacion' => $conTarjeta,
        'puntos' => round($totalPuntos, 2),
        'acumuladoFidelizacion' => round($totalAcumulado, 2),
      ],
      'truncado' => $truncado,
      'limite' => self::LIMITE_FILAS,
    ];
  }

  /** @param list<string> $permitidas */
  private function opcion(mixed $raw, array $permitidas, string $defecto): string
  {
    $v = strtolower(trim((string) $raw));

    return in_array($v, $permitidas, true) ? $v : $defecto;
  }

  /** @param list<string> $where */
  private function filtroSiNo(array &$where, string $condicion, mixed $raw): void
  {
    $v = $this->opcion($raw, ['todos', 'si', 'no'], 'todos');
    if ($v === 'si') {
      $where[] = $condicion;
    } elseif ($v === 'no') {
      $where[] = "NOT ({$condicion})";
    }
  }

  /**
   * Rango inclusivo; cada extremo es opcional (solo "desde" = a partir de, solo "hasta" = hasta).
   *
   * @param list<string> $where
   * @param array<string, mixed> $params
   * @param array<string, mixed> $query
   */
  private function rangoFecha(array &$where, array &$params, string $columna, array $query, string $desdeKey, string $hastaKey, string $prefijo): void
  {
    $desde = ListadosFiltrosSql::fechaDiaInput($query[$desdeKey] ?? null);
    $hasta = ListadosFiltrosSql::fechaDiaInput($query[$hastaKey] ?? null);
    if ($desde !== null && $hasta !== null && $desde > $hasta) {
      throw new InvalidArgumentException("{$desdeKey} no puede ser posterior a {$hastaKey}");
    }
    if ($desde !== null) {
      $where[] = "{$columna} >= :{$prefijo}_d";
      $params["{$prefijo}_d"] = $desde . ' 00:00:00';
    }
    if ($hasta !== null) {
      $where[] = "{$columna} <= :{$prefijo}_h";
      $params["{$prefijo}_h"] = $hasta . ' 23:59:59';
    }
  }

  private function like(string $valor): string
  {
    return '%' . $valor . '%';
  }

  /**
   * (col1 LIKE :p0 OR col2 LIKE :p1 …) con un parámetro por columna: PDO no admite repetir el mismo nombre.
   *
   * @param list<string> $where
   * @param array<string, mixed> $params
   * @param list<string> $columnas
   */
  private function variasColumnasLike(array &$where, array &$params, string $prefijo, array $columnas, string $valor): void
  {
    $partes = [];
    foreach ($columnas as $i => $columna) {
      $nombre = "{$prefijo}_{$i}";
      $partes[] = "{$columna} LIKE :{$nombre}";
      $params[$nombre] = $this->like($valor);
    }
    $where[] = '(' . implode(' OR ', $partes) . ')';
  }

  private function fecha(mixed $raw): string
  {
    $t = substr(trim((string) $raw), 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) || $t <= self::FECHA_VACIA || $t === '0000-00-00') {
      return '';
    }

    return $t;
  }
}
