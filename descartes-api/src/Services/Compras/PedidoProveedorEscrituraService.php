<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Compras;

use PDO;

/**
 * Escritura de pedidos a proveedor (004 US3 / T026).
 * Contador Empresas.UltPedidoCom.
 */
final class PedidoProveedorEscrituraService
{
  private PDO $pdo;
  private PedidoProveedorConsultaService $consulta;

  public function __construct(PDO $pdo, PedidoProveedorConsultaService $consulta)
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
    $lineasNorm = $this->normalizarLineasParaEscritura($lineas, null);

    $fecha = $this->normalizeFecha($body['fechaPedido'] ?? $body['fecha'] ?? null);
    $almacen = $this->intOrNull($body['almacen'] ?? null);
    if ($almacen === null) {
      $almacen = $this->almacenTienda($empresa);
    }
    $importe = $this->calcularImporte($lineasNorm);
    $situacion = PedidoProveedorConsultaService::SIT_PENDIENTE;

    $this->pdo->beginTransaction();
    try {
      $pedido = isset($body['pedido']) && (int) $body['pedido'] > 0
        ? (int) $body['pedido']
        : $this->nextPedidoLocked($empresa);

      $fechaMax = $this->normalizeFechaOrNull($body['fechaMaxRecepcion'] ?? null);
      $sql = 'INSERT INTO PedidosCab (
          Empresa, Pedido, FechaPedido, Proveedor, Importe, Situacion,
          FechaMaxRecepcion, Observaciones, ObservInternas, Vendedor,
          TrasModem, Almacen, PreciosActualizados
        ) VALUES (
          :empresa, :pedido, CONVERT(datetime, :fechaPedido, 120), :proveedor, :importe, :situacion,
          ' . ($fechaMax === null ? 'NULL' : 'CONVERT(datetime, :fechaMaxRecepcion, 120)') . ',
          :observaciones, :observInternas, :vendedor,
          0, :almacen, 0
        )';
      $params = [
        'empresa' => $empresa,
        'pedido' => $pedido,
        'fechaPedido' => $fecha,
        'proveedor' => $proveedor,
        'importe' => $importe,
        'situacion' => $situacion,
        'observaciones' => $this->nullIfEmpty($body['observaciones'] ?? null),
        'observInternas' => $this->nullIfEmpty($body['observInternas'] ?? null),
        'vendedor' => $this->nullIfEmpty($body['vendedor'] ?? null),
        'almacen' => $almacen ?? 0,
      ];
      if ($fechaMax !== null) {
        $params['fechaMaxRecepcion'] = $fechaMax;
      }
      $this->pdo->prepare($sql)->execute($params);

