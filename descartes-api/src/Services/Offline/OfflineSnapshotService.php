<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Offline;

use Descartes\Api\Services\Tpv\TpvContextoService;
use Descartes\Api\Services\Tpv\TpvTecladoService;
use PDO;

/**
 * Datos que el puesto guarda en local para vender sin conexión: artículos,
 * clientes, descuentos y lo que el TPV lee al abrir (contexto y teclado).
 *
 * Cada bloque lleva su hash. Si el puesto manda el hash que ya tiene, el
 * bloque vuelve sin datos: así el refresco cada 15 minutos solo baja lo que cambió.
 */
final class OfflineSnapshotService
{
  public const BLOQUES = [
    'empresa',
    'articulos',
    'barras',
    'familias',
    'macrofamilias',
    'subfamilias',
    'catalogos',
    'clientes',
    'tiposDescuento',
    'cache',
  ];

  private PDO $pdo;
  private TpvContextoService $contexto;
  private TpvTecladoService $teclado;

  public function __construct(PDO $pdo, TpvContextoService $contexto, TpvTecladoService $teclado)
  {
    $this->pdo = $pdo;
    $this->contexto = $contexto;
    $this->teclado = $teclado;
  }

  /**
   * @param array<string, string> $hashesPuesto hash que el puesto ya tiene por bloque
   * @return array<string, mixed>
   */
  public function snapshot(string $empresa, string $puesto, array $hashesPuesto = []): array
  {
    $empresa = trim($empresa);
    $puesto = trim($puesto);
    if ($empresa === '' || $puesto === '') {
      throw new \InvalidArgumentException('Empresa y puesto son obligatorios');
    }

    $bloques = [];
    foreach (self::BLOQUES as $nombre) {
      $datos = match ($nombre) {
        'empresa' => $this->empresa($empresa),
        'articulos' => $this->articulos(),
        'barras' => $this->barras(),
        'familias' => $this->familias(),
        'macrofamilias' => $this->macrofamilias(),
        'subfamilias' => $this->subfamilias(),
        'catalogos' => $this->catalogos(),
        'clientes' => $this->clientes(),
        'tiposDescuento' => $this->tiposDescuento(),
        'cache' => $this->cache($empresa, $puesto),
      };
      $hash = md5((string) json_encode($datos, JSON_UNESCAPED_UNICODE));
      if (($hashesPuesto[$nombre] ?? '') === $hash) {
        $bloques[$nombre] = ['hash' => $hash, 'sinCambios' => true];
      } else {
        $bloques[$nombre] = ['hash' => $hash, 'datos' => $datos];
      }
    }

    return [
      'version' => OfflineEstadoService::VERSION_DATOS,
      'generado' => date('Y-m-d H:i:s'),
      'empresa' => $empresa,
      'puesto' => $puesto,
      'claveOffline' => OfflineEstadoService::clavePuesto($empresa, $puesto),
      'bloques' => $bloques,
    ];
  }

