<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Compras;

use PDO;

/**
 * Escritura de albaranes de compra (004 US2 / T019).
 * Contador Empresas.UltAlbaranCom (o UltAlbaranDevCom si devolución).
 */
final class AlbaranCompraEscrituraService
{
  private PDO $pdo;
  private AlbaranCompraConsultaService $consulta;

  public function __construct(PDO $pdo, AlbaranCompraConsultaService $consulta)
  {
    $this->pdo = $pdo;
    $this->consulta = $consulta;
  }

  /**
   * @param array<string, mixed> $body
   * @return array<string, mixed>
   */
  public function crear(array $body): array
  {
    $this->pdo->beginTransaction();
    try {
      $detalle = $this->crearEnTransaccion($body);
      $this->pdo->commit();
      return $detalle;
    } catch (\Throwable $e) {
      if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      throw $e;
    }
  }

  /**
   * Alta de albarán asumiendo transacción abierta por el llamador (p. ej. recepción pedido).
   *
   * @param array<string, mixed> $body
   * @return array<string, mixed>
   */
  public function crearEnTransaccion(array $body): array
  {
    $empresa = trim((string) ($body['empresa'] ?? ''));
    if ($empresa === '') {
      throw new \InvalidArgumentException('Empresa (tienda) obligatoria');
    }
    $this->assertTiendaExiste($empresa);

    $proveedor = trim((string) ($body['proveedor'] ?? ''));
    if ($proveedor === '') {
      throw new \InvalidArgumentException('Proveedor obligatorio');
    }
    $this->assertProveedorValido($proveedor);

    $lineas = $body['lineas'] ?? [];
    if (!is_array($lineas)) {
      $lineas = [];
    }
    $this->validarLineasArticulos($lineas);

    $esDevolucion = !empty($body['albaranDevolucion']);
    $totales = $this->calcularImportes($lineas, $body);
    $fecha = $this->normalizeFecha($body['fechaAlbaran'] ?? $body['fecha'] ?? null);
    $almacen = $this->intOrNull($body['almacen'] ?? null);
    if ($almacen === null) {
      $almacen = $this->almacenTienda($empresa);
    }

    $albaran = isset($body['albaran']) && (int) $body['albaran'] > 0
      ? (int) $body['albaran']
      : $this->nextAlbaranLocked($empresa, $esDevolucion);

    $sql = 'INSERT INTO AlbaranesCompraCab (
        Empresa, Albaran, SuAlbaran, FechaAlbaran, Proveedor, FPago,
        ImporteAlb, ImporteDtos, ImporteIVA, ImporteRec, Observaciones,
        Actualizado, AlbaranDevolucion, TrasModem, TrasCtb, Almacen, Serie, Seleccion,
        Cliente, Proyecto, ImporteTransporte, CoeficienteTransporte, BrutoConTransporte,
        Estado, AlbaranDevolucionEstado, LUpdate
      ) VALUES (
        :empresa, :albaran, :suAlbaran, CONVERT(datetime, :fechaAlbaran, 120), :proveedor, :fpago,
        :importeAlb, :importeDtos, :importeIva, :importeRec, :observaciones,
        0, :albaranDevolucion, 0, 0, :almacen, :serie, 0,
        :cliente, :proyecto, :importeTransporte, :coeficienteTransporte, :brutoConTransporte,
        :estado, :albaranDevolucionEstado, GETDATE()
      )';
    $this->pdo->prepare($sql)->execute([
      'empresa' => $empresa,
      'albaran' => $albaran,
      'suAlbaran' => $this->nullIfEmpty($body['suAlbaran'] ?? null),
      'fechaAlbaran' => $fecha,
      'proveedor' => $proveedor,
      'fpago' => $this->nullIfEmpty($body['fpago'] ?? null),
      'importeAlb' => $totales['importeAlb'],
      'importeDtos' => $totales['importeDtos'],
      'importeIva' => $totales['importeIva'],
      'importeRec' => $totales['importeRec'],
      'observaciones' => $this->nullIfEmpty($body['observaciones'] ?? null),
      'albaranDevolucion' => $esDevolucion ? 1 : 0,
      'almacen' => $almacen ?? 0,
      'serie' => $this->nullIfEmpty($body['serie'] ?? null),
      'cliente' => $this->nullIfEmpty($body['cliente'] ?? null),
      'proyecto' => $this->nullIfEmpty($body['proyecto'] ?? null),
      'importeTransporte' => (float) ($body['importeTransporte'] ?? 0),
      'coeficienteTransporte' => (float) ($body['coeficienteTransporte'] ?? 0),
      'brutoConTransporte' => (float) ($body['brutoConTransporte'] ?? $totales['importeAlb']),
      'estado' => $this->nullIfEmpty($body['estado'] ?? null) ?? 'B',
      'albaranDevolucionEstado' => (int) ($body['albaranDevolucionEstado'] ?? 0),
    ]);