      $this->reemplazarLineas($empresa, $pedido, $lineasNorm);
      $this->pdo->commit();
    } catch (\Throwable $e) {
      if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      throw $e;
    }

    $detalle = $this->consulta->obtener($empresa, $pedido);
    if ($detalle === null) {
      throw new \RuntimeException('Pedido a proveedor creado pero no se pudo releer');
    }
    return $detalle;
  }

  /**
   * Actualiza pedido abierto o parcial (no servido).
   * Conserva CantidadSer; no permite bajar CantidadPed por debajo de lo servido.
   *
   * @param array<string, mixed> $body
   * @return array<string, mixed>
   */
  public function actualizar(string $empresa, int $pedido, array $body): array
  {
    $empresa = trim($empresa);
    $actual = $this->consulta->obtener($empresa, $pedido);
    if ($actual === null) {
      throw new \RuntimeException('Pedido a proveedor no encontrado', 404);
    }
    $this->assertEditable($actual);

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
    $lineasNorm = $this->normalizarLineasParaEscritura($lineas, $actual['lineas'] ?? []);

    $merged = array_merge($actual, $body);
    $merged['proveedor'] = $proveedor;
    $fecha = $this->normalizeFecha($merged['fechaPedido'] ?? null);
    $almacen = $this->intOrNull($merged['almacen'] ?? null);
    if ($almacen === null) {
      $almacen = $this->almacenTienda($empresa);
    }
    $importe = $this->calcularImporte($lineasNorm);
    $situacion = $this->situacionDesdeLineas($lineasNorm);

    $fechaMax = $this->normalizeFechaOrNull($merged['fechaMaxRecepcion'] ?? null);

    $this->pdo->beginTransaction();
    try {
      $sql = 'UPDATE PedidosCab SET
          FechaPedido = CONVERT(datetime, :fechaPedido, 120),
          Proveedor = :proveedor,
          Importe = :importe,
          Situacion = :situacion,
          FechaMaxRecepcion = ' . ($fechaMax === null ? 'NULL' : 'CONVERT(datetime, :fechaMaxRecepcion, 120)') . ',
          Observaciones = :observaciones,
          ObservInternas = :observInternas,
          Vendedor = :vendedor,
          Almacen = :almacen
        WHERE Empresa = :empresa AND Pedido = :pedido';
      $params = [
        'fechaPedido' => $fecha,
        'proveedor' => $proveedor,
        'importe' => $importe,
        'situacion' => $situacion,
        'observaciones' => $this->nullIfEmpty($merged['observaciones'] ?? null),
        'observInternas' => $this->nullIfEmpty($merged['observInternas'] ?? null),
        'vendedor' => $this->nullIfEmpty($merged['vendedor'] ?? null),
        'almacen' => $almacen ?? 0,
        'empresa' => $empresa,
        'pedido' => $pedido,
      ];
      if ($fechaMax !== null) {
        $params['fechaMaxRecepcion'] = $fechaMax;
      }
      $this->pdo->prepare($sql)->execute($params);

      $this->reemplazarLineas($empresa, $pedido, $lineasNorm);
      $this->pdo->commit();
    } catch (\Throwable $e) {
      if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      throw $e;
    }

    $detalle = $this->consulta->obtener($empresa, $pedido);
    if ($detalle === null) {
      throw new \RuntimeException('Pedido actualizado pero no se pudo releer');
    }
    return $detalle;
  }

  /**
   * @param array<string, mixed> $actual
   */
  public function assertEditable(array $actual): void
  {
    $label = (string) ($actual['situacionLabel'] ?? '');
    if ($label === 'servido') {
      throw new \RuntimeException(
        'No se puede modificar: el pedido está completamente servido.',
        409
      );
    }
  }

  /**
   * Recalcula y persiste Situacion a partir de las líneas actuales (uso recepción T031).
   */
  public function refrescarSituacion(string $empresa, int $pedido): void
  {
    $stmt = $this->pdo->prepare(
      'SELECT ISNULL(SUM(CantidadPed), 0) AS SumPed, ISNULL(SUM(CantidadSer), 0) AS SumSer
       FROM PedidosLin WHERE Empresa = :e AND Pedido = :p'
    );
    $stmt->execute(['e' => $empresa, 'p' => $pedido]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['SumPed' => 0, 'SumSer' => 0];
    $label = $this->consulta->labelFromCantidades((float) $row['SumPed'], (float) $row['SumSer']);
    $codigo = $this->consulta->codigoFromLabel($label);
    $this->pdo->prepare(
      'UPDATE PedidosCab SET Situacion = :s WHERE Empresa = :e AND Pedido = :p'
    )->execute(['s' => $codigo, 'e' => $empresa, 'p' => $pedido]);
  }

  /**
   * Reserva el siguiente nº de pedido a proveedor (UltPedidoCom), sin grabar cabecera.
   *
   * @return array{empresa: string, pedido: int, almacen: ?int}
   */
  public function reservarPedido(string $empresa): array
  {
    $empresa = trim($empresa);
    if ($empresa === '') {
      throw new \InvalidArgumentException('Empresa (tienda) obligatoria');
    }

    $this->pdo->beginTransaction();
    try {
      $stmt = $this->pdo->prepare(
        'SELECT UltPedidoCom, Almacen FROM Empresas WITH (UPDLOCK, ROWLOCK) WHERE Codigo = :e'
      );
      $stmt->execute(['e' => $empresa]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      if ($row === false) {
        throw new \RuntimeException('Tienda no encontrada', 404);
      }

      $pedido = (int) ($row['UltPedidoCom'] ?? 0) + 1;
      $this->pdo->prepare(
        'UPDATE Empresas SET UltPedidoCom = :n WHERE Codigo = :e'
      )->execute(['n' => $pedido, 'e' => $empresa]);

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
      'pedido' => $pedido,
      'almacen' => $almacen,
    ];
  }

  private function nextPedidoLocked(string $empresa): int
  {
    $stmt = $this->pdo->prepare(
      'SELECT UltPedidoCom FROM Empresas WITH (UPDLOCK, ROWLOCK) WHERE Codigo = :e'
    );
    $stmt->execute(['e' => $empresa]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      throw new \RuntimeException('Tienda no encontrada', 404);
    }
    $n = (int) ($row['UltPedidoCom'] ?? 0) + 1;
    $this->pdo->prepare(
      'UPDATE Empresas SET UltPedidoCom = :n WHERE Codigo = :e'
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
      if (!is_array($lin)) {
        continue;
      }
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
   * @param list<array<string, mixed>>|null $existentes
   * @return list<array<string, mixed>>
   */
  private function normalizarLineasParaEscritura(array $lineas, ?array $existentes): array
  {
    $serByKey = [];
    if (is_array($existentes)) {
      foreach ($existentes as $ex) {
        if (!is_array($ex)) {
          continue;
        }
        $art = trim((string) ($ex['articulo'] ?? ''));
        $num = (int) ($ex['numLin'] ?? 0);
        if ($art === '') {
          continue;
        }
        $serByKey[$art . '|' . $num] = (float) ($ex['cantidadSer'] ?? 0);
        // También indexar solo por artículo+posición si numLin nuevo.
        if (!isset($serByKey[$art])) {
          $serByKey[$art] = (float) ($ex['cantidadSer'] ?? 0);
        }
      }
    }

    $out = [];
    $num = 1;
    $usedSerKeys = [];
    foreach ($lineas as $lin) {
      if (!is_array($lin)) {
        continue;
      }
      $articulo = trim((string) ($lin['articulo'] ?? ''));
      if ($articulo === '') {
        continue;
      }
      $numLin = isset($lin['numLin']) && (int) $lin['numLin'] > 0 ? (int) $lin['numLin'] : $num;
      $cantidadPed = (float) ($lin['cantidadPed'] ?? $lin['cantidad'] ?? 0);
      $key = $articulo . '|' . $numLin;
      $ser = 0.0;
      if (isset($serByKey[$key])) {
        $ser = $serByKey[$key];
        $usedSerKeys[$key] = true;
      } elseif (array_key_exists('cantidadSer', $lin)) {
        $ser = (float) $lin['cantidadSer'];
      }
      if ($cantidadPed + 0.0000001 < $ser) {
        throw new \InvalidArgumentException(
          "Cantidad pedida ({$cantidadPed}) no puede ser menor que la servida ({$ser}) en artículo {$articulo}"
        );
      }

      $precioPed = (float) ($lin['precioPed'] ?? $lin['precio'] ?? 0);
      $pjeDto = (float) ($lin['pjeDto'] ?? 0);
      $dto1 = (float) ($lin['dto1'] ?? 0);
      $dto2 = (float) ($lin['dto2'] ?? 0);
      $dto3 = (float) ($lin['dto3'] ?? 0);
      $factor = (1 - $pjeDto / 100) * (1 - $dto1 / 100) * (1 - $dto2 / 100) * (1 - $dto3 / 100);
      $importeLin = isset($lin['importe']) ? (float) $lin['importe'] : round($cantidadPed * $precioPed * $factor, 2);

      $descripcion = trim((string) ($lin['descripcion'] ?? ''));
      if ($descripcion === '') {
        $descripcion = $this->descripcionArticulo($articulo) ?? $articulo;
      }

      $out[] = [
        'numLin' => $numLin,
        'articulo' => $articulo,
        'descripcion' => substr($descripcion, 0, 50),
        'cantidadPed' => $cantidadPed,
        'cantidadSer' => $ser,
        'precioPed' => $precioPed,
        'precioRec' => (float) ($lin['precioRec'] ?? 0),
        'pjeDto' => $pjeDto,
        'dto1' => $dto1,
        'dto2' => $dto2,
        'dto3' => $dto3,
        'importe' => $importeLin,
        'almacen' => $this->intOrNull($lin['almacen'] ?? null) ?? 0,
      ];
      $num = max($num, $numLin) + 1;
    }

    // No eliminar líneas con mercancía ya servida.
    if (is_array($existentes)) {
      foreach ($existentes as $ex) {
        if (!is_array($ex)) {
          continue;
        }
        $art = trim((string) ($ex['articulo'] ?? ''));
        $n = (int) ($ex['numLin'] ?? 0);
        $ser = (float) ($ex['cantidadSer'] ?? 0);
        if ($art === '' || $ser <= 0.0000001) {
          continue;
        }
        $key = $art . '|' . $n;
        if (!isset($usedSerKeys[$key])) {
          throw new \InvalidArgumentException(
            "No se puede eliminar la línea {$art} (numLin {$n}): ya tiene cantidad servida ({$ser})"
          );
        }
      }
    }

    if ($out === []) {
      throw new \InvalidArgumentException('El pedido debe tener al menos una línea de artículo');
    }

    return $out;
  }

  /**
   * @param list<array<string, mixed>> $lineas
   */
  private function calcularImporte(array $lineas): float
  {
    $sum = 0.0;
    foreach ($lineas as $lin) {
      $sum += (float) ($lin['importe'] ?? 0);
    }
    return round($sum, 2);
  }

  /**
   * @param list<array<string, mixed>> $lineas
   */
  private function situacionDesdeLineas(array $lineas): int
  {
    $ped = 0.0;
    $ser = 0.0;
    foreach ($lineas as $lin) {
      $ped += (float) ($lin['cantidadPed'] ?? 0);
      $ser += (float) ($lin['cantidadSer'] ?? 0);
    }
    return $this->consulta->codigoFromLabel($this->consulta->labelFromCantidades($ped, $ser));
  }

  /**
   * @param list<array<string, mixed>> $lineas
   */
  private function reemplazarLineas(string $empresa, int $pedido, array $lineas): void
  {
    $this->pdo->prepare(
      'DELETE FROM PedidosLin WHERE Empresa = :e AND Pedido = :p'
    )->execute(['e' => $empresa, 'p' => $pedido]);

    // NumLin es IDENTITY; hay que forzar valores estables (UI / recepción por numLin).
    $this->pdo->exec('SET IDENTITY_INSERT PedidosLin ON');
    try {
      $ins = $this->pdo->prepare(
        'INSERT INTO PedidosLin (
           Empresa, Pedido, NumLin, Articulo, Descripcion,
           CantidadPed, CantidadSer, PrecioPed, PrecioRec, PjeDto,
           SW_Etiqueta, Importe, Dto1, Dto2, Dto3, Almacen
         ) VALUES (
           :e, :p, :numLin, :articulo, :descripcion,
           :cantidadPed, :cantidadSer, :precioPed, :precioRec, :pjeDto,
           0, :importe, :dto1, :dto2, :dto3, :almacen
         )'
      );

      foreach ($lineas as $lin) {
        $ins->execute([
          'e' => $empresa,
          'p' => $pedido,
          'numLin' => (int) $lin['numLin'],
          'articulo' => $lin['articulo'],
          'descripcion' => $lin['descripcion'],
          'cantidadPed' => (float) $lin['cantidadPed'],
          'cantidadSer' => (float) $lin['cantidadSer'],
          'precioPed' => (float) $lin['precioPed'],
          'precioRec' => (float) $lin['precioRec'],
          'pjeDto' => (float) $lin['pjeDto'],
          'importe' => (float) $lin['importe'],
          'dto1' => (float) $lin['dto1'],
          'dto2' => (float) $lin['dto2'],
          'dto3' => (float) $lin['dto3'],
          'almacen' => (int) ($lin['almacen'] ?? 0),
        ]);
      }
    } finally {
      $this->pdo->exec('SET IDENTITY_INSERT PedidosLin OFF');
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
    if ($v === false || $v === null || (int) $v <= 0) {
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
    if ($s !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) {
      $ts = strtotime($s);
      if ($ts) {
        return date('Y-m-d H:i:s', $ts);
      }
    }
    return date('Y-m-d H:i:s');
  }

  private function normalizeFechaOrNull($value): ?string
  {
    if ($value === null || $value === '') {
      return null;
    }
    return $this->normalizeFecha($value);
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