  /** @return array<string, mixed> */
  private function empresa(string $empresa): array
  {
    $st = $this->pdo->prepare(
      'SELECT RTRIM(Codigo) AS Codigo, RTRIM(ISNULL(Nombre, \'\')) AS Nombre,
              ISNULL(SW_IVA, 0) AS SwIva, RTRIM(ISNULL(TicketSI_Territorio, \'\')) AS Territorio,
              Almacen
       FROM Empresas_Ges WHERE RTRIM(Codigo) = :e'
    );
    $st->execute(['e' => $empresa]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
      throw new \InvalidArgumentException("No existe la tienda {$empresa}");
    }

    return [
      'codigo' => trim((string) $row['Codigo']),
      'nombre' => trim((string) $row['Nombre']),
      'preciosIvaIncluido' => (int) $row['SwIva'] !== 0,
      // TicketBAI exige firmar cada ticket al emitirlo: sin conexión no se vende.
      'ticketBai' => trim((string) $row['Territorio']) !== '',
      'almacen' => $row['Almacen'] !== null ? (int) $row['Almacen'] : null,
    ];
  }

  /** @return list<array<string, mixed>> */
  private function articulos(): array
  {
    $st = $this->pdo->query(
      'SELECT a.*,
              RTRIM(ISNULL(f.MacroFamilia, \'\')) AS MacroFamiliaJoin,
              i.PjeIVA AS PjeIva
       FROM Articulos a
       LEFT JOIN Impuestos i ON a.Impuesto = i.Codigo
       LEFT JOIN Familias f ON RTRIM(f.Codigo) = RTRIM(a.Familia)
       WHERE a.FechaBaja IS NULL OR LTRIM(RTRIM(CAST(a.FechaBaja AS NVARCHAR(30)))) = \'\'
       ORDER BY a.Codigo'
    );
    $stock = $this->stockPorArticulo();
    $items = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $precios = [];
      for ($i = 1; $i <= 9; $i++) {
        $precios[] = round((float) ($row['PrecioVen' . $i] ?? 0), 4);
      }
      $iva = (float) ($row['PjeIva'] ?? 0);
      $codigo = trim((string) $row['Codigo']);
      $ficha = $this->fichaArticulo($row);
      $ficha['stock'] = $stock[$codigo] ?? [];
      $items[] = [
        $codigo,
        trim((string) $row['Descripcion']),
        trim((string) $row['Alternativo']),
        trim((string) $row['Familia']),
        trim((string) $row['Subfamilia']),
        trim((string) $row['Seccion']),
        trim((string) $row['SubSeccion']),
        trim((string) ($row['MacroFamiliaJoin'] ?? '')),
        trim((string) ($row['UltProveedor'] ?? '')),
        $iva > 0 ? $iva : 21.0,
        $precios,
        trim((string) $row['Impuesto']),
        trim((string) $row['Agrupacion']),
        $ficha,
      ];
    }

    return $items;
  }

  /** @param array<string, mixed> $row */
  private function fichaArticulo(array $row): array
  {
    $config = require dirname(__DIR__, 2) . '/Config/articulos-fields.php';
    $ficha = [];
    foreach ($config['fields'] as $api => $columna) {
      if (!array_key_exists($columna, $row)) {
        continue;
      }
      $valor = $row[$columna];
      if ($api === 'activo') {
        $ficha[$api] = trim((string) $valor) === '';
        continue;
      }
      if (in_array($api, $config['booleanFields'] ?? [], true)) {
        $ficha[$api] = (bool) $valor;
        continue;
      }
      if ($valor instanceof \DateTimeInterface) {
        $ficha[$api] = $valor->format('Y-m-d');
        continue;
      }
      if (is_string($valor) && preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $valor) === 1) {
        $ficha[$api] = substr($valor, 0, 10);
        continue;
      }
      $ficha[$api] = is_string($valor) ? trim($valor) : $valor;
    }
    $ficha['macroFamilia'] = trim((string) ($row['MacroFamiliaJoin'] ?? ''));

    return $ficha;
  }

  /** @return array<string, list<array{almacenCodigo: int, almacenDescripcion: string, cantidad: float}>> */
  private function stockPorArticulo(): array
  {
    $st = $this->pdo->query(
      'SELECT RTRIM(s.[Codigo]) AS Codigo, s.[Almacen] AS almacenCodigo,
              a.[Descripcion] AS almacenDescripcion,
              SUM(
                ISNULL(s.[Entradas], 0) - ISNULL(s.[Salidas], 0) - ISNULL(s.[Ventas], 0)
                + ISNULL(s.[TraspasosEntradas], 0) - ISNULL(s.[TraspasosSalidas], 0)
              ) AS cantidad
       FROM [Stock] s
       LEFT JOIN [Almacenes] a ON a.[Codigo] = s.[Almacen]
       GROUP BY s.[Codigo], s.[Almacen], a.[Descripcion]
       HAVING ABS(SUM(
         ISNULL(s.[Entradas], 0) - ISNULL(s.[Salidas], 0) - ISNULL(s.[Ventas], 0)
         + ISNULL(s.[TraspasosEntradas], 0) - ISNULL(s.[TraspasosSalidas], 0)
       )) > 0.0001'
    );
    $map = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $codigo = trim((string) $row['Codigo']);
      $map[$codigo][] = [
        'almacenCodigo' => (int) $row['almacenCodigo'],
        'almacenDescripcion' => trim((string) ($row['almacenDescripcion'] ?? '')),
        'cantidad' => round((float) $row['cantidad'], 4),
      ];
    }

    return $map;
  }

  /** @return list<array{0: string, 1: string, 2: float}> */
  private function barras(): array
  {
    $st = $this->pdo->query(
      'SELECT LTRIM(RTRIM(STR(Ean, 18, 0))) AS Ean, RTRIM(Codigo) AS Codigo, ISNULL(Unidades, 0) AS Unidades
       FROM ArtBarras WHERE Ean IS NOT NULL'
    );
    $items = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $ean = ltrim(trim((string) $row['Ean']), '0');
      $codigo = trim((string) $row['Codigo']);
      if ($ean === '' || $codigo === '') {
        continue;
      }
      $unidades = (float) $row['Unidades'];
      $items[] = [$ean, $codigo, $unidades > 0 ? $unidades : 1.0];
    }

    return $items;
  }

