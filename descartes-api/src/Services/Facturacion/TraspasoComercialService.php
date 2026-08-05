<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Facturacion;

use PDO;

/**
 * Traspaso comercial desde Generador Manual (legacy CmdGenTraspaso → GenTraspaso).
 *
 * Convierte albaranes de venta seleccionados en AlbaranesTraspasoCab/Lin
 * y los saca del pool facturable (FacturaTipo = 'R').
 */
final class TraspasoComercialService
{
  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  /**
   * @param array<string, mixed> $body
   * @return array{traspasos: list<array<string, mixed>>, totales: array{traspasos: int, albaranes: int}}
   */
  public function generar(array $body): array
  {
    $empresaFacturacion = trim((string) ($body['empresa'] ?? ''));
    if ($empresaFacturacion === '') {
      throw new \InvalidArgumentException('empresa (tienda) obligatoria');
    }

    $albaranes = $body['albaranes'] ?? null;
    if (!is_array($albaranes) || $albaranes === []) {
      throw new \InvalidArgumentException('Seleccione al menos un albarán');
    }

    $bonet = $this->iniFlag('GeneracionTraspasosBonet', false);
    $identificar = $this->iniFlag('IdentificarAlbaranEnTraspaso', true);

    $this->pdo->beginTransaction();
    try {
      $creados = [];
      foreach ($albaranes as $ref) {
        if (!is_array($ref)) {
          throw new \InvalidArgumentException('Formato de albarán inválido');
        }
        $empresa = trim((string) ($ref['empresa'] ?? ''));
        $tipo = trim((string) ($ref['tipo'] ?? ''));
        $albaran = (int) ($ref['albaran'] ?? 0);
        if ($empresa === '' || $tipo === '' || $albaran <= 0) {
          throw new \InvalidArgumentException('Albarán incompleto (empresa/tipo/albaran)');
        }

        $cab = $this->cargarAlbaran($empresa, $tipo, $albaran);
        $creados[] = $this->convertirUno(
          $cab,
          $empresaFacturacion,
          $bonet,
          $identificar
        );
      }
      $this->pdo->commit();
    } catch (\Throwable $e) {
      if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      throw $e;
    }

    return [
      'traspasos' => $creados,
      'totales' => [
        'traspasos' => count($creados),
        'albaranes' => count($creados),
      ],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function cargarAlbaran(string $empresa, string $tipo, int $albaran): array
  {
    $stmt = $this->pdo->prepare(
      "SELECT Empresa, Tipo, Albaran, Almacen, Cliente, SuPedido, RebajeStock,
              Factura, FacturaTipo, Estado
       FROM AlbaranesVentasCab
       WHERE Empresa = :e AND Tipo = :t AND Albaran = :a"
    );
    $stmt->execute(['e' => $empresa, 't' => $tipo, 'a' => $albaran]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      throw new \RuntimeException("Albarán {$empresa}-{$tipo}-{$albaran} no encontrado", 404);
    }

    $factura = (int) ($row['Factura'] ?? 0);
    $facturaTipo = strtoupper(trim((string) ($row['FacturaTipo'] ?? '')));
    if ($factura > 0 || ($facturaTipo !== '' && $facturaTipo !== 'Z' && $facturaTipo !== 'R')) {
      throw new \RuntimeException(
        "Albarán {$empresa}-{$albaran} ya facturado o no convertible (FacturaTipo={$facturaTipo})",
        409
      );
    }
    if ($facturaTipo === 'R') {
      throw new \RuntimeException("Albarán {$empresa}-{$albaran} ya es un traspaso", 409);
    }

    return $row;
  }

  /**
   * @param array<string, mixed> $cab
   * @return array{empresa: string, albaran: int, albaranOrigen: int, empresaOrigen: string}
   */
  private function convertirUno(array $cab, string $empresaFacturacion, bool $bonet, bool $identificar): array
  {
    $empresaOrigen = trim((string) $cab['Empresa']);
    $tipo = trim((string) $cab['Tipo']);
    $albaranOrigen = (int) $cab['Albaran'];
    $almacen = (int) ($cab['Almacen'] ?? 0);
    $rebajeStock = !empty($cab['RebajeStock']);

    if (!$bonet && $rebajeStock) {
      $this->revertirStockVenta($empresaOrigen, $tipo, $albaranOrigen, $almacen);
    }

    $numTraspaso = $this->nextContador($empresaFacturacion, 'UltAlbaranTra');
    $suAlbaran = $identificar
      ? sprintf('%03s-%07d', $empresaOrigen, $albaranOrigen)
      : ' ';
    $proyecto = substr(trim((string) ($cab['SuPedido'] ?? '')), 0, 15);
    $cliente = trim((string) ($cab['Cliente'] ?? ''));
    $hoy = date('Y-m-d H:i:s');

    $insCab = $this->pdo->prepare(
      "INSERT INTO AlbaranesTraspasoCab (
         Empresa, Albaran, SuAlbaran, FechaAlbaran, AlmacenOrigen, AlmacenDestino,
         ImporteAlb, ImporteIVA, ImporteRec, Observaciones,
         Actualizado, AlbaranSalida, Transmitido, TrasModem, BloqueadoTrasModem,
         OrigenExterno, DestinoExterno, LUpdate, Cliente, Proyecto, GenAlbaranTraspaso, UltNum
       ) VALUES (
         :empresa, :albaran, :su, :fecha, :origen, 0,
         0, 0, 0, :obs,
         1, 1, 0, 0, 0,
         0, 1, :lupdate, :cliente, :proyecto, 0, 0
       )"
    );
    $insCab->execute([
      'empresa' => $empresaFacturacion,
      'albaran' => $numTraspaso,
      'su' => $suAlbaran,
      'fecha' => $hoy,
      'origen' => $almacen,
      'obs' => 'Traspaso Comercial',
      'lupdate' => $hoy,
      'cliente' => $cliente !== '' ? $cliente : null,
      'proyecto' => $proyecto !== '' ? $proyecto : null,
    ]);

    $linStmt = $this->pdo->prepare(
      "SELECT Articulo, Descripcion, Cantidad, PrecioMedio, Importe
       FROM AlbaranesVentasLin
       WHERE Empresa = :e AND Tipo = :t AND Albaran = :a
         AND ISNULL(Cantidad, 0) <> 0"
    );
    $linStmt->execute(['e' => $empresaOrigen, 't' => $tipo, 'a' => $albaranOrigen]);
    $lineas = $linStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $insLin = $this->pdo->prepare(
      "INSERT INTO AlbaranesTraspasoLin (
         Empresa, Albaran, Articulo, Descripcion, Cantidad, Precio, PrecioVenta
       ) VALUES (
         :empresa, :albaran, :articulo, :descripcion, :cantidad, :precio, :precioVenta
       )"
    );

    $totalTraspaso = 0.0;
    $year = (int) date('Y');
    $month = (int) date('n');
    $almacenExterno = $this->almacenEsExterno($almacen);

    foreach ($lineas as $lin) {
      $cantidad = (float) ($lin['Cantidad'] ?? 0);
      if (abs($cantidad) < 0.0000001) {
        continue;
      }
      $importe = (float) ($lin['Importe'] ?? 0);
      $precioMedio = (float) ($lin['PrecioMedio'] ?? 0);
      $precioVenta = $importe / $cantidad;
      $articulo = trim((string) ($lin['Articulo'] ?? ''));
      $descripcion = substr(trim((string) ($lin['Descripcion'] ?? '')), 0, 50);

      $insLin->execute([
        'empresa' => $empresaFacturacion,
        'albaran' => $numTraspaso,
        'articulo' => $articulo,
        'descripcion' => $descripcion,
        'cantidad' => $cantidad,
        'precio' => $precioMedio,
        'precioVenta' => $precioVenta,
      ]);

      $lineaImporte = round($cantidad * $precioMedio, 2);
      $totalTraspaso += $lineaImporte;

      if (!$bonet && $almacen > 0 && !$almacenExterno && $articulo !== '') {
        $this->modificaStock(
          'ST',
          $almacen,
          $year,
          $month,
          $articulo,
          $cantidad,
          $lineaImporte
        );
      }
    }

    $this->pdo->prepare(
      'UPDATE AlbaranesTraspasoCab SET ImporteAlb = :imp WHERE Empresa = :e AND Albaran = :a'
    )->execute([
      'imp' => round($totalTraspaso, 2),
      'e' => $empresaFacturacion,
      'a' => $numTraspaso,
    ]);

    $this->pdo->prepare(
      "UPDATE AlbaranesVentasCab
       SET FacturaTipo = 'R', Estado = NULL
       WHERE Empresa = :e AND Tipo = :t AND Albaran = :a"
    )->execute(['e' => $empresaOrigen, 't' => $tipo, 'a' => $albaranOrigen]);

    return [
      'empresa' => $empresaFacturacion,
      'albaran' => $numTraspaso,
      'empresaOrigen' => $empresaOrigen,
      'albaranOrigen' => $albaranOrigen,
    ];
  }

