<?php

declare(strict_types=1);

namespace Descartes\Api\Services;

use Descartes\Api\Database\SqlPagination;
use PDO;

/**
 * Mantenimiento de TiposDescuentos.
 * La venta no lee esta tabla: aquí solo se guardan las ofertas.
 */
final class TipoDescuentoService
{
  private const TIPOS = ['.', 'F', 'A', 'S', 'T', 'C', 'P', 'I', 'M'];

  /** Menor número = oferta más concreta. */
  private const PRIORIDAD_TIPO = [
    'A' => 1,
    'S' => 2,
    'F' => 3,
    'I' => 4,
    'T' => 5,
    'M' => 6,
    'P' => 7,
    'C' => 8,
    '.' => 9,
  ];

  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  /**
   * @param array<string, mixed> $query
   * @return array{items: list<array<string, mixed>>, total: int, page: int, pageSize: int}
   */
  public function list(array $query): array
  {
    $page = max(1, (int) ($query['page'] ?? 1));
    $pageSize = min(200, max(1, (int) ($query['pageSize'] ?? 100)));
    $offset = ($page - 1) * $pageSize;

    $where = [];
    $params = [];
    $q = trim((string) ($query['q'] ?? ''));
    if ($q !== '') {
      $where[] = '(RTRIM(t.[TipoDescuento]) LIKE :qTipo OR RTRIM(t.[Codigo]) LIKE :qCodigo OR RTRIM(t.[Tipo]) LIKE :qLetra)';
      $like = '%' . $q . '%';
      $params['qTipo'] = $like;
      $params['qCodigo'] = $like;
      $params['qLetra'] = $like;
    }
    $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

    $count = $this->pdo->prepare("SELECT COUNT(*) FROM [TiposDescuentos] t {$whereSql}");
    $this->execute($count, $params);
    $total = (int) $count->fetchColumn();

    $select = "SELECT
        RTRIM(t.[TipoDescuento]) AS TipoDescuento,
        RTRIM(t.[Tipo]) AS Tipo,
        RTRIM(t.[Codigo]) AS Codigo,
        CONVERT(varchar(19), t.[FechaInicio], 120) AS FechaInicio,
        CONVERT(varchar(19), t.[FechaFin], 120) AS FechaFin,
        t.[Descuento],
        RTRIM(t.[ArticuloRegalo]) AS ArticuloRegalo,
        t.[CantidadRegalo],
        t.[ImporteMinimo],
        t.[ImporteMaximo],
        t.[ControlStock],
        RTRIM(t.[BloquearOfeEmpresa1]) AS BloquearOfeEmpresa1,
        RTRIM(t.[BloquearOfeEmpresa2]) AS BloquearOfeEmpresa2,
        RTRIM(t.[BloquearOfeEmpresa3]) AS BloquearOfeEmpresa3,
        RTRIM(t.[BloquearOfeEmpresa4]) AS BloquearOfeEmpresa4,
        RTRIM(t.[BloquearOfeEmpresa5]) AS BloquearOfeEmpresa5,
        RTRIM(t.[BloquearOfeEmpresa6]) AS BloquearOfeEmpresa6,
        RTRIM(t.[BloquearOfeEmpresa7]) AS BloquearOfeEmpresa7,
        RTRIM(t.[BloquearOfeEmpresa8]) AS BloquearOfeEmpresa8,
        RTRIM(t.[BloquearOfeEmpresa9]) AS BloquearOfeEmpresa9,
        RTRIM(t.[BloquearOfeEmpresa10]) AS BloquearOfeEmpresa10
      FROM [TiposDescuentos] t
      {$whereSql}";

    $sql = SqlPagination::wrap($select, 'TipoDescuento, Tipo, Codigo, FechaInicio', $offset, $pageSize);
    $stmt = $this->pdo->prepare($sql);
    $this->bind($stmt, $params);
    SqlPagination::bind($stmt, $offset, $pageSize);
    $stmt->execute();

    $items = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $items[] = $this->map($row);
    }

