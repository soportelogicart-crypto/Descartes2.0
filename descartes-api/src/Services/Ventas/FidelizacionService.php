<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Ventas;

use PDO;

/**
 * Acumulación de fidelización al cerrar documentos de venta (007-fidelizacion-clientes US3).
 * Resuelve tienda → tipo de cálculo → motor registrado (EUROS / PUNTOS / NINGUNO).
 */
final class FidelizacionService
{
  private const MOTORES_REGISTRADOS = ['NINGUNO', 'EUROS', 'PUNTOS', 'VALE_SEMESTRAL'];
  /** Árbol de exclusión: Macrofamilia → Familia → Subfamilia → Artículo. */
  private const NIVELES_EXCLUSION = ['M', 'F', 'S', 'A'];
  private const MAX_EXCLUSIONES = 2000;
  private const MAX_ARTICULOS_NODO = 500;

  private PDO $pdo;

  /** @var bool|null */
  private static $schemaReady = null;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  /**
   * @param array<string, mixed> $cabecera Ficha de venta con lineas (obtenerFicha)
   * @param array{sesion: int, facturaTipo: string, factura: int} $estadoPrevio Estado antes del cierre
   * @return array{avisos: list<string>, aplicado: bool, puntos: int, acumulados: float}
   */
  public function procesarAlCierre(
    string $empresa,
    array $cabecera,
    string $opcionFinalizar,
    bool $esTicketAFactura,
    array $estadoPrevio
  ): array {
    $avisos = [];
    $nada = ['avisos' => $avisos, 'aplicado' => false, 'puntos' => 0, 'acumulados' => 0.0];

    if (!$this->schemaListo()) {
      return $nada;
    }

    if (!$this->debeProcesar($opcionFinalizar, $esTicketAFactura, $estadoPrevio)) {
      return $nada;
    }

    $clienteCodigo = trim((string) ($cabecera['cliente'] ?? ''));
    if ($clienteCodigo === '' || strtoupper($clienteCodigo) === 'ZZZZZZZZZ') {
      return $nada;
    }

    $politica = $this->cargarPoliticaTienda($empresa);
    if ($politica === null) {
      return $nada;
    }

    if (!empty($politica['bloqueo'])) {
      return $nada;
    }

    $tipoCodigo = trim((string) ($politica['tipoCalculo'] ?? ''));
    if ($tipoCodigo === '') {
      return $nada;
    }

    $tipo = $this->cargarTipoCalculo($tipoCodigo);
    if ($tipo === null) {
      $avisos[] = "Fidelización: el tipo de cálculo «{$tipoCodigo}» no existe o está de baja; la venta se ha cerrado sin acumular.";
      return ['avisos' => $avisos, 'aplicado' => false, 'puntos' => 0, 'acumulados' => 0.0];
    }

    $motor = strtoupper(trim((string) ($tipo['motor'] ?? 'NINGUNO')));
    if ($motor === 'NINGUNO') {
      return ['avisos' => $avisos, 'aplicado' => false, 'puntos' => 0, 'acumulados' => 0.0];
    }
    // El programa semestral calcula los puntos desde las ventas cerradas;
    // no usa los acumulados incrementales de Clientes.
    if ($motor === 'VALE_SEMESTRAL') {
      return ['avisos' => $avisos, 'aplicado' => true, 'puntos' => 0, 'acumulados' => 0.0];
    }

    if (!in_array($motor, self::MOTORES_REGISTRADOS, true)) {
      $avisos[] = "Fidelización: motor «{$motor}» no registrado en la API; la venta se ha cerrado sin acumular.";
      return ['avisos' => $avisos, 'aplicado' => false, 'puntos' => 0, 'acumulados' => 0.0];
    }

    $socio = $this->cargarSocio($clienteCodigo);
    if ($socio === null) {
      return ['avisos' => $avisos, 'aplicado' => false, 'puntos' => 0, 'acumulados' => 0.0];
    }

    if (!$this->socioElegible($motor, $socio)) {
      return ['avisos' => $avisos, 'aplicado' => false, 'puntos' => 0, 'acumulados' => 0.0];
    }

    $configPuntos = $motor === 'PUNTOS' ? $this->configPuntos($tipo) : null;
    $base = $this->calcularBaseElegible($cabecera, $configPuntos);
    $minimo = $configPuntos !== null && $configPuntos['configurado']
      ? $configPuntos['importeMinimo']
      : (float) ($politica['minimo'] ?? 0);
    if ($minimo > 0 && abs($base) < $minimo) {
      return ['avisos' => $avisos, 'aplicado' => false, 'puntos' => 0, 'acumulados' => 0.0];
    }

    $factor = (float) ($tipo['factor'] ?? 1);
    if ($motor === 'EUROS') {
      $pje = (float) ($socio['pjeFidelizacion'] ?? 0);
      $delta = round($base * $pje / 100, 2);
      if (abs($delta) < 0.0001) {
        return ['avisos' => $avisos, 'aplicado' => false, 'puntos' => 0, 'acumulados' => 0.0];
      }
      $this->actualizarAcumuladoEuros($clienteCodigo, $delta);
      return ['avisos' => $avisos, 'aplicado' => true, 'puntos' => 0, 'acumulados' => 0.0];
    }

    // PUNTOS: el porcentaje del programa (20 → 100 € = 20 puntos). Sin configurar, 1 punto por euro.
    $porcentaje = $configPuntos !== null && $configPuntos['configurado']
      ? $configPuntos['porcentaje']
      : $factor * 100;
    $deltaPuntos = (int) floor($base * $porcentaje / 100);
    if ($deltaPuntos === 0) {
      return ['avisos' => $avisos, 'aplicado' => false, 'puntos' => 0, 'acumulados' => 0.0];
    }
    $this->actualizarAcumuladoPuntos($clienteCodigo, $deltaPuntos);
    $saldo = $this->pdo->prepare(
      'SELECT ISNULL([AcumuladoPuntos], 0) FROM [Clientes] WHERE RTRIM([Codigo]) = :codigo'
    );
    $saldo->bindValue(':codigo', $clienteCodigo);
    $saldo->execute();

    return [
      'avisos' => $avisos,
      'aplicado' => true,
      'puntos' => $deltaPuntos,
      'acumulados' => (float) $saldo->fetchColumn(),
    ];
  }

