<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Ventas;

use Descartes\Api\Database\SqlPagination;
use PDO;

final class PedidoClienteService
{
  private PDO $pdo;
  private ?VentaEscrituraService $ventas;

  public function __construct(PDO $pdo, ?VentaEscrituraService $ventas = null)
  {
    $this->pdo = $pdo;
    $this->ventas = $ventas;
  }

  /** @param array<string, mixed> $query */
  public function listar(array $query): array
  {
    $page = max(1, (int) ($query['page'] ?? 1));
    $pageSize = min(500, max(1, (int) ($query['pageSize'] ?? 50)));
    $offset = ($page - 1) * $pageSize;

    $where = ['1=1'];
    $params = [];
    if (!empty($query['estado'])) {
      $where[] = 'p.Estado = :estado';
      $params['estado'] = $query['estado'];
    }
    if (!empty($query['cliente'])) {
      $where[] = '(p.Cliente LIKE :cliente OR p.RazonSocial LIKE :clienteNom OR p.NIF LIKE :clienteNif)';
      $params['cliente'] = '%' . $query['cliente'] . '%';
      $params['clienteNom'] = '%' . $query['cliente'] . '%';
      $params['clienteNif'] = '%' . $query['cliente'] . '%';
    }
    if ($this->fechaIsoValida($query['fechaDesde'] ?? null)) {
      $where[] = 'p.Fecha >= CONVERT(datetime, :fechaDesde, 120)';
      $params['fechaDesde'] = substr((string) $query['fechaDesde'], 0, 10) . ' 00:00:00';
    }
    if ($this->fechaIsoValida($query['fechaHasta'] ?? null)) {
      $where[] = 'p.Fecha <= CONVERT(datetime, :fechaHasta, 120)';
      $params['fechaHasta'] = substr((string) $query['fechaHasta'], 0, 10) . ' 23:59:59';
    }
    if (!empty($query['empresa'])) {
      $where[] = 'p.Empresa = :empresa';
      $params['empresa'] = $query['empresa'];
    }
    if (!empty($query['puesto'])) {
      $where[] = 'p.Puesto = :puesto';
      $params['puesto'] = $query['puesto'];
    }
    if (!empty($query['vendedor'])) {
      $where[] = 'p.Vendedor = :vendedor';
      $params['vendedor'] = $query['vendedor'];
    }
    // situacion UI: abierto = Actualizado=0/false; cerrado = Actualizado<>0
    $situacion = strtolower(trim((string) ($query['situacion'] ?? '')));
    if ($situacion === 'abierto' || $situacion === 'a') {
      $where[] = '(p.Actualizado = 0 OR p.Actualizado IS NULL)';
    } elseif ($situacion === 'cerrado' || $situacion === 'c') {
      $where[] = 'p.Actualizado <> 0';
    }

    $sqlWhere = implode(' AND ', $where);
    $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM PedidosClientes p WHERE {$sqlWhere}");
    try {
      $countStmt->execute($params);
    } catch (\Throwable $e) {
      // Fallback sin filtro Actualizado / NIF si el esquema no lo admite.
      $where = array_values(array_filter($where, static fn ($w) => !str_contains($w, 'Actualizado') && !str_contains($w, 'NIF')));
      unset($params['clienteNif']);
      $sqlWhere = implode(' AND ', $where);
      $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM PedidosClientes p WHERE {$sqlWhere}");
      $countStmt->execute($params);
    }
    $total = (int) $countStmt->fetchColumn();

    $innerSql = "SELECT p.Empresa, p.Pedido, p.Cliente, p.RazonSocial, p.NIF, p.Fecha, p.Estado, p.Situacion,
                   p.Impreso, p.Importe, p.Actualizado, p.Puesto, p.Vendedor, p.SuPedido
            FROM PedidosClientes p
            WHERE {$sqlWhere}";
    $orderBy = 'p.Fecha DESC, p.Pedido DESC';
    $sql = SqlPagination::wrap($innerSql, $orderBy, $offset, $pageSize);
    $stmt = $this->pdo->prepare($sql);
    foreach ($params as $k => $v) {
      $stmt->bindValue(':' . $k, $v);
    }
    SqlPagination::bind($stmt, $offset, $pageSize);
    try {
      $stmt->execute();
    } catch (\Throwable $e) {
      $innerSql = "SELECT p.Empresa, p.Pedido, p.Cliente, p.RazonSocial, p.Fecha, p.Estado, p.Situacion,
                     p.Impreso, p.Importe
              FROM PedidosClientes p
              WHERE {$sqlWhere}";
      $sql = SqlPagination::wrap($innerSql, $orderBy, $offset, $pageSize);
      $stmt = $this->pdo->prepare($sql);
      foreach ($params as $k => $v) {
        $stmt->bindValue(':' . $k, $v);
      }
      SqlPagination::bind($stmt, $offset, $pageSize);
      $stmt->execute();
    }

    $items = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $items[] = $this->mapResumen($row);
    }