  private function revertirStockVenta(string $empresa, string $tipo, int $albaran, int $almacen): void
  {
    if ($almacen <= 0 || $this->almacenEsExterno($almacen)) {
      return;
    }

    $cabFecha = $this->pdo->prepare(
      'SELECT Fecha FROM AlbaranesVentasCab WHERE Empresa = :e AND Tipo = :t AND Albaran = :a'
    );
    $cabFecha->execute(['e' => $empresa, 't' => $tipo, 'a' => $albaran]);
    $fechaRow = $cabFecha->fetch(PDO::FETCH_ASSOC);
    $fecha = $fechaRow['Fecha'] ?? date('Y-m-d');
    $ts = is_string($fecha) ? strtotime($fecha) : (is_numeric($fecha) ? (int) $fecha : time());
    if ($ts === false) {
      $ts = time();
    }
    $year = (int) date('Y', $ts);
    $month = (int) date('n', $ts);

    $linStmt = $this->pdo->prepare(
      "SELECT Articulo, Cantidad, Importe, PrecioMedio
       FROM AlbaranesVentasLin
       WHERE Empresa = :e AND Tipo = :t AND Albaran = :a
         AND ISNULL(Cantidad, 0) <> 0"
    );
    $linStmt->execute(['e' => $empresa, 't' => $tipo, 'a' => $albaran]);
    $lineas = $linStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($lineas as $lin) {
      $articulo = trim((string) ($lin['Articulo'] ?? ''));
      $cantidad = (float) ($lin['Cantidad'] ?? 0);
      if ($articulo === '' || abs($cantidad) < 0.0000001) {
        continue;
      }
      $importeVenta = (float) ($lin['Importe'] ?? 0);
      $importeCoste = round($cantidad * (float) ($lin['PrecioMedio'] ?? 0), 2);

      // PosNeg = -1 → resta ventas (revierte SV).
      $this->modificaStock('SV', $almacen, $year, $month, $articulo, -$cantidad, -$importeCoste, -$importeVenta);
    }
  }

