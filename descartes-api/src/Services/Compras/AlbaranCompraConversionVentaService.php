<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Compras;

use Descartes\Api\Services\Ventas\VentaEscrituraService;
use PDO;

/**
 * Albarán de compra ACTUALIZADO → albarán de venta al cliente (legacy GenAlbaranCompras).
 * D2.0 exige cliente en el body (legacy no lo pedía en UI).
 */
final class AlbaranCompraConversionVentaService
{
  private PDO $pdo;
  private AlbaranCompraConsultaService $consulta;
  private ?VentaEscrituraService $ventas;

  public function __construct(
    PDO $pdo,
    AlbaranCompraConsultaService $consulta,
    ?VentaEscrituraService $ventas = null
  ) {
    $this->pdo = $pdo;
    $this->consulta = $consulta;
    $this->ventas = $ventas;
  }

  /**
   * @param array<string, mixed> $body
   * @return array{albaranCompra: array<string, mixed>, venta: array<string, mixed>}
   */
  public function convertir(string $empresa, int $albaran, array $body): array
  {
    if ($this->ventas === null) {
      throw new \RuntimeException('Servicio de ventas no disponible para conversion');
    }

    $empresa = trim($empresa);
    $cliente = trim((string) ($body['cliente'] ?? ''));
    if ($cliente === '') {
      throw new \InvalidArgumentException('Cliente obligatorio para generar la venta');
    }

    $detalle = $this->consulta->obtener($empresa, $albaran);
    if ($detalle === null) {
      throw new \RuntimeException('Albarán de compra no encontrado', 404);
    }
    if (empty($detalle['actualizado'])) {
      throw new \RuntimeException(
        'El albarán de compra debe estar ACTUALIZADO (stock aplicado) antes de generar la venta',
        409
      );
    }
    if (!empty($detalle['trasCtb'])) {
      throw new \RuntimeException('No se puede generar venta: albarán traspasado a contabilidad', 409);
    }

    $existente = $this->ventaExistenteParaCompra($empresa, $albaran);
    if ($existente !== null) {
      throw new \RuntimeException(
        'Ya existe un albarán de venta generado desde este albarán de compra ('
        . $existente['tipo'] . '-' . $existente['albaran'] . ')',
        409
      );
    }

    if (!$this->clienteExiste($cliente)) {
      throw new \InvalidArgumentException('Cliente no encontrado: ' . $cliente);
    }

    /** @var list<array<string, mixed>> $lineasCompra */
    $lineasCompra = $detalle['lineas'] ?? [];
    $tarifa = $this->tarifaParaCliente($cliente, $empresa);
    $lineasVenta = [];
    foreach ($lineasCompra as $lin) {
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
      $precio = $this->precioVentaArticulo($articulo, $tarifa);
      if ($precio <= 0) {
        throw new \InvalidArgumentException(
          "El artículo {$articulo} no tiene PVP de venta (tarifa {$tarifa})"
        );
      }
      $pjeDto = (float) ($lin['pjeDto'] ?? 0);
      if ($pjeDto <= 0 && ((float) ($lin['dto1'] ?? 0)) > 0) {
        $pjeDto = (float) $lin['dto1'];
      }
      $lineasVenta[] = [
        'articulo' => $articulo,
        'descripcion' => $lin['descripcion'] ?? null,
        'cantidad' => abs($cantidad),
        'precio' => $precio,
        'pjeDto' => $pjeDto,
        'pjeIva' => $this->pjeIvaArticulo($articulo),
        'loteVenta' => $lin['lote'] ?? null,
        'importe' => round(abs($cantidad) * $precio * (1 - $pjeDto / 100), 2),
      ];
    }

    if ($lineasVenta === []) {
      throw new \InvalidArgumentException('El albarán no tiene líneas de artículo con cantidad');
    }

    $puesto = trim((string) ($body['puesto'] ?? ''));
    if ($puesto === '') {
      $puesto = trim((string) ($detalle['puesto'] ?? ''));
    }
    if ($puesto === '') {
      $puesto = '99';
    }

    $vendedor = trim((string) ($body['vendedor'] ?? ''));
    if ($vendedor === '' && $puesto !== '' && $puesto !== '99') {
      $vendedor = $this->trabajadorDePuesto($puesto) ?? '';
    }
    $vendedor = $vendedor !== '' ? $vendedor : null;

    $fiscal = $this->direccionFiscalCliente($cliente);
    $almacen = isset($detalle['almacen']) && $detalle['almacen'] !== null
      ? (int) $detalle['almacen']
      : null;

    $refCompra = $this->referenciaAlbaranCompra($albaran);

    $venta = $this->ventas->crear([
      'empresa' => $empresa,
      'cliente' => $cliente,
      'razonSocial' => $fiscal['razonSocial'],
      'nif' => $fiscal['nif'] ?? '',
      'puesto' => $puesto,
      'vendedor' => $vendedor,
      'vendedorApertura' => $vendedor,
      'almacen' => $almacen,
      'facturaTipo' => 'R',
      'tarifa' => $tarifa,
      'genAlbaranCompras' => $albaran,
      'referencia1' => $refCompra,
      'suPedido' => $this->trimOrEmpty($detalle['suAlbaran'] ?? null),
      'direccionEnvio' => $fiscal['direccion'],
      'poblacionEnvio' => $fiscal['poblacion'],
      'codigoPostalEnvio' => $fiscal['codigoPostal'],
      'provinciaEnvio' => $fiscal['provincia'],
      'paisEnvio' => $fiscal['pais'],
      'telefono' => $fiscal['telefono'],
      'telefono2' => $fiscal['telefono2'],
      'email' => $fiscal['email'],
      'lineas' => $lineasVenta,
    ]);

    $this->pdo->prepare(
      'UPDATE AlbaranesCompraCab SET Cliente = :cliente, LUpdate = GETDATE()
       WHERE Empresa = :e AND Albaran = :a'
    )->execute([
      'cliente' => $cliente,
      'e' => $empresa,
      'a' => $albaran,
    ]);

    $albaranCompra = $this->consulta->obtener($empresa, $albaran);
    if ($albaranCompra === null) {
      throw new \RuntimeException('Venta creada pero no se pudo releer el albarán de compra');
    }

    return [
      'albaranCompra' => $albaranCompra,
      'venta' => $venta,
    ];
  }