    $this->reemplazarLineas($empresa, $albaran, $lineas);

    $detalle = $this->consulta->obtener($empresa, $albaran);
    if ($detalle === null) {
      throw new \RuntimeException('Albarán de compra creado pero no se pudo releer');
    }
    return $detalle;
  }

  /**
   * @param array<string, mixed> $body
   * @return array<string, mixed>
   */
  public function actualizar(string $empresa, int $albaran, array $body): array
  {
    $empresa = trim($empresa);
    $actual = $this->consulta->obtener($empresa, $albaran);
    if ($actual === null) {
      throw new \RuntimeException('Albarán de compra no encontrado', 404);
    }
    $this->assertEditable($actual, 'editar');

    $proveedor = array_key_exists('proveedor', $body)
      ? trim((string) ($body['proveedor'] ?? ''))
      : trim((string) ($actual['proveedor'] ?? ''));
    if ($proveedor === '') {
      throw new \InvalidArgumentException('Proveedor obligatorio');
    }
    $this->assertProveedorValido($proveedor);

    $lineas = $body['lineas'] ?? $actual['lineas'] ?? [];
    if (!is_array($lineas)) {
      $lineas = [];
    }
    $this->validarLineasArticulos($lineas);

    $merged = array_merge($actual, $body);
    $merged['proveedor'] = $proveedor;
    $totales = $this->calcularImportes($lineas, $merged);
    $fecha = $this->normalizeFecha($merged['fechaAlbaran'] ?? null);
    $esDevolucion = !empty($merged['albaranDevolucion']);

    $this->pdo->beginTransaction();
    try {
      $sql = 'UPDATE AlbaranesCompraCab SET
          SuAlbaran = :suAlbaran,
          FechaAlbaran = CONVERT(datetime, :fechaAlbaran, 120),
          Proveedor = :proveedor,
          FPago = :fpago,
          ImporteAlb = :importeAlb,
          ImporteDtos = :importeDtos,
          ImporteIVA = :importeIva,
          ImporteRec = :importeRec,
          Observaciones = :observaciones,
          AlbaranDevolucion = :albaranDevolucion,
          Almacen = :almacen,
          Serie = :serie,
          Cliente = :cliente,
          Proyecto = :proyecto,
          ImporteTransporte = :importeTransporte,
          CoeficienteTransporte = :coeficienteTransporte,
          BrutoConTransporte = :brutoConTransporte,
          Estado = :estado,
          AlbaranDevolucionEstado = :albaranDevolucionEstado,
          LUpdate = GETDATE()
        WHERE Empresa = :empresa AND Albaran = :albaran';
      $this->pdo->prepare($sql)->execute([
        'suAlbaran' => $this->nullIfEmpty($merged['suAlbaran'] ?? null),
        'fechaAlbaran' => $fecha,
        'proveedor' => $proveedor,
        'fpago' => $this->nullIfEmpty($merged['fpago'] ?? null),
        'importeAlb' => $totales['importeAlb'],
        'importeDtos' => $totales['importeDtos'],
        'importeIva' => $totales['importeIva'],
        'importeRec' => $totales['importeRec'],
        'observaciones' => $this->nullIfEmpty($merged['observaciones'] ?? null),
        'albaranDevolucion' => $esDevolucion ? 1 : 0,
        'almacen' => $this->intOrNull($merged['almacen'] ?? null) ?? 0,
        'serie' => $this->nullIfEmpty($merged['serie'] ?? null),
        'cliente' => $this->nullIfEmpty($merged['cliente'] ?? null),
        'proyecto' => $this->nullIfEmpty($merged['proyecto'] ?? null),
        'importeTransporte' => (float) ($merged['importeTransporte'] ?? 0),
        'coeficienteTransporte' => (float) ($merged['coeficienteTransporte'] ?? 0),
        'brutoConTransporte' => (float) ($merged['brutoConTransporte'] ?? $totales['importeAlb']),
        'estado' => $this->nullIfEmpty($merged['estado'] ?? null),
        'albaranDevolucionEstado' => (int) ($merged['albaranDevolucionEstado'] ?? 0),
        'empresa' => $empresa,
        'albaran' => $albaran,
      ]);

      $this->reemplazarLineas($empresa, $albaran, $lineas);
      $this->pdo->commit();
    } catch (\Throwable $e) {
      if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      throw $e;
    }

    $detalle = $this->consulta->obtener($empresa, $albaran);
    if ($detalle === null) {
      throw new \RuntimeException('Albarán actualizado pero no se pudo releer');
    }
    return $detalle;
  }

  public function eliminar(string $empresa, int $albaran): void
  {
    $empresa = trim($empresa);
    $actual = $this->consulta->obtener($empresa, $albaran);
    if ($actual === null) {
      throw new \RuntimeException('Albarán de compra no encontrado', 404);
    }
    $this->assertEditable($actual, 'eliminar');

    $this->pdo->beginTransaction();
    try {
      $this->pdo->prepare(
        'DELETE FROM AlbaranesComprasLin WHERE Empresa = :e AND Albaran = :a'
      )->execute(['e' => $empresa, 'a' => $albaran]);
      $this->pdo->prepare(
        'DELETE FROM AlbaranesCompraCab WHERE Empresa = :e AND Albaran = :a'
      )->execute(['e' => $empresa, 'a' => $albaran]);
      $this->pdo->commit();
    } catch (\Throwable $e) {
      if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      throw $e;
    }
  }

  /**
   * T024 / R-004: aplicar entradas (o salidas si devolución) en Stock y marcar Actualizado=1.
   *
   * @return array<string, mixed>
   */
  public function actualizarStock(string $empresa, int $albaran): array
  {
    $empresa = trim($empresa);
    $actual = $this->consulta->obtener($empresa, $albaran);
    if ($actual === null) {
      throw new \RuntimeException('Albarán de compra no encontrado', 404);
    }
    if (!empty($actual['trasCtb'])) {
      throw new \RuntimeException(
        'No se puede actualizar stock: el albarán está traspasado a contabilidad (TrasCtb=1).',
        409
      );
    }
    if (!empty($actual['actualizado'])) {
      throw new \RuntimeException(
        'El stock de este albarán ya está actualizado (Actualizado=1).',
        409
      );
    }

    $almacenCab = (int) ($actual['almacen'] ?? 0);
    if ($almacenCab <= 0) {
      throw new \InvalidArgumentException('El albarán no tiene almacén de cabecera válido');
    }

    $lineas = $actual['lineas'] ?? [];
    if (!is_array($lineas) || $lineas === []) {
      throw new \InvalidArgumentException('El albarán no tiene líneas para actualizar stock');
    }

    [$year, $month] = $this->anioMesDesdeFecha($actual['fechaAlbaran'] ?? null);
    $esDevolucion = !empty($actual['albaranDevolucion']);

    $this->pdo->beginTransaction();
    try {
      $aplicadas = 0;
      foreach ($lineas as $lin) {
        if (!is_array($lin)) {
          continue;
        }
        $articulo = trim((string) ($lin['articulo'] ?? ''));
        if ($articulo === '' || strtoupper($articulo) === 'NO') {
          continue;
        }
        $cantidad = (float) ($lin['cantidad'] ?? 0);
        if (abs($cantidad) < 0.0000001) {
          continue;
        }
        $almacenLin = isset($lin['almacen']) ? (int) $lin['almacen'] : 0;
        $almacen = $almacenLin > 0 ? $almacenLin : $almacenCab;
        if ($almacen <= 0) {
          throw new \InvalidArgumentException(
            'Línea sin almacén válido (artículo ' . $articulo . ')'
          );
        }

        $valor = $this->valorNetoLinea($lin);
        $devolver = $esDevolucion || $cantidad < 0;
        $q = abs($cantidad);
        $v = abs($valor);

        $this->upsertMovimientoStock($articulo, $almacen, $year, $month, $q, $v, $devolver);
        $aplicadas++;
      }

      if ($aplicadas === 0) {
        throw new \InvalidArgumentException('No hay líneas de artículo con cantidad para stock');
      }

      $this->pdo->prepare(
        'UPDATE AlbaranesCompraCab
         SET Actualizado = 1, LUpdate = GETDATE()
         WHERE Empresa = :e AND Albaran = :a'
      )->execute(['e' => $empresa, 'a' => $albaran]);

      $this->pdo->commit();
    } catch (\Throwable $e) {
      if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      throw $e;
    }

    $detalle = $this->consulta->obtener($empresa, $albaran);
    if ($detalle === null) {
      throw new \RuntimeException('Stock actualizado pero no se pudo releer el albarán');
    }
    return $detalle;
  }

  /**
   * @param array<string, mixed> $lin
   */
  private function valorNetoLinea(array $lin): float
  {
    $cant = (float) ($lin['cantidad'] ?? 0);
    $precio = (float) ($lin['precio'] ?? 0);
    $pjeDto = (float) ($lin['pjeDto'] ?? 0);
    $dto1 = (float) ($lin['dto1'] ?? 0);
    $dto2 = (float) ($lin['dto2'] ?? 0);
    $dto3 = (float) ($lin['dto3'] ?? 0);
    $factor = (1 - $pjeDto / 100) * (1 - $dto1 / 100) * (1 - $dto2 / 100) * (1 - $dto3 / 100);
    return $cant * $precio * $factor;
  }

  /** @return array{0: int, 1: int} */
  private function anioMesDesdeFecha($fecha): array
  {
    if ($fecha instanceof \DateTimeInterface) {
      return [(int) $fecha->format('Y'), (int) $fecha->format('n')];
    }
    $s = trim((string) ($fecha ?? ''));
    if ($s !== '') {
      $ts = strtotime($s);
      if ($ts) {
        return [(int) date('Y', $ts), (int) date('n', $ts)];
      }
    }
    return [(int) date('Y'), (int) date('n')];
  }

  private function upsertMovimientoStock(
    string $articulo,
    int $almacen,
    int $year,
    int $month,
    float $cantidad,
    float $valor,
    bool $comoSalida
  ): void {
    $sel = $this->pdo->prepare(
      'SELECT Codigo FROM Stock WITH (UPDLOCK, ROWLOCK)
       WHERE Codigo = :c AND Almacen = :a AND [Año] = :y AND Mes = :m'
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

    if ($comoSalida) {
      $this->pdo->prepare(
        'UPDATE Stock SET
           Salidas = ISNULL(Salidas, 0) + :q,
           ValorSalidas = ISNULL(ValorSalidas, 0) + :imp
         WHERE Codigo = :c AND Almacen = :a AND [Año] = :y AND Mes = :m'
      )->execute([
        'q' => $cantidad,
        'imp' => $valor,
        'c' => $articulo,
        'a' => $almacen,
        'y' => $year,
        'm' => $month,
      ]);
      return;
    }

    $this->pdo->prepare(
      'UPDATE Stock SET
         Entradas = ISNULL(Entradas, 0) + :q,
         ValorEntradas = ISNULL(ValorEntradas, 0) + :imp
       WHERE Codigo = :c AND Almacen = :a AND [Año] = :y AND Mes = :m'
    )->execute([
      'q' => $cantidad,
      'imp' => $valor,
      'c' => $articulo,
      'a' => $almacen,
      'y' => $year,
      'm' => $month,
    ]);
  }

  /**
   * T020: denegar escritura si TrasCtb=1 o Actualizado=1.
   *
   * @param array<string, mixed> $actual
   */
  public function assertEditable(array $actual, string $accion = 'editar'): void
  {
    $verbo = $accion === 'eliminar' ? 'eliminar' : 'modificar';
    if (!empty($actual['trasCtb'])) {
      throw new \RuntimeException(
        "No se puede {$verbo}: el albarán está traspasado a contabilidad (TrasCtb=1).",
        409
      );
    }
    if (!empty($actual['actualizado'])) {
      throw new \RuntimeException(
        "No se puede {$verbo}: el stock ya está actualizado (Actualizado=1). "
        . 'Hay que revertir la entrada de stock antes de cambiar el documento.',
        409
      );
    }
  }

  private function nextAlbaranLocked(string $empresa, bool $esDevolucion): int
  {
    $campo = $esDevolucion ? 'UltAlbaranDevCom' : 'UltAlbaranCom';
    $stmt = $this->pdo->prepare(
      "SELECT [{$campo}], Almacen FROM Empresas WITH (UPDLOCK, ROWLOCK) WHERE Codigo = :e"
    );
    $stmt->execute(['e' => $empresa]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      throw new \RuntimeException('Tienda no encontrada', 404);
    }
    $n = (int) ($row[$campo] ?? 0) + 1;
    $this->pdo->prepare(
      "UPDATE Empresas SET [{$campo}] = :n WHERE Codigo = :e"
    )->execute(['n' => $n, 'e' => $empresa]);
    return $n;
  }

  private function assertTiendaExiste(string $empresa): void
  {
    $stmt = $this->pdo->prepare('SELECT 1 FROM Empresas WHERE Codigo = :e');
    $stmt->execute(['e' => $empresa]);
    if ($stmt->fetchColumn() === false) {
      throw new \InvalidArgumentException('Tienda no encontrada: ' . $empresa);
    }
  }

  private function assertProveedorValido(string $codigo): void
  {
    $stmt = $this->pdo->prepare(
      'SELECT ISNULL(Baja, 0) AS Baja FROM Proveedores WHERE RTRIM(Codigo) = :c'
    );
    $stmt->execute(['c' => $codigo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      throw new \InvalidArgumentException('Proveedor no encontrado: ' . $codigo);
    }
    if ((int) ($row['Baja'] ?? 0) === 1) {
      throw new \InvalidArgumentException('Proveedor dado de baja: ' . $codigo);
    }
  }

  /**
   * @param list<array<string, mixed>> $lineas
   */
  private function validarLineasArticulos(array $lineas): void
  {
    foreach ($lineas as $lin) {
      $articulo = trim((string) ($lin['articulo'] ?? ''));
      if ($articulo === '' || strtoupper($articulo) === 'NO') {
        continue;
      }
      $stmt = $this->pdo->prepare(
        'SELECT ISNULL(a.BloqueoCompra, 0) AS BloqueoCompra,
                ISNULL(a2.BloqueadoCompra, 0) AS BloqueadoCompra
         FROM Articulos a
         LEFT JOIN Articulos2 a2 ON RTRIM(a2.Codigo) = RTRIM(a.Codigo)
         WHERE RTRIM(a.Codigo) = :c'
      );
      $stmt->execute(['c' => $articulo]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      if ($row === false) {
        throw new \InvalidArgumentException('Artículo no encontrado: ' . $articulo);
      }
      if ((int) ($row['BloqueoCompra'] ?? 0) === 1 || (int) ($row['BloqueadoCompra'] ?? 0) === 1) {
        throw new \InvalidArgumentException('Artículo bloqueado para compra: ' . $articulo);
      }
    }
  }

  /**
   * @param list<array<string, mixed>> $lineas
   * @param array<string, mixed> $body
   * @return array{importeAlb: float, importeDtos: float, importeIva: float, importeRec: float}
   */
  private function calcularImportes(array $lineas, array $body): array
  {
    $bruto = 0.0;
    $neto = 0.0;
    foreach ($lineas as $lin) {
      $articulo = trim((string) ($lin['articulo'] ?? ''));
      if ($articulo === '') {
        continue;
      }
      $cant = (float) ($lin['cantidad'] ?? 0);
      $precio = (float) ($lin['precio'] ?? 0);
      $pjeDto = (float) ($lin['pjeDto'] ?? 0);
      $dto1 = (float) ($lin['dto1'] ?? 0);
      $dto2 = (float) ($lin['dto2'] ?? 0);
      $dto3 = (float) ($lin['dto3'] ?? 0);
      $lineaBruto = $cant * $precio;
      $factor = (1 - $pjeDto / 100) * (1 - $dto1 / 100) * (1 - $dto2 / 100) * (1 - $dto3 / 100);
      $lineaNeto = $lineaBruto * $factor;
      $bruto += $lineaBruto;
      $neto += $lineaNeto;
    }

    $transporte = (float) ($body['importeTransporte'] ?? 0);
    $importeIva = array_key_exists('importeIva', $body)
      ? (float) $body['importeIva']
      : (float) ($body['importeIVA'] ?? 0);
    $importeRec = (float) ($body['importeRec'] ?? 0);

    return [
      'importeAlb' => round($neto + $transporte + $importeIva + $importeRec, 2),
      'importeDtos' => round(max(0.0, $bruto - $neto), 2),
      'importeIva' => round($importeIva, 2),
      'importeRec' => round($importeRec, 2),
    ];
  }

  /** @param list<array<string, mixed>> $lineas */
  private function reemplazarLineas(string $empresa, int $albaran, array $lineas): void
  {
    $this->pdo->prepare(
      'DELETE FROM AlbaranesComprasLin WHERE Empresa = :e AND Albaran = :a'
    )->execute(['e' => $empresa, 'a' => $albaran]);

    $ins = $this->pdo->prepare(
      'INSERT INTO AlbaranesComprasLin (
         Empresa, Albaran, Articulo, Descripcion, Cantidad, Precio, PjeDto,
         Pedido, Dto1, Dto2, Dto3, ArticuloOriginal, Lote, Almacen
       ) VALUES (
         :e, :a, :articulo, :descripcion, :cantidad, :precio, :pjeDto,
         :pedido, :dto1, :dto2, :dto3, :articuloOriginal, :lote, :almacen
       )'
    );

    foreach ($lineas as $lin) {
      $articulo = trim((string) ($lin['articulo'] ?? ''));
      if ($articulo === '') {
        continue;
      }
      $descripcion = trim((string) ($lin['descripcion'] ?? ''));
      if ($descripcion === '') {
        $descripcion = $this->descripcionArticulo($articulo) ?? $articulo;
      }
      $pedido = isset($lin['pedido']) && (int) $lin['pedido'] > 0 ? (int) $lin['pedido'] : 0;
      $ins->execute([
        'e' => $empresa,
        'a' => $albaran,
        'articulo' => $articulo,
        'descripcion' => substr($descripcion, 0, 50),
        'cantidad' => (float) ($lin['cantidad'] ?? 0),
        'precio' => (float) ($lin['precio'] ?? 0),
        'pjeDto' => (float) ($lin['pjeDto'] ?? 0),
        'pedido' => $pedido,
        'dto1' => (float) ($lin['dto1'] ?? 0),
        'dto2' => (float) ($lin['dto2'] ?? 0),
        'dto3' => (float) ($lin['dto3'] ?? 0),
        'articuloOriginal' => $this->nullIfEmpty($lin['articuloOriginal'] ?? null),
        'lote' => $this->nullIfEmpty($lin['lote'] ?? null),
        'almacen' => $this->intOrNull($lin['almacen'] ?? null) ?? 0,
      ]);
    }
  }

  private function descripcionArticulo(string $codigo): ?string
  {
    try {
      $stmt = $this->pdo->prepare(
        'SELECT Descripcion FROM Articulos WHERE RTRIM(Codigo) = :c'
      );
      $stmt->execute(['c' => $codigo]);
      $d = $stmt->fetchColumn();
      if ($d === false) {
        return null;
      }
      $s = trim((string) $d);
      return $s === '' ? null : $s;
    } catch (\Throwable $e) {
      return null;
    }
  }

  private function almacenTienda(string $empresa): ?int
  {
    $stmt = $this->pdo->prepare('SELECT Almacen FROM Empresas WHERE Codigo = :e');
    $stmt->execute(['e' => $empresa]);
    $v = $stmt->fetchColumn();
    if ($v === false || $v === null || $v === '') {
      return null;
    }
    return (int) $v;
  }

  private function normalizeFecha($value): string
  {
    if ($value instanceof \DateTimeInterface) {
      return $value->format('Y-m-d H:i:s');
    }
    $s = trim((string) ($value ?? ''));
    if ($s === '') {
      return date('Y-m-d H:i:s');
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) {
      $day = substr($s, 0, 10);
      return strlen($s) > 10 ? $day . ' ' . substr($s, 11, 8) : $day . ' 00:00:00';
    }
    $ts = strtotime($s);
    return $ts ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
  }

  private function nullIfEmpty($value): ?string
  {
    if ($value === null) {
      return null;
    }
    $s = trim((string) $value);
    return $s === '' ? null : $s;
  }

  private function intOrNull($value): ?int
  {
    if ($value === null || $value === '') {
      return null;
    }
    return (int) $value;
  }
}