  private function modificaStock(
    string $tipo,
    int $almacen,
    int $year,
    int $month,
    string $articulo,
    float $cantidad,
    float $importe,
    float $importeVenta = 0.0
  ): void {
    $sel = $this->pdo->prepare(
      'SELECT Codigo FROM Stock WHERE Codigo = :c AND Almacen = :a AND [Año] = :y AND Mes = :m'
    );
    $sel->execute(['c' => $articulo, 'a' => $almacen, 'y' => $year, 'm' => $month]);
    if ($sel->fetch() === false) {
      $this->pdo->prepare(
        "INSERT INTO Stock (
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
         )"
      )->execute(['c' => $articulo, 'a' => $almacen, 'y' => $year, 'm' => $month]);
    }

    if ($tipo === 'ST') {
      $this->pdo->prepare(
        "UPDATE Stock SET
           TraspasosSalidas = ISNULL(TraspasosSalidas, 0) + :q,
           ValorTraspasosSalidas = ISNULL(ValorTraspasosSalidas, 0) + :imp
         WHERE Codigo = :c AND Almacen = :a AND [Año] = :y AND Mes = :m"
      )->execute([
        'q' => $cantidad,
        'imp' => $importe,
        'c' => $articulo,
        'a' => $almacen,
        'y' => $year,
        'm' => $month,
      ]);
      return;
    }

    if ($tipo === 'SV') {
      $this->pdo->prepare(
        "UPDATE Stock SET
           Salidas = ISNULL(Salidas, 0) + :q,
           ValorSalidas = ISNULL(ValorSalidas, 0) + :imp,
           Ventas = ISNULL(Ventas, 0) + :q2,
           ValorVentas = ISNULL(ValorVentas, 0) + :impV
         WHERE Codigo = :c AND Almacen = :a AND [Año] = :y AND Mes = :m"
      )->execute([
        'q' => $cantidad,
        'imp' => $importe,
        'q2' => $cantidad,
        'impV' => $importeVenta,
        'c' => $articulo,
        'a' => $almacen,
        'y' => $year,
        'm' => $month,
      ]);
    }
  }

  private function almacenEsExterno(int $codigo): bool
  {
    if ($codigo <= 0) {
      return true;
    }
    $st = $this->pdo->prepare('SELECT Externo FROM Almacenes WHERE Codigo = :c');
    $st->execute(['c' => $codigo]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row !== false && !empty($row['Externo']);
  }

  private function nextContador(string $empresa, string $campo): int
  {
    if ($campo !== 'UltAlbaranTra' && $campo !== 'UltAlbaranVen') {
      throw new \InvalidArgumentException('Contador no permitido');
    }
    $stmt = $this->pdo->prepare(
      "SELECT [{$campo}] FROM Empresas WITH (UPDLOCK, ROWLOCK) WHERE Codigo = :e"
    );
    $stmt->execute(['e' => $empresa]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      throw new \RuntimeException('Tienda no encontrada', 404);
    }
    $n = (int) ($row[$campo] ?? 0) + 1;
    $this->pdo->prepare("UPDATE Empresas SET [{$campo}] = :n WHERE Codigo = :e")
      ->execute(['n' => $n, 'e' => $empresa]);
    return $n;
  }

  private function iniFlag(string $key, bool $default): bool
  {
    $defaultStr = $default ? 'S' : 'N';
    $paths = [
      'C:\\DesOra\\DesParametros.ini',
      '\\DesOra\\DesParametros.ini',
      dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'DesParametros.ini',
    ];
    foreach ($paths as $path) {
      if (!is_readable($path)) {
        continue;
      }
      $ini = @parse_ini_file($path, true, INI_SCANNER_RAW);
      if (!is_array($ini)) {
        continue;
      }
      $val = $ini['General'][$key] ?? null;
      if ($val === null) {
        continue;
      }
      return strtoupper(trim((string) $val)) === 'S';
    }
    return $default;
  }
}
