<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Inventario;

use Descartes\Api\Repositories\ArtBarrasRepository;
use Descartes\Api\Services\Listados\ListadosFiltrosSql;
use Descartes\Api\Services\TiendaAlmacenService;
use PDO;

/**
 * Recuento de inventario (legacy: congelación, entrada y actualización).
 * El stock congelado usa la misma fórmula que el listado de stock.
 */
final class InventarioRecuentoService
{
  private const STOCK_SQL = 'ISNULL(s.[Entradas], 0) - ISNULL(s.[Salidas], 0) - ISNULL(s.[Ventas], 0)
    + ISNULL(s.[TraspasosEntradas], 0) - ISNULL(s.[TraspasosSalidas], 0)';

  private PDO $pdo;
  private ArtBarrasRepository $barras;
  private TiendaAlmacenService $tiendas;

  public function __construct(PDO $pdo, ArtBarrasRepository $barras, TiendaAlmacenService $tiendas)
  {
    $this->pdo = $pdo;
    $this->barras = $barras;
    $this->tiendas = $tiendas;
  }

  /** @param array<string, mixed> $query */
  public function estado(array $query): array
  {
    $empresa = trim((string) ($query['empresa'] ?? ''));
    $almacen = (int) ($query['almacen'] ?? 0);
    if ($almacen <= 0 && $empresa !== '') {
      $almacen = (int) ($this->tiendas->getAlmacenPrincipal($empresa) ?? 0);
    }

    return [
      'empresa' => $empresa,
      'almacen' => $almacen,
      'almacenes' => $this->almacenes(),
      'lineas' => $almacen > 0 ? $this->lineas($almacen) : [],
      'resumen' => $almacen > 0 ? $this->resumen($almacen) : $this->resumenVacio(),
    ];
  }