  /** @return array{tipo: string, albaran: int}|null */
  private function ventaExistenteParaCompra(string $empresa, int $albaranCompra): ?array
  {
    $ref = $this->referenciaAlbaranCompra($albaranCompra);
    try {
      $st = $this->pdo->prepare(
        'SELECT TOP 1 Tipo, Albaran FROM AlbaranesVentasCab
         WHERE Empresa = :e AND Referencia1 = :ref AND ISNULL(Anulado, 0) = 0
         ORDER BY Albaran DESC'
      );
      $st->execute(['e' => $empresa, 'ref' => $ref]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if ($row === false) {
        $st2 = $this->pdo->prepare(
          'SELECT TOP 1 Tipo, Albaran FROM AlbaranesVentasCab
           WHERE Empresa = :e AND GenAlbaranCompras = :alb AND ISNULL(Anulado, 0) = 0
           ORDER BY Albaran DESC'
        );
        $st2->execute(['e' => $empresa, 'alb' => $albaranCompra]);
        $row = $st2->fetch(PDO::FETCH_ASSOC);
      }
      if ($row === false) {
        return null;
      }
      return [
        'tipo' => trim((string) ($row['Tipo'] ?? 'A')),
        'albaran' => (int) ($row['Albaran'] ?? 0),
      ];
    } catch (\Throwable $e) {
      return null;
    }
  }

  private function referenciaAlbaranCompra(int $albaran): string
  {
    return 'AC:' . $albaran;
  }

  private function clienteExiste(string $cliente): bool
  {
    try {
      $st = $this->pdo->prepare('SELECT 1 FROM Clientes WHERE Codigo = :c');
      $st->execute(['c' => $cliente]);
      return (bool) $st->fetchColumn();
    } catch (\Throwable $e) {
      return false;
    }
  }

  private function tarifaParaCliente(string $cliente, string $empresa): int
  {
    $tarifa = 0;
    try {
      $st = $this->pdo->prepare('SELECT Tarifa FROM Clientes WHERE Codigo = :c');
      $st->execute(['c' => $cliente]);
      $v = $st->fetchColumn();
      if ($v !== false) {
        $tarifa = (int) $v;
      }
    } catch (\Throwable $e) {
      $tarifa = 0;
    }
    if ($tarifa > 0) {
      return min(9, max(1, $tarifa));
    }
    try {
      $st = $this->pdo->prepare('SELECT Tarifa FROM Empresas_Ges WHERE Codigo = :e');
      $st->execute(['e' => $empresa]);
      $v = $st->fetchColumn();
      if ($v !== false && (int) $v > 0) {
        return min(9, max(1, (int) $v));
      }
    } catch (\Throwable $e) {
      // ignore
    }
    return 1;
  }

  private function precioVentaArticulo(string $articulo, int $tarifa): float
  {
    $tarifa = min(9, max(1, $tarifa));
    $col = 'PrecioVen' . $tarifa;
    $allowed = [
      'PrecioVen1', 'PrecioVen2', 'PrecioVen3', 'PrecioVen4', 'PrecioVen5',
      'PrecioVen6', 'PrecioVen7', 'PrecioVen8', 'PrecioVen9',
    ];
    if (!in_array($col, $allowed, true)) {
      $col = 'PrecioVen1';
    }
    try {
      $st = $this->pdo->prepare("SELECT {$col} AS p FROM Articulos WHERE Codigo = :c");
      $st->execute(['c' => $articulo]);
      $v = $st->fetchColumn();
      return $v !== false ? (float) $v : 0.0;
    } catch (\Throwable $e) {
      return 0.0;
    }
  }

  private function pjeIvaArticulo(string $articulo): float
  {
    try {
      $st = $this->pdo->prepare(
        'SELECT TOP 1 i.PjeIVA
         FROM Articulos a
         LEFT JOIN Impuestos i ON a.Impuesto = i.Codigo
         WHERE a.Codigo = :c'
      );
      $st->execute(['c' => $articulo]);
      $v = $st->fetchColumn();
      if ($v !== false && (float) $v > 0) {
        return (float) $v;
      }
    } catch (\Throwable $e) {
      // fallback
    }
    return 21.0;
  }

  /**
   * @return array{
   *   razonSocial: ?string,
   *   nif: ?string,
   *   direccion: ?string,
   *   poblacion: ?string,
   *   codigoPostal: ?string,
   *   provincia: ?string,
   *   pais: ?string,
   *   telefono: ?string,
   *   telefono2: ?string,
   *   email: ?string
   * }
   */
  private function direccionFiscalCliente(string $cliente): array
  {
    $vacío = [
      'razonSocial' => null,
      'nif' => null,
      'direccion' => null,
      'poblacion' => null,
      'codigoPostal' => null,
      'provincia' => null,
      'pais' => null,
      'telefono' => null,
      'telefono2' => null,
      'email' => null,
    ];
    $cliente = trim($cliente);
    if ($cliente === '') {
      return $vacío;
    }
    try {
      $st = $this->pdo->prepare(
        'SELECT RazonSocial, NIF, Direccion, Poblacion, CodigoPostal, Provincia, Pais,
                Telefono1, Telefono2, Email
         FROM Clientes WHERE Codigo = :c'
      );
      $st->execute(['c' => $cliente]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if ($row === false) {
        return $vacío;
      }
      $trim = static function ($v): ?string {
        if ($v === null) {
          return null;
        }
        $s = trim((string) $v);
        return $s !== '' ? $s : null;
      };
      return [
        'razonSocial' => $trim($row['RazonSocial'] ?? null),
        'nif' => $trim($row['NIF'] ?? null),
        'direccion' => $trim($row['Direccion'] ?? null),
        'poblacion' => $trim($row['Poblacion'] ?? null),
        'codigoPostal' => $trim($row['CodigoPostal'] ?? null),
        'provincia' => $trim($row['Provincia'] ?? null),
        'pais' => $trim($row['Pais'] ?? null),
        'telefono' => $trim($row['Telefono1'] ?? null),
        'telefono2' => $trim($row['Telefono2'] ?? null),
        'email' => $trim($row['Email'] ?? null),
      ];
    } catch (\Throwable $e) {
      return $vacío;
    }
  }

  private function trabajadorDePuesto(string $puesto): ?string
  {
    $puesto = trim($puesto);
    if ($puesto === '') {
      return null;
    }
    try {
      $ps = $this->pdo->prepare('SELECT Trabajador FROM Puestos WHERE Puesto = :p');
      $ps->execute(['p' => $puesto]);
      $trab = $ps->fetchColumn();
      if ($trab === false) {
        return null;
      }
      $s = trim((string) $trab);
      return $s !== '' ? $s : null;
    } catch (\Throwable $e) {
      return null;
    }
  }

  private function trimOrEmpty($value): string
  {
    if ($value === null) {
      return '';
    }
    return trim((string) $value);
  }
}