  /**
   * @param array{sesion: int, facturaTipo: string, factura: int} $estadoPrevio
   */
  private function debeProcesar(string $opcionFinalizar, bool $esTicketAFactura, array $estadoPrevio): bool
  {
    $opcion = strtoupper(substr(trim($opcionFinalizar), 0, 1));
    if ($opcion === 'P') {
      return false;
    }
    if ($esTicketAFactura) {
      return false;
    }
    if ($opcion === 'F') {
      $sesion = (int) ($estadoPrevio['sesion'] ?? 0);
      $factura = (int) ($estadoPrevio['factura'] ?? 0);
      $ft = strtoupper(trim((string) ($estadoPrevio['facturaTipo'] ?? '')));
      // Albarán ya cerrado (acumuló al cerrar): la factura no duplica.
      if ($sesion > 0 && $factura <= 0 && ($ft === '' || $ft === 'Z')) {
        return false;
      }
    }
    return in_array($opcion, ['T', 'A', 'F'], true);
  }

  private function schemaListo(): bool
  {
    if (self::$schemaReady !== null) {
      return self::$schemaReady;
    }
    try {
      $col = $this->pdo->query("SELECT COL_LENGTH('dbo.Empresas_Ges', 'TipoCalculoFidelizacion')")->fetchColumn();
      if ($col === false || $col === null) {
        self::$schemaReady = false;
        return false;
      }
      $obj = $this->pdo->query("SELECT OBJECT_ID('dbo.TiposCalculoFidelizacion', 'U')")->fetchColumn();
      self::$schemaReady = $obj !== false && $obj !== null;
    } catch (\Throwable $e) {
      self::$schemaReady = false;
    }
    return self::$schemaReady;
  }