  /** @param array<string, mixed> $body */
  public function congelar(array $body): array
  {
    $almacen = (int) ($body['almacen'] ?? 0);
    $this->tiendas->assertAlmacenActivo($almacen);
    $reemplazar = filter_var($body['reemplazar'] ?? false, FILTER_VALIDATE_BOOL);
    $conReservas = strtolower(trim((string) ($body['reservas'] ?? 'no'))) === 'inventariadas';
    $empresa = trim((string) ($body['empresa'] ?? ''));
    if ($conReservas && $empresa === '') {
      $empresa = $this->tiendas->listTiendasPorAlmacen($almacen)[0] ?? '';
    }
    if ($conReservas && $empresa === '') {
      throw new \InvalidArgumentException('Indique la tienda para sumar las reservas');
    }
    [$whereArt, $paramsArt] = $this->filtrosArticulo($body);

    $this->pdo->beginTransaction();
    try {
      $hay = $this->contarFilas($almacen);
      if ($hay > 0 && !$reemplazar) {
        throw new \RuntimeException('Ya hay un recuento en este almacén. Descártelo o confirme que quiere sustituirlo.', 409);
      }
      if ($hay > 0) {
        $this->borrarFilas($almacen);
      }

      $whereSql = $whereArt === [] ? '' : ' AND ' . implode(' AND ', $whereArt);
      $sql = 'INSERT INTO Inventario (Articulo, Almacen, FechaInventario, StockFechaInventario, Inventario)
              SELECT det.Articulo, :alm, GETDATE(), det.Stock, det.Reservado
              FROM (
                SELECT RTRIM(a.Codigo) AS Articulo,
                       ISNULL(stk.Stock, 0) AS Stock,
                       ISNULL(res.Reservado, 0) * :factorReserva AS Reservado
                FROM Articulos a
                LEFT JOIN (
                  SELECT RTRIM(s.Codigo) AS Codigo, SUM(' . self::STOCK_SQL . ') AS Stock
                  FROM Stock s
                  WHERE s.Almacen = :almFiltro AND RTRIM(ISNULL(s.Codigo, \'\')) <> \'\'
                  GROUP BY RTRIM(s.Codigo)
                ) stk ON stk.Codigo = RTRIM(a.Codigo)
                LEFT JOIN (
                  SELECT RTRIM(l.Articulo) AS Articulo, SUM(ISNULL(l.Cantidad, 0)) AS Reservado
                  FROM AlbaranesVentasCab c
                  INNER JOIN AlbaranesVentasLin l
                    ON c.Albaran = l.Albaran AND RTRIM(c.Tipo) = RTRIM(l.Tipo) AND RTRIM(c.Empresa) = RTRIM(l.Empresa)
                  WHERE RTRIM(c.Empresa) = :empresaRes
                    AND RTRIM(ISNULL(c.FacturaTipo, \'\')) = \'Z\'
                    AND ISNULL(c.Factura, 0) = 0
                  GROUP BY RTRIM(l.Articulo)
                ) res ON res.Articulo = RTRIM(a.Codigo)
                WHERE RTRIM(ISNULL(a.Codigo, \'\')) <> \'\'' . $whereSql . '
              ) det
              WHERE ABS(det.Stock) > 0.0001 OR ABS(det.Reservado) > 0.0001';
      $st = $this->pdo->prepare($sql);
      $st->execute($paramsArt + [
        'alm' => $almacen,
        'almFiltro' => $almacen,
        'factorReserva' => $conReservas ? 1 : 0,
        'empresaRes' => $empresa,
      ]);
      $this->pdo->commit();
    } catch (\Throwable $e) {
      if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      throw $e;
    }

    $estado = $this->estado(['almacen' => $almacen, 'empresa' => (string) ($body['empresa'] ?? '')]);
    $insertadas = (int) ($estado['resumen']['filas'] ?? 0);
    $estado['insertadas'] = $insertadas;
    $estado['mensaje'] = $insertadas > 0
      ? "Stock congelado: {$insertadas} artículos. Lo no contado se regularizará a cero."
      : 'Ningún artículo coincide con el filtro. Puede contar artículos uno a uno.';

    return $estado;
  }

  /** @param array<string, mixed> $body */
  public function anotar(array $body): array
  {
    $almacen = (int) ($body['almacen'] ?? 0);
    $this->tiendas->assertAlmacenActivo($almacen);
    $ref = trim((string) ($body['articulo'] ?? ''));
    if ($ref === '') {
      throw new \InvalidArgumentException('Indique el artículo o el código de barras');
    }
    $cantidad = $this->cantidad($body['cantidad'] ?? null);
    $hit = $this->barras->resolverReferencia($ref);
    if ($hit === null) {
      throw new \InvalidArgumentException('Artículo no encontrado');
    }
    $codigo = trim((string) $hit['codigo']);
    $stock = $this->stockArticulo($codigo, $almacen);

    $this->pdo->beginTransaction();
    try {
      $sel = $this->pdo->prepare(
        'SELECT Inventario FROM Inventario WITH (UPDLOCK, HOLDLOCK)
         WHERE Almacen = :a AND RTRIM(Articulo) = :art'
      );
      $sel->execute(['a' => $almacen, 'art' => $codigo]);
      $row = $sel->fetch(PDO::FETCH_ASSOC);
      if ($row === false) {
        $ins = $this->pdo->prepare(
          'INSERT INTO Inventario (Articulo, Almacen, FechaInventario, StockFechaInventario, Inventario)
           VALUES (:art, :a, GETDATE(), :stock, :cant)'
        );
        $ins->execute([
          'art' => $codigo,
          'a' => $almacen,
          'stock' => $stock,
          'cant' => $cantidad,
        ]);
        $contado = $cantidad;
      } else {
        $contado = round((float) $row['Inventario'] + $cantidad, 4);
        $upd = $this->pdo->prepare(
          'UPDATE Inventario
           SET FechaInventario = GETDATE(), StockFechaInventario = :stock, Inventario = :cant
           WHERE Almacen = :a AND RTRIM(Articulo) = :art'
        );
        $upd->execute([
          'stock' => $stock,
          'cant' => $contado,
          'a' => $almacen,
          'art' => $codigo,
        ]);
      }
      $this->pdo->commit();
    } catch (\Throwable $e) {
      if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      throw $e;
    }

    $estado = $this->estado(['almacen' => $almacen, 'empresa' => (string) ($body['empresa'] ?? '')]);
    $estado['articulo'] = $codigo;
    $estado['contado'] = $contado;

    return $estado;
  }

  /** @param array<string, mixed> $body */
  public function descartar(array $body): array
  {
    $almacen = (int) ($body['almacen'] ?? 0);
    $this->tiendas->assertAlmacenActivo($almacen);
    $this->borrarFilas($almacen);

    $estado = $this->estado(['almacen' => $almacen, 'empresa' => (string) ($body['empresa'] ?? '')]);
    $estado['mensaje'] = 'Recuento descartado. El stock no ha cambiado.';

    return $estado;
  }

  /** @param array<string, mixed> $body */
  public function actualizar(array $body): array
  {
    $almacen = (int) ($body['almacen'] ?? 0);
    $this->tiendas->assertAlmacenActivo($almacen);
    $empresa = trim((string) ($body['empresa'] ?? ''));
    if ($empresa === '') {
      $tiendas = $this->tiendas->listTiendasPorAlmacen($almacen);
      $empresa = $tiendas[0] ?? '';
    }
    if ($empresa === '') {
      throw new \InvalidArgumentException('Indique la tienda del albarán de inventario');
    }

    $fecha = trim((string) ($body['fecha'] ?? ''));
    $ts = $fecha !== '' ? strtotime($fecha) : time();
    if ($ts === false) {
      $ts = time();
    }
    $fechaSql = date('Y-m-d H:i:s', $ts);
    $year = (int) date('Y', $ts);
    $month = (int) date('n', $ts);

    $this->pdo->beginTransaction();
    try {
      $lineas = $this->lineasBloqueadas($almacen);
      if ($lineas === []) {
        throw new \RuntimeException('No hay recuento en este almacén', 404);
      }

      $ajustes = [];
      foreach ($lineas as $lin) {
        $diferencia = round($lin['contado'] - $lin['congelado'], 4);
        if (abs($diferencia) <= 0.0001) {
          continue;
        }
        $ajustes[] = $lin + ['diferencia' => $diferencia];
      }

      $albaran = null;
      $importe = 0.0;
      $iva = 0.0;
      $recargo = 0.0;
      if ($ajustes !== []) {
        $albaran = $this->siguienteTraspaso($empresa);
        $this->pdo->prepare(
          "INSERT INTO AlbaranesTraspasoCab (
             Empresa, Albaran, SuAlbaran, FechaAlbaran, AlmacenOrigen, AlmacenDestino,
             ImporteAlb, ImporteIVA, ImporteRec, Observaciones,
             Actualizado, AlbaranSalida, Transmitido, TrasModem, BloqueadoTrasModem,
             OrigenExterno, DestinoExterno, LUpdate, SubTipo, GenAlbaranTraspaso, UltNum
           ) VALUES (
             :empresa, :albaran, 'Inventario', CONVERT(datetime, :fecha, 120), :origen, 0,
             0, 0, 0, 'Inventario',
             1, 1, 0, 0, 0,
             0, 0, CONVERT(datetime, :lupdate, 120), 'I', 0, 0
           )"
        )->execute([
          'empresa' => $empresa,
          'albaran' => $albaran,
          'fecha' => $fechaSql,
          'origen' => $almacen,
          'lupdate' => $fechaSql,
        ]);

        $insLin = $this->pdo->prepare(
          'INSERT INTO AlbaranesTraspasoLin (
             Empresa, Albaran, Articulo, Descripcion, Cantidad, Precio, PrecioVenta
           ) VALUES (
             :empresa, :albaran, :articulo, :descripcion, :cantidad, :precio, :precioVenta
           )'
        );
        $impuestos = [];
        foreach ($ajustes as $lin) {
          $cantidad = round(-1 * $lin['diferencia'], 4);
          $precio = round((float) $lin['precioMedio'], 4);
          $lineaImporte = round($cantidad * $precio, 2);
          $insLin->execute([
            'empresa' => $empresa,
            'albaran' => $albaran,
            'articulo' => $lin['articulo'],
            'descripcion' => mb_substr($lin['descripcion'], 0, 50),
            'cantidad' => $cantidad,
            'precio' => $precio,
            'precioVenta' => round((float) $lin['precioVenta'], 4),
          ]);
          $this->moverStock($almacen, $year, $month, $lin['articulo'], $cantidad, $lineaImporte);
          $this->pdo->prepare('UPDATE Articulos SET Finventario = CONVERT(datetime, :f, 120) WHERE RTRIM(Codigo) = :c')
            ->execute(['f' => $fechaSql, 'c' => $lin['articulo']]);
          $tasas = $this->tasas($lin['impuesto'], $impuestos);
          $importe += $lineaImporte;
          $iva += round($lineaImporte * $tasas['iva'] / 100, 2);
          $recargo += round($lineaImporte * $tasas['recargo'] / 100, 2);
        }
        $importe = round($importe, 2);
        $iva = round($iva, 2);
        $recargo = round($recargo, 2);
        $this->pdo->prepare(
          'UPDATE AlbaranesTraspasoCab
           SET ImporteAlb = :imp, ImporteIVA = :iva, ImporteRec = :rec
           WHERE Empresa = :e AND Albaran = :a'
        )->execute([
          'imp' => $importe,
          'iva' => $iva,
          'rec' => $recargo,
          'e' => $empresa,
          'a' => $albaran,
        ]);
      }

      $this->borrarFilas($almacen);
      $this->pdo->commit();
    } catch (\Throwable $e) {
      if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      throw $e;
    }

    $mensaje = $albaran === null
      ? 'Sin diferencias. El recuento se ha cerrado y el stock no ha cambiado.'
      : "Stock actualizado. Albarán de inventario {$albaran} ({$empresa}).";

    return [
      'empresa' => $empresa,
      'almacen' => $almacen,
      'albaran' => $albaran,
      'lineas' => count($ajustes),
      'importe' => $importe,
      'mensaje' => $mensaje,
    ];
  }

  /**
   * @param array<string, mixed> $body
   * @return array{0: list<string>, 1: array<string, mixed>}
   */
  private function filtrosArticulo(array $body): array
  {
    $where = [];
    $params = [];
    $excluirBajas = filter_var($body['excluirBajas'] ?? true, FILTER_VALIDATE_BOOL);
    if ($excluirBajas) {
      $where[] = 'a.FechaBaja IS NULL';
    }
    ListadosFiltrosSql::filtroRangoTexto($where, $params, 'a.[Familia]', $body, 'familiaDesde', 'familiaHasta', null, 'fam');
    ListadosFiltrosSql::filtroRangoTexto($where, $params, 'a.[Subfamilia]', $body, 'subfamiliaDesde', 'subfamiliaHasta', null, 'sf');
    ListadosFiltrosSql::filtroRangoTexto($where, $params, 'a.[Agrupacion]', $body, 'agrupacionDesde', 'agrupacionHasta', null, 'ag');
    ListadosFiltrosSql::filtroRangoTexto($where, $params, 'a.[Codigo]', $body, 'articuloDesde', 'articuloHasta', null, 'art');
    ListadosFiltrosSql::filtroRangoTexto($where, $params, 'a.[UltProveedor]', $body, 'proveedorDesde', 'proveedorHasta', null, 'prov');

    return [$where, $params];
  }

  /** @return list<array{codigo: int, descripcion: string}> */
  private function almacenes(): array
  {
    $rows = $this->pdo->query(
      'SELECT Codigo, RTRIM(ISNULL(Descripcion, \'\')) AS Descripcion
       FROM Almacenes
       WHERE ISNULL(Baja, 0) = 0
       ORDER BY Codigo'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
      $out[] = [
        'codigo' => (int) $row['Codigo'],
        'descripcion' => (string) $row['Descripcion'],
      ];
    }

    return $out;
  }

  /** @return list<array<string, mixed>> */
  private function lineas(int $almacen): array
  {
    $st = $this->pdo->prepare(
      'SELECT RTRIM(i.Articulo) AS articulo,
              RTRIM(ISNULL(a.Descripcion, \'\')) AS descripcion,
              ISNULL(i.StockFechaInventario, 0) AS congelado,
              ISNULL(i.Inventario, 0) AS contado
       FROM Inventario i
       LEFT JOIN Articulos a ON RTRIM(a.Codigo) = RTRIM(i.Articulo)
       WHERE i.Almacen = :a
       ORDER BY i.Articulo'
    );
    $st->execute(['a' => $almacen]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
      $congelado = round((float) $row['congelado'], 4);
      $contado = round((float) $row['contado'], 4);
      $out[] = [
        'articulo' => (string) $row['articulo'],
        'descripcion' => (string) $row['descripcion'],
        'congelado' => $congelado,
        'contado' => $contado,
        'diferencia' => round($contado - $congelado, 4),
      ];
    }

    return $out;
  }

  /** @return array{filas: int, sinContar: int, conDiferencia: int} */
  private function resumen(int $almacen): array
  {
    $st = $this->pdo->prepare(
      'SELECT COUNT(*) AS filas,
              SUM(CASE WHEN ABS(ISNULL(Inventario, 0)) <= 0.0001 THEN 1 ELSE 0 END) AS sinContar,
              SUM(CASE WHEN ABS(ISNULL(Inventario, 0) - ISNULL(StockFechaInventario, 0)) > 0.0001 THEN 1 ELSE 0 END) AS conDiferencia
       FROM Inventario
       WHERE Almacen = :a'
    );
    $st->execute(['a' => $almacen]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
      'filas' => (int) ($row['filas'] ?? 0),
      'sinContar' => (int) ($row['sinContar'] ?? 0),
      'conDiferencia' => (int) ($row['conDiferencia'] ?? 0),
    ];
  }

  /** @return array{filas: int, sinContar: int, conDiferencia: int} */
  private function resumenVacio(): array
  {
    return ['filas' => 0, 'sinContar' => 0, 'conDiferencia' => 0];
  }

  private function contarFilas(int $almacen): int
  {
    $st = $this->pdo->prepare('SELECT COUNT(*) FROM Inventario WHERE Almacen = :a');
    $st->execute(['a' => $almacen]);

    return (int) $st->fetchColumn();
  }

  private function borrarFilas(int $almacen): void
  {
    $st = $this->pdo->prepare('DELETE FROM Inventario WHERE Almacen = :a');
    $st->execute(['a' => $almacen]);
  }

  private function stockArticulo(string $codigo, int $almacen): float
  {
    $st = $this->pdo->prepare(
      'SELECT SUM(' . self::STOCK_SQL . ')
       FROM Stock s
       WHERE s.Almacen = :a AND RTRIM(s.Codigo) = :c'
    );
    $st->execute(['a' => $almacen, 'c' => $codigo]);
    $v = $st->fetchColumn();

    return round($v === false || $v === null ? 0.0 : (float) $v, 4);
  }

  private function cantidad(mixed $raw): float
  {
    if (is_string($raw)) {
      $raw = str_replace(',', '.', trim($raw));
    }
    if (!is_numeric($raw)) {
      throw new \InvalidArgumentException('Indique la cantidad contada');
    }
    $n = round((float) $raw, 4);
    if (abs($n) < 0.0001) {
      throw new \InvalidArgumentException('La cantidad no puede ser cero');
    }

    return $n;
  }

  /**
   * @return list<array{articulo: string, descripcion: string, congelado: float, contado: float, precioMedio: float, precioVenta: float, impuesto: string}>
   */
  private function lineasBloqueadas(int $almacen): array
  {
    $st = $this->pdo->prepare(
      'SELECT RTRIM(i.Articulo) AS articulo,
              RTRIM(ISNULL(a.Descripcion, \'\')) AS descripcion,
              ISNULL(i.StockFechaInventario, 0) AS congelado,
              ISNULL(i.Inventario, 0) AS contado,
              ISNULL(a.PrecioMedio, 0) AS precioMedio,
              ISNULL(a.PrecioVen1, 0) AS precioVenta,
              RTRIM(ISNULL(a.Impuesto, \'\')) AS impuesto
       FROM Inventario i WITH (UPDLOCK, HOLDLOCK)
       LEFT JOIN Articulos a ON RTRIM(a.Codigo) = RTRIM(i.Articulo)
       WHERE i.Almacen = :a'
    );
    $st->execute(['a' => $almacen]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
      $art = trim((string) $row['articulo']);
      if ($art === '') {
        continue;
      }
      $out[] = [
        'articulo' => $art,
        'descripcion' => (string) $row['descripcion'],
        'congelado' => round((float) $row['congelado'], 4),
        'contado' => round((float) $row['contado'], 4),
        'precioMedio' => (float) $row['precioMedio'],
        'precioVenta' => (float) $row['precioVenta'],
        'impuesto' => (string) $row['impuesto'],
      ];
    }

    return $out;
  }

  private function siguienteTraspaso(string $empresa): int
  {
    $stmt = $this->pdo->prepare(
      'SELECT UltAlbaranTra FROM Empresas_Ges WITH (UPDLOCK, ROWLOCK) WHERE Codigo = :e'
    );
    $stmt->execute(['e' => $empresa]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      throw new \RuntimeException('Tienda no encontrada', 404);
    }
    $n = (int) ($row['UltAlbaranTra'] ?? 0) + 1;
    $this->pdo->prepare('UPDATE Empresas_Ges SET UltAlbaranTra = :n WHERE Codigo = :e')
      ->execute(['n' => $n, 'e' => $empresa]);

    return $n;
  }

  private function moverStock(int $almacen, int $year, int $month, string $articulo, float $cantidad, float $importe): void
  {
    $sel = $this->pdo->prepare(
      'SELECT Codigo FROM Stock WHERE Codigo = :c AND Almacen = :a AND [Año] = :y AND Mes = :m'
    );
    $sel->execute(['c' => $articulo, 'a' => $almacen, 'y' => $year, 'm' => $month]);
    if ($sel->fetch() === false) {
      $this->pdo->prepare(
        'INSERT INTO Stock (
           Codigo, Almacen, [Año], Mes,
           Entradas, ValorEntradas, Salidas, ValorSalidas,
           Ventas, ValorVentas, MargenEnvios,
           TraspasosEntradas, ValorTraspasosEntradas,
           TraspasosSalidas, ValorTraspasosSalidas
         ) VALUES (
           :c, :a, :y, :m,
           0, 0, 0, 0,
           0, 0, 0,
           0, 0,
           0, 0
         )'
      )->execute(['c' => $articulo, 'a' => $almacen, 'y' => $year, 'm' => $month]);
    }
    $this->pdo->prepare(
      'UPDATE Stock SET
         TraspasosSalidas = ISNULL(TraspasosSalidas, 0) + :q,
         ValorTraspasosSalidas = ISNULL(ValorTraspasosSalidas, 0) + :imp
       WHERE Codigo = :c AND Almacen = :a AND [Año] = :y AND Mes = :m'
    )->execute([
      'q' => $cantidad,
      'imp' => $importe,
      'c' => $articulo,
      'a' => $almacen,
      'y' => $year,
      'm' => $month,
    ]);
  }

  /**
   * @param array<string, array{iva: float, recargo: float}> $cache
   * @return array{iva: float, recargo: float}
   */
  private function tasas(string $codigo, array &$cache): array
  {
    $codigo = trim($codigo);
    if ($codigo === '') {
      return ['iva' => 0.0, 'recargo' => 0.0];
    }
    if (isset($cache[$codigo])) {
      return $cache[$codigo];
    }
    $st = $this->pdo->prepare('SELECT PjeIVA, PjeRec FROM Impuestos WHERE Codigo = :c');
    $st->execute(['c' => $codigo]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $cache[$codigo] = [
      'iva' => $row === false ? 0.0 : (float) ($row['PjeIVA'] ?? 0),
      'recargo' => $row === false ? 0.0 : (float) ($row['PjeRec'] ?? 0),
    ];

    return $cache[$codigo];
  }
}