  /** @return list<array{0: string, 1: string, 2: string}> */
  private function familias(): array
  {
    $st = $this->pdo->query(
      'SELECT RTRIM(Codigo) AS Codigo, RTRIM(ISNULL(Descripcion, \'\')) AS Descripcion,
              RTRIM(ISNULL(MacroFamilia, \'\')) AS MacroFamilia
       FROM Familias'
    );
    $items = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $items[] = [trim((string) $row['Codigo']), trim((string) $row['Descripcion']), trim((string) $row['MacroFamilia'])];
    }

    return $items;
  }

  /** @return list<array{0: string, 1: string}> */
  private function macrofamilias(): array
  {
    $st = $this->pdo->query(
      'SELECT RTRIM(Codigo) AS Codigo, RTRIM(ISNULL(Descripcion, \'\')) AS Descripcion FROM MacroFamilias'
    );
    $items = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $items[] = [trim((string) $row['Codigo']), trim((string) $row['Descripcion'])];
    }

    return $items;
  }

  /** @return list<array{0: string, 1: string, 2: string}> */
  private function subfamilias(): array
  {
    $st = $this->pdo->query(
      'SELECT RTRIM(Familia) AS Familia, RTRIM(Subfamilia) AS Subfamilia,
              RTRIM(ISNULL([Descripción], \'\')) AS Descripcion
       FROM Subfamilias'
    );
    $items = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $items[] = [trim((string) $row['Familia']), trim((string) $row['Subfamilia']), trim((string) $row['Descripcion'])];
    }

    return $items;
  }

  /**
   * Nombres que la ficha de artículo muestra al lado del código.
   *
   * @return array{impuestos: list<array{0: string, 1: string}>, agrupaciones: list<array{0: string, 1: string}>, secciones: list<array{0: string, 1: string}>, subsecciones: list<array{0: string, 1: string}>, proveedores: list<array{0: string, 1: string}>}
   */
  private function catalogos(): array
  {
    return [
      'impuestos' => $this->pares('Impuestos'),
      'agrupaciones' => $this->pares('Agrupaciones'),
      'secciones' => $this->pares('Secciones'),
      'subsecciones' => $this->pares('SubSecciones'),
      'proveedores' => $this->pares('Proveedores', 'RazonSocial'),
    ];
  }

  /** @return list<array{0: string, 1: string}> */
  private function pares(string $tabla, string $nombre = 'Descripcion'): array
  {
    $st = $this->pdo->query(
      "SELECT RTRIM(Codigo) AS Codigo, RTRIM(ISNULL([$nombre], '')) AS Nombre FROM [$tabla]"
    );
    $items = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $items[] = [trim((string) $row['Codigo']), trim((string) $row['Nombre'])];
    }

    return $items;
  }

  /**
   * Solo lo que la caja necesita: sin cuentas bancarias ni notas.
   *
   * @return list<list<mixed>>
   */
  private function clientes(): array
  {
    $st = $this->pdo->query(
      "SELECT RTRIM(Codigo) AS Codigo,
              RTRIM(ISNULL(RazonSocial, '')) AS RazonSocial,
              RTRIM(ISNULL(RazonSocial2, '')) AS RazonSocial2,
              RTRIM(ISNULL(NIF, '')) AS NIF,
              RTRIM(ISNULL(Direccion, '')) AS Direccion,
              RTRIM(ISNULL(Poblacion, '')) AS Poblacion,
              RTRIM(ISNULL(CodigoPostal, '')) AS CodigoPostal,
              RTRIM(ISNULL(Provincia, '')) AS Provincia,
              RTRIM(ISNULL(Pais, '')) AS Pais,
              RTRIM(ISNULL(Telefono1, '')) AS Telefono1,
              RTRIM(ISNULL(NULLIF(EmailFacturacion, ''), ISNULL(Email, ''))) AS Email,
              RTRIM(ISNULL(FormaPago, '')) AS FormaPago,
              ISNULL(Tarifa, 0) AS Tarifa,
              RTRIM(ISNULL(TipoDescuento, '')) AS TipoDescuento,
              ISNULL(Dto1, 0) AS Dto1
       FROM Clientes
       WHERE ISNULL(Baja, 0) = 0"
    );
    $items = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $items[] = [
        trim((string) $row['Codigo']),
        trim((string) $row['RazonSocial']),
        trim((string) $row['RazonSocial2']),
        trim((string) $row['NIF']),
        trim((string) $row['Direccion']),
        trim((string) $row['Poblacion']),
        trim((string) $row['CodigoPostal']),
        trim((string) $row['Provincia']),
        trim((string) $row['Pais']),
        trim((string) $row['Telefono1']),
        trim((string) $row['Email']),
        trim((string) $row['FormaPago']),
        (int) $row['Tarifa'],
        trim((string) $row['TipoDescuento']),
        (float) $row['Dto1'],
      ];
    }