  /**
   * @return array{bloqueo: bool, minimo: float, tipoCalculo: string}|null
   */
  private function cargarPoliticaTienda(string $empresa): ?array
  {
    $stmt = $this->pdo->prepare(
      'SELECT TOP 1
          ISNULL([BloqueoFidelizacion], 0) AS BloqueoFidelizacion,
          ISNULL([MinimoFidelizacion], 0) AS MinimoFidelizacion,
          RTRIM(ISNULL([TipoCalculoFidelizacion], \'\')) AS TipoCalculoFidelizacion
       FROM [Empresas_Ges]
       WHERE RTRIM([Codigo]) = :empresa'
    );
    $stmt->execute(['empresa' => trim($empresa)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      return null;
    }
    return [
      'bloqueo' => !empty($row['BloqueoFidelizacion']),
      'minimo' => (float) ($row['MinimoFidelizacion'] ?? 0),
      'tipoCalculo' => trim((string) ($row['TipoCalculoFidelizacion'] ?? '')),
    ];
  }

  /**
   * @return array{motor: string, factor: float, configuracion: array<string, mixed>}|null
   */
  private function cargarTipoCalculo(string $codigo): ?array
  {
    $stmt = $this->pdo->prepare(
      'SELECT TOP 1 RTRIM([Motor]) AS Motor, [Factor], [Configuracion]
       FROM [TiposCalculoFidelizacion]
       WHERE RTRIM([Codigo]) = :codigo AND ISNULL([Baja], 0) = 0'
    );
    $stmt->execute(['codigo' => $codigo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      return null;
    }
    $config = json_decode((string) ($row['Configuracion'] ?? ''), true);

    return [
      'motor' => trim((string) ($row['Motor'] ?? 'NINGUNO')),
      'factor' => (float) ($row['Factor'] ?? 1),
      'configuracion' => is_array($config) ? $config : [],
    ];
  }

  /**
   * Regla del modelo Puntos. Sin JSON guardado, el cierre sigue con el factor antiguo.
   *
   * @param array{motor: string, factor: float, configuracion: array<string, mixed>} $tipo
   * @return array{configurado: bool, porcentaje: float, importeMinimo: float, exclusiones: array<string, true>, multiplo: int, valorPunto: float}
   */
  private function configPuntos(array $tipo): array
  {
    $cfg = $tipo['configuracion'];
    $configurado = array_key_exists('porcentaje', $cfg);

    return [
      'configurado' => $configurado,
      'porcentaje' => (float) ($cfg['porcentaje'] ?? 0),
      'importeMinimo' => (float) ($cfg['importeMinimo'] ?? 0),
      'exclusiones' => $this->clavesExclusion($cfg['exclusiones'] ?? []),
      'multiplo' => max(1, (int) ($cfg['multiplo'] ?? 1)),
      'valorPunto' => (float) ($cfg['valorPunto'] ?? 0),
    ];
  }

  /**
   * @param mixed $lista
   * @return array<string, true> Claves «M:codigo», «F:codigo», «S:codigo», «A:codigo».
   */
  private function clavesExclusion(mixed $lista): array
  {
    $claves = [];
    foreach ($this->normalizarExclusiones($lista) as $item) {
      $claves[$item['tipo'] . ':' . strtoupper($item['codigo'])] = true;
    }
    return $claves;
  }

  /**
   * @param mixed $lista
   * @return list<array{tipo: string, codigo: string, descripcion: string}>
   */
  private function normalizarExclusiones(mixed $lista): array
  {
    if (!is_array($lista)) {
      return [];
    }
    $vistos = [];
    $salida = [];
    foreach ($lista as $item) {
      if (!is_array($item)) {
        continue;
      }
      $tipo = strtoupper(trim((string) ($item['tipo'] ?? '')));
      $codigo = trim((string) ($item['codigo'] ?? ''));
      if (!in_array($tipo, self::NIVELES_EXCLUSION, true)) {
        continue;
      }
      // Solo «Sin macrofamilia» puede tener código vacío.
      if ($codigo === '' && $tipo !== 'M') {
        continue;
      }
      $clave = $tipo . ':' . strtoupper($codigo);
      if (isset($vistos[$clave])) {
        continue;
      }
      $vistos[$clave] = true;
      $salida[] = [
        'tipo' => $tipo,
        'codigo' => substr($codigo, 0, 18),
        'descripcion' => mb_substr(trim((string) ($item['descripcion'] ?? '')), 0, 60),
      ];
    }
    return $salida;
  }

  /**
   * @param array<string, true> $exclusiones
   * @param array{codigo: string, familia: string, subfamilia: string, macrofamilia: string}|null $clase
   */
  private function articuloExcluido(array $exclusiones, ?array $clase, string $articulo): bool
  {
    if (isset($exclusiones['A:' . strtoupper($articulo)])) {
      return true;
    }
    if ($clase === null) {
      return false;
    }
    return isset($exclusiones['M:' . strtoupper($clase['macrofamilia'])])
      || ($clase['familia'] !== '' && isset($exclusiones['F:' . strtoupper($clase['familia'])]))
      || ($clase['subfamilia'] !== '' && isset($exclusiones['S:' . strtoupper($clase['subfamilia'])]));
  }

  /**
   * @return array{pjeFidelizacion: float, tarjetaFidelizacion: string}|null
   */
  private function cargarSocio(string $clienteCodigo): ?array
  {
    $stmt = $this->pdo->prepare(
      'SELECT TOP 1
          ISNULL([PjeFidelizacion], 0) AS PjeFidelizacion,
          RTRIM(ISNULL([TarjetaFidelizacion], \'\')) AS TarjetaFidelizacion
       FROM [Clientes]
       WHERE RTRIM([Codigo]) = :codigo'
    );
    $stmt->execute(['codigo' => $clienteCodigo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      return null;
    }
    return [
      'pjeFidelizacion' => (float) ($row['PjeFidelizacion'] ?? 0),
      'tarjetaFidelizacion' => trim((string) ($row['TarjetaFidelizacion'] ?? '')),
    ];
  }

  /**
   * @param array{pjeFidelizacion: float, tarjetaFidelizacion: string} $socio
   */
  private function socioElegible(string $motor, array $socio): bool
  {
    $tarjeta = trim((string) ($socio['tarjetaFidelizacion'] ?? ''));
    if ($tarjeta === '') {
      return false;
    }
    if ($motor === 'PUNTOS') {
      return true;
    }
    $pje = (float) ($socio['pjeFidelizacion'] ?? 0);
    return $pje > 0;
  }

  /**
   * @param array<string, mixed> $cabecera
   * @param array{configurado: bool, exclusiones: array<string, true>}|null $ambito
   */
  private function calcularBaseElegible(array $cabecera, ?array $ambito = null): float
  {
    $lineas = $cabecera['lineas'] ?? [];
    if (!is_array($lineas) || $lineas === []) {
      return 0.0;
    }

    $articulos = [];
    foreach ($lineas as $lin) {
      if (!is_array($lin)) {
        continue;
      }
      $art = trim((string) ($lin['articulo'] ?? ''));
      if ($art === '' || strtoupper($art) === 'NO') {
        continue;
      }
      $articulos[$art] = true;
    }

    $bloqueados = $this->articulosBloqueados(array_keys($articulos));
    $clases = [];
    $exclusiones = $ambito !== null && !empty($ambito['configurado'])
      ? ($ambito['exclusiones'] ?? [])
      : [];
    $filtrarAmbito = $exclusiones !== [];
    if ($filtrarAmbito) {
      $clases = $this->clasificacionArticulos(array_keys($articulos));
    }

    $base = 0.0;
    foreach ($lineas as $lin) {
      if (!is_array($lin)) {
        continue;
      }
      $art = trim((string) ($lin['articulo'] ?? ''));
      if ($art === '' || strtoupper($art) === 'NO') {
        continue;
      }
      if (!empty($bloqueados[$art])) {
        continue;
      }
      if ($filtrarAmbito && $this->articuloExcluido($exclusiones, $clases[$art] ?? $clases[strtoupper($art)] ?? null, $art)) {
        continue;
      }
      if (isset($lin['importe'])) {
        $base += (float) $lin['importe'];
        continue;
      }
      $cant = (float) ($lin['cantidad'] ?? 0);
      $precio = (float) ($lin['precio'] ?? 0);
      $pjeDto = (float) ($lin['pjeDto'] ?? 0);
      $base += round($cant * $precio * (1 - $pjeDto / 100), 2);
    }

    // El canje de esta venta no reduce los puntos que genera. Solo cuenta el importe de las líneas.
    return round(max(0.0, $base), 2);
  }

  /**
   * @param list<string> $codigos
   * @return array<string, array{codigo: string, familia: string, subfamilia: string, seccion: string, subseccion: string, macrofamilia: string, proveedor: string}>
   */
  private function clasificacionArticulos(array $codigos): array
  {
    if ($codigos === []) {
      return [];
    }
    $params = [];
    $marcas = [];
    foreach (array_values($codigos) as $i => $codigo) {
      $nombre = 'cls' . $i;
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
    foreach ($params as $nombre => $valor) {
      $stmt->bindValue(':' . $nombre, $valor);
    }
    $stmt->execute();
    $clases = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $codigo = trim((string) $row['Codigo']);
      $clase = [
        'codigo' => $codigo,
        'familia' => trim((string) $row['Familia']),
        'subfamilia' => trim((string) $row['Subfamilia']),
        'seccion' => trim((string) $row['Seccion']),
        'subseccion' => trim((string) $row['SubSeccion']),
        'macrofamilia' => trim((string) $row['MacroFamilia']),
        'proveedor' => trim((string) $row['Proveedor']),
      ];
      $clases[$codigo] = $clase;
      $clases[strtoupper($codigo)] = $clase;
    }

    return $clases;
  }

  /**
   * @param list<string> $codigos
   * @return array<string, true>
   */
  private function articulosBloqueados(array $codigos): array
  {
    if ($codigos === []) {
      return [];
    }
    $bloqueados = [];
    $chunks = array_chunk($codigos, 100);
    foreach ($chunks as $chunk) {
      $placeholders = [];
      $params = [];
      foreach ($chunk as $i => $codigo) {
        $key = 'a' . $i;
        $placeholders[] = ':' . $key;
        $params[$key] = $codigo;
      }
      $sql = 'SELECT RTRIM([Codigo]) AS Codigo
              FROM [Articulos]
              WHERE RTRIM([Codigo]) IN (' . implode(', ', $placeholders) . ')
                AND ISNULL([BloqueoFidelizacion], 0) <> 0';
      $stmt = $this->pdo->prepare($sql);
      $stmt->execute($params);
      while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $cod = trim((string) ($row['Codigo'] ?? ''));
        if ($cod !== '') {
          $bloqueados[$cod] = true;
        }
      }
    }
    return $bloqueados;
  }

  private function actualizarAcumuladoEuros(string $clienteCodigo, float $delta): void
  {
    $stmt = $this->pdo->prepare(
      'UPDATE [Clientes]
       SET [AcumuladoFidelizacion] = ISNULL([AcumuladoFidelizacion], 0) + :delta
       WHERE RTRIM([Codigo]) = :codigo'
    );
    $stmt->execute(['delta' => $delta, 'codigo' => $clienteCodigo]);
  }

  private function actualizarAcumuladoPuntos(string $clienteCodigo, int $delta): void
  {
    $stmt = $this->pdo->prepare(
      'UPDATE [Clientes]
       SET [AcumuladoPuntos] = ISNULL([AcumuladoPuntos], 0) + :delta
       WHERE RTRIM([Codigo]) = :codigo'
    );
    $stmt->execute(['delta' => $delta, 'codigo' => $clienteCodigo]);
  }

  /**
   * @param array<string, mixed> $body
   * @return array<string, mixed>
   */
  public function guardarConfiguracionPuntos(string $codigo, array $body): array
  {
    $codigo = trim($codigo);
    if ($codigo === '') {
      throw new \InvalidArgumentException('El modelo de puntos es obligatorio');
    }
    $stmt = $this->pdo->prepare(
      'SELECT RTRIM([Motor]) FROM [TiposCalculoFidelizacion]
       WHERE RTRIM([Codigo]) = :codigo AND ISNULL([Baja], 0) = 0'
    );
    $stmt->bindValue(':codigo', $codigo);
    $stmt->execute();
    $motor = strtoupper(trim((string) $stmt->fetchColumn()));
    if ($motor !== 'PUNTOS') {
      throw new \InvalidArgumentException('El modelo seleccionado no es de puntos');
    }

    $porcentaje = $this->numeroConfig($body['porcentaje'] ?? null, 'El porcentaje');
    if ($porcentaje <= 0 || $porcentaje > 100) {
      throw new \InvalidArgumentException('El porcentaje debe ser mayor que 0 y hasta 100');
    }
    $importeMinimo = $this->numeroConfig($body['importeMinimo'] ?? 0, 'El importe mínimo');
    if ($importeMinimo < 0) {
      throw new \InvalidArgumentException('El importe mínimo no puede ser negativo');
    }
    $exclusionesBody = $body['exclusiones'] ?? [];
    if (!is_array($exclusionesBody)) {
      throw new \InvalidArgumentException('Las exclusiones no son válidas');
    }
    $exclusiones = $this->normalizarExclusiones($exclusionesBody);
    if (count($exclusiones) > self::MAX_EXCLUSIONES) {
      throw new \InvalidArgumentException('Se admiten hasta ' . self::MAX_EXCLUSIONES . ' exclusiones');
    }
    $multiplo = $body['multiplo'] ?? 1;
    if (is_string($multiplo)) {
      $multiplo = trim($multiplo);
    }
    if (!is_numeric($multiplo) || (int) $multiplo != (float) $multiplo || (int) $multiplo < 1) {
      throw new \InvalidArgumentException('El múltiplo debe ser un entero mayor que cero');
    }
    $valorPunto = $this->numeroConfig($body['valorPunto'] ?? null, 'El valor del punto');
    if ($valorPunto <= 0) {
      throw new \InvalidArgumentException('El valor del punto debe ser mayor que cero');
    }

    $config = [
      'porcentaje' => round($porcentaje, 4),
      'importeMinimo' => round($importeMinimo, 2),
      'exclusiones' => $exclusiones,
      'multiplo' => (int) $multiplo,
      'valorPunto' => round($valorPunto, 4),
    ];
    $json = json_encode($config, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
      throw new \RuntimeException('No se pudo guardar la configuración de puntos');
    }
    $upd = $this->pdo->prepare(
      'UPDATE [TiposCalculoFidelizacion]
       SET [Configuracion] = :configuracion, [Factor] = :factor
       WHERE RTRIM([Codigo]) = :codigo'
    );
    $upd->bindValue(':configuracion', $json);
    $upd->bindValue(':factor', round($porcentaje / 100, 6));
    $upd->bindValue(':codigo', $codigo);
    $upd->execute();

    return $config;
  }

  /**
   * Hijos de un nodo del árbol de exclusión. Sin nivel: macrofamilias.
   *
   * @return list<array{tipo: string, codigo: string, descripcion: string, hijos: bool}>
   */
  public function arbolExclusion(string $nivel, string $codigo): array
  {
    $nivel = strtoupper(trim($nivel));
    $codigo = trim($codigo);

    if ($nivel === '') {
      $nodos = $this->nodos(
        'SELECT RTRIM(CAST([Codigo] AS nvarchar(18))) AS Codigo, RTRIM(ISNULL([Descripcion], \'\')) AS Descripcion
         FROM [MacroFamilias] ORDER BY [Codigo]',
        [],
        'M',
        true
      );
      $sinMacro = (int) $this->pdo->query(
        'SELECT COUNT(*) FROM [Familias] f
         WHERE RTRIM(ISNULL(CAST(f.[MacroFamilia] AS nvarchar(18)), \'\')) = \'\'
            OR NOT EXISTS (
              SELECT 1 FROM [MacroFamilias] m
              WHERE RTRIM(CAST(m.[Codigo] AS nvarchar(18))) = RTRIM(CAST(f.[MacroFamilia] AS nvarchar(18)))
            )'
      )->fetchColumn();
      if ($sinMacro > 0) {
        $nodos[] = ['tipo' => 'M', 'codigo' => '', 'descripcion' => '(Sin macrofamilia)', 'hijos' => true];
      }
      return $nodos;
    }

    if ($nivel === 'M') {
      $filtro = $codigo === ''
        ? 'RTRIM(ISNULL(CAST(f.[MacroFamilia] AS nvarchar(18)), \'\')) = \'\'
           OR NOT EXISTS (
             SELECT 1 FROM [MacroFamilias] m
             WHERE RTRIM(CAST(m.[Codigo] AS nvarchar(18))) = RTRIM(CAST(f.[MacroFamilia] AS nvarchar(18)))
           )'
        : 'RTRIM(CAST(f.[MacroFamilia] AS nvarchar(18))) = :codigo';
      return $this->nodos(
        'SELECT RTRIM(CAST(f.[Codigo] AS nvarchar(18))) AS Codigo, RTRIM(ISNULL(f.[Descripcion], \'\')) AS Descripcion
         FROM [Familias] f WHERE ' . $filtro . ' ORDER BY f.[Codigo]',
        $codigo === '' ? [] : ['codigo' => $codigo],
        'F',
        true
      );
    }

    if ($nivel === 'F') {
      return $this->nodos(
        'SELECT RTRIM(CAST([Subfamilia] AS nvarchar(18))) AS Codigo, RTRIM(ISNULL([Descripción], \'\')) AS Descripcion
         FROM [Subfamilias]
         WHERE RTRIM(CAST([Familia] AS nvarchar(18))) = :codigo
         ORDER BY [Subfamilia]',
        ['codigo' => $codigo],
        'S',
        true
      );
    }

    if ($nivel === 'S') {
      return $this->nodos(
        'SELECT TOP ' . self::MAX_ARTICULOS_NODO . ' RTRIM([Codigo]) AS Codigo, RTRIM(ISNULL([Descripcion], \'\')) AS Descripcion
         FROM [Articulos]
         WHERE RTRIM(CAST([Subfamilia] AS nvarchar(18))) = :codigo
         ORDER BY [Codigo]',
        ['codigo' => $codigo],
        'A',
        false
      );
    }

    throw new \InvalidArgumentException('Nivel del árbol no válido');
  }

  /**
   * @param array<string, string> $params
   * @return list<array{tipo: string, codigo: string, descripcion: string, hijos: bool}>
   */
  private function nodos(string $sql, array $params, string $tipo, bool $hijos): array
  {
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);
    $nodos = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $nodos[] = [
        'tipo' => $tipo,
        'codigo' => trim((string) ($row['Codigo'] ?? '')),
        'descripcion' => trim((string) ($row['Descripcion'] ?? '')),
        'hijos' => $hijos,
      ];
    }
    return $nodos;
  }

  /**
   * Puntos ya acumulados que se pueden gastar en esta venta. Los de esta compra no entran.
   *
   * @return array{aplica: bool, puntos: int, puntosUsables: int, euros: float, multiplo: int, valorPunto: float}
   */
  public function canjeDisponible(string $empresa, string $cliente, float $importe): array
  {
    $vacio = [
      'aplica' => false,
      'puntos' => 0,
      'puntosUsables' => 0,
      'euros' => 0.0,
      'multiplo' => 1,
      'valorPunto' => 0.0,
    ];
    $cliente = trim($cliente);
    if ($cliente === '' || strcasecmp($cliente, 'ZZZZZZZZZ') === 0 || $importe <= 0) {
      return $vacio;
    }
    $politica = $this->cargarPoliticaTienda($empresa);
    if ($politica === null || !empty($politica['bloqueo']) || trim((string) $politica['tipoCalculo']) === '') {
      return $vacio;
    }
    $tipo = $this->cargarTipoCalculo((string) $politica['tipoCalculo']);
    if ($tipo === null || strtoupper($tipo['motor']) !== 'PUNTOS') {
      return $vacio;
    }
    $config = $this->configPuntos($tipo);
    if (!$config['configurado'] || $config['valorPunto'] <= 0 || $config['multiplo'] < 1) {
      return $vacio;
    }
    $stmt = $this->pdo->prepare(
      'SELECT ISNULL([AcumuladoPuntos], 0) FROM [Clientes] WHERE RTRIM([Codigo]) = :cliente'
    );
    $stmt->bindValue(':cliente', $cliente);
    $stmt->execute();
    $puntos = (int) floor((float) $stmt->fetchColumn());
    if ($puntos < $config['multiplo']) {
      return $vacio;
    }
    $bloques = intdiv($puntos, $config['multiplo']);
    $usables = $bloques * $config['multiplo'];
    $euros = round($usables * $config['valorPunto'], 2);
    while ($euros > $importe + 0.001 && $bloques > 0) {
      $bloques--;
      $usables = $bloques * $config['multiplo'];
      $euros = round($usables * $config['valorPunto'], 2);
    }
    if ($bloques < 1 || $euros < 0.01) {
      return $vacio;
    }

    return [
      'aplica' => true,
      'puntos' => $puntos,
      'puntosUsables' => $usables,
      'euros' => $euros,
      'multiplo' => $config['multiplo'],
      'valorPunto' => $config['valorPunto'],
    ];
  }

  public function descontarPuntos(string $cliente, int $puntos): void
  {
    if ($puntos > 0) {
      $this->actualizarAcumuladoPuntos(trim($cliente), -$puntos);
    }
  }

  private function numeroConfig(mixed $value, string $campo): float
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
}