    return [
      'items' => $items,
      'total' => $total,
      'page' => $page,
      'pageSize' => $pageSize,
    ];
  }

  public function obtener(string $empresa, int $pedido): ?array
  {
    $cab = $this->obtenerCabRaw($empresa, $pedido);
    if ($cab === null) {
      return null;
    }

    $lineas = [];
    try {
      $linStmt = $this->pdo->prepare(
        'SELECT NroLin, Articulo, Descripcion, CantidadPedida, CantidadServida, CantidadaServir,
                Precio, PjeDto, Importe, PjeIva, PjeRec, Zona, LoteVenta
         FROM PedidosClientesLin
         WHERE Empresa = :empresa AND Pedido = :pedido
         ORDER BY NroLin'
      );
      $linStmt->execute(['empresa' => $empresa, 'pedido' => $pedido]);
    } catch (\Throwable $e) {
      $linStmt = $this->pdo->prepare(
        'SELECT NroLin, Articulo, Descripcion, CantidadPedida, CantidadServida, CantidadaServir, Precio, Importe
         FROM PedidosClientesLin
         WHERE Empresa = :empresa AND Pedido = :pedido
         ORDER BY NroLin'
      );
      try {
        $linStmt->execute(['empresa' => $empresa, 'pedido' => $pedido]);
      } catch (\Throwable $e2) {
        $linStmt = $this->pdo->prepare(
          'SELECT NroLin, Articulo, CantidadPedida, CantidadServida, CantidadaServir, Precio, Importe
           FROM PedidosClientesLin
           WHERE Empresa = :empresa AND Pedido = :pedido
           ORDER BY NroLin'
        );
        $linStmt->execute(['empresa' => $empresa, 'pedido' => $pedido]);
      }
    }
    while ($lin = $linStmt->fetch(PDO::FETCH_ASSOC)) {
      $lineas[] = $this->mapLinea($lin);
    }

    $detalle = $this->mapResumen($cab);
    $detalle['nif'] = isset($cab['NIF']) ? trim((string) $cab['NIF']) : null;
    $detalle['transporte'] = isset($cab['Transporte']) ? trim((string) $cab['Transporte']) : null;
    $detalle['direccionEnvio'] = isset($cab['DireccionEnvio']) ? trim((string) $cab['DireccionEnvio']) : null;
    $detalle['poblacionEnvio'] = isset($cab['PoblacionEnvio']) ? trim((string) $cab['PoblacionEnvio']) : null;
    $detalle['codigoPostalEnvio'] = isset($cab['CodigoPostalEnvio']) ? trim((string) $cab['CodigoPostalEnvio']) : null;
    $detalle['provinciaEnvio'] = isset($cab['ProvinciaEnvio']) ? trim((string) $cab['ProvinciaEnvio']) : null;
    $detalle['paisEnvio'] = isset($cab['PaisEnvio']) ? trim((string) $cab['PaisEnvio']) : null;
    $detalle['email'] = isset($cab['Email']) ? trim((string) $cab['Email']) : null;
    $detalle['suPedido'] = isset($cab['SuPedido']) ? trim((string) $cab['SuPedido']) : null;
    $detalle['observaciones'] = isset($cab['Observaciones']) ? (string) $cab['Observaciones'] : null;
    $detalle['fechaPrevista'] = isset($cab['FechaPrevista']) && $cab['FechaPrevista']
      ? date('c', strtotime((string) $cab['FechaPrevista']))
      : null;
    $detalle['importeBase1'] = (float) ($cab['ImporteBase1'] ?? 0);
    $detalle['importeIva1'] = (float) ($cab['ImporteIva1'] ?? 0);
    $detalle['pjeIva1'] = (float) ($cab['PjeIva1'] ?? 0);
    $detalle['importePreparacion'] = (float) ($cab['ImportePreparacion'] ?? 0);
    $detalle['pedidosWeb'] = !empty($cab['PedidosWeb']);
    $detalle['lineas'] = $lineas;
    $detalle['editable'] = $this->esEditable($cab);
    $detalle['convertible'] = $this->esEditable($cab) && $this->hayCantidadAServir($lineas);

    $ventaStmt = $this->pdo->prepare(
      'SELECT TOP 1 Tipo, Albaran FROM AlbaranesVentasCab
       WHERE Empresa = :empresa AND Pedido = :pedido ORDER BY Fecha DESC'
    );
    $ventaStmt->execute(['empresa' => $empresa, 'pedido' => $pedido]);
    $venta = $ventaStmt->fetch(PDO::FETCH_ASSOC);
    $detalle['ventaAsociada'] = $venta
      ? ['tipo' => (string) $venta['Tipo'], 'albaran' => (int) $venta['Albaran']]
      : null;

    return $detalle;
  }

  /** @param array<string, mixed> $body */
  public function crear(array $body): array
  {
    $empresa = trim((string) ($body['empresa'] ?? ''));
    $cliente = trim((string) ($body['cliente'] ?? ''));
    $lineas = $body['lineas'] ?? [];
    if ($empresa === '' || $cliente === '') {
      throw new \InvalidArgumentException('Empresa y cliente son obligatorios');
    }
    if (!is_array($lineas)) {
      throw new \InvalidArgumentException('Lineas invalidas');
    }

    $pedidoReservado = (int) ($body['pedido'] ?? 0);
    $puesto = isset($body['puesto']) ? trim((string) $body['puesto']) : null;
    if ($puesto === '') {
      $puesto = null;
    }

    $importeTotal = 0.0;
    foreach ($lineas as $lin) {
      if (!is_array($lin)) {
        continue;
      }
      $cant = (float) ($lin['cantidadPedida'] ?? 0);
      $precio = (float) ($lin['precio'] ?? 0);
      $pjeDto = (float) ($lin['pjeDto'] ?? 0);
      $importeTotal += $cant * $precio * (1 - $pjeDto / 100);
    }
    $importeTotal = round($importeTotal, 2);

    $razon = trim((string) ($body['razonSocial'] ?? '')) ?: $this->razonSocialCliente($cliente);
    $nif = trim((string) ($body['nif'] ?? '')) ?: $this->campoCliente($cliente, 'NIF');

    $this->pdo->beginTransaction();
    try {
      if ($pedidoReservado > 0) {
        $pedido = $pedidoReservado;
      } else {
        try {
          $reserva = $this->reservarPedido($empresa, $puesto);
          $pedido = (int) $reserva['pedido'];
          if ($puesto === null && !empty($reserva['puesto'])) {
            $puesto = $reserva['puesto'];
          }
          if (empty($body['vendedor']) && !empty($reserva['vendedor'])) {
            $body['vendedor'] = $reserva['vendedor'];
          }
        } catch (\Throwable $e) {
          $st = $this->pdo->prepare(
            'SELECT ISNULL(MAX(Pedido), 0) + 1 FROM PedidosClientes WHERE Empresa = :empresa'
          );
          $st->execute(['empresa' => $empresa]);
          $pedido = (int) $st->fetchColumn();
        }
      }

      $params = [
        'empresa' => $empresa,
        'pedido' => $pedido,
        'cliente' => $cliente,
        'razon' => $razon,
        'nif' => $nif !== '' ? $nif : null,
        'puesto' => $puesto,
        'vendedor' => $body['vendedor'] ?? null,
        'importe' => $importeTotal,
        'estado' => $body['estado'] ?? 'P',
        'situacion' => $body['situacion'] ?? 'A',
        'suPedido' => $body['suPedido'] ?? '',
        'observaciones' => $body['observaciones'] ?? null,
        'email' => isset($body['email']) ? trim((string) $body['email']) : null,
        'transporte' => isset($body['transporte']) ? trim((string) $body['transporte']) : null,
        'direccion' => isset($body['direccionEnvio']) ? trim((string) $body['direccionEnvio']) : null,
        'poblacion' => isset($body['poblacionEnvio']) ? trim((string) $body['poblacionEnvio']) : null,
        'cp' => isset($body['codigoPostalEnvio']) ? trim((string) $body['codigoPostalEnvio']) : null,
        'provincia' => isset($body['provinciaEnvio']) ? trim((string) $body['provinciaEnvio']) : null,
        'pais' => isset($body['paisEnvio']) ? trim((string) $body['paisEnvio']) : null,
      ];

      try {
        $this->pdo->prepare(
          'INSERT INTO PedidosClientes
            (Empresa, Pedido, Cliente, RazonSocial, NIF, Fecha, Puesto, Vendedor, Importe, Impreso,
             Actualizado, TrasModem, Estado, Situacion, SuPedido, Observaciones, Email, Transporte,
             DireccionEnvio, PoblacionEnvio, CodigoPostalEnvio, ProvinciaEnvio, PaisEnvio)
           VALUES
            (:empresa, :pedido, :cliente, :razon, :nif, GETDATE(), :puesto, :vendedor, :importe, 0,
             0, 0, :estado, :situacion, :suPedido, :observaciones, :email, :transporte,
             :direccion, :poblacion, :cp, :provincia, :pais)'
        )->execute($params);
      } catch (\Throwable $e) {
        $this->pdo->prepare(
          'INSERT INTO PedidosClientes
            (Empresa, Pedido, Cliente, RazonSocial, Fecha, Puesto, Vendedor, Importe, Impreso, Actualizado, TrasModem, Estado, Situacion)
           VALUES
            (:empresa, :pedido, :cliente, :razon, GETDATE(), :puesto, :vendedor, :importe, 0, 0, 0, :estado, :situacion)'
        )->execute([
          'empresa' => $empresa,
          'pedido' => $pedido,
          'cliente' => $cliente,
          'razon' => $razon,
          'puesto' => $puesto,
          'vendedor' => $body['vendedor'] ?? null,
          'importe' => $importeTotal,
          'estado' => $body['estado'] ?? 'P',
          'situacion' => $body['situacion'] ?? 'A',
        ]);
      }

      if (count($lineas) > 0) {
        $this->insertarLineas($empresa, $pedido, $lineas);
      }
      $this->pdo->commit();
    } catch (\Throwable $e) {
      $this->pdo->rollBack();
      throw $e;
    }

    $detalle = $this->obtener($empresa, $pedido);
    if ($detalle === null) {
      throw new \RuntimeException('Pedido creado pero no se pudo releer');
    }
    return $detalle;
  }

  /**
   * Reserva el siguiente pedido de cliente desde Empresas.UltPedidoCli (legacy FrmPedido).
   *
   * @return array{empresa: string, pedido: int, puesto: ?string, vendedor: ?string}
   */
  public function reservarPedido(string $empresa, ?string $puesto = null): array
  {
    $empresa = trim($empresa);
    if ($empresa === '') {
      throw new \InvalidArgumentException('Empresa (tienda) obligatoria');
    }

    $ownTx = !$this->pdo->inTransaction();
    if ($ownTx) {
      $this->pdo->beginTransaction();
    }
    try {
      $stmt = $this->pdo->prepare(
        'SELECT UltPedidoCli FROM Empresas WITH (UPDLOCK, ROWLOCK) WHERE Codigo = :e'
      );
      $stmt->execute(['e' => $empresa]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      if ($row === false) {
        throw new \RuntimeException('Tienda no encontrada', 404);
      }

      $pedido = (int) ($row['UltPedidoCli'] ?? 0) + 1;
      $this->pdo->prepare(
        'UPDATE Empresas SET UltPedidoCli = :n WHERE Codigo = :e'
      )->execute(['n' => $pedido, 'e' => $empresa]);

      $vendedor = null;
      $puestoLimpio = $puesto !== null ? trim($puesto) : '';
      if ($puestoLimpio !== '') {
        try {
          $ps = $this->pdo->prepare('SELECT Trabajador FROM Puestos WHERE Puesto = :p');
          $ps->execute(['p' => $puestoLimpio]);
          $trab = $ps->fetchColumn();
          if ($trab !== false && trim((string) $trab) !== '') {
            $vendedor = trim((string) $trab);
          }
        } catch (\Throwable $e) {
          $vendedor = null;
        }
      }

      if ($ownTx) {
        $this->pdo->commit();
      }
    } catch (\Throwable $e) {
      if ($ownTx && $this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      throw $e;
    }

    return [
      'empresa' => $empresa,
      'pedido' => $pedido,
      'puesto' => $puestoLimpio !== '' ? $puestoLimpio : null,
      'vendedor' => $vendedor,
    ];
  }

  /**
   * Actualiza cabecera y lineas de un pedido abierto (Actualizado=0).
   *
   * @param array{cliente?: string, puesto?: string, vendedor?: string, lineas?: list<array<string,mixed>>} $body
   */
  public function actualizar(string $empresa, int $pedido, array $body): array
  {
    $empresa = trim($empresa);
    $actual = $this->obtenerCabRaw($empresa, $pedido);
    if ($actual === null) {
      throw new \RuntimeException('Pedido no encontrado', 404);
    }
    if (!$this->esEditable($actual)) {
      throw new \RuntimeException('Pedido cerrado o no editable', 409);
    }

    $lineas = $body['lineas'] ?? null;
    if ($lineas !== null) {
      if (!is_array($lineas)) {
        throw new \InvalidArgumentException('Lineas invalidas');
      }
    }

    $cliente = array_key_exists('cliente', $body)
      ? trim((string) $body['cliente'])
      : trim((string) ($actual['Cliente'] ?? ''));
    if ($cliente === '') {
      throw new \InvalidArgumentException('Cliente obligatorio');
    }

    $this->pdo->beginTransaction();
    try {
      $importeTotal = null;
      if (is_array($lineas)) {
        $this->pdo->prepare(
          'DELETE FROM PedidosClientesLin WHERE Empresa = :e AND Pedido = :p'
        )->execute(['e' => $empresa, 'p' => $pedido]);
        $importeTotal = count($lineas) > 0
          ? $this->insertarLineas($empresa, $pedido, $lineas)
          : 0.0;
      } else {
        $importeTotal = $this->recalcularImporte($empresa, $pedido);
      }

      $razon = $this->razonSocialCliente($cliente);
      $puesto = array_key_exists('puesto', $body) ? ($body['puesto'] ?: null) : ($actual['Puesto'] ?? null);
      $vendedor = array_key_exists('vendedor', $body) ? ($body['vendedor'] ?: null) : ($actual['Vendedor'] ?? null);
      $suPedido = array_key_exists('suPedido', $body)
        ? (string) $body['suPedido']
        : (string) ($actual['SuPedido'] ?? '');
      $observaciones = array_key_exists('observaciones', $body)
        ? (string) $body['observaciones']
        : (string) ($actual['Observaciones'] ?? '');
      $email = array_key_exists('email', $body)
        ? trim((string) $body['email'])
        : trim((string) ($actual['Email'] ?? ''));
      $direccion = array_key_exists('direccionEnvio', $body)
        ? trim((string) $body['direccionEnvio'])
        : trim((string) ($actual['DireccionEnvio'] ?? ''));
      $poblacion = array_key_exists('poblacionEnvio', $body)
        ? trim((string) $body['poblacionEnvio'])
        : trim((string) ($actual['PoblacionEnvio'] ?? ''));
      $cp = array_key_exists('codigoPostalEnvio', $body)
        ? trim((string) $body['codigoPostalEnvio'])
        : trim((string) ($actual['CodigoPostalEnvio'] ?? ''));
      $provincia = array_key_exists('provinciaEnvio', $body)
        ? trim((string) $body['provinciaEnvio'])
        : trim((string) ($actual['ProvinciaEnvio'] ?? ''));
      $pais = array_key_exists('paisEnvio', $body)
        ? trim((string) $body['paisEnvio'])
        : trim((string) ($actual['PaisEnvio'] ?? ''));
      $transporte = array_key_exists('transporte', $body)
        ? trim((string) $body['transporte'])
        : trim((string) ($actual['Transporte'] ?? ''));

      try {
        $this->pdo->prepare(
          'UPDATE PedidosClientes SET
              Cliente = :cliente,
              RazonSocial = :razon,
              Puesto = :puesto,
              Vendedor = :vendedor,
              SuPedido = :suPedido,
              Observaciones = :observaciones,
              Email = :email,
              DireccionEnvio = :direccion,
              PoblacionEnvio = :poblacion,
              CodigoPostalEnvio = :cp,
              ProvinciaEnvio = :provincia,
              PaisEnvio = :pais,
              Transporte = :transporte,
              Importe = :importe,
              Actualizado = 0
           WHERE Empresa = :e AND Pedido = :p'
        )->execute([
          'cliente' => $cliente,
          'razon' => $razon,
          'puesto' => $puesto,
          'vendedor' => $vendedor,
          'suPedido' => $suPedido,
          'observaciones' => $observaciones,
          'email' => $email,
          'direccion' => $direccion,
          'poblacion' => $poblacion,
          'cp' => $cp,
          'provincia' => $provincia,
          'pais' => $pais,
          'transporte' => $transporte,
          'importe' => $importeTotal,
          'e' => $empresa,
          'p' => $pedido,
        ]);
      } catch (\Throwable $e) {
        $this->pdo->prepare(
          'UPDATE PedidosClientes SET
              Cliente = :cliente,
              RazonSocial = :razon,
              Puesto = :puesto,
              Vendedor = :vendedor,
              Importe = :importe,
              Actualizado = 0
           WHERE Empresa = :e AND Pedido = :p'
        )->execute([
          'cliente' => $cliente,
          'razon' => $razon,
          'puesto' => $puesto,
          'vendedor' => $vendedor,
          'importe' => $importeTotal,
          'e' => $empresa,
          'p' => $pedido,
        ]);
      }

      $this->pdo->commit();
    } catch (\Throwable $e) {
      $this->pdo->rollBack();
      throw $e;
    }

    $detalle = $this->obtener($empresa, $pedido);
    if ($detalle === null) {
      throw new \RuntimeException('Pedido actualizado pero no se pudo releer');
    }
    return $detalle;
  }

  /**
   * Convierte cantidades a servir en albaran de venta (Tipo A) y actualiza servido del pedido.
   *
   * @param array{puesto?: string, vendedor?: string, servirPendiente?: bool} $body
   * @return array{pedido: array<string,mixed>, venta: array<string,mixed>}
   */
  public function convertirAVenta(string $empresa, int $pedido, array $body = []): array
  {
    if ($this->ventas === null) {
      throw new \RuntimeException('Servicio de ventas no disponible para conversion');
    }

    $empresa = trim($empresa);
    $detalle = $this->obtener($empresa, $pedido);
    if ($detalle === null) {
      throw new \RuntimeException('Pedido no encontrado', 404);
    }
    if (empty($detalle['editable'])) {
      throw new \RuntimeException('Pedido cerrado o no editable', 409);
    }

    $servirPendiente = !empty($body['servirPendiente']);
    /** @var list<array<string,mixed>> $lineasPedido */
    $lineasPedido = $detalle['lineas'] ?? [];
    $lineasVenta = [];
    $servidas = [];

    foreach ($lineasPedido as $lin) {
      $articulo = trim((string) ($lin['articulo'] ?? ''));
      if ($articulo === '' || strtoupper($articulo) === 'NO') {
        continue;
      }
      $pedida = (float) ($lin['cantidadPedida'] ?? 0);
      $servida = (float) ($lin['cantidadServida'] ?? 0);
      $pendiente = max(0.0, $pedida - $servida);
      $aServir = $servirPendiente
        ? $pendiente
        : (float) ($lin['cantidadAServir'] ?? 0);
      if ($aServir <= 0.00001) {
        continue;
      }
      if ($aServir > $pendiente + 0.0001) {
        throw new \InvalidArgumentException(
          "Cantidad a servir ({$aServir}) supera el pendiente ({$pendiente}) del articulo {$articulo}"
        );
      }
      $precio = (float) ($lin['precio'] ?? 0);
      $pjeDto = (float) ($lin['pjeDto'] ?? 0);
      $lineasVenta[] = [
        'articulo' => $articulo,
        'descripcion' => $lin['descripcion'] ?? null,
        'cantidad' => $aServir,
        'precio' => $precio,
        'pjeDto' => $pjeDto,
        'importe' => round($aServir * $precio * (1 - $pjeDto / 100), 2),
      ];
      $servidas[] = [
        'nroLin' => (int) ($lin['nroLin'] ?? 0),
        'cantidadAServir' => $aServir,
      ];
    }

    if ($lineasVenta === []) {
      throw new \InvalidArgumentException(
        'No hay cantidades a servir. Indique cantidad a servir en las lineas o use servirPendiente.'
      );
    }

    $puesto = trim((string) ($body['puesto'] ?? $detalle['puesto'] ?? ''));
    if ($puesto === '') {
      $puesto = '99';
    }
    $vendedor = trim((string) ($body['vendedor'] ?? $detalle['vendedor'] ?? ''));
    if ($vendedor === '' && $puesto !== '' && $puesto !== '99') {
      $vendedor = $this->trabajadorDePuesto($puesto) ?? '';
    }
    $vendedor = $vendedor !== '' ? $vendedor : null;

    $clienteCod = trim((string) ($detalle['cliente'] ?? ''));
    $fiscal = $this->direccionFiscalCliente($clienteCod);

    $venta = $this->ventas->crear([
      'empresa' => $empresa,
      'cliente' => $detalle['cliente'],
      'razonSocial' => $detalle['razonSocial'] ?? $fiscal['razonSocial'],
      'nif' => $detalle['nif'] ?? $fiscal['nif'],
      'puesto' => $puesto,
      'vendedor' => $vendedor,
      // Legacy: VendedorApertura = codigo de vendedor (trabajador), nunca usuario de login.
      'vendedorApertura' => $vendedor,
      'pedido' => $pedido,
      'facturaTipo' => 'R',
      // Legacy: cabecera del albaran usa direccion FISCAL del cliente (no la de envio del pedido).
      'direccionEnvio' => $fiscal['direccion'],
      'poblacionEnvio' => $fiscal['poblacion'],
      'codigoPostalEnvio' => $fiscal['codigoPostal'],
      'provinciaEnvio' => $fiscal['provincia'],
      'paisEnvio' => $fiscal['pais'],
      'telefono' => $fiscal['telefono'],
      'telefono2' => $fiscal['telefono2'],
      'email' => $detalle['email'] ?? $fiscal['email'],
      'transporte' => $detalle['transporte'] ?? null,
      'lineas' => $lineasVenta,
    ]);

    $this->aplicarServido($empresa, $pedido, $servidas);

    $pedidoAct = $this->obtener($empresa, $pedido);
    if ($pedidoAct === null) {
      throw new \RuntimeException('Venta creada pero no se pudo releer el pedido');
    }

    return [
      'pedido' => $pedidoAct,
      'venta' => $venta,
    ];
  }

  public function marcarImpreso(string $empresa, int $pedido): array
  {
    $upd = $this->pdo->prepare(
      'UPDATE PedidosClientes SET Impreso = 1 WHERE Empresa = :empresa AND Pedido = :pedido'
    );
    $upd->execute(['empresa' => $empresa, 'pedido' => $pedido]);
    if ($upd->rowCount() === 0) {
      $existe = $this->obtener($empresa, $pedido);
      if ($existe === null) {
        throw new \RuntimeException('Pedido no encontrado', 404);
      }
    }
    $detalle = $this->obtener($empresa, $pedido);
    if ($detalle === null) {
      throw new \RuntimeException('Pedido no encontrado', 404);
    }
    return $detalle;
  }

  /**
   * @param list<array{nroLin: int, cantidadAServir: float}> $servidas
   */
  private function aplicarServido(string $empresa, int $pedido, array $servidas): void
  {
    $this->pdo->beginTransaction();
    try {
      $updLin = $this->pdo->prepare(
        'UPDATE PedidosClientesLin SET
            CantidadServida = ISNULL(CantidadServida, 0) + :qty,
            CantidadaServir = 0
         WHERE Empresa = :e AND Pedido = :p AND NroLin = :n'
      );
      foreach ($servidas as $s) {
        $updLin->execute([
          'qty' => $s['cantidadAServir'],
          'e' => $empresa,
          'p' => $pedido,
          'n' => $s['nroLin'],
        ]);
      }

      $completo = $this->estaCompletamenteServido($empresa, $pedido);
      $importe = $this->recalcularImporte($empresa, $pedido);
      $this->pdo->prepare(
        'UPDATE PedidosClientes SET
            Importe = :imp,
            Situacion = \' \',
            Actualizado = :act
         WHERE Empresa = :e AND Pedido = :p'
      )->execute([
        'imp' => $importe,
        'act' => $completo ? 1 : 0,
        'e' => $empresa,
        'p' => $pedido,
      ]);

      $this->pdo->commit();
    } catch (\Throwable $e) {
      $this->pdo->rollBack();
      throw $e;
    }
  }

  private function estaCompletamenteServido(string $empresa, int $pedido): bool
  {
    $st = $this->pdo->prepare(
      'SELECT COUNT(*) FROM PedidosClientesLin
       WHERE Empresa = :e AND Pedido = :p
         AND ISNULL(CantidadServida, 0) + 0.0001 < ISNULL(CantidadPedida, 0)'
    );
    $st->execute(['e' => $empresa, 'p' => $pedido]);
    return (int) $st->fetchColumn() === 0;
  }

  /**
   * @param list<array<string, mixed>> $lineas
   */
  private function insertarLineas(string $empresa, int $pedido, array $lineas): float
  {
    $importeTotal = 0.0;
    $n = 0;
    foreach ($lineas as $lin) {
      $articulo = trim((string) ($lin['articulo'] ?? ''));
      if ($articulo === '') {
        throw new \InvalidArgumentException('Cada linea requiere articulo');
      }
      $n++;
      $cant = (float) ($lin['cantidadPedida'] ?? 0);
      $servida = (float) ($lin['cantidadServida'] ?? 0);
      $aServir = (float) ($lin['cantidadAServir'] ?? 0);
      $precio = (float) ($lin['precio'] ?? 0);
      $pjeDto = (float) ($lin['pjeDto'] ?? 0);
      $pjeIva = (float) ($lin['pjeIva'] ?? 0);
      $importe = round($cant * $precio * (1 - $pjeDto / 100), 2);
      $importeTotal += $importe;
      $desc = $lin['descripcion'] ?? $this->descripcionArticulo($articulo);
      $zona = $lin['zona'] ?? null;
      $lote = $lin['loteVenta'] ?? null;
      $ok = false;
      try {
        $this->pdo->prepare(
          'INSERT INTO PedidosClientesLin
            (Empresa, Pedido, Articulo, Descripcion, CantidadPedida, CantidadServida, CantidadaServir,
             Precio, PjeDto, Importe, PjeIva, Zona, LoteVenta)
           VALUES
            (:empresa, :pedido, :articulo, :descripcion, :cant, :servida, :aservir,
             :precio, :pjeDto, :importe, :pjeIva, :zona, :lote)'
        )->execute([
          'empresa' => $empresa,
          'pedido' => $pedido,
          'articulo' => $articulo,
          'descripcion' => $desc,
          'cant' => $cant,
          'servida' => $servida,
          'aservir' => $aServir,
          'precio' => $precio,
          'pjeDto' => $pjeDto,
          'importe' => $importe,
          'pjeIva' => $pjeIva,
          'zona' => $zona,
          'lote' => $lote,
        ]);
        $ok = true;
      } catch (\Throwable $e) {
        // fallback esquemas más simples
      }
      if (!$ok) {
        try {
          $this->pdo->prepare(
            'INSERT INTO PedidosClientesLin
              (Empresa, Pedido, Articulo, Descripcion, CantidadPedida, CantidadServida, CantidadaServir, Precio, Importe)
             VALUES
              (:empresa, :pedido, :articulo, :descripcion, :cant, :servida, :aservir, :precio, :importe)'
          )->execute([
            'empresa' => $empresa,
            'pedido' => $pedido,
            'articulo' => $articulo,
            'descripcion' => $desc,
            'cant' => $cant,
            'servida' => $servida,
            'aservir' => $aServir,
            'precio' => $precio,
            'importe' => $importe,
          ]);
        } catch (\Throwable $e) {
          $this->pdo->prepare(
            'INSERT INTO PedidosClientesLin
              (Empresa, Pedido, Articulo, CantidadPedida, CantidadServida, CantidadaServir, Precio, Importe)
             VALUES
              (:empresa, :pedido, :articulo, :cant, :servida, :aservir, :precio, :importe)'
          )->execute([
            'empresa' => $empresa,
            'pedido' => $pedido,
            'articulo' => $articulo,
            'cant' => $cant,
            'servida' => $servida,
            'aservir' => $aServir,
            'precio' => $precio,
            'importe' => $importe,
          ]);
        }
      }
    }
    if ($n < 1) {
      throw new \InvalidArgumentException('El pedido debe tener al menos una linea');
    }
    return round($importeTotal, 2);
  }

  private function recalcularImporte(string $empresa, int $pedido): float
  {
    $st = $this->pdo->prepare(
      'SELECT ISNULL(SUM(Importe), 0) FROM PedidosClientesLin WHERE Empresa = :e AND Pedido = :p'
    );
    $st->execute(['e' => $empresa, 'p' => $pedido]);
    return round((float) $st->fetchColumn(), 2);
  }

  /** @return array<string, mixed>|null */
  private function obtenerCabRaw(string $empresa, int $pedido): ?array
  {
    $stmt = $this->pdo->prepare(
      'SELECT * FROM PedidosClientes WHERE Empresa = :empresa AND Pedido = :pedido'
    );
    $stmt->execute(['empresa' => $empresa, 'pedido' => $pedido]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
  }

  /** @param array<string, mixed> $cab */
  private function esEditable(array $cab): bool
  {
    if (!empty($cab['Actualizado']) || !empty($cab['actualizado'])) {
      return false;
    }
    if (!empty($cab['TrasModem']) || !empty($cab['trasModem'])) {
      return false;
    }
    return true;
  }

  /** @param list<array<string, mixed>> $lineas */
  private function hayCantidadAServir(array $lineas): bool
  {
    foreach ($lineas as $lin) {
      $a = (float) ($lin['cantidadAServir'] ?? 0);
      $pend = (float) ($lin['pendiente'] ?? 0);
      if ($a > 0.00001 || $pend > 0.00001) {
        return true;
      }
    }
    return false;
  }

  private function nextPedido(string $empresa): int
  {
    try {
      $reserva = $this->reservarPedido($empresa, null);
      return (int) $reserva['pedido'];
    } catch (\Throwable $e) {
      $stmt = $this->pdo->prepare(
        'SELECT ISNULL(MAX(Pedido), 0) + 1 FROM PedidosClientes WHERE Empresa = :empresa'
      );
      $stmt->execute(['empresa' => $empresa]);
      return (int) $stmt->fetchColumn();
    }
  }

  private function campoCliente(string $cliente, string $campo): ?string
  {
    $cols = [
      'NIF' => 'NIF',
      'RazonSocial' => 'RazonSocial',
      'Direccion' => 'Direccion',
      'Poblacion' => 'Poblacion',
      'CodigoPostal' => 'CodigoPostal',
      'Provincia' => 'Provincia',
      'Pais' => 'Pais',
      'Telefono1' => 'Telefono1',
      'Telefono2' => 'Telefono2',
      'Email' => 'Email',
    ];
    if (!isset($cols[$campo])) {
      return null;
    }
    $col = $cols[$campo];
    try {
      $st = $this->pdo->prepare("SELECT {$col} FROM Clientes WHERE Codigo = :c");
      $st->execute(['c' => $cliente]);
      $v = $st->fetchColumn();
      return $v !== false && trim((string) $v) !== '' ? trim((string) $v) : null;
    } catch (\Throwable $e) {
      return null;
    }
  }

  /**
   * Direccion fiscal del cliente (legacy: se copia a DireccionEnvio* de AlbaranesVentasCab).
   *
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
    $cliente = trim($cliente);
    if ($cliente === '') {
      return [
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
        return $this->direccionFiscalCliente('');
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
      return $this->direccionFiscalCliente('');
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

  private function razonSocialCliente(string $cliente): ?string
  {
    return $this->campoCliente($cliente, 'RazonSocial');
  }

  private function descripcionArticulo(string $articulo): ?string
  {
    try {
      $st = $this->pdo->prepare('SELECT Descripcion FROM Articulos WHERE Codigo = :c');
      $st->execute(['c' => $articulo]);
      $v = $st->fetchColumn();
      return $v !== false ? (string) $v : null;
    } catch (\Throwable $e) {
      return null;
    }
  }

  /** @param array<string, mixed> $row */
  private function mapResumen(array $row): array
  {
    $actualizado = !empty($row['Actualizado']);
    return [
      'empresa' => (string) $row['Empresa'],
      'pedido' => (int) $row['Pedido'],
      'cliente' => $row['Cliente'],
      'razonSocial' => $row['RazonSocial'] ?? null,
      'nif' => isset($row['NIF']) ? trim((string) $row['NIF']) : null,
      'fecha' => isset($row['Fecha']) && $row['Fecha'] ? date('c', strtotime((string) $row['Fecha'])) : null,
      'estado' => $row['Estado'],
      'situacion' => $row['Situacion'],
      'situacionLabel' => $actualizado ? 'CERRADO' : 'ABIERTO',
      'impreso' => (bool) ($row['Impreso'] ?? false),
      'importe' => (float) ($row['Importe'] ?? 0),
      'actualizado' => $actualizado,
      'puesto' => isset($row['Puesto']) ? trim((string) $row['Puesto']) : null,
      'vendedor' => isset($row['Vendedor']) ? trim((string) $row['Vendedor']) : null,
      'suPedido' => isset($row['SuPedido']) ? trim((string) $row['SuPedido']) : null,
    ];
  }

  /** @param array<string, mixed> $lin */
  private function mapLinea(array $lin): array
  {
    $pedida = (float) ($lin['CantidadPedida'] ?? 0);
    $servida = (float) ($lin['CantidadServida'] ?? 0);
    $aServir = (float) ($lin['CantidadaServir'] ?? 0);
    return [
      'nroLin' => (int) $lin['NroLin'],
      'articulo' => $lin['Articulo'],
      'descripcion' => $lin['Descripcion'] ?? null,
      'cantidadPedida' => $pedida,
      'cantidadServida' => $servida,
      'cantidadAServir' => $aServir,
      'pendiente' => round(max(0, $pedida - $servida), 4),
      'precio' => (float) ($lin['Precio'] ?? 0),
      'pjeDto' => (float) ($lin['PjeDto'] ?? 0),
      'importe' => (float) ($lin['Importe'] ?? 0),
      'pjeIva' => (float) ($lin['PjeIva'] ?? 0),
      'pjeRec' => (float) ($lin['PjeRec'] ?? 0),
      'zona' => $lin['Zona'] ?? null,
      'loteVenta' => isset($lin['LoteVenta']) ? (string) $lin['LoteVenta'] : null,
    ];
  }

  private function fechaIsoValida($value): bool
  {
    if ($value === null || $value === '') {
      return false;
    }
    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $value);
  }
}
