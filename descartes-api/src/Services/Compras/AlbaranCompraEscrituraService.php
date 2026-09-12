<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Compras;

use PDO;

/**
 * Escritura de albaranes de compra (004 US2 / T019).
 * Contador Empresas_Ges.UltAlbaranCom (o UltAlbaranDevCom si devolución).
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
    [$lineas, $totales] = $this->prepararLineasYTotales($lineas, $body);
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
      'importeTransporte' => $totales['importeTransporte'],
      'coeficienteTransporte' => $totales['coeficienteTransporte'],
      'brutoConTransporte' => $totales['brutoConTransporte'],
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

    $lineasDesdeBody = array_key_exists('lineas', $body);
    $lineas = $lineasDesdeBody ? ($body['lineas'] ?? []) : ($actual['lineas'] ?? []);
    if (!is_array($lineas)) {
      $lineas = [];
    }
    $this->validarLineasArticulos($lineas);

    $merged = array_merge($actual, $body);
    $merged['proveedor'] = $proveedor;
    // El front envía coste base; las líneas ya persistidas van con CT.
    if (!$lineasDesdeBody) {
      $merged['preciosYaConTransporte'] = true;
    }
    [$lineas, $totales] = $this->prepararLineasYTotales($lineas, $merged);
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
        'importeTransporte' => $totales['importeTransporte'],
        'coeficienteTransporte' => $totales['coeficienteTransporte'],
        'brutoConTransporte' => $totales['brutoConTransporte'],
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
        $this->actualizarCostesArticuloDesdeLinea(
          $articulo,
          (float) ($lin['precio'] ?? 0),
          (float) ($actual['coeficienteTransporte'] ?? 0)
        );
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
   * Legacy «Recuperar»: revierte entradas/salidas de stock y Actualizado=0 para permitir editar.
   *
   * @return array<string, mixed>
   */
  public function recuperarStock(string $empresa, int $albaran): array
  {
    $empresa = trim($empresa);
    $actual = $this->consulta->obtener($empresa, $albaran);
    if ($actual === null) {
      throw new \RuntimeException('Albarán de compra no encontrado', 404);
    }
    if (!empty($actual['trasCtb'])) {
      throw new \RuntimeException(
        'No se puede recuperar: el albarán está traspasado a contabilidad (TrasCtb=1).',
        409
      );
    }
    if (empty($actual['actualizado'])) {
      throw new \RuntimeException(
        'El albarán no está actualizado; no hace falta recuperar.',
        409
      );
    }

    $almacenCab = (int) ($actual['almacen'] ?? 0);
    if ($almacenCab <= 0) {
      throw new \InvalidArgumentException('El albarán no tiene almacén de cabecera válido');
    }

    $lineas = $actual['lineas'] ?? [];
    if (!is_array($lineas) || $lineas === []) {
      throw new \InvalidArgumentException('El albarán no tiene líneas');
    }

    [$year, $month] = $this->anioMesDesdeFecha($actual['fechaAlbaran'] ?? null);
    $esDevolucion = !empty($actual['albaranDevolucion']);

    $this->pdo->beginTransaction();
    try {
      $revertidas = 0;
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

        $this->revertirMovimientoStock($articulo, $almacen, $year, $month, $q, $v, $devolver);
        $revertidas++;
      }

      if ($revertidas === 0) {
        throw new \InvalidArgumentException('No hay líneas de artículo para revertir stock');
      }

      $this->pdo->prepare(
        'UPDATE AlbaranesCompraCab
         SET Actualizado = 0, LUpdate = GETDATE()
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
      throw new \RuntimeException('Albarán recuperado pero no se pudo releer');
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

  /** Deshace upsertMovimientoStock (Recuperar albarán). */
  private function revertirMovimientoStock(
    string $articulo,
    int $almacen,
    int $year,
    int $month,
    float $cantidad,
    float $valor,
    bool $eraSalida
  ): void {
    $sel = $this->pdo->prepare(
      'SELECT Entradas, ValorEntradas, Salidas, ValorSalidas FROM Stock WITH (UPDLOCK, ROWLOCK)
       WHERE Codigo = :c AND Almacen = :a AND [Año] = :y AND Mes = :m'
    );
    $sel->execute(['c' => $articulo, 'a' => $almacen, 'y' => $year, 'm' => $month]);
    $row = $sel->fetch(\PDO::FETCH_ASSOC);
    if ($row === false) {
      throw new \RuntimeException(
        "No hay movimiento de stock que revertir para {$articulo} (almacén {$almacen})."
      );
    }

    if ($eraSalida) {
      $sal = (float) ($row['Salidas'] ?? 0);
      $vs = (float) ($row['ValorSalidas'] ?? 0);
      if ($sal + 0.0000001 < $cantidad) {
        throw new \RuntimeException(
          "Salidas insuficientes en stock para revertir {$articulo} (almacén {$almacen})."
        );
      }
      $this->pdo->prepare(
        'UPDATE Stock SET
           Salidas = ISNULL(Salidas, 0) - :q,
           ValorSalidas = ISNULL(ValorSalidas, 0) - :imp
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

    $ent = (float) ($row['Entradas'] ?? 0);
    if ($ent + 0.0000001 < $cantidad) {
      throw new \RuntimeException(
        "Entradas insuficientes en stock para revertir {$articulo} (almacén {$almacen})."
      );
    }
    $this->pdo->prepare(
      'UPDATE Stock SET
         Entradas = ISNULL(Entradas, 0) - :q,
         ValorEntradas = ISNULL(ValorEntradas, 0) - :imp
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

  /**
   * Abono / devolución parcial por líneas (como ventas): nuevo albarán con AlbaranDevolucion=1.
   *
   * @param array<string, mixed> $body { nroLins?: list<int>, observacion?: string }
   * @return array<string, mixed>
   */
  public function crearAbonoDesdeAlbaran(string $empresa, int $albaran, array $body = []): array
  {
    $empresa = trim($empresa);
    $origen = $this->consulta->obtener($empresa, $albaran);
    if ($origen === null) {
      throw new \RuntimeException('Albarán de compra origen no encontrado', 404);
    }
    if (!empty($origen['albaranDevolucion'])) {
      throw new \InvalidArgumentException('No se puede abonar un albarán que ya es devolución');
    }
    if (!empty($origen['trasCtb'])) {
      throw new \InvalidArgumentException('No se puede abonar: el albarán está traspasado a contabilidad');
    }

    $lineasOrig = $origen['lineas'] ?? [];
    if (!is_array($lineasOrig) || $lineasOrig === []) {
      throw new \InvalidArgumentException('El albarán no tiene líneas para abonar');
    }

    /** @var list<int> $nroLins */
    $nroLins = [];
    if (isset($body['nroLins']) && is_array($body['nroLins'])) {
      foreach ($body['nroLins'] as $n) {
        $nroLins[] = (int) $n;
      }
      $nroLins = array_values(array_unique(array_filter($nroLins, static fn ($n) => $n > 0)));
    }

    $seleccionadas = [];
    foreach ($lineasOrig as $lin) {
      if (!is_array($lin)) {
        continue;
      }
      $art = trim((string) ($lin['articulo'] ?? ''));
      if ($art === '' || strtoupper($art) === 'NO') {
        continue;
      }
      $nro = (int) ($lin['nroLin'] ?? 0);
      if ($nroLins !== [] && !in_array($nro, $nroLins, true)) {
        continue;
      }
      $cant = (float) ($lin['cantidad'] ?? 0);
      if (abs($cant) < 0.0001) {
        continue;
      }
      $seleccionadas[] = [
        'articulo' => $art,
        'descripcion' => $lin['descripcion'] ?? null,
        'cantidad' => -abs($cant),
        'precio' => (float) ($lin['precio'] ?? 0),
        'pjeDto' => (float) ($lin['pjeDto'] ?? 0),
        'dto1' => (float) ($lin['dto1'] ?? 0),
        'dto2' => (float) ($lin['dto2'] ?? 0),
        'dto3' => (float) ($lin['dto3'] ?? 0),
        'lote' => $lin['lote'] ?? null,
        'almacen' => $lin['almacen'] ?? $origen['almacen'] ?? null,
        'pedido' => $lin['pedido'] ?? null,
        'articuloOriginal' => $lin['articuloOriginal'] ?? null,
      ];
    }

    if ($seleccionadas === []) {
      throw new \InvalidArgumentException('Seleccione al menos una línea para abonar');
    }

    $obsExtra = trim((string) ($body['observacion'] ?? $body['observaciones'] ?? ''));
    $obsBase = 'Abono/devolución de albarán ' . $albaran;
    $obs = $obsExtra !== '' ? $obsBase . '. ' . $obsExtra : $obsBase;
    $obsOrig = trim((string) ($origen['observaciones'] ?? ''));
    if ($obsOrig !== '') {
      $obs .= ' | ' . $obsOrig;
    }

    return $this->crear([
      'empresa' => $empresa,
      'proveedor' => $origen['proveedor'] ?? null,
      'fpago' => $origen['fpago'] ?? null,
      'fechaAlbaran' => date('Y-m-d'),
      'suAlbaran' => $origen['suAlbaran'] ?? null,
      'almacen' => $origen['almacen'] ?? null,
      'serie' => $origen['serie'] ?? null,
      'albaranDevolucion' => true,
      'observaciones' => $obs,
      'importeTransporte' => 0,
      'coeficienteTransporte' => 0,
      'brutoConTransporte' => 0,
      'lineas' => $seleccionadas,
    ]);
  }

  /**
   * Reserva el siguiente nº de albarán de compra (contador tienda), sin grabar cabecera.
   * Igual que ventas: el nº se muestra al crear; la cabecera se persiste al Guardar.
   *
   * @return array{empresa: string, albaran: int, albaranDevolucion: bool, almacen: ?int}
   */
  public function reservarAlbaran(string $empresa, bool $esDevolucion = false): array
  {
    $empresa = trim($empresa);
    if ($empresa === '') {
      throw new \InvalidArgumentException('Empresa (tienda) obligatoria');
    }

    $this->pdo->beginTransaction();
    try {
      $campo = $esDevolucion ? 'UltAlbaranDevCom' : 'UltAlbaranCom';
      $stmt = $this->pdo->prepare(
        "SELECT [{$campo}], Almacen FROM Empresas_Ges WITH (UPDLOCK, ROWLOCK) WHERE Codigo = :e"
      );
      $stmt->execute(['e' => $empresa]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      if ($row === false) {
        throw new \RuntimeException('Tienda no encontrada', 404);
      }

      $albaran = (int) ($row[$campo] ?? 0) + 1;
      $this->pdo->prepare(
        "UPDATE Empresas_Ges SET [{$campo}] = :n WHERE Codigo = :e"
      )->execute(['n' => $albaran, 'e' => $empresa]);

      $almacenRaw = $row['Almacen'] ?? null;
      $almacen = ($almacenRaw === null || $almacenRaw === '') ? null : (int) $almacenRaw;

      $this->pdo->commit();
    } catch (\Throwable $e) {
      if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      throw $e;
    }

    return [
      'empresa' => $empresa,
      'albaran' => $albaran,
      'albaranDevolucion' => $esDevolucion,
      'almacen' => $almacen,
    ];
  }

  private function nextAlbaranLocked(string $empresa, bool $esDevolucion): int
  {
    $campo = $esDevolucion ? 'UltAlbaranDevCom' : 'UltAlbaranCom';
    $stmt = $this->pdo->prepare(
      "SELECT [{$campo}], Almacen FROM Empresas_Ges WITH (UPDLOCK, ROWLOCK) WHERE Codigo = :e"
    );
    $stmt->execute(['e' => $empresa]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      throw new \RuntimeException('Tienda no encontrada', 404);
    }
    $n = (int) ($row[$campo] ?? 0) + 1;
    $this->pdo->prepare(
      "UPDATE Empresas_Ges SET [{$campo}] = :n WHERE Codigo = :e"
    )->execute(['n' => $n, 'e' => $empresa]);
    return $n;
  }

  private function assertTiendaExiste(string $empresa): void
  {
    $stmt = $this->pdo->prepare('SELECT 1 FROM Empresas_Ges WHERE Codigo = :e');
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
   * Precios de línea en UI = coste base; al guardar se repercuten en Precio (CT) como legacy.
   *
   * @param list<array<string, mixed>> $lineas
   * @param array<string, mixed> $body
   * @return array{0: list<array<string, mixed>>, 1: array<string, float>}
   */
  private function prepararLineasYTotales(array $lineas, array $body): array
  {
    $transporte = max(0.0, (float) ($body['importeTransporte'] ?? 0));
    $netoBase = $this->sumNetoLineas($lineas);
    $coef = 0.0;
    $lineasGuardar = $lineas;

    if ($transporte > 0.00001) {
      $brutoConBody = (float) ($body['brutoConTransporte'] ?? 0);
      $brutoSinTrans = $brutoConBody > $transporte + 0.00001
        ? $brutoConBody - $transporte
        : $netoBase;
      if ($brutoSinTrans <= 0.00001) {
        $brutoSinTrans = $netoBase;
      }
      $coefBody = (float) ($body['coeficienteTransporte'] ?? 0);
      $coef = $coefBody > 0.0000001
        ? $coefBody
        : $this->calcularCoeficienteLegacy($transporte, $brutoSinTrans);
      if (empty($body['preciosYaConTransporte'])) {
        $lineasGuardar = $this->repercutirTransporteEnLineas($lineas, $coef);
      }
    }

    $netoFinal = $this->sumNetoLineas($lineasGuardar);
    $brutoFinal = $this->sumBrutoLineas($lineasGuardar);
    $importeIva = array_key_exists('importeIva', $body)
      ? (float) $body['importeIva']
      : (float) ($body['importeIVA'] ?? 0);
    $importeRec = (float) ($body['importeRec'] ?? 0);

    return [
      $lineasGuardar,
      [
        'importeAlb' => round($netoFinal + $importeIva + $importeRec, 2),
        'importeDtos' => round(max(0.0, $brutoFinal - $netoFinal), 2),
        'importeIva' => round($importeIva, 2),
        'importeRec' => round($importeRec, 2),
        'brutoConTransporte' => round($netoFinal, 2),
        'coeficienteTransporte' => $coef,
        'importeTransporte' => round($transporte, 2),
      ],
    ];
  }

  /** Coeficiente legacy: transporte / bruto mercancía sin transporte. */
  private function calcularCoeficienteLegacy(float $transporte, float $brutoSinTransporte): float
  {
    if ($transporte <= 0.00001 || $brutoSinTransporte <= 0.00001) {
      return 0.0;
    }
    return round($transporte / $brutoSinTransporte, 6);
  }

  /**
   * @param list<array<string, mixed>> $lineas
   * @return list<array<string, mixed>>
   */
  private function repercutirTransporteEnLineas(array $lineas, float $coef): array
  {
    if ($coef <= 0.0000001) {
      return $lineas;
    }
    $factor = 1 + $coef;
    $out = [];
    foreach ($lineas as $lin) {
      if (!is_array($lin)) {
        continue;
      }
      $copia = $lin;
      $copia['precio'] = round((float) ($lin['precio'] ?? 0) * $factor, 6);
      $out[] = $copia;
    }
    return $out;
  }

  /** @param list<array<string, mixed>> $lineas */
  private function sumNetoLineas(array $lineas): float
  {
    $neto = 0.0;
    foreach ($lineas as $lin) {
      if (!is_array($lin)) {
        continue;
      }
      $articulo = trim((string) ($lin['articulo'] ?? ''));
      if ($articulo === '') {
        continue;
      }
      $neto += $this->valorNetoLinea($lin);
    }
    return round($neto, 2);
  }

  /** @param list<array<string, mixed>> $lineas */
  private function sumBrutoLineas(array $lineas): float
  {
    $bruto = 0.0;
    foreach ($lineas as $lin) {
      if (!is_array($lin)) {
        continue;
      }
      $articulo = trim((string) ($lin['articulo'] ?? ''));
      if ($articulo === '') {
        continue;
      }
      $bruto += (float) ($lin['cantidad'] ?? 0) * (float) ($lin['precio'] ?? 0);
    }
    return round($bruto, 2);
  }

  private function actualizarCostesArticuloDesdeLinea(string $articulo, float $precioCt, float $coefTransporte): void
  {
    $articulo = trim($articulo);
    if ($articulo === '' || $precioCt <= 0.00001) {
      return;
    }
    $precioBase = $coefTransporte > 0.0000001
      ? round($precioCt / (1 + $coefTransporte), 6)
      : $precioCt;
    try {
      $this->pdo->prepare(
        'UPDATE Articulos SET
           PrecioMedio = :pm,
           PrecioUltimo = :pu,
           PrecioBaseUltimo = :pb
         WHERE RTRIM(Codigo) = :c'
      )->execute([
        'pm' => $precioCt,
        'pu' => $precioBase,
        'pb' => $precioBase,
        'c' => $articulo,
      ]);
    } catch (\Throwable $e) {
      // No bloquear stock si falla actualización de maestro.
    }
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
    $stmt = $this->pdo->prepare('SELECT Almacen FROM Empresas_Ges WHERE Codigo = :e');
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