    return $items;
  }

  /**
   * Ofertas vigentes hoy o que empiezan en los próximos 7 días.
   *
   * @return list<list<mixed>>
   */
  private function tiposDescuento(): array
  {
    $hoy = date('Y-m-d');
    $hasta = date('Y-m-d', strtotime('+7 days'));
    $st = $this->pdo->prepare(
      'SELECT RTRIM(TipoDescuento) AS TipoDescuento, RTRIM(Tipo) AS Tipo, RTRIM(ISNULL(Codigo, \'\')) AS Codigo,
              CONVERT(varchar(10), FechaInicio, 120) AS FechaInicio,
              CONVERT(varchar(10), FechaFin, 120) AS FechaFin,
              Descuento,
              RTRIM(ISNULL(BloquearOfeEmpresa1, \'\')) AS B1, RTRIM(ISNULL(BloquearOfeEmpresa2, \'\')) AS B2,
              RTRIM(ISNULL(BloquearOfeEmpresa3, \'\')) AS B3, RTRIM(ISNULL(BloquearOfeEmpresa4, \'\')) AS B4,
              RTRIM(ISNULL(BloquearOfeEmpresa5, \'\')) AS B5, RTRIM(ISNULL(BloquearOfeEmpresa6, \'\')) AS B6,
              RTRIM(ISNULL(BloquearOfeEmpresa7, \'\')) AS B7, RTRIM(ISNULL(BloquearOfeEmpresa8, \'\')) AS B8,
              RTRIM(ISNULL(BloquearOfeEmpresa9, \'\')) AS B9, RTRIM(ISNULL(BloquearOfeEmpresa10, \'\')) AS B10
       FROM TiposDescuentos
       WHERE CONVERT(varchar(10), FechaInicio, 120) <= :hasta
         AND (FechaFin IS NULL OR CONVERT(varchar(10), FechaFin, 120) >= :hoy)'
    );
    $st->execute(['hasta' => $hasta, 'hoy' => $hoy]);
    $items = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $bloqueos = [];
      for ($i = 1; $i <= 10; $i++) {
        $b = trim((string) ($row['B' . $i] ?? ''));
        if ($b !== '') {
          $bloqueos[] = $b;
        }
      }
      $items[] = [
        trim((string) $row['TipoDescuento']),
        trim((string) $row['Tipo']),
        trim((string) $row['Codigo']),
        trim((string) $row['FechaInicio']),
        $row['FechaFin'] !== null ? trim((string) $row['FechaFin']) : null,
        (float) $row['Descuento'],
        $bloqueos,
      ];
    }

    return $items;
  }

  /**
   * Respuestas GET que el TPV pide al abrir la caja, con la misma URL que usa
   * la UI: así sin conexión se leen igual que si hubiera servidor.
   *
   * @return list<array{path: string, params: array<string, string>, datos: mixed}>
   */
  private function cache(string $empresa, string $puesto): array
  {
    $items = [];
    try {
      $contexto = $this->contexto->resolver($empresa, $puesto);
    } catch (\Throwable $e) {
      return $items;
    }
    $items[] = [
      'path' => '/api/tpv/contexto',
      'params' => ['empresa' => $empresa, 'puesto' => $puesto],
      'datos' => $contexto,
    ];

    $general = (string) ($contexto['tecladoGeneral'] ?? '');
    if ($general === '') {
      return $items;
    }
    $st = $this->pdo->prepare(
      'SELECT DISTINCT RTRIM(H_NIVEL) AS Nivel FROM DefPlus WHERE RTRIM(H_GENERAL) = :g'
    );
    $st->execute(['g' => $general]);
    $niveles = ['000' => true];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $nivel = trim((string) $row['Nivel']);
      if ($nivel !== '') {
        $niveles[$nivel] = true;
      }
    }
    foreach (array_keys($niveles) as $nivel) {
      try {
        $datos = $this->teclado->obtenerNivel($general, (string) $nivel);
      } catch (\Throwable $e) {
        continue;
      }
      if ($datos === null) {
        continue;
      }
      $items[] = [
        'path' => '/api/tpv/teclados/' . rawurlencode($general) . '/niveles/' . rawurlencode((string) $nivel),
        'params' => [],
        'datos' => $datos,
      ];
    }

    return $items;
  }
}