    return [
      'items' => $items,
      'total' => $total,
      'page' => $page,
      'pageSize' => $pageSize,
    ];
  }

  /**
   * @param array<string, mixed> $clave
   * @return array<string, mixed>|null
   */
  public function get(array $clave): ?array
  {
    $key = $this->clave($clave);
    $stmt = $this->pdo->prepare($this->selectUno() . ' ' . $this->whereClave());
    $this->bindClave($stmt, $key);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row === false ? null : $this->map($row);
  }

  /**
   * @param array<string, mixed> $body
   * @return array<string, mixed>
   */
  public function create(array $body): array
  {
    $fila = $this->fila($body);
    if ($this->get($fila) !== null) {
      throw new \InvalidArgumentException('Ya existe un tipo de descuento con esa clave');
    }
    $this->insertar($fila);

    $guardada = $this->get($fila);
    if ($guardada === null) {
      throw new \RuntimeException('No se pudo leer el tipo de descuento guardado');
    }

    return $guardada;
  }

  /**
   * @param array<string, mixed> $body
   * @return array<string, mixed>
   */
  public function update(array $body): array
  {
    $original = $body['claveOriginal'] ?? null;
    if (!is_array($original)) {
      throw new \InvalidArgumentException('Falta la clave original');
    }
    $claveOriginal = $this->clave($original);
    $fila = $this->fila($body);

    $cambiaClave = $claveOriginal['tipoDescuento'] !== $fila['tipoDescuento']
      || $claveOriginal['tipo'] !== $fila['tipo']
      || $claveOriginal['codigo'] !== $fila['codigo']
      || $claveOriginal['fechaInicio'] !== $fila['fechaInicio'];
    if ($cambiaClave && $this->get($fila) !== null) {
      throw new \InvalidArgumentException('Ya existe un tipo de descuento con esa clave');
    }

    $stmt = $this->pdo->prepare(
      'UPDATE [TiposDescuentos] SET
        [TipoDescuento] = :tipoDescuentoNuevo,
        [Tipo] = :tipoNuevo,
        [Codigo] = :codigoNuevo,
        [FechaInicio] = :fechaInicioNueva,
        [FechaFin] = :fechaFin,
        [Descuento] = :descuento,
        [LUpdate] = GETDATE(),
        [ArticuloRegalo] = :articuloRegalo,
        [CantidadRegalo] = :cantidadRegalo,
        [ImporteMinimo] = :importeMinimo,
        [ImporteMaximo] = :importeMaximo,
        [ControlStock] = :controlStock,
        [BloquearOfeEmpresa1] = :bloquear1,
        [BloquearOfeEmpresa2] = :bloquear2,
        [BloquearOfeEmpresa3] = :bloquear3,
        [BloquearOfeEmpresa4] = :bloquear4,
        [BloquearOfeEmpresa5] = :bloquear5,
        [BloquearOfeEmpresa6] = :bloquear6,
        [BloquearOfeEmpresa7] = :bloquear7,
        [BloquearOfeEmpresa8] = :bloquear8,
        [BloquearOfeEmpresa9] = :bloquear9,
        [BloquearOfeEmpresa10] = :bloquear10
      ' . $this->whereClave()
    );
    $stmt->bindValue(':tipoDescuentoNuevo', $fila['tipoDescuento']);
    $stmt->bindValue(':tipoNuevo', $fila['tipo']);
    $stmt->bindValue(':codigoNuevo', $fila['codigo']);
    $stmt->bindValue(':fechaInicioNueva', $fila['fechaInicio']);
    $this->bindNullable($stmt, ':fechaFin', $fila['fechaFin']);
    $stmt->bindValue(':descuento', $fila['descuento']);
    $this->bindNullable($stmt, ':articuloRegalo', $fila['articuloRegalo']);
    $stmt->bindValue(':cantidadRegalo', $fila['cantidadRegalo'], PDO::PARAM_INT);
    $stmt->bindValue(':importeMinimo', $fila['importeMinimo']);
    $stmt->bindValue(':importeMaximo', $fila['importeMaximo']);
    $stmt->bindValue(':controlStock', $fila['controlStock'] ? 1 : 0, PDO::PARAM_INT);
    $this->bindBloquear($stmt, $fila['bloquearTiendas']);
    $this->bindClave($stmt, $claveOriginal);
    $stmt->execute();

    $guardada = $this->get($fila);
    if ($guardada === null) {
      throw new \InvalidArgumentException('Tipo de descuento no encontrado');
    }

    return $guardada;
  }

  /**
   * @param array<string, mixed> $clave
   */
  public function delete(array $clave): bool
  {
    $key = $this->clave($clave);
    $stmt = $this->pdo->prepare('DELETE FROM [TiposDescuentos] ' . $this->whereClave());
    $this->bindClave($stmt, $key);
    $stmt->execute();

    return $stmt->rowCount() > 0;
  }

  /**
   * Resumen de las ofertas de un código de tipo de descuento (el que guarda el cliente).
   *
   * @return array{codigo: string, descripcion: string}|null
   */
  public function resumenPorCodigo(string $tipoDescuento): ?array
  {
    $tipoDescuento = trim($tipoDescuento);
    if ($tipoDescuento === '' || strlen($tipoDescuento) > 6) {
      throw new \InvalidArgumentException('El tipo de descuento no es válido');
    }

    $stmt = $this->pdo->prepare(
      'SELECT RTRIM([Tipo]) AS Tipo, RTRIM([Codigo]) AS Codigo,
        CONVERT(varchar(10), [FechaInicio], 120) AS FechaInicio,
        CONVERT(varchar(10), [FechaFin], 120) AS FechaFin,
        [Descuento]
      FROM [TiposDescuentos]
      WHERE RTRIM([TipoDescuento]) = :tipoDescuento
      ORDER BY [FechaInicio]'
    );
    $stmt->bindValue(':tipoDescuento', $tipoDescuento);
    $stmt->execute();

    $textos = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $textos[] = $this->textoOferta($row);
    }
    if ($textos === []) {
      return null;
    }

    return [
      'codigo' => $tipoDescuento,
      'descripcion' => implode(' | ', $textos),
    ];
  }

  /**
   * Porcentaje y regalo de la oferta del cliente para cada artículo.
   * Entra si el tipo y el código coinciden, la fecha de la venta está en el periodo
   * y la tienda no está excluida. Si hay varias, gana la más concreta; el regalo se
   * busca aparte entre las que tienen artículo regalo.
   * Con control de stock, el regalo solo se da si hay existencias en el almacén de la tienda.
   *
   * @param list<string> $articulos
   * @return list<array{articulo: string, pjeDto: float, regalo: ?array{articulo: string, cantidad: int}}>
   */
  public function porcentajesLinea(string $cliente, string $empresa, string $fecha, array $articulos): array
  {
    $cliente = trim($cliente);
    $empresa = trim($empresa);
    $fecha = $this->fechaOferta($fecha);
    $codigos = [];
    foreach ($articulos as $articulo) {
      $articulo = trim((string) $articulo);
      if ($articulo === '' || strcasecmp($articulo, 'NO') === 0) {
        continue;
      }
      $codigos[$articulo] = true;
    }
    $codigos = array_keys($codigos);

    $ceros = [];
    foreach ($codigos as $articulo) {
      $ceros[] = ['articulo' => $articulo, 'pjeDto' => 0.0, 'regalo' => null];
    }
    if ($codigos === [] || $cliente === '' || strcasecmp($cliente, 'ZZZZZZZZZ') === 0) {
      return $ceros;
    }

    $tipoDescuento = $this->tipoDescuentoCliente($cliente);
    if ($tipoDescuento === '') {
      return $ceros;
    }

    $ofertas = $this->ofertasVigentes($tipoDescuento, $fecha, $empresa);
    if ($ofertas === []) {
      return $ceros;
    }

    $clases = $this->clasificacionArticulos($codigos);
    $porMayusculas = [];
    foreach ($clases as $codigo => $clase) {
      $porMayusculas[strtoupper($codigo)] = $clase;
    }
    $stockRegalos = [];
    $items = [];
    foreach ($codigos as $articulo) {
      $clase = $clases[$articulo] ?? $porMayusculas[strtoupper($articulo)] ?? null;
      $pjeDto = 0.0;
      $regalo = null;
      if ($clase !== null) {
        $mejor = $this->mejorOferta($ofertas, $clase, $cliente, false);
        $pjeDto = $mejor === null ? 0.0 : $mejor['descuento'];
        $conRegalo = $this->mejorOferta($ofertas, $clase, $cliente, true);
        if ($conRegalo !== null) {
          $regalo = $this->regaloDisponible($conRegalo, $empresa, $stockRegalos);
        }
      }
      $items[] = ['articulo' => $articulo, 'pjeDto' => $pjeDto, 'regalo' => $regalo];
    }

    return $items;
  }

  /**
   * @param array{articuloRegalo: string, cantidadRegalo: int, controlStock: bool} $oferta
   * @param array<string, float> $stockRegalos
   * @return ?array{articulo: string, cantidad: int}
   */
  private function regaloDisponible(array $oferta, string $empresa, array &$stockRegalos): ?array
  {
    $articulo = $oferta['articuloRegalo'];
    $cantidad = $oferta['cantidadRegalo'];
    if ($oferta['controlStock']) {
      $clave = strtoupper($articulo);
      if (!array_key_exists($clave, $stockRegalos)) {
        $stockRegalos[$clave] = $this->stockEnTienda($articulo, $empresa);
      }
      if ($stockRegalos[$clave] < $cantidad) {
        return null;
      }
    }

    return ['articulo' => $articulo, 'cantidad' => $cantidad];
  }

  private function stockEnTienda(string $articulo, string $empresa): float
  {
    $stmt = $this->pdo->prepare(
      'SELECT SUM(
          ISNULL(s.[Entradas], 0) - ISNULL(s.[Salidas], 0) - ISNULL(s.[Ventas], 0)
          + ISNULL(s.[TraspasosEntradas], 0) - ISNULL(s.[TraspasosSalidas], 0)
        )
      FROM [Stock] s
      INNER JOIN [Empresas_Ges] e ON CAST(e.[Almacen] AS int) = CAST(s.[Almacen] AS int)
      WHERE RTRIM(s.[Codigo]) = :articulo AND RTRIM(e.[Codigo]) = :empresa'
    );
    $stmt->bindValue(':articulo', $articulo);
    $stmt->bindValue(':empresa', $empresa);
    $stmt->execute();

    return (float) ($stmt->fetchColumn() ?: 0);
  }

  /**
   * @param array<string, mixed> $fila
   */
  private function insertar(array $fila): void
  {
    $stmt = $this->pdo->prepare(
      'INSERT INTO [TiposDescuentos] (
        [TipoDescuento], [Tipo], [Codigo], [FechaInicio], [FechaFin], [Descuento],
        [Tarifa], [Precio], [LUpdate], [ArticuloRegalo], [CantidadRegalo],
        [ImporteMinimo], [ImporteMaximo], [Cupo], [ControlStock],
        [BloquearOfeEmpresa1], [BloquearOfeEmpresa2], [BloquearOfeEmpresa3],
        [BloquearOfeEmpresa4], [BloquearOfeEmpresa5], [BloquearOfeEmpresa6],
        [BloquearOfeEmpresa7], [BloquearOfeEmpresa8], [BloquearOfeEmpresa9],
        [BloquearOfeEmpresa10]
      ) VALUES (
        :tipoDescuento, :tipo, :codigo, :fechaInicio, :fechaFin, :descuento,
        0, 0, GETDATE(), :articuloRegalo, :cantidadRegalo,
        :importeMinimo, :importeMaximo, 0, :controlStock,
        :bloquear1, :bloquear2, :bloquear3, :bloquear4, :bloquear5,
        :bloquear6, :bloquear7, :bloquear8, :bloquear9, :bloquear10
      )'
    );
    $stmt->bindValue(':tipoDescuento', $fila['tipoDescuento']);
    $stmt->bindValue(':tipo', $fila['tipo']);
    $stmt->bindValue(':codigo', $fila['codigo']);
    $stmt->bindValue(':fechaInicio', $fila['fechaInicio']);
    $this->bindNullable($stmt, ':fechaFin', $fila['fechaFin']);
    $stmt->bindValue(':descuento', $fila['descuento']);
    $this->bindNullable($stmt, ':articuloRegalo', $fila['articuloRegalo']);
    $stmt->bindValue(':cantidadRegalo', $fila['cantidadRegalo'], PDO::PARAM_INT);
    $stmt->bindValue(':importeMinimo', $fila['importeMinimo']);
    $stmt->bindValue(':importeMaximo', $fila['importeMaximo']);
    $stmt->bindValue(':controlStock', $fila['controlStock'] ? 1 : 0, PDO::PARAM_INT);
    $this->bindBloquear($stmt, $fila['bloquearTiendas']);
    $stmt->execute();
  }

  private function selectUno(): string
  {
    return "SELECT
      RTRIM([TipoDescuento]) AS TipoDescuento,
      RTRIM([Tipo]) AS Tipo,
      RTRIM([Codigo]) AS Codigo,
      CONVERT(varchar(19), [FechaInicio], 120) AS FechaInicio,
      CONVERT(varchar(19), [FechaFin], 120) AS FechaFin,
      [Descuento],
      RTRIM([ArticuloRegalo]) AS ArticuloRegalo,
      [CantidadRegalo],
      [ImporteMinimo],
      [ImporteMaximo],
      [ControlStock],
      RTRIM([BloquearOfeEmpresa1]) AS BloquearOfeEmpresa1,
      RTRIM([BloquearOfeEmpresa2]) AS BloquearOfeEmpresa2,
      RTRIM([BloquearOfeEmpresa3]) AS BloquearOfeEmpresa3,
      RTRIM([BloquearOfeEmpresa4]) AS BloquearOfeEmpresa4,
      RTRIM([BloquearOfeEmpresa5]) AS BloquearOfeEmpresa5,
      RTRIM([BloquearOfeEmpresa6]) AS BloquearOfeEmpresa6,
      RTRIM([BloquearOfeEmpresa7]) AS BloquearOfeEmpresa7,
      RTRIM([BloquearOfeEmpresa8]) AS BloquearOfeEmpresa8,
      RTRIM([BloquearOfeEmpresa9]) AS BloquearOfeEmpresa9,
      RTRIM([BloquearOfeEmpresa10]) AS BloquearOfeEmpresa10
    FROM [TiposDescuentos]";
  }

  private function whereClave(): string
  {
    return 'WHERE RTRIM([TipoDescuento]) = :tipoDescuento
      AND RTRIM([Tipo]) = :tipo
      AND RTRIM([Codigo]) = :codigo
      AND CONVERT(varchar(19), [FechaInicio], 120) = :fechaInicio';
  }

  /**
   * @param array{tipoDescuento: string, tipo: string, codigo: string, fechaInicio: string} $clave
   */
  private function bindClave(\PDOStatement $stmt, array $clave): void
  {
    $stmt->bindValue(':tipoDescuento', $clave['tipoDescuento']);
    $stmt->bindValue(':tipo', $clave['tipo']);
    $stmt->bindValue(':codigo', $clave['codigo']);
    $stmt->bindValue(':fechaInicio', $clave['fechaInicio']);
  }

  /**
   * @param list<string|null> $tiendas
   */
  private function bindBloquear(\PDOStatement $stmt, array $tiendas): void
  {
    for ($i = 1; $i <= 10; $i++) {
      $this->bindNullable($stmt, ':bloquear' . $i, $tiendas[$i - 1] ?? null);
    }
  }

  private function bindNullable(\PDOStatement $stmt, string $name, ?string $value): void
  {
    if ($value === null || $value === '') {
      $stmt->bindValue($name, null, PDO::PARAM_NULL);
      return;
    }
    $stmt->bindValue($name, $value);
  }

  /**
   * @param array<string, mixed> $params
   */
  private function execute(\PDOStatement $stmt, array $params): void
  {
    $this->bind($stmt, $params);
    $stmt->execute();
  }

  /**
   * @param array<string, mixed> $params
   */
  private function bind(\PDOStatement $stmt, array $params): void
  {
    foreach ($params as $name => $value) {
      $stmt->bindValue(':' . $name, $value);
    }
  }

  /**
   * @param array<string, mixed> $row
   * @return array<string, mixed>
   */
  private function map(array $row): array
  {
    $tiendas = [];
    for ($i = 1; $i <= 10; $i++) {
      $codigo = trim((string) ($row['BloquearOfeEmpresa' . $i] ?? ''));
      if ($codigo !== '') {
        $tiendas[] = $codigo;
      }
    }
    $regalo = trim((string) ($row['ArticuloRegalo'] ?? ''));
    $fechaFin = trim((string) ($row['FechaFin'] ?? ''));

    return [
      'tipoDescuento' => trim((string) $row['TipoDescuento']),
      'tipo' => trim((string) $row['Tipo']),
      'codigo' => trim((string) $row['Codigo']),
      'fechaInicio' => trim((string) $row['FechaInicio']),
      'fechaFin' => $fechaFin === '' ? null : $fechaFin,
      'descuento' => (float) $row['Descuento'],
      'articuloRegalo' => $regalo === '' ? null : $regalo,
      'cantidadRegalo' => (int) $row['CantidadRegalo'],
      'importeMinimo' => (float) $row['ImporteMinimo'],
      'importeMaximo' => (float) $row['ImporteMaximo'],
      'controlStock' => (bool) $row['ControlStock'],
      'bloquearTiendas' => $tiendas,
    ];
  }

  /**
   * @param array<string, mixed> $body
   * @return array{
   *   tipoDescuento: string,
   *   tipo: string,
   *   codigo: string,
   *   fechaInicio: string,
   *   fechaFin: ?string,
   *   descuento: string,
   *   articuloRegalo: ?string,
   *   cantidadRegalo: int,
   *   importeMinimo: string,
   *   importeMaximo: string,
   *   controlStock: bool,
   *   bloquearTiendas: list<string|null>
   * }
   */
  private function fila(array $body): array
  {
    $clave = $this->clave($body);
    $fechaFin = $this->fechaHora($body['fechaFin'] ?? null, 'La fecha fin', false);
    if ($fechaFin !== null && $fechaFin < $clave['fechaInicio']) {
      throw new \InvalidArgumentException('La fecha fin no puede ser anterior al inicio');
    }

    $descuento = $this->numero($body['descuento'] ?? 0, 'El descuento');
    if ($descuento < 0 || $descuento > 100) {
      throw new \InvalidArgumentException('El descuento debe estar entre 0 y 100');
    }
    $importeMinimo = $this->numero($body['importeMinimo'] ?? 0, 'El importe mínimo');
    $importeMaximo = $this->numero($body['importeMaximo'] ?? 0, 'El importe máximo');
    if ($importeMinimo < 0 || $importeMaximo < 0) {
      throw new \InvalidArgumentException('Los importes no pueden ser negativos');
    }
    if ($importeMaximo > 0 && $importeMaximo < $importeMinimo) {
      throw new \InvalidArgumentException('El importe máximo es menor que el mínimo');
    }

    $cantidad = $body['cantidadRegalo'] ?? 0;
    if (is_string($cantidad)) {
      $cantidad = trim(str_replace(',', '.', $cantidad));
    }
    if (!is_numeric($cantidad) || (int) $cantidad != (float) $cantidad) {
      throw new \InvalidArgumentException('La cantidad de regalo no es un número entero');
    }
    $cantidadRegalo = (int) $cantidad;
    if ($cantidadRegalo < 0) {
      throw new \InvalidArgumentException('La cantidad de regalo no puede ser negativa');
    }

    $regalo = trim((string) ($body['articuloRegalo'] ?? ''));
    if (strlen($regalo) > 18) {
      throw new \InvalidArgumentException('El artículo regalo admite 18 caracteres');
    }

    $tiendasIn = $body['bloquearTiendas'] ?? [];
    if (!is_array($tiendasIn)) {
      throw new \InvalidArgumentException('Las tiendas excluidas no son válidas');
    }
    $tiendas = [];
    foreach ($tiendasIn as $codigo) {
      $codigo = trim((string) $codigo);
      if ($codigo === '' || in_array($codigo, $tiendas, true)) {
        continue;
      }
      if (strlen($codigo) > 3) {
        throw new \InvalidArgumentException('El código de tienda excluida admite 3 caracteres');
      }
      $tiendas[] = $codigo;
    }
    if (count($tiendas) > 10) {
      throw new \InvalidArgumentException('Se pueden excluir hasta 10 tiendas');
    }
    $bloquear = array_pad($tiendas, 10, null);

    return [
      'tipoDescuento' => $clave['tipoDescuento'],
      'tipo' => $clave['tipo'],
      'codigo' => $clave['codigo'],
      'fechaInicio' => $clave['fechaInicio'],
      'fechaFin' => $fechaFin,
      'descuento' => $this->decimal($descuento),
      'articuloRegalo' => $regalo === '' ? null : $regalo,
      'cantidadRegalo' => $cantidadRegalo,
      'importeMinimo' => $this->decimal($importeMinimo),
      'importeMaximo' => $this->decimal($importeMaximo),
      'controlStock' => filter_var($body['controlStock'] ?? false, FILTER_VALIDATE_BOOL),
      'bloquearTiendas' => $bloquear,
    ];
  }

  /**
   * @param array<string, mixed> $body
   * @return array{tipoDescuento: string, tipo: string, codigo: string, fechaInicio: string}
   */
  private function clave(array $body): array
  {
    $tipoDescuento = trim((string) ($body['tipoDescuento'] ?? ''));
    if ($tipoDescuento === '') {
      throw new \InvalidArgumentException('El tipo de descuento es obligatorio');
    }
    if (strlen($tipoDescuento) > 6) {
      throw new \InvalidArgumentException('El tipo de descuento admite 6 caracteres');
    }

    $tipo = trim((string) ($body['tipo'] ?? ''));
    if ($tipo !== '.') {
      $tipo = strtoupper($tipo);
    }
    if (!in_array($tipo, self::TIPOS, true)) {
      throw new \InvalidArgumentException('El tipo no es válido');
    }

    $codigo = trim((string) ($body['codigo'] ?? ''));
    if ($tipo === '.') {
      $codigo = '.';
    } elseif ($codigo === '' || $codigo === '.') {
      throw new \InvalidArgumentException('El código es obligatorio para este tipo');
    }
    if (strlen($codigo) > 18) {
      throw new \InvalidArgumentException('El código admite 18 caracteres');
    }

    $fechaInicio = $this->fechaHora($body['fechaInicio'] ?? null, 'La fecha de inicio', true);
    if ($fechaInicio === null) {
      throw new \InvalidArgumentException('La fecha de inicio es obligatoria');
    }

    return [
      'tipoDescuento' => $tipoDescuento,
      'tipo' => $tipo,
      'codigo' => $codigo,
      'fechaInicio' => $fechaInicio,
    ];
  }

  private function fechaHora(mixed $value, string $campo, bool $obligatoria): ?string
  {
    $texto = trim((string) $value);
    if ($texto === '') {
      if ($obligatoria) {
        throw new \InvalidArgumentException($campo . ' es obligatoria');
      }
      return null;
    }
    if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:[ T](\d{2}:\d{2}:\d{2}))?$/', $texto, $m) !== 1) {
      throw new \InvalidArgumentException($campo . ' no es válida');
    }
    $normalizada = $m[1] . ' ' . ($m[2] ?? '00:00:00');
    $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $normalizada);
    if ($dt === false || $dt->format('Y-m-d H:i:s') !== $normalizada) {
      throw new \InvalidArgumentException($campo . ' no es válida');
    }

    return $normalizada;
  }

  private function numero(mixed $value, string $campo): float
  {
    if (is_string($value)) {
      $value = trim(str_replace(',', '.', $value));
    }
    if ($value === '' || $value === null) {
      return 0.0;
    }
    if (!is_numeric($value)) {
      throw new \InvalidArgumentException($campo . ' no es un número');
    }

    return (float) $value;
  }

  private function fechaOferta(string $fecha): string
  {
    $fecha = trim($fecha);
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $fecha, $m) === 1) {
      return $m[1];
    }

    return date('Y-m-d');
  }

  private function tipoDescuentoCliente(string $cliente): string
  {
    $stmt = $this->pdo->prepare(
      'SELECT RTRIM(ISNULL([TipoDescuento], \'\')) FROM [Clientes] WHERE RTRIM([Codigo]) = :cliente'
    );
    $stmt->bindValue(':cliente', $cliente);
    $stmt->execute();
    $valor = $stmt->fetchColumn();

    return is_string($valor) ? trim($valor) : '';
  }

  /**
   * @return list<array{tipo: string, codigo: string, fechaInicio: string, descuento: float, articuloRegalo: string, cantidadRegalo: int, controlStock: bool}>
   */
  private function ofertasVigentes(string $tipoDescuento, string $fecha, string $empresa): array
  {
    $stmt = $this->pdo->prepare(
      'SELECT RTRIM([Tipo]) AS Tipo, RTRIM([Codigo]) AS Codigo,
        CONVERT(varchar(19), [FechaInicio], 120) AS FechaInicio,
        [Descuento],
        RTRIM(ISNULL([ArticuloRegalo], \'\')) AS ArticuloRegalo,
        ISNULL([CantidadRegalo], 0) AS CantidadRegalo,
        ISNULL([ControlStock], 0) AS ControlStock,
        RTRIM([BloquearOfeEmpresa1]) AS Bloquear1,
        RTRIM([BloquearOfeEmpresa2]) AS Bloquear2,
        RTRIM([BloquearOfeEmpresa3]) AS Bloquear3,
        RTRIM([BloquearOfeEmpresa4]) AS Bloquear4,
        RTRIM([BloquearOfeEmpresa5]) AS Bloquear5,
        RTRIM([BloquearOfeEmpresa6]) AS Bloquear6,
        RTRIM([BloquearOfeEmpresa7]) AS Bloquear7,
        RTRIM([BloquearOfeEmpresa8]) AS Bloquear8,
        RTRIM([BloquearOfeEmpresa9]) AS Bloquear9,
        RTRIM([BloquearOfeEmpresa10]) AS Bloquear10
      FROM [TiposDescuentos]
      WHERE RTRIM([TipoDescuento]) = :tipoDescuento
        AND CONVERT(varchar(10), [FechaInicio], 120) <= :fechaHasta
        AND ([FechaFin] IS NULL OR CONVERT(varchar(10), [FechaFin], 120) >= :fechaDesde)'
    );
    $stmt->bindValue(':tipoDescuento', $tipoDescuento);
    $stmt->bindValue(':fechaHasta', $fecha);
    $stmt->bindValue(':fechaDesde', $fecha);
    $stmt->execute();

    $ofertas = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      if ($this->tiendaExcluida($row, $empresa)) {
        continue;
      }
      $ofertas[] = [
        'tipo' => trim((string) $row['Tipo']),
        'codigo' => trim((string) $row['Codigo']),
        'fechaInicio' => trim((string) $row['FechaInicio']),
        'descuento' => (float) $row['Descuento'],
        'articuloRegalo' => trim((string) $row['ArticuloRegalo']),
        'cantidadRegalo' => (int) $row['CantidadRegalo'],
        'controlStock' => (bool) $row['ControlStock'],
      ];
    }

    return $ofertas;
  }

  /**
   * @param array<string, mixed> $row
   */
  private function tiendaExcluida(array $row, string $empresa): bool
  {
    if ($empresa === '') {
      return false;
    }
    for ($i = 1; $i <= 10; $i++) {
      $codigo = trim((string) ($row['Bloquear' . $i] ?? ''));
      if ($codigo !== '' && strcasecmp($codigo, $empresa) === 0) {
        return true;
      }
    }

    return false;
  }

  /**
   * @param list<string> $codigos
   * @return array<string, array{codigo: string, familia: string, subfamilia: string, seccion: string, subseccion: string, macrofamilia: string, proveedor: string}>
   */
  private function clasificacionArticulos(array $codigos): array
  {
    $params = [];
    $marcas = [];
    foreach ($codigos as $i => $codigo) {
      $nombre = 'art' . $i;
      $marcas[] = ':' . $nombre;
      $params[$nombre] = $codigo;
    }
    $stmt = $this->pdo->prepare(
      'SELECT RTRIM(a.[Codigo]) AS Codigo,
        RTRIM(ISNULL(a.[Familia], \'\')) AS Familia,
        RTRIM(ISNULL(a.[Subfamilia], \'\')) AS Subfamilia,
        RTRIM(ISNULL(a.[Seccion], \'\')) AS Seccion,
        RTRIM(ISNULL(a.[SubSeccion], \'\')) AS SubSeccion,
        RTRIM(ISNULL(f.[MacroFamilia], \'\')) AS MacroFamilia,
        RTRIM(ISNULL(a.[UltProveedor], \'\')) AS Proveedor
      FROM [Articulos] a
      LEFT JOIN [Familias] f ON RTRIM(a.[Familia]) = RTRIM(f.[Codigo])
      WHERE RTRIM(a.[Codigo]) IN (' . implode(', ', $marcas) . ')'
    );
    $this->bind($stmt, $params);
    $stmt->execute();

    $clases = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $codigo = trim((string) $row['Codigo']);
      $clases[$codigo] = [
        'codigo' => $codigo,
        'familia' => trim((string) $row['Familia']),
        'subfamilia' => trim((string) $row['Subfamilia']),
        'seccion' => trim((string) $row['Seccion']),
        'subseccion' => trim((string) $row['SubSeccion']),
        'macrofamilia' => trim((string) $row['MacroFamilia']),
        'proveedor' => trim((string) $row['Proveedor']),
      ];
    }

    return $clases;
  }

  /**
   * @param list<array{tipo: string, codigo: string, fechaInicio: string, descuento: float, articuloRegalo: string, cantidadRegalo: int, controlStock: bool}> $ofertas
   * @param array{codigo: string, familia: string, subfamilia: string, seccion: string, subseccion: string, macrofamilia: string, proveedor: string} $clase
   * @return ?array{tipo: string, codigo: string, fechaInicio: string, descuento: float, articuloRegalo: string, cantidadRegalo: int, controlStock: bool}
   */
  private function mejorOferta(array $ofertas, array $clase, string $cliente, bool $soloConRegalo): ?array
  {
    $mejor = null;
    $mejorPrioridad = 99;
    foreach ($ofertas as $oferta) {
      if ($soloConRegalo && ($oferta['articuloRegalo'] === '' || $oferta['cantidadRegalo'] <= 0)) {
        continue;
      }
      if (!$this->ofertaCoincide($oferta['tipo'], $oferta['codigo'], $clase, $cliente)) {
        continue;
      }
      $prioridad = self::PRIORIDAD_TIPO[$oferta['tipo']] ?? 99;
      if (
        $mejor === null
        || $prioridad < $mejorPrioridad
        || ($prioridad === $mejorPrioridad && $oferta['fechaInicio'] > $mejor['fechaInicio'])
      ) {
        $mejor = $oferta;
        $mejorPrioridad = $prioridad;
      }
    }

    return $mejor;
  }

  /**
   * @param array{codigo: string, familia: string, subfamilia: string, seccion: string, subseccion: string, macrofamilia: string, proveedor: string} $clase
   */
  private function ofertaCoincide(string $tipo, string $codigoOferta, array $clase, string $cliente): bool
  {
    if ($tipo !== '.') {
      $tipo = strtoupper($tipo);
    }
    if ($tipo === '.') {
      return true;
    }
    if ($tipo === 'C') {
      return $codigoOferta !== '' && strcasecmp($codigoOferta, $cliente) === 0;
    }
    $valor = match ($tipo) {
      'A' => $clase['codigo'],
      'F' => $clase['familia'],
      'S' => $clase['subfamilia'],
      'T' => $clase['seccion'],
      'I' => $clase['subseccion'],
      'M' => $clase['macrofamilia'],
      'P' => $clase['proveedor'],
      default => '',
    };

    return $codigoOferta !== '' && $valor !== '' && strcasecmp($codigoOferta, $valor) === 0;
  }

  /**
   * @param array<string, mixed> $row
   */
  private function textoOferta(array $row): string
  {
    $tipo = trim((string) ($row['Tipo'] ?? ''));
    $codigo = trim((string) ($row['Codigo'] ?? ''));
    $quien = $tipo === '.' ? 'General' : trim($tipo . ' ' . $codigo);
    $desde = trim((string) ($row['FechaInicio'] ?? ''));
    $hasta = trim((string) ($row['FechaFin'] ?? ''));
    $periodo = $hasta !== '' ? $desde . ' a ' . $hasta : 'desde ' . $desde;

    return $quien . ' ' . $this->decimal((float) ($row['Descuento'] ?? 0)) . '% (' . $periodo . ')';
  }

  private function decimal(float $value): string
  {
    $texto = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');

    return $texto === '' ? '0' : $texto;
  }
}
