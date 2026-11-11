<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Ventas;

use Descartes\Api\Services\UsuarioCorreoService;
use PDO;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Circuito de revelado: encargo, etiqueta y albarán de venta.
 * El número del encargo es el del albarán. No usa la cola de etiquetas de artículo ni el QR de fidelización.
 */
final class EncargoService
{
  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  private static bool $asegurado = false;

  public function asegurar(): void
  {
    if (self::$asegurado) {
      return;
    }
    $this->asegurarEsquema();
    self::$asegurado = true;
  }

  private function asegurarEsquema(): void
  {
    if ($this->esMysql()) {
      // Las tablas legacy (Clientes, Empresas_Ges, AlbaranesVentasCab) usan utf8mb4_general_ci;
      // si creamos Encargos con unicode_ci, los JOIN por Codigo/Cliente fallan con
      // "Illegal mix of collations (1267)". Misma collation que el resto.
      $this->pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
      $this->pdo->exec(
        'CREATE TABLE IF NOT EXISTS Encargos (
          Numero INT NOT NULL,
          Cliente VARCHAR(20) NOT NULL,
          AlbaranEmpresa VARCHAR(10) NOT NULL,
          AlbaranTipo VARCHAR(2) NOT NULL,
          Albaran INT NOT NULL,
          TiendaOrigen VARCHAR(10) NOT NULL,
          TiendaDestino VARCHAR(10) NOT NULL,
          Modalidad VARCHAR(12) NOT NULL,
          MismaTienda TINYINT NOT NULL DEFAULT 0,
          Rodillos INT NOT NULL DEFAULT 1,
          EtiquetasPedidas INT NOT NULL DEFAULT 1,
          EtiquetasImpresas INT NOT NULL DEFAULT 0,
          EstadoTrabajo VARCHAR(24) NOT NULL,
          EstadoSobre VARCHAR(24) NOT NULL,
          ExpedicionFecha DATE NULL,
          FechaAlta DATETIME NOT NULL,
          FechaImpresion DATETIME NULL,
          FechaExpedicion DATETIME NULL,
          FechaRecepcion DATETIME NULL,
          FechaListo DATETIME NULL,
          FechaCierre DATETIME NULL,
          Observaciones VARCHAR(200) NULL,
          PRIMARY KEY (Numero)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
      );
      $this->pdo->exec(
        'CREATE TABLE IF NOT EXISTS EncargoEmails (
          Id INT NOT NULL AUTO_INCREMENT,
          Cliente VARCHAR(20) NOT NULL,
          EncargoNumero INT NULL,
          Tipo VARCHAR(12) NOT NULL,
          Destinatario VARCHAR(160) NOT NULL,
          Fecha DATETIME NOT NULL,
          Resultado VARCHAR(40) NOT NULL,
          Detalle VARCHAR(400) NULL,
          PRIMARY KEY (Id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
      );
      $this->pdo->exec(
        'CREATE TABLE IF NOT EXISTS EncargoSecuencia (
          Id TINYINT NOT NULL,
          Ultimo INT NOT NULL,
          PRIMARY KEY (Id)
        ) ENGINE=InnoDB'
      );
      $this->pdo->exec('INSERT IGNORE INTO EncargoSecuencia (Id, Ultimo) VALUES (1, 0)');
      $this->pdo->exec('ALTER TABLE Encargos CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
      $this->pdo->exec('ALTER TABLE EncargoEmails CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
      $this->pdo->exec('CREATE INDEX IF NOT EXISTS ix_Clientes_RazonSocial ON Clientes (RazonSocial)');
      $this->pdo->exec('CREATE INDEX IF NOT EXISTS ix_Clientes_Telefono1 ON Clientes (Telefono1)');
      $this->pdo->exec('CREATE INDEX IF NOT EXISTS ix_Clientes_Telefono2 ON Clientes (Telefono2)');
      $this->ampliarEncargos();
      return;
    }

    $this->pdo->exec(
      "IF OBJECT_ID('dbo.Encargos', 'U') IS NULL
        CREATE TABLE dbo.Encargos (
          Numero INT NOT NULL PRIMARY KEY,
          Cliente NVARCHAR(20) NOT NULL,
          AlbaranEmpresa NVARCHAR(10) NOT NULL,
          AlbaranTipo NVARCHAR(2) NOT NULL,
          Albaran INT NOT NULL,
          TiendaOrigen NVARCHAR(10) NOT NULL,
          TiendaDestino NVARCHAR(10) NOT NULL,
          Modalidad NVARCHAR(12) NOT NULL,
          MismaTienda BIT NOT NULL DEFAULT 0,
          Rodillos INT NOT NULL DEFAULT 1,
          EtiquetasPedidas INT NOT NULL DEFAULT 1,
          EtiquetasImpresas INT NOT NULL DEFAULT 0,
          EstadoTrabajo NVARCHAR(24) NOT NULL,
          EstadoSobre NVARCHAR(24) NOT NULL,
          ExpedicionFecha DATE NULL,
          FechaAlta DATETIME NOT NULL,
          FechaImpresion DATETIME NULL,
          FechaExpedicion DATETIME NULL,
          FechaRecepcion DATETIME NULL,
          FechaListo DATETIME NULL,
          FechaCierre DATETIME NULL,
          Observaciones NVARCHAR(200) NULL
        )"
    );
    $this->pdo->exec(
      "IF OBJECT_ID('dbo.EncargoEmails', 'U') IS NULL
        CREATE TABLE dbo.EncargoEmails (
          Id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
          Cliente NVARCHAR(20) NOT NULL,
          EncargoNumero INT NULL,
          Tipo NVARCHAR(12) NOT NULL,
          Destinatario NVARCHAR(160) NOT NULL,
          Fecha DATETIME NOT NULL,
          Resultado NVARCHAR(40) NOT NULL,
          Detalle NVARCHAR(400) NULL
        )"
    );
    $this->pdo->exec(
      "IF OBJECT_ID('dbo.EncargoSecuencia', 'U') IS NULL
        CREATE TABLE dbo.EncargoSecuencia (
          Id TINYINT NOT NULL PRIMARY KEY,
          Ultimo INT NOT NULL
        )"
    );
    $this->pdo->exec(
      'IF NOT EXISTS (SELECT 1 FROM EncargoSecuencia WHERE Id = 1)
        INSERT INTO EncargoSecuencia (Id, Ultimo) VALUES (1, 0)'
    );
    $this->ampliarEncargos();
  }

  private function ampliarEncargos(): void
  {
    $nuevaSituacion = !$this->tieneColumna('Encargos', 'Estado');
    if (!$this->tieneColumna('Encargos', 'Tipo')) {
      $tipo = $this->esMysql() ? 'VARCHAR(12)' : 'NVARCHAR(12)';
      $this->pdo->exec("ALTER TABLE Encargos ADD Tipo {$tipo} NOT NULL DEFAULT 'revelado'");
    }
    if (!$this->tieneColumna('Encargos', 'Origen')) {
      // 'venta' = nacido de líneas con sobre: se mantiene solo desde la venta. 'manual' = pantalla antigua.
      $origen = $this->esMysql() ? 'VARCHAR(12)' : 'NVARCHAR(12)';
      $this->pdo->exec("ALTER TABLE Encargos ADD Origen {$origen} NOT NULL DEFAULT 'manual'");
    }
    if ($nuevaSituacion) {
      $estado = $this->esMysql() ? 'VARCHAR(32)' : 'NVARCHAR(32)';
      $this->pdo->exec("ALTER TABLE Encargos ADD Estado {$estado} NOT NULL DEFAULT 'pendiente_realizar'");
      $this->pdo->exec(
        "UPDATE Encargos SET Estado = CASE
          WHEN EstadoSobre = 'entregado' OR EstadoTrabajo = 'finalizado' THEN 'entregado'
          WHEN EstadoTrabajo = 'preparado' AND MismaTienda = 1 THEN 'pendiente_recoger'
          WHEN EstadoTrabajo = 'preparado' AND MismaTienda = 0 THEN 'pendiente_devolver'
          WHEN EstadoSobre = 'nuevo' THEN 'pendiente_enviar'
          ELSE 'pendiente_realizar'
        END"
      );
    }
    $this->normalizarEstados();
    if ($this->esMysql()) {
      $this->pdo->exec(
        'CREATE UNIQUE INDEX IF NOT EXISTS ux_Encargos_Albaran ON Encargos (AlbaranEmpresa, AlbaranTipo, Albaran)'
      );
      return;
    }
    $this->pdo->exec(
      "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'ux_Encargos_Albaran' AND object_id = OBJECT_ID('dbo.Encargos'))
        CREATE UNIQUE INDEX ux_Encargos_Albaran ON Encargos (AlbaranEmpresa, AlbaranTipo, Albaran)"
    );
  }

  private function tieneColumna(string $tabla, string $columna): bool
  {
    if ($this->esMysql()) {
      $st = $this->pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = LOWER(:t) AND LOWER(COLUMN_NAME) = LOWER(:c)'
      );
      $st->execute(['t' => $tabla, 'c' => $columna]);
      return (int) $st->fetchColumn() > 0;
    }
    $st = $this->pdo->prepare('SELECT COL_LENGTH(:t, :c)');
    $st->execute(['t' => 'dbo.' . $tabla, 'c' => $columna]);
    return $st->fetchColumn() !== null;
  }

  /** @return array{items: list<array<string, mixed>>} */
  public function listar(?string $q, ?string $cliente): array
  {
    $this->asegurar();
    $where = ['1=1'];
    $params = [];
    $q = trim((string) $q);
    if ($q !== '') {
      // El lector entrega tienda + albarán (el código de barras de la etiqueta).
      $where[] = '(e.Numero = :num OR e.Albaran = :numAlbaran
        OR CONCAT(RTRIM(e.AlbaranEmpresa), CAST(e.Albaran AS varchar(20))) = :barras
        OR e.Cliente LIKE :qc OR c.RazonSocial LIKE :qn OR c.Telefono1 LIKE :qt)';
      $n = ctype_digit($q) ? (int) $q : -1;
      $params['num'] = $n;
      $params['numAlbaran'] = $n;
      $params['barras'] = ctype_digit($q) ? $q : '-';
      $params['qc'] = '%' . $q . '%';
      $params['qn'] = '%' . $q . '%';
      $params['qt'] = '%' . $q . '%';
    }
    $cliente = trim((string) $cliente);
    if ($cliente !== '') {
      $where[] = 'e.Cliente = :cliente';
      $params['cliente'] = $cliente;
    }
    $sql = 'SELECT e.*, c.RazonSocial AS Nombre, c.Telefono1 AS Telefono, c.Telefono2 AS Telefono2, c.Email AS Email,
              c.Direccion AS DireccionCliente, c.CodigoPostal AS CodigoPostal, c.Poblacion AS Poblacion, c.Provincia AS Provincia,
              o.Nombre AS OrigenNombre, d.Nombre AS DestinoNombre
            FROM Encargos e
            LEFT JOIN Clientes c ON RTRIM(c.Codigo) = e.Cliente
            LEFT JOIN Empresas_Ges o ON RTRIM(o.Codigo) = e.TiendaOrigen
            LEFT JOIN Empresas_Ges d ON RTRIM(d.Codigo) = e.TiendaDestino
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY e.FechaAlta DESC, e.Numero DESC
            LIMIT 200';
    if (!$this->esMysql()) {
      $sql = str_replace('LIMIT 200', '', $sql);
      $sql = preg_replace('/^SELECT /', 'SELECT TOP 200 ', $sql) ?? $sql;
    }
    $st = $this->pdo->prepare($sql);
    $st->execute($params);
    $items = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $items[] = $this->fila($row);
    }
    return ['items' => $items];
  }

  /** @return array<string, mixed> */
  public function obtener(int $numero): array
  {
    $row = $this->filaEncargo($numero);
    if ($row === null) {
      throw new \InvalidArgumentException('Encargo no encontrado');
    }
    return $row;
  }

  /**
   * Código, nombre o teléfono. El prefijo usa los índices; el contenido cubre el resto del nombre.
   *
   * @return array{items: list<array{codigo: string, nombre: string, telefono: string, email: string}>}
   */
  public function buscarClientes(string $q): array
  {
    $this->asegurar();
    $q = trim($q);
    if (mb_strlen($q) < 3) {
      return ['items' => []];
    }
    $sql = $this->esMysql()
      ? 'SELECT Codigo, RazonSocial, Telefono1, Email FROM (
           SELECT Codigo, RazonSocial, Telefono1, Email FROM Clientes
             WHERE IFNULL(Baja, 0) = 0 AND Codigo LIKE :pre1
           UNION
           SELECT Codigo, RazonSocial, Telefono1, Email FROM Clientes
             WHERE IFNULL(Baja, 0) = 0 AND RazonSocial LIKE :pre2
           UNION
           SELECT Codigo, RazonSocial, Telefono1, Email FROM Clientes
             WHERE IFNULL(Baja, 0) = 0 AND (Telefono1 LIKE :pre3 OR Telefono2 LIKE :pre4)
           UNION
           SELECT Codigo, RazonSocial, Telefono1, Email FROM Clientes
             WHERE IFNULL(Baja, 0) = 0 AND (RazonSocial LIKE :mid OR Telefono1 LIKE :tel OR Telefono2 LIKE :tel2)
         ) c
         ORDER BY RazonSocial
         LIMIT 40'
      : 'SELECT TOP 40 Codigo, RazonSocial, Telefono1, Email FROM (
           SELECT Codigo, RazonSocial, Telefono1, Email FROM Clientes
             WHERE ISNULL(Baja, 0) = 0 AND Codigo LIKE :pre1
           UNION
           SELECT Codigo, RazonSocial, Telefono1, Email FROM Clientes
             WHERE ISNULL(Baja, 0) = 0 AND RazonSocial LIKE :pre2
           UNION
           SELECT Codigo, RazonSocial, Telefono1, Email FROM Clientes
             WHERE ISNULL(Baja, 0) = 0 AND (Telefono1 LIKE :pre3 OR Telefono2 LIKE :pre4)
           UNION
           SELECT Codigo, RazonSocial, Telefono1, Email FROM Clientes
             WHERE ISNULL(Baja, 0) = 0 AND (RazonSocial LIKE :mid OR Telefono1 LIKE :tel OR Telefono2 LIKE :tel2)
         ) c
         ORDER BY RazonSocial';
    $pre = $q . '%';
    $mid = '%' . $q . '%';
    $st = $this->pdo->prepare($sql);
    $st->execute([
      'pre1' => $pre,
      'pre2' => $pre,
      'pre3' => $pre,
      'pre4' => $pre,
      'mid' => $mid,
      'tel' => $mid,
      'tel2' => $mid,
    ]);
    $items = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $items[] = [
        'codigo' => trim((string) $row['Codigo']),
        'nombre' => trim((string) ($row['RazonSocial'] ?? '')),
        'telefono' => trim((string) ($row['Telefono1'] ?? '')),
        'email' => trim((string) ($row['Email'] ?? '')),
      ];
    }
    return ['items' => $items];
  }

  /**
   * QR de alta: solo el cliente. QR de encargo: cliente y albarán.
   *
   * @return array<string, mixed>
   */
  /**
   * El lector, con teclado español, cambia los separadores:
   * dos puntos se leen como Ñ y el guion como apóstrofo.
   */
  public static function normalizarLectura(string $codigo): string
  {
    $codigo = trim($codigo);
    $codigo = str_replace(['Ñ', 'ñ'], ':', $codigo);
    if (stripos($codigo, 'DCTB') === 0) {
      $codigo = preg_replace("/[-'´`’‘?]/u", ':', $codigo) ?? $codigo;
    }
    return $codigo;
  }

  public function resolverQr(string $codigo): array
  {
    $this->asegurar();
    $codigo = self::normalizarLectura($codigo);
    if (preg_match('/^DCTB:C:(.+)$/i', $codigo, $m) === 1) {
      $cliente = $this->cliente(trim($m[1]));
      return ['tipo' => 'cliente', 'cliente' => $cliente, 'encargo' => null];
    }
    if (preg_match('/^DCTB:E:(.+):(\d+)$/i', $codigo, $m) === 1) {
      $encargo = $this->localizarEncargo(trim($m[1]), (int) $m[2]);
      return ['tipo' => 'encargo', 'cliente' => $this->cliente((string) $encargo['cliente']), 'encargo' => $encargo];
    }
    if (ctype_digit($codigo)) {
      // Código de barras de la etiqueta: tienda + albarán.
      $porBarras = $this->localizarPorBarras($codigo);
      if ($porBarras !== null) {
        return ['tipo' => 'encargo', 'cliente' => $this->cliente((string) $porBarras['cliente']), 'encargo' => $porBarras];
      }
      $encargo = $this->localizarEncargo(null, (int) $codigo);
      return ['tipo' => 'encargo', 'cliente' => $this->cliente((string) $encargo['cliente']), 'encargo' => $encargo];
    }
    throw new \InvalidArgumentException('QR no reconocido');
  }

  /**
   * Tienda + albarán pegados (122600803). Si dos encargos dan la misma cadena, el que sigue abierto.
   *
   * @return array<string, mixed>|null
   */
  public function localizarPorBarras(string $barras): ?array
  {
    $this->asegurar();
    $barras = trim($barras);
    if ($barras === '' || !ctype_digit($barras)) {
      return null;
    }
    $st = $this->pdo->prepare(
      "SELECT Numero, Estado FROM Encargos
       WHERE CONCAT(RTRIM(AlbaranEmpresa), CAST(Albaran AS varchar(20))) = :b
       ORDER BY Numero DESC"
    );
    $st->execute(['b' => $barras]);
    $filas = $st->fetchAll(PDO::FETCH_ASSOC);
    if ($filas === []) {
      return null;
    }
    foreach ($filas as $f) {
      if ((string) ($f['Estado'] ?? '') !== 'entregado') {
        return $this->obtener((int) $f['Numero']);
      }
    }
    return $this->obtener((int) $filas[0]['Numero']);
  }

  /**
   * Tienda del puesto, por código. No se busca por el nombre de la tienda.
   *
   * @return array{codigo: string, nombre: string}|null
   */
  public function tiendaPorCodigo(string $codigo): ?array
  {
    $this->asegurar();
    $pedido = trim($codigo);
    if ($pedido === '') {
      return null;
    }
    $st = $this->pdo->query('SELECT Codigo, Nombre FROM Empresas_Ges');
    $numerico = ctype_digit($pedido) ? (string) (int) $pedido : null;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $item = [
        'codigo' => trim((string) $row['Codigo']),
        'nombre' => trim((string) $row['Nombre']),
      ];
      if (strcasecmp($item['codigo'], $pedido) === 0) {
        return $item;
      }
      if ($numerico !== null && ctype_digit($item['codigo']) && (string) (int) $item['codigo'] === $numerico) {
        return $item;
      }
    }

    return null;
  }

  /**
   * Balmes y Torrent de l'Olla por nombre: el circuito del laboratorio de los encargos de venta.
   *
   * @return array{balmes: ?array{codigo: string, nombre: string}, torrent: ?array{codigo: string, nombre: string}, completas: bool}
   */
  public function tiendasCircuito(): array
  {
    $this->asegurar();
    $st = $this->pdo->query('SELECT Codigo, Nombre FROM Empresas_Ges');
    $balmes = null;
    $torrent = null;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $item = [
        'codigo' => trim((string) $row['Codigo']),
        'nombre' => trim((string) $row['Nombre']),
      ];
      if ($balmes === null && preg_match('/balmes/i', $item['nombre']) === 1) {
        $balmes = $item;
      }
      if ($torrent === null && preg_match('/torrent/i', $item['nombre']) === 1) {
        $torrent = $item;
      }
    }
    return [
      'balmes' => $balmes,
      'torrent' => $torrent,
      'completas' => $balmes !== null && $torrent !== null,
    ];
  }

  /** @return array{numero: int} */
  public function proponerNumero(): array
  {
    $this->asegurar();
    $ultimo = (int) $this->pdo->query('SELECT Ultimo FROM EncargoSecuencia WHERE Id = 1')->fetchColumn();
    $numero = max(1, $ultimo + 1);
    while ($this->numeroOcupado($numero)) {
      $numero++;
    }
    return ['numero' => $numero];
  }

  /** @param array<string, mixed> $body */
  public function crear(array $body): array
  {
    $this->asegurar();
    $codigo = trim((string) ($body['cliente'] ?? ''));
    if ($codigo === '' || strcasecmp($codigo, 'VARIOS') === 0) {
      throw new \InvalidArgumentException('El encargo exige un cliente ya creado');
    }
    $cliente = $this->cliente($codigo);
    if (strcasecmp((string) $cliente['nombre'], 'VARIOS') === 0) {
      throw new \InvalidArgumentException('No se admite un cliente de paso');
    }
    $tipoTrabajo = strtolower(trim((string) ($body['tipo'] ?? '')));
    if (!in_array($tipoTrabajo, ['revelado', 'impresion'], true)) {
      throw new \InvalidArgumentException('Indique si el encargo es de revelado o de impresión');
    }
    $tienda = $this->tiendaPorCodigo((string) ($body['tiendaEntrega'] ?? ''));
    if ($tienda === null) {
      throw new \InvalidArgumentException('El puesto no tiene una tienda configurada');
    }
    $entrega = $tienda['codigo'];
    $modalidad = strtolower(trim((string) ($body['modalidad'] ?? '')));
    if (!in_array($modalidad, ['recogida', 'envio'], true)) {
      throw new \InvalidArgumentException('Indique si el cliente lo recogerá o si se envía por mensajero');
    }
    $laboratorio = $entrega;
    $misma = true;
    $estado = 'pendiente_revelar';
    $rodillos = $tipoTrabajo === 'revelado' ? max(1, (int) ($body['rodillos'] ?? 1)) : 0;
    $etiquetas = $rodillos;
    $venta = $this->escrituras()->crear([
      'empresa' => $entrega,
      'cliente' => $codigo,
      'razonSocial' => $this->texto($body, 'nombre', $cliente['nombre']),
      'nif' => $this->texto($body, 'nif', $cliente['nif']),
      'telefono' => $this->texto($body, 'telefono', $cliente['telefono']),
      'email' => $this->texto($body, 'email', $cliente['email']),
      'direccionEnvio' => $this->texto($body, 'direccion', $cliente['direccion']),
      'codigoPostalEnvio' => $this->texto($body, 'codigoPostal', $cliente['codigoPostal']),
      'poblacionEnvio' => $this->texto($body, 'poblacion', $cliente['poblacion']),
      'provinciaEnvio' => $this->texto($body, 'provincia', $cliente['provincia']),
      'fpago1' => $this->texto($body, 'formaPago', $cliente['formaPago']),
      'puesto' => $this->texto($body, 'puesto', ''),
      'vendedor' => $this->texto($body, 'vendedor', ''),
      'fecha' => date('Y-m-d'),
      'referencia1' => 'ENC',
      'observaciones' => ($tipoTrabajo === 'revelado' ? 'Revelado' : 'Impresión')
        . ($etiquetas > 0 ? ' (' . $etiquetas . ' etiquetas)' : ''),
      'lineas' => $this->lineasPorDefecto($entrega, $rodillos),
    ]);
    $albaran = (int) ($venta['albaran'] ?? 0);
    $tipoDoc = (string) ($venta['tipo'] ?? 'A');
    if ($albaran <= 0) {
      throw new \RuntimeException('No se pudo crear el albarán del encargo');
    }
    $yaCreado = $this->porAlbaran($entrega, $tipoDoc, $albaran);
    if ($yaCreado !== null) {
      // Un artículo con sobre en las líneas ya lo creó al guardar la venta.
      return $yaCreado;
    }
    $numero = $this->numeroOcupado($albaran) ? $this->proponerNumero()['numero'] : $albaran;
    $this->reservarNumero($numero);
    $this->anotarVentaEncargo($entrega, $tipoDoc, $albaran, $etiquetas, $tipoTrabajo);
    [$trabajo, $sobre] = $this->legado($estado, $misma);
    $st = $this->pdo->prepare(
      'INSERT INTO Encargos (
        Numero, Cliente, AlbaranEmpresa, AlbaranTipo, Albaran, TiendaOrigen, TiendaDestino,
        Modalidad, MismaTienda, Rodillos, EtiquetasPedidas, EtiquetasImpresas,
        EstadoTrabajo, EstadoSobre, Estado, Tipo, FechaAlta, Observaciones
      ) VALUES (
        :numero, :cliente, :empresa, :tipo, :albaran, :origen, :destino,
        :modalidad, :misma, :rodillos, :etiquetas, 0,
        :trabajo, :sobre, :estado, :tipoTrabajo, ' . $this->ahora() . ', :obs
      )'
    );
    $st->execute([
      'numero' => $numero,
      'cliente' => $codigo,
      'empresa' => $entrega,
      'tipo' => $tipoDoc,
      'albaran' => $albaran,
      'origen' => $entrega,
      'destino' => $laboratorio,
      'modalidad' => $modalidad,
      'misma' => $misma ? 1 : 0,
      'rodillos' => $rodillos,
      'etiquetas' => $rodillos,
      'trabajo' => $trabajo,
      'sobre' => $sobre,
      'estado' => $estado,
      'tipoTrabajo' => $tipoTrabajo,
      'obs' => trim((string) ($body['observaciones'] ?? '')),
    ]);
    $encargo = $this->obtener($numero);
    $this->enviarEmailEncargo($encargo);
    return $this->obtener($numero);
  }

  /**
   * Sobres que piden unas líneas de venta (cuerpo de la petición, antes de guardar):
   * por línea con artículo "lleva sobre", cantidad x sobres por unidad, redondeado hacia arriba.
   *
   * @param list<array<string, mixed>> $lineas
   */
  public function sobresEnLineas(array $lineas): int
  {
    $cantidades = [];
    foreach ($lineas as $l) {
      $codigo = trim((string) ($l['articulo'] ?? ''));
      if ($codigo === '') {
        continue;
      }
      $cantidades[$codigo][] = (float) ($l['cantidad'] ?? 0);
    }
    if ($cantidades === []) {
      return 0;
    }
    try {
      $codigos = array_keys($cantidades);
      $marcas = implode(',', array_fill(0, count($codigos), '?'));
      $st = $this->pdo->prepare(
        "SELECT RTRIM(Codigo) AS Codigo, SobresPorUnidad FROM Articulos
         WHERE COALESCE(LlevaSobre, 0) = 1 AND Codigo IN ({$marcas})"
      );
      $st->execute($codigos);
      $total = 0;
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $porUnidad = max(1, (int) ($row['SobresPorUnidad'] ?? 1));
        foreach ($cantidades[trim((string) $row['Codigo'])] ?? [] as $cantidad) {
          $total += $this->sobresDeCantidad($cantidad, $porUnidad);
        }
      }
      return $total;
    } catch (\Throwable) {
      return 0;
    }
  }

  private function sobresDeCantidad(float $cantidad, int $porUnidad): int
  {
    if ($cantidad <= 0) {
      return 0;
    }
    return (int) ceil(round($cantidad * max(1, $porUnidad), 4));
  }

  /** Sin asegurar el esquema: en instalaciones sin encargos la tabla puede no existir. */
  public function tieneEncargo(string $empresa, string $tipo, int $albaran): bool
  {
    try {
      $st = $this->pdo->prepare(
        'SELECT COUNT(*) FROM Encargos WHERE AlbaranEmpresa = :e AND AlbaranTipo = :t AND Albaran = :a'
      );
      $st->execute(['e' => trim($empresa), 't' => trim($tipo), 'a' => $albaran]);
      return (int) $st->fetchColumn() > 0;
    } catch (\Throwable) {
      return false;
    }
  }

  /** Cliente real con ficha. El encargo nunca es de paso. */
  public function exigirClienteEncargo(string $codigo): void
  {
    $codigo = trim($codigo);
    $mensaje = 'Las líneas con sobre exigen un cliente con ficha. Identifique al cliente antes de guardar';
    if ($codigo === '' || strcasecmp($codigo, 'VARIOS') === 0) {
      throw new \InvalidArgumentException($mensaje);
    }
    try {
      $cliente = $this->cliente($codigo);
    } catch (\InvalidArgumentException) {
      throw new \InvalidArgumentException($mensaje);
    }
    if (strcasecmp((string) $cliente['nombre'], 'VARIOS') === 0) {
      throw new \InvalidArgumentException($mensaje);
    }
  }

  /**
   * Líneas del albarán cuyo artículo lleva sobre.
   *
   * @return list<array{nroLin: int, articulo: string, producto: string, sobres: int}>
   */
  public function lineasConSobre(string $empresa, string $tipo, int $albaran): array
  {
    $st = $this->pdo->prepare(
      'SELECT l.NroLin, RTRIM(l.Articulo) AS Articulo, l.Descripcion, a.Descripcion AS ArtDescripcion,
              l.Cantidad, a.SobresPorUnidad
       FROM AlbaranesVentasLin l
       INNER JOIN Articulos a ON RTRIM(a.Codigo) = RTRIM(l.Articulo)
       WHERE l.Empresa = :e AND l.Tipo = :t AND l.Albaran = :a AND COALESCE(a.LlevaSobre, 0) = 1
       ORDER BY l.NroLin'
    );
    $st->execute(['e' => trim($empresa), 't' => trim($tipo), 'a' => $albaran]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $sobres = $this->sobresDeCantidad((float) ($row['Cantidad'] ?? 0), (int) ($row['SobresPorUnidad'] ?? 1));
      if ($sobres < 1) {
        continue;
      }
      $producto = trim((string) ($row['Descripcion'] ?? ''));
      if ($producto === '') {
        $producto = trim((string) ($row['ArtDescripcion'] ?? ''));
      }
      $out[] = [
        'nroLin' => (int) $row['NroLin'],
        'articulo' => trim((string) $row['Articulo']),
        'producto' => $producto,
        'sobres' => $sobres,
      ];
    }
    return $out;
  }

  /**
   * Mantiene el encargo de un albarán según sus líneas con sobre. Va después de guardar la venta.
   * Crea el encargo si hay sobres, ajusta el número de etiquetas y lo quita si ya no queda ninguno
   * (solo los nacidos de la venta y todavía sin entregar).
   *
   * @return array<string, mixed>|null
   */
  public function sincronizarDesdeVenta(string $empresa, string $tipo, int $albaran, string $modalidad = ''): ?array
  {
    $empresa = trim($empresa);
    $tipo = trim($tipo);
    if ($empresa === '' || $tipo === '' || $albaran < 1) {
      return null;
    }
    $this->asegurar();
    $sobres = array_sum(array_column($this->lineasConSobre($empresa, $tipo, $albaran), 'sobres'));

    $st = $this->pdo->prepare(
      'SELECT Numero, Origen, Estado FROM Encargos
       WHERE AlbaranEmpresa = :e AND AlbaranTipo = :t AND Albaran = :a'
    );
    $st->execute(['e' => $empresa, 't' => $tipo, 'a' => $albaran]);
    $actual = $st->fetch(PDO::FETCH_ASSOC);

    if ($sobres < 1) {
      if ($actual === false) {
        return null;
      }
      if ((string) $actual['Origen'] === 'venta' && (string) $actual['Estado'] !== 'entregado') {
        $this->pdo->prepare('DELETE FROM Encargos WHERE Numero = :n')->execute(['n' => (int) $actual['Numero']]);
        return null;
      }
      return $this->obtener((int) $actual['Numero']);
    }

    $stCab = $this->pdo->prepare(
      'SELECT Cliente FROM AlbaranesVentasCab WHERE Empresa = :e AND Tipo = :t AND Albaran = :a'
    );
    $stCab->execute(['e' => $empresa, 't' => $tipo, 'a' => $albaran]);
    $codigo = trim((string) $stCab->fetchColumn());
    $this->exigirClienteEncargo($codigo);
    if (!in_array($modalidad, ['recogida', 'envio'], true)) {
      $modalidad = 'recogida';
    }
    // #region agent log
    @file_put_contents('c:\\descartes-2.0\\.cursor\\debug-0d3608.log', json_encode(['sessionId' => '0d3608', 'runId' => 'post-fix', 'hypothesisId' => 'F', 'location' => 'EncargoService.php:sincronizarDesdeVenta', 'message' => $actual === false ? 'alta encargo' : 'encargo ya existe', 'data' => ['albaran' => $albaran, 'modalidad' => $modalidad, 'sobres' => $sobres, 'existia' => $actual !== false], 'timestamp' => (int) (microtime(true) * 1000)]) . "\n", FILE_APPEND);
    // #endregion

    if ($actual !== false) {
      $numero = (int) $actual['Numero'];
      if ((string) $actual['Estado'] !== 'entregado' && (string) $actual['Origen'] === 'venta') {
        $this->pdo->prepare(
          'UPDATE Encargos SET Cliente = :c, Rodillos = :n, EtiquetasPedidas = :n2 WHERE Numero = :num'
        )->execute(['c' => $codigo, 'n' => $sobres, 'n2' => $sobres, 'num' => $numero]);
      }
      return $this->obtener($numero);
    }

    $circuito = $this->tiendasCircuito();
    $laboratorio = $circuito['torrent']['codigo'] ?? $empresa;
    $misma = $laboratorio === $empresa;
    $estado = 'pendiente_revelar';
    [$trabajo, $sobre] = $this->legado($estado, $misma);
    $numero = $this->numeroOcupado($albaran) ? $this->proponerNumero()['numero'] : $albaran;
    $this->reservarNumero($numero);
    $this->pdo->prepare(
      'INSERT INTO Encargos (
        Numero, Cliente, AlbaranEmpresa, AlbaranTipo, Albaran, TiendaOrigen, TiendaDestino,
        Modalidad, MismaTienda, Rodillos, EtiquetasPedidas, EtiquetasImpresas,
        EstadoTrabajo, EstadoSobre, Estado, Tipo, Origen, FechaAlta, Observaciones
      ) VALUES (
        :numero, :cliente, :empresa, :tipo, :albaran, :origen, :destino,
        :modalidad, :misma, :rodillos, :etiquetas, 0,
        :trabajo, :sobre, :estado, :tipoTrabajo, :origenEncargo, ' . $this->ahora() . ", ''
      )"
    )->execute([
      'numero' => $numero,
      'cliente' => $codigo,
      'empresa' => $empresa,
      'tipo' => $tipo,
      'albaran' => $albaran,
      'origen' => $empresa,
      'destino' => $laboratorio,
      'modalidad' => $modalidad,
      'misma' => $misma ? 1 : 0,
      'rodillos' => $sobres,
      'etiquetas' => $sobres,
      'trabajo' => $trabajo,
      'sobre' => $sobre,
      'estado' => $estado,
      'tipoTrabajo' => 'revelado',
      'origenEncargo' => 'venta',
    ]);
    $encargo = $this->obtener($numero);
    $this->enviarEmailEncargo($encargo);
    return $encargo;
  }

  /** Cambia modalidad (recogida o envío) de un encargo aún abierto. */
  public function cambiarModalidad(int $numero, string $modalidad): array
  {
    $modalidad = strtolower(trim($modalidad));
    if (!in_array($modalidad, ['recogida', 'envio'], true)) {
      throw new \InvalidArgumentException('Indique si el cliente lo recogerá o si se envía por mensajero');
    }
    $encargo = $this->obtener($numero);
    if ((string) $encargo['estado'] === 'entregado') {
      throw new \InvalidArgumentException('El encargo ya está entregado');
    }
    $this->pdo->prepare('UPDATE Encargos SET Modalidad = :m WHERE Numero = :n')
      ->execute(['m' => $modalidad, 'n' => $numero]);
    return $this->obtener($numero);
  }

  public function realizar(int $numero): array
  {
    $encargo = $this->obtener($numero);
    if ((string) $encargo['estado'] !== 'pendiente_realizar') {
      throw new \InvalidArgumentException('El encargo no está pendiente de realizar');
    }
    $siguiente = !empty($encargo['mismaTienda']) ? 'pendiente_recoger' : 'pendiente_devolver';
    $this->fijarEstado($numero, $siguiente, 'FechaListo', !empty($encargo['mismaTienda']));
    return $this->obtener($numero);
  }

  public function expedir(int $numero): array
  {
    $encargo = $this->obtener($numero);
    if (!empty($encargo['mismaTienda'])) {
      throw new \InvalidArgumentException('Este encargo se realiza en la tienda donde se entregó');
    }
    if ((string) $encargo['estado'] !== 'pendiente_enviar') {
      throw new \InvalidArgumentException('Este encargo no está pendiente de enviar');
    }
    $this->fijarEstado($numero, 'pendiente_realizar', 'FechaExpedicion', false);
    return $this->obtener($numero);
  }

  /** @return array{expedidos: int, fecha: string} */
  public function expedirDia(string $origen, string $destino): array
  {
    $this->asegurar();
    $origen = trim($origen);
    $destino = trim($destino);
    $sql = 'UPDATE Encargos
       SET Estado = :estado, EstadoTrabajo = :trabajo, EstadoSobre = :sobre,
           ExpedicionFecha = ' . $this->hoy() . ', FechaExpedicion = ' . $this->ahora() . '
       WHERE Estado = :actual AND MismaTienda = 0';
    $params = [
      'estado' => 'pendiente_realizar',
      'trabajo' => 'abierto',
      'sobre' => 'recibido',
      'actual' => 'pendiente_enviar',
    ];
    if ($origen !== '' && $destino !== '') {
      if ($origen === $destino) {
        throw new \InvalidArgumentException('La expedición del día es entre dos tiendas distintas');
      }
      $sql .= ' AND TiendaOrigen = :o AND TiendaDestino = :d';
      $params['o'] = $origen;
      $params['d'] = $destino;
    }
    $st = $this->pdo->prepare($sql);
    $st->execute($params);
    return ['expedidos' => $st->rowCount(), 'fecha' => date('Y-m-d')];
  }

  public function devolver(int $numero): array
  {
    $encargo = $this->obtener($numero);
    if ((string) $encargo['estado'] !== 'pendiente_devolver') {
      throw new \InvalidArgumentException('Este encargo no está pendiente de volver a la tienda de recogida');
    }
    $this->fijarEstado($numero, 'pendiente_recoger', 'FechaRecepcion', false);
    return $this->obtener($numero);
  }

  public function entregar(int $numero): array
  {
    $encargo = $this->obtener($numero);
    $estado = (string) $encargo['estado'];
    // #region agent log
    @file_put_contents('c:\\descartes-2.0\\.cursor\\debug-0d3608.log', json_encode(['sessionId' => '0d3608', 'runId' => 'post-fix', 'hypothesisId' => 'E', 'location' => 'EncargoService.php:entregar', 'message' => 'entregar', 'data' => ['numero' => $numero, 'estado' => $estado], 'timestamp' => (int) (microtime(true) * 1000)]) . "\n", FILE_APPEND);
    // #endregion
    if ($estado === 'entregado') {
      throw new \InvalidArgumentException('El encargo ya está entregado');
    }
    if (!in_array($estado, ['pendiente_revelar', 'pendiente_entrega', 'pendiente_recoger'], true)) {
      throw new \InvalidArgumentException('El encargo todavía no se puede entregar');
    }
    $this->fijarEstado($numero, 'entregado', 'FechaCierre', !empty($encargo['mismaTienda']));
    return $this->obtener($numero);
  }

  /**
   * El cobro (ticket o factura) NO entrega el encargo: la entrega es una acción explícita
   * cuando el cliente recoge el carrete revelado. Aquí solo se deja constancia.
   */
  public function marcarFacturado(string $empresa, string $tipo, int $albaran): void
  {
    // #region agent log
    $estado = null;
    try {
      $st = $this->pdo->prepare('SELECT Estado FROM Encargos WHERE AlbaranEmpresa = :e AND AlbaranTipo = :t AND Albaran = :a');
      $st->execute(['e' => trim($empresa), 't' => trim($tipo), 'a' => $albaran]);
      $estado = $st->fetchColumn();
    } catch (\Throwable) {
    }
    @file_put_contents('c:\\descartes-2.0\\.cursor\\debug-0d3608.log', json_encode(['sessionId' => '0d3608', 'runId' => 'post-fix', 'hypothesisId' => 'A', 'location' => 'EncargoService.php:marcarFacturado', 'message' => 'cobro sin entregar', 'data' => ['albaran' => $albaran, 'tipo' => $tipo, 'estadoEncargo' => $estado], 'timestamp' => (int) (microtime(true) * 1000)]) . "\n", FILE_APPEND);
    // #endregion
  }

  /**
   * Lectura de los sobres que vuelven del laboratorio. Varias etiquetas, cada encargo una sola vez.
   *
   * @param list<mixed> $codigos
   * @return array{items: list<array<string, mixed>>}
   */
  public function recibirLecturas(array $codigos): array
  {
    $this->asegurar();
    $items = [];
    $vistos = [];
    foreach ($codigos as $raw) {
      $codigo = trim((string) $raw);
      if ($codigo === '') {
        continue;
      }
      try {
        $resuelto = $this->resolverQr($codigo);
        if (($resuelto['tipo'] ?? '') !== 'encargo' || !is_array($resuelto['encargo'] ?? null)) {
          $items[] = ['codigo' => $codigo, 'ok' => false, 'mensaje' => 'Esta lectura no es una etiqueta de encargo'];
          continue;
        }
        $encargo = $resuelto['encargo'];
        $numero = (int) $encargo['numero'];
        $publico = (int) ($encargo['albaran'] ?? $numero);
        $base = [
          'codigo' => $codigo,
          'numero' => $publico,
          'cliente' => (string) ($encargo['cliente'] ?? ''),
          'nombre' => (string) ($encargo['nombre'] ?? ''),
        ];
        if (isset($vistos[$numero])) {
          $items[] = $base + [
            'ok' => true,
            'estado' => (string) $encargo['estado'],
            'mensaje' => 'Esa etiqueta es del encargo ' . $publico . ', que ya se ha leído',
          ];
          continue;
        }
        $vistos[$numero] = true;
        $estado = (string) $encargo['estado'];
        if ($estado === 'pendiente_revelar') {
          $this->fijarEstado($numero, 'pendiente_entrega', 'FechaRecepcion', !empty($encargo['mismaTienda']));
          $items[] = $base + [
            'ok' => true,
            'estado' => 'pendiente_entrega',
            'mensaje' => 'Ya revelado, pendiente de entrega',
          ];
          continue;
        }
        if ($estado === 'pendiente_entrega') {
          $items[] = $base + [
            'ok' => true,
            'estado' => $estado,
            'mensaje' => 'Ya estaba pendiente de entrega',
          ];
          continue;
        }
        $items[] = $base + [
          'ok' => false,
          'estado' => $estado,
          'mensaje' => $estado === 'entregado'
            ? 'Ya está entregado'
            : 'No está pendiente de revelar',
        ];
      } catch (\Throwable $e) {
        $items[] = ['codigo' => $codigo, 'ok' => false, 'mensaje' => $e->getMessage()];
      }
    }
    return ['items' => $items];
  }

  /** @return array<string, mixed> */
  public function etiqueta(int $numero, bool $vuelta): array
  {
    $encargo = $this->obtener($numero);
    if ((string) ($encargo['tipo'] ?? 'revelado') !== 'revelado') {
      throw new \InvalidArgumentException('La impresión no lleva etiquetas de sobre');
    }
    if ($vuelta && !empty($encargo['mismaTienda'])) {
      throw new \InvalidArgumentException('No hay etiqueta de vuelta si no hay viaje');
    }
    $this->pdo->prepare(
      'UPDATE Encargos SET EtiquetasImpresas = EtiquetasPedidas, FechaImpresion = ' . $this->ahora() . ' WHERE Numero = :n'
    )->execute(['n' => $numero]);
    $encargo = $this->obtener($numero);
    $origen = $vuelta ? (string) $encargo['destinoNombre'] : (string) $encargo['origenNombre'];
    $destino = $vuelta ? (string) $encargo['origenNombre'] : (string) $encargo['destinoNombre'];
    $copias = max(1, (int) ($encargo['etiquetasPedidas'] ?? 1));
    $publico = (int) ($encargo['albaran'] ?? $encargo['numero']);
    $etiquetas = $this->listaEtiquetas($encargo, $copias);
    return [
      'albaran' => $publico,
      'empresa' => (string) $encargo['albaranEmpresa'],
      'codigoBarras' => (string) $encargo['codigoBarras'],
      'etiquetas' => $etiquetas,
      'entregado' => (string) $encargo['estado'] === 'entregado',
      'numero' => $encargo['numero'],
      'cliente' => $encargo['cliente'],
      'nombre' => $encargo['nombre'],
      'telefono' => $encargo['telefono'],
      'telefono2' => $encargo['telefono2'] ?? '',
      'email' => $encargo['email'],
      'direccion' => $encargo['direccion'],
      'codigoPostal' => $encargo['codigoPostal'],
      'poblacion' => $encargo['poblacion'],
      'provincia' => $encargo['provincia'],
      'fecha' => $encargo['fechaAlta'],
      'tienda' => (string) $encargo['origenNombre'],
      'tiendaCodigo' => (string) $encargo['tiendaOrigen'],
      'modalidad' => $encargo['modalidad'],
      'tipo' => $encargo['tipo'],
      'copias' => $copias,
      'numeros' => array_fill(0, $copias, $publico),
      'desde' => $origen,
      'hacia' => $destino,
      'vuelta' => $vuelta,
      'transporte' => false,
      'qr' => (string) ($encargo['qr'] ?? ''),
    ];
  }

  /**
   * Una etiqueta por sobre, cada una con el producto de su línea y "n/N" en conjunto.
   * Los encargos antiguos (sin líneas con sobre) sacan los sobres pedidos con la primera línea.
   *
   * @param array<string, mixed> $encargo
   * @return list<array{producto: string, articulo: string, sobre: string}>
   */
  private function listaEtiquetas(array $encargo, int $copias): array
  {
    $lineas = $this->lineasConSobre(
      (string) $encargo['albaranEmpresa'],
      (string) $encargo['albaranTipo'],
      (int) $encargo['albaran']
    );
    $items = [];
    foreach ($lineas as $l) {
      for ($i = 0; $i < $l['sobres']; $i++) {
        $items[] = ['producto' => $l['producto'], 'articulo' => $l['articulo']];
      }
    }
    if ($items === []) {
      $producto = '';
      $articulo = '';
      $st = $this->pdo->prepare(
        'SELECT RTRIM(Articulo) AS Articulo, Descripcion FROM AlbaranesVentasLin
         WHERE Empresa = :e AND Tipo = :t AND Albaran = :a ORDER BY NroLin'
      );
      $st->execute([
        'e' => (string) $encargo['albaranEmpresa'],
        't' => (string) $encargo['albaranTipo'],
        'a' => (int) $encargo['albaran'],
      ]);
      $primera = $st->fetch(PDO::FETCH_ASSOC);
      if ($primera !== false) {
        $producto = trim((string) ($primera['Descripcion'] ?? ''));
        $articulo = trim((string) ($primera['Articulo'] ?? ''));
      }
      for ($i = 0; $i < $copias; $i++) {
        $items[] = ['producto' => $producto, 'articulo' => $articulo];
      }
    }
    $total = count($items);
    foreach ($items as $i => $item) {
      $items[$i]['sobre'] = ($i + 1) . '/' . $total;
    }
    return $items;
  }

  /** @return array<string, mixed> */
  public function etiquetaTransporte(int $numero): array
  {
    $encargo = $this->obtener($numero);
    if ((string) $encargo['modalidad'] !== 'envio') {
      throw new \InvalidArgumentException('Este encargo lo recoge el cliente. No lleva etiqueta de transporte');
    }
    if ((string) $encargo['estado'] !== 'pendiente_entrega') {
      throw new \InvalidArgumentException('La etiqueta de transporte se imprime cuando el encargo está pendiente de entrega');
    }
    return [
      'numero' => $encargo['numero'],
      'nombre' => $encargo['nombre'],
      'telefono' => $encargo['telefono'],
      'direccion' => $encargo['direccion'],
      'codigoPostal' => $encargo['codigoPostal'],
      'poblacion' => $encargo['poblacion'],
      'provincia' => $encargo['provincia'],
      'tienda' => $encargo['origenNombre'],
      'copias' => 1,
      'transporte' => true,
    ];
  }

  public function enviarEmailAlta(string $codigo): void
  {
    $this->asegurar();
    $cliente = $this->cliente($codigo);
    $qr = self::qrCliente($codigo);
    $html = '<div style="font-family:Arial,sans-serif">'
      . '<p>Alta de cliente <strong>' . $this->e((string) $cliente['nombre']) . '</strong>.</p>'
      . '<p>Este código identifica solo al cliente. Todavía no hay encargo ni albarán.</p>'
      . '<p style="font-family:monospace;font-size:16px">' . $this->e($qr) . '</p>'
      . '</div>';
    $this->enviar((string) $cliente['email'], 'Alta de cliente', $html, $codigo, null, 'alta', $qr);
  }

  /** @param array<string, mixed> $encargo */
  private function enviarEmailEncargo(array $encargo): void
  {
    $revelado = (string) ($encargo['tipo'] ?? 'revelado') === 'revelado';
    $publico = (int) ($encargo['albaran'] ?? $encargo['numero']);
    $qr = $revelado ? (string) ($encargo['qr'] ?? '') : '';
    $modalidad = $encargo['modalidad'] === 'envio' ? 'Mensajero' : 'Recogida en tienda';
    $trabajo = $revelado ? 'Revelado' : 'Impresión';
    $html = '<div style="font-family:Arial,sans-serif">'
      . '<p>' . $this->e($trabajo) . ' <strong>' . $publico . '</strong></p>'
      . '<p>Cliente: ' . $this->e((string) $encargo['nombre']) . '<br>'
      . 'Fecha: ' . $this->e((string) $encargo['fechaAlta']) . '<br>'
      . 'Tienda: ' . $this->e((string) $encargo['origenNombre']) . '<br>'
      . $this->e($modalidad) . '</p>'
      . ($revelado
        ? '<p>El mismo código va en cada etiqueta de rodillo.</p><p style="font-family:monospace;font-size:16px">' . $this->e($qr) . '</p>'
        : '<p>La impresión no lleva etiqueta ni código QR.</p>')
      . '</div>';
    $this->enviar(
      (string) $encargo['email'],
      'Encargo ' . $publico,
      $html,
      (string) $encargo['cliente'],
      (int) $encargo['numero'],
      'encargo',
      $qr
    );
  }

  private function enviar(
    string $destinatario,
    string $asunto,
    string $html,
    string $cliente,
    ?int $numero,
    string $tipo,
    string $qr
  ): void {
    $destinatario = trim($destinatario);
    $resultado = 'enviado';
    $detalle = '';
    try {
      if (!filter_var($destinatario, FILTER_VALIDATE_EMAIL)) {
        throw new \RuntimeException('El cliente no tiene un email válido');
      }
      $mail = (new UsuarioCorreoService($this->pdo))->crearMailer();
      $mail->addAddress($destinatario);
      $mail->Subject = $asunto;
      $mail->isHTML(true);
      $png = $qr !== '' ? $this->pngQr($qr) : null;
      if ($png !== null) {
        $mail->addStringEmbeddedImage($png, 'qrencargo', 'qr.png', PHPMailer::ENCODING_BASE64, 'image/png');
        $html .= '<p><img src="cid:qrencargo" alt="QR" /></p>';
      }
      $mail->Body = $html;
      $mail->AltBody = $asunto . "\n" . $qr;
      $mail->send();
    } catch (\Throwable $e) {
      $resultado = 'error';
      $detalle = substr($e->getMessage(), 0, 400);
    }
    $st = $this->pdo->prepare(
      'INSERT INTO EncargoEmails (Cliente, EncargoNumero, Tipo, Destinatario, Fecha, Resultado, Detalle)
       VALUES (:c, :n, :tipo, :d, ' . $this->ahora() . ', :r, :det)'
    );
    $st->execute([
      'c' => $cliente,
      'n' => $numero,
      'tipo' => $tipo,
      'd' => $destinatario !== '' ? $destinatario : '-',
      'r' => $resultado,
      'det' => $detalle,
    ]);
  }

  private function pngQr(string $texto): ?string
  {
    $script = dirname(__DIR__, 3) . '/scripts/encargo-qr.cjs';
    $modulo = dirname(__DIR__, 4) . '/descartes-gestion/node_modules/qrcode';
    if (!is_file($script) || !is_dir($modulo)) {
      return null;
    }
    $cmd = 'node ' . escapeshellarg($script) . ' ' . escapeshellarg($modulo) . ' ' . escapeshellarg($texto);
    $png = shell_exec($cmd);
    if (!is_string($png) || !str_starts_with($png, "\x89PNG")) {
      return null;
    }
    return $png;
  }

  private function numeroOcupado(int $numero): bool
  {
    if ($numero < 1) {
      return true;
    }
    $st = $this->pdo->prepare('SELECT 1 FROM Encargos WHERE Numero = :n');
    $st->execute(['n' => $numero]);
    return (bool) $st->fetchColumn();
  }

  private function reservarNumero(int $numero): void
  {
    if ($numero < 1 || $this->numeroOcupado($numero)) {
      throw new \InvalidArgumentException('Ese número de encargo ya está usado');
    }
    $ultimo = (int) $this->pdo->query('SELECT Ultimo FROM EncargoSecuencia WHERE Id = 1')->fetchColumn();
    $this->pdo->prepare('UPDATE EncargoSecuencia SET Ultimo = :n WHERE Id = 1')->execute([
      'n' => max($ultimo, $numero),
    ]);
  }

  private function anotarVentaEncargo(string $empresa, string $tipo, int $albaran, int $etiquetas, string $tipoTrabajo): void
  {
    $texto = ($tipoTrabajo === 'revelado' ? 'Revelado ' : 'Impresión ') . $albaran;
    if ($etiquetas > 1) {
      $texto .= ' (' . $etiquetas . ' etiquetas)';
    }
    $this->pdo->prepare(
      'UPDATE AlbaranesVentasCab SET Referencia1 = :r, Observaciones = :o
       WHERE Empresa = :e AND Tipo = :t AND Albaran = :a'
    )->execute([
      'r' => 'ENC' . $albaran,
      'o' => $texto,
      'e' => $empresa,
      't' => $tipo,
      'a' => $albaran,
    ]);
  }

  /** @return array<string, mixed> */
  private function localizarEncargo(?string $cliente, int $n): array
  {
    if ($n < 1) {
      throw new \InvalidArgumentException('Encargo no encontrado');
    }
    $cliente = $cliente !== null ? trim($cliente) : '';
    $porNumero = null;
    if ($this->numeroOcupado($n)) {
      $fila = $this->obtener($n);
      if ($cliente === '' || strcasecmp((string) $fila['cliente'], $cliente) === 0) {
        $porNumero = $fila;
      }
    }
    $sql = 'SELECT Numero FROM Encargos WHERE Albaran = :a AND Numero <> :n';
    $params = ['a' => $n, 'n' => $n];
    if ($cliente !== '') {
      $sql .= ' AND Cliente = :c';
      $params['c'] = $cliente;
    }
    $sql .= ' ORDER BY Numero DESC';
    $st = $this->pdo->prepare($sql);
    $st->execute($params);
    $otros = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
      $otros[] = $this->obtener((int) $id);
    }
    if ($porNumero !== null) {
      return $porNumero;
    }
    if (count($otros) === 1) {
      return $otros[0];
    }
    if (count($otros) > 1) {
      $abiertos = array_values(array_filter(
        $otros,
        static fn (array $f): bool => (string) ($f['estado'] ?? '') !== 'entregado'
      ));
      $pool = $abiertos !== [] ? $abiertos : $otros;
      return $pool[0];
    }
    if ($this->numeroOcupado($n)) {
      throw new \InvalidArgumentException('El QR no corresponde a ese cliente');
    }
    throw new \InvalidArgumentException('Encargo no encontrado');
  }

  private function numeroEnCodigo(int $numero, int $albaran, string $cliente): int
  {
    if ($albaran < 1) {
      return $numero;
    }
    if ($numero === $albaran) {
      return $albaran;
    }
    $st = $this->pdo->prepare('SELECT Cliente FROM Encargos WHERE Numero = :n');
    $st->execute(['n' => $albaran]);
    $otro = $st->fetchColumn();
    if ($otro !== false && strcasecmp(trim((string) $otro), trim($cliente)) === 0) {
      return $numero;
    }
    return $albaran;
  }

  /** @return list<array<string, mixed>> */
  private function lineasPorDefecto(string $empresa, int $cantidad): array
  {
    if ($cantidad < 1) {
      return [];
    }
    $articulo = (new TiendaArticuloEncargoService())->codigoArticulo($empresa);
    if ($articulo === '') {
      return [];
    }
    $tarifa = 1;
    $stTarifa = $this->pdo->prepare('SELECT Tarifa FROM Empresas_Ges WHERE RTRIM(Codigo) = :e');
    $stTarifa->execute(['e' => trim($empresa)]);
    $leida = (int) $stTarifa->fetchColumn();
    if ($leida >= 1 && $leida <= 9) {
      $tarifa = $leida;
    }
    $columna = 'PrecioVen' . $tarifa;
    $st = $this->pdo->prepare(
      "SELECT a.Codigo, a.Descripcion, a.{$columna} AS Precio, i.PjeIVA AS PjeIva
       FROM Articulos a
       LEFT JOIN Impuestos i ON RTRIM(a.Impuesto) = RTRIM(i.Codigo)
       WHERE RTRIM(a.Codigo) = :c"
    );
    $st->execute(['c' => $articulo]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      return [];
    }
    $precio = round((float) ($row['Precio'] ?? 0), 4);
    return [[
      'articulo' => trim((string) $row['Codigo']),
      'descripcion' => trim((string) ($row['Descripcion'] ?? '')),
      'cantidad' => $cantidad,
      'precio' => $precio,
      'pjeIva' => (float) ($row['PjeIva'] ?? 21),
      'precioTarifa' => $precio,
    ]];
  }

  private function normalizarEstados(): void
  {
    if (!$this->tieneColumna('Encargos', 'Estado')) {
      return;
    }
    $this->pdo->exec(
      "UPDATE Encargos SET Estado = CASE
        WHEN Estado = 'pendiente_recoger' THEN 'pendiente_entrega'
        ELSE 'pendiente_revelar'
      END
      WHERE Estado IN ('pendiente_enviar', 'pendiente_realizar', 'pendiente_devolver', 'pendiente_recoger')"
    );
  }

  /** @return array{0: string, 1: string} */
  private function legado(string $estado, bool $misma): array
  {
    return match ($estado) {
      'pendiente_revelar' => ['abierto', $misma ? 'directo' : 'nuevo'],
      'pendiente_entrega' => ['preparado', $misma ? 'directo' : 'recibido'],
      'pendiente_enviar' => ['abierto', 'nuevo'],
      'pendiente_realizar' => ['abierto', $misma ? 'directo' : 'recibido'],
      'pendiente_devolver' => ['preparado', 'recibido'],
      'pendiente_recoger' => ['preparado', $misma ? 'directo' : 'recibido'],
      'entregado' => ['finalizado', 'entregado'],
      default => ['abierto', 'nuevo'],
    };
  }

  private function fijarEstado(int $numero, string $estado, string $fechaCol, bool $misma): void
  {
    if (!in_array($fechaCol, ['FechaListo', 'FechaExpedicion', 'FechaRecepcion', 'FechaCierre'], true)) {
      throw new \InvalidArgumentException('Fecha de situación no válida');
    }
    [$trabajo, $sobre] = $this->legado($estado, $misma);
    $expedicion = $fechaCol === 'FechaExpedicion' ? ', ExpedicionFecha = ' . $this->hoy() : '';
    $this->pdo->prepare(
      "UPDATE Encargos SET Estado = :estado, EstadoTrabajo = :trabajo, EstadoSobre = :sobre,
        {$fechaCol} = " . $this->ahora() . $expedicion . ' WHERE Numero = :n'
    )->execute([
      'estado' => $estado,
      'trabajo' => $trabajo,
      'sobre' => $sobre,
      'n' => $numero,
    ]);
  }

  /** @return array<string, mixed>|null */
  private function filaEncargo(int $numero): ?array
  {
    $this->asegurar();
    $st = $this->pdo->prepare(
      'SELECT e.*, c.RazonSocial AS Nombre, c.Telefono1 AS Telefono, c.Telefono2 AS Telefono2, c.Email AS Email,
              c.Direccion AS DireccionCliente, c.CodigoPostal AS CodigoPostal, c.Poblacion AS Poblacion, c.Provincia AS Provincia,
              o.Nombre AS OrigenNombre, d.Nombre AS DestinoNombre
       FROM Encargos e
       LEFT JOIN Clientes c ON RTRIM(c.Codigo) = e.Cliente
       LEFT JOIN Empresas_Ges o ON RTRIM(o.Codigo) = e.TiendaOrigen
       LEFT JOIN Empresas_Ges d ON RTRIM(d.Codigo) = e.TiendaDestino
       WHERE e.Numero = :n'
    );
    $st->execute(['n' => $numero]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? $this->fila($row) : null;
  }

  /** @return array<string, mixed>|null */
  public function porAlbaran(string $empresa, string $tipo, int $albaran): ?array
  {
    $this->asegurar();
    if ($albaran <= 0 || trim($empresa) === '' || trim($tipo) === '') {
      return null;
    }
    $st = $this->pdo->prepare(
      'SELECT e.*, c.RazonSocial AS Nombre, c.Telefono1 AS Telefono, c.Telefono2 AS Telefono2, c.Email AS Email,
              c.Direccion AS DireccionCliente, c.CodigoPostal AS CodigoPostal, c.Poblacion AS Poblacion, c.Provincia AS Provincia,
              o.Nombre AS OrigenNombre, d.Nombre AS DestinoNombre
       FROM Encargos e
       LEFT JOIN Clientes c ON RTRIM(c.Codigo) = e.Cliente
       LEFT JOIN Empresas_Ges o ON RTRIM(o.Codigo) = e.TiendaOrigen
       LEFT JOIN Empresas_Ges d ON RTRIM(d.Codigo) = e.TiendaDestino
       WHERE e.AlbaranEmpresa = :e AND e.AlbaranTipo = :t AND e.Albaran = :a'
    );
    $st->execute(['e' => trim($empresa), 't' => trim($tipo), 'a' => $albaran]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? $this->fila($row) : null;
  }

  /** @param array<string, mixed> $row */
  private function fila(array $row): array
  {
    $numero = (int) $row['Numero'];
    $albaran = (int) $row['Albaran'];
    $cliente = trim((string) $row['Cliente']);
    return [
      'numero' => $numero,
      'cliente' => $cliente,
      'nombre' => trim((string) ($row['Nombre'] ?? '')),
      'telefono' => trim((string) ($row['Telefono'] ?? '')),
      'telefono2' => trim((string) ($row['Telefono2'] ?? '')),
      'email' => trim((string) ($row['Email'] ?? '')),
      'direccion' => trim((string) ($row['DireccionCliente'] ?? '')),
      'codigoPostal' => trim((string) ($row['CodigoPostal'] ?? '')),
      'poblacion' => trim((string) ($row['Poblacion'] ?? '')),
      'provincia' => trim((string) ($row['Provincia'] ?? '')),
      'albaranEmpresa' => trim((string) $row['AlbaranEmpresa']),
      'albaranTipo' => trim((string) $row['AlbaranTipo']),
      'albaran' => $albaran,
      'tiendaOrigen' => trim((string) $row['TiendaOrigen']),
      'tiendaDestino' => trim((string) $row['TiendaDestino']),
      'origenNombre' => trim((string) ($row['OrigenNombre'] ?? $row['TiendaOrigen'])),
      'destinoNombre' => trim((string) ($row['DestinoNombre'] ?? $row['TiendaDestino'])),
      'modalidad' => (string) $row['Modalidad'],
      'mismaTienda' => (int) $row['MismaTienda'] === 1,
      'rodillos' => (int) $row['Rodillos'],
      'etiquetasPedidas' => (int) $row['EtiquetasPedidas'],
      'etiquetasImpresas' => (int) $row['EtiquetasImpresas'],
      'etiquetaDesde' => $albaran,
      'etiquetaHasta' => $albaran,
      'tipo' => (string) ($row['Tipo'] ?? 'revelado'),
      'estado' => (string) ($row['Estado'] ?? ''),
      'estadoTrabajo' => (string) $row['EstadoTrabajo'],
      'estadoSobre' => (string) $row['EstadoSobre'],
      'expedicionFecha' => $row['ExpedicionFecha'] ?? null,
      'fechaAlta' => (string) $row['FechaAlta'],
      'fechaImpresion' => $row['FechaImpresion'] ?? null,
      'fechaExpedicion' => $row['FechaExpedicion'] ?? null,
      'fechaRecepcion' => $row['FechaRecepcion'] ?? null,
      'fechaListo' => $row['FechaListo'] ?? null,
      'fechaCierre' => $row['FechaCierre'] ?? null,
      'qr' => self::qrEncargo($cliente, $this->numeroEnCodigo($numero, $albaran, $cliente)),
      // Tienda + albarán: lo que lleva el código de barras de la etiqueta y busca el listado de ventas.
      'codigoBarras' => trim((string) $row['AlbaranEmpresa']) . $albaran,
      'origen' => (string) ($row['Origen'] ?? 'manual'),
    ];
  }

  /** @return array{codigo: string, nombre: string, nif: string, telefono: string, email: string, direccion: string, codigoPostal: string, poblacion: string, provincia: string, formaPago: string} */
  private function cliente(string $codigo): array
  {
    $st = $this->pdo->prepare(
      'SELECT Codigo, RazonSocial, NIF, Telefono1, Email, Direccion, CodigoPostal, Poblacion, Provincia, FormaPago
       FROM Clientes WHERE RTRIM(Codigo) = :c'
    );
    $st->execute(['c' => $codigo]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
      throw new \InvalidArgumentException('El cliente no existe. Dése de alta antes del encargo');
    }
    return [
      'codigo' => trim((string) $row['Codigo']),
      'nombre' => trim((string) ($row['RazonSocial'] ?? '')),
      'nif' => trim((string) ($row['NIF'] ?? '')),
      'telefono' => trim((string) ($row['Telefono1'] ?? '')),
      'email' => trim((string) ($row['Email'] ?? '')),
      'direccion' => trim((string) ($row['Direccion'] ?? '')),
      'codigoPostal' => trim((string) ($row['CodigoPostal'] ?? '')),
      'poblacion' => trim((string) ($row['Poblacion'] ?? '')),
      'provincia' => trim((string) ($row['Provincia'] ?? '')),
      'formaPago' => trim((string) ($row['FormaPago'] ?? '')),
    ];
  }

  /** @param array<string, mixed> $body */
  private function texto(array $body, string $clave, string $defecto): string
  {
    if (!array_key_exists($clave, $body)) {
      return $defecto;
    }
    return trim((string) $body[$clave]);
  }

  private function tienda(string $codigo): void
  {
    $st = $this->pdo->prepare('SELECT Codigo FROM Empresas_Ges WHERE RTRIM(Codigo) = :c');
    $st->execute(['c' => $codigo]);
    if (!$st->fetchColumn()) {
      throw new \InvalidArgumentException('La tienda ' . $codigo . ' no existe');
    }
  }

  private function escrituras(): VentaEscrituraService
  {
    return new VentaEscrituraService($this->pdo, new VentaConsultaService($this->pdo));
  }

  private function esMysql(): bool
  {
    return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
  }

  private function ahora(): string
  {
    return $this->esMysql() ? 'NOW()' : 'GETDATE()';
  }

  private function hoy(): string
  {
    return $this->esMysql() ? 'CURDATE()' : 'CAST(GETDATE() AS date)';
  }

  private function e(string $valor): string
  {
    return htmlspecialchars($valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

  public static function qrCliente(string $codigo): string
  {
    return 'DCTB-C-' . trim($codigo);
  }

  public static function qrEncargo(string $codigo, int $numero): string
  {
    return 'DCTB-E-' . trim($codigo) . '-' . $numero;
  }
}
