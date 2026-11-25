<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Importacion;

use Descartes\Api\Config\EntityConfig;
use Descartes\Api\Services\MantenimientoService;
use PDO;

/**
 * Importacion masiva (alta de registros nuevos) reutilizando MantenimientoService::create().
 * No sobrescribe: si el codigo ya existe, la fila se omite. En simulacro no escribe nada.
 */
final class ImportacionService
{
  /** Maximo de filas por peticion (el cliente envia lotes de unas 200). */
  public const MAX_FILAS_LOTE = 500;

  private PDO $pdo;
  private MantenimientoService $mantenimiento;

  /** @var array<string, array<string, true>> */
  private array $cacheReferencias = [];

  public function __construct(PDO $pdo, MantenimientoService $mantenimiento)
  {
    $this->pdo = $pdo;
    $this->mantenimiento = $mantenimiento;
  }

  /**
   * Hojas y columnas para generar la plantilla y leer el archivo.
   *
   * @return array<string, mixed>
   */
  public function columnas(): array
  {
    $cfg = ImportacionFilaValidator::config();
    $hojas = [];
    foreach ($cfg['orden'] as $entidad) {
      $hoja = $cfg['hojas'][$entidad];
      $hojas[] = array_merge(['entidad' => $entidad], $hoja);
    }

    return ['hojas' => $hojas, 'instrucciones' => $cfg['instrucciones']];
  }

  /**
   * @param list<array{fila?: int, datos?: array<string, mixed>}> $filas
   * @param array<string, mixed> $contexto  codigos del propio archivo (solo se usan en simulacro)
   * @return array{
   *   entidad: string, hoja: string, simulacro: bool, total: int, creadas: int, omitidas: int,
   *   errores: list<array{hoja: string, fila: int, campo: string, mensaje: string}>,
   *   avisos: list<array{hoja: string, fila: int, campo: string, mensaje: string}>
   * }
   */
  public function importar(
    string $entidad,
    array $filas,
    bool $simulacro,
    array $contexto = [],
    string $empresaCodigo = ''
  ): array {
    $hoja = ImportacionFilaValidator::hoja($entidad);
    if ($hoja === null) {
      throw new \InvalidArgumentException('Esta entidad no se puede importar');
    }
    if (count($filas) > self::MAX_FILAS_LOTE) {
      throw new \InvalidArgumentException('Demasiadas filas en una peticion (maximo ' . self::MAX_FILAS_LOTE . ')');
    }

    $nombreHoja = (string) $hoja['hoja'];
    $claveCodigo = (string) $hoja['claveCodigo'];
    $limites = $this->limitesBd($entidad, $hoja);
    $codigoNumerico = ($limites[$claveCodigo]['tipo'] ?? '') === 'int';
    $numeracion = !empty($hoja['codigoAutomatico']) && $this->numeracionAutomatica($entidad, $empresaCodigo);

    if ($simulacro) {
      $this->mantenimiento->setFamiliasSimuladas($this->familiasDelContexto($contexto));
    }

    $res = [
      'entidad' => $entidad,
      'hoja' => $nombreHoja,
      'simulacro' => $simulacro,
      'total' => count($filas),
      'creadas' => 0,
      'omitidas' => 0,
      'errores' => [],
      'avisos' => [],
    ];

    /** @var array<string, int> $codigosVistos */
    $codigosVistos = [];
    /** @var array<string, array<string, int>> $unicosVistos */
    $unicosVistos = [];

    foreach (array_values($filas) as $i => $item) {
      $fila = (int) ($item['fila'] ?? ($i + 2));
      $datosCrudos = is_array($item['datos'] ?? null) ? $item['datos'] : [];

      $validada = ImportacionFilaValidator::validar($hoja, $datosCrudos, $limites);
      $errFila = $validada['errores'];
      $datos = $validada['datos'];

      $codigo = trim((string) ($datos[$claveCodigo] ?? ''));
      $codigoRellenado = ImportacionFilaValidator::textoCelda($datosCrudos[$claveCodigo] ?? null) !== '';
      // Si la hoja exige el codigo, el validador ya avisa de la celda vacia.
      if ($codigo === '' && !$codigoRellenado && !$numeracion && !empty($hoja['codigoAutomatico'])) {
        $errFila[] = [
          'campo' => $claveCodigo,
          'mensaje' => 'El codigo es obligatorio (la tienda no tiene numeracion automatica activa)',
        ];
      }

      if ($errFila === []) {
        $errFila = $this->comprobarReferencias($hoja, $datos, $simulacro, $contexto);
      }

      if ($errFila === [] && $codigo !== '') {
        $clave = ImportacionFilaValidator::normalizarCodigo($codigo);
        if (isset($codigosVistos[$clave])) {
          $errFila[] = [
            'campo' => $claveCodigo,
            'mensaje' => "Codigo repetido en el archivo (ya aparece en la fila {$codigosVistos[$clave]})",
          ];
        }
      }
      if ($errFila === []) {
        foreach ($hoja['unicos'] ?? [] as $campoUnico) {
          $valor = trim((string) ($datos[$campoUnico] ?? ''));
          if ($valor === '') {
            continue;
          }
          $k = mb_strtolower($valor, 'UTF-8');
          if (isset($unicosVistos[$campoUnico][$k])) {
            $errFila[] = [
              'campo' => (string) $campoUnico,
              'mensaje' => "{$this->cabeceraDe($hoja, (string) $campoUnico)} repetido en el archivo (fila {$unicosVistos[$campoUnico][$k]})",
            ];
          }
        }
      }

      if ($errFila !== []) {
        foreach ($errFila as $e) {
          $res['errores'][] = $this->errorFila($nombreHoja, $fila, $e['campo'], $e['mensaje']);
        }
        continue;
      }

      if ($codigo !== '') {
        $codigosVistos[ImportacionFilaValidator::normalizarCodigo($codigo)] = $fila;
        if ($this->existeCodigo($entidad, $codigo, $codigoNumerico)) {
          $res['omitidas']++;
          $res['avisos'][] = $this->errorFila($nombreHoja, $fila, $claveCodigo, 'Ya existe, se omite (no se modifica)');
          continue;
        }
      }
      foreach ($hoja['unicos'] ?? [] as $campoUnico) {
        $valor = trim((string) ($datos[$campoUnico] ?? ''));
        if ($valor !== '') {
          $unicosVistos[$campoUnico][mb_strtolower($valor, 'UTF-8')] = $fila;
        }
      }

      try {
        $this->aplicarEmpresa($entidad, $datos, $empresaCodigo);
        if ($simulacro) {
          $paraValidar = $datos;
          if ($codigo === '') {
            $paraValidar[$claveCodigo] = '~auto';
          }
          $this->mantenimiento->validarAltaImportacion($entidad, $paraValidar);
        } else {
          $this->mantenimiento->create($entidad, $datos, ['importacion' => true]);
        }
        $res['creadas']++;
      } catch (\InvalidArgumentException $e) {
        $res['errores'][] = $this->errorFila($nombreHoja, $fila, '', $e->getMessage());
      } catch (\PDOException $e) {
        $mensaje = 'No se pudo grabar la fila';
        if (($_ENV['APP_ENV'] ?? '') === 'local') {
          $mensaje .= ': ' . $e->getMessage();
        }
        $res['errores'][] = $this->errorFila($nombreHoja, $fila, '', $mensaje);
      }
    }

    if ($simulacro) {
      $this->mantenimiento->setFamiliasSimuladas([]);
    }

    return $res;
  }

  /**
   * @param array<string, mixed> $datos
   */
  private function aplicarEmpresa(string $entidad, array &$datos, string $empresaCodigo): void
  {
    $empresaCodigo = trim($empresaCodigo);
    if ($empresaCodigo === '') {
      return;
    }
    if ($entidad === 'clientes') {
      $datos['tiendaCodigo'] = $empresaCodigo;
    } elseif ($entidad === 'proveedores' || $entidad === 'articulos') {
      $datos['empresaCodigo'] = $empresaCodigo;
    }
  }

  /**
   * @param array<string, mixed> $hoja
   * @param array<string, mixed> $datos
   * @param array<string, mixed> $contexto
   * @return list<array{campo: string, mensaje: string}>
   */
  private function comprobarReferencias(array $hoja, array $datos, bool $simulacro, array $contexto): array
  {
    $errores = [];
    foreach ($hoja['columnas'] as $col) {
      $ref = $col['ref'] ?? null;
      $clave = (string) $col['clave'];
      if ($ref === null || !isset($datos[$clave])) {
        continue;
      }
      $valor = trim((string) $datos[$clave]);
      if ($valor === '') {
        continue;
      }
      $norm = ImportacionFilaValidator::normalizarCodigo($valor);
      if (isset($this->referencias((string) $ref)[$norm])) {
        continue;
      }
      if ($simulacro && isset($this->referenciasContexto((string) $ref, $contexto)[$norm])) {
        continue;
      }
      $errores[] = [
        'campo' => $clave,
        'mensaje' => $this->mensajeReferencia((string) $col['cabecera'], $valor, (string) $ref),
      ];
    }

    return $errores;
  }

  private function mensajeReferencia(string $cabecera, string $valor, string $ref): string
  {
    $donde = [
      'macrofamilias' => 'Mantenimiento > Macrofamilias o la hoja MacroFamilias',
      'familias' => 'Mantenimiento > Familias o la hoja Familias',
      'subfamilias' => 'Mantenimiento > Subfamilias o la hoja SubFamilias',
      'agrupaciones' => 'Mantenimiento > Agrupaciones',
      'impuestos' => 'Mantenimiento > Impuestos o la hoja Impuestos',
      'formas-pago' => 'Mantenimiento > Formas de pago o la hoja Formas de pago',
      'proveedores' => 'Mantenimiento > Proveedores o la hoja Proveedores',
    ][$ref] ?? 'Mantenimiento';

    return "{$cabecera} \"{$valor}\" no existe ({$donde})";
  }

  /**
   * @return array<string, true>
   */
  private function referencias(string $ref): array
  {
    if (isset($this->cacheReferencias[$ref])) {
      return $this->cacheReferencias[$ref];
    }
    $config = EntityConfig::get($ref);
    $set = [];
    if ($config !== null) {
      $stmt = $this->pdo->query(sprintf('SELECT [%s] FROM [%s]', $config['primaryKey'], $config['table']));
      if ($stmt !== false) {
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $codigo) {
          $set[ImportacionFilaValidator::normalizarCodigo((string) $codigo)] = true;
        }
      }
    }
    $this->cacheReferencias[$ref] = $set;
    return $set;
  }

  /**
   * Codigos que el propio archivo crearia (impuestos, formas de pago, familias, subfamilias y proveedores).
   *
   * @param array<string, mixed> $contexto
   * @return array<string, true>
   */
  private function referenciasContexto(string $ref, array $contexto): array
  {
    $set = [];
    if ($ref === 'familias') {
      foreach (array_keys($this->familiasDelContexto($contexto)) as $codigo) {
        $set[ImportacionFilaValidator::normalizarCodigo((string) $codigo)] = true;
      }
    } elseif (in_array($ref, ['macrofamilias', 'subfamilias', 'proveedores', 'impuestos', 'formas-pago'], true)) {
      foreach ((array) ($contexto[$ref] ?? []) as $codigo) {
        $codigo = trim((string) $codigo);
        if ($codigo !== '') {
          $set[ImportacionFilaValidator::normalizarCodigo($codigo)] = true;
        }
      }
    }
    return $set;
  }

  /**
   * @param array<string, mixed> $contexto
   * @return array<string, string> codigo => macrofamilia
   */
  private function familiasDelContexto(array $contexto): array
  {
    $out = [];
    foreach ((array) ($contexto['familias'] ?? []) as $f) {
      if (!is_array($f)) {
        continue;
      }
      $codigo = trim((string) ($f['codigo'] ?? ''));
      $macro = trim((string) ($f['macroFamiliaCodigo'] ?? ''));
      if ($codigo === '' || $macro === '') {
        continue;
      }
      $out[$codigo] = $macro;
      if (ctype_digit($codigo)) {
        $out[ltrim($codigo, '0') === '' ? '0' : ltrim($codigo, '0')] = $macro;
      }
    }
    return $out;
  }

  private function numeracionAutomatica(string $entidad, string $empresaCodigo): bool
  {
    try {
      $info = match ($entidad) {
        'clientes' => $this->mantenimiento->siguienteCodigoCliente($empresaCodigo),
        'proveedores' => $this->mantenimiento->siguienteCodigoProveedor($empresaCodigo),
        'articulos' => $this->mantenimiento->siguienteCodigoArticulo($empresaCodigo),
        default => ['automatico' => false],
      };
      return !empty($info['automatico']);
    } catch (\Throwable $e) {
      return false;
    }
  }

  private function existeCodigo(string $entidad, string $codigo, bool $codigoNumerico): bool
  {
    $config = EntityConfig::get($entidad);
    if ($config === null) {
      return false;
    }
    $variantes = [trim($codigo)];
    if ($codigoNumerico && ctype_digit($variantes[0])) {
      $sin = ltrim($variantes[0], '0');
      $variantes[] = $sin === '' ? '0' : $sin;
    }
    $variantes = array_values(array_unique($variantes));
    $placeholders = [];
    $params = [];
    foreach ($variantes as $i => $v) {
      $placeholders[] = ':c' . $i;
      $params['c' . $i] = $v;
    }
    $stmt = $this->pdo->prepare(sprintf(
      'SELECT 1 FROM [%s] WHERE RTRIM([%s]) IN (%s)',
      $config['table'],
      $config['primaryKey'],
      implode(', ', $placeholders)
    ));
    $stmt->execute($params);
    return (bool) $stmt->fetchColumn();
  }

  /**
   * Limites reales de las columnas (INFORMATION_SCHEMA) para no aceptar valores que la base rechazaria.
   *
   * @param array<string, mixed> $hoja
   * @return array<string, array{tipo?: string, min?: int, max?: int}>
   */
  private function limitesBd(string $entidad, array $hoja): array
  {
    $config = EntityConfig::get($entidad);
    if ($config === null) {
      return [];
    }
    try {
      $esMysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
      $sql = $esMysql
        ? 'SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        : 'SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_NAME = ?';
      $stmt = $this->pdo->prepare($sql);
      $stmt->execute([$config['table']]);
      $porColumna = [];
      foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row = array_change_key_case($row, CASE_LOWER);
        $porColumna[strtolower((string) $row['column_name'])] = $row;
      }
    } catch (\Throwable $e) {
      return [];
    }

    $enteros = [
      'tinyint' => [$esMysql ? -128 : 0, $esMysql ? 127 : 255],
      'smallint' => [-32768, 32767],
      'mediumint' => [-8388608, 8388607],
      'int' => [-2147483648, 2147483647],
    ];

    $limites = [];
    foreach ($hoja['columnas'] as $col) {
      $clave = (string) $col['clave'];
      $sqlCol = $config['fields'][$clave] ?? null;
      $info = $sqlCol !== null ? ($porColumna[strtolower((string) $sqlCol)] ?? null) : null;
      if ($info === null) {
        continue;
      }
      $tipoBd = strtolower((string) ($info['data_type'] ?? ''));
      if (isset($enteros[$tipoBd])) {
        $limites[$clave] = ['tipo' => 'int', 'min' => $enteros[$tipoBd][0], 'max' => $enteros[$tipoBd][1]];
        continue;
      }
      $largo = $info['character_maximum_length'] ?? null;
      if ($largo !== null && (int) $largo > 0 && (int) $largo < 60000) {
        $limites[$clave] = ['max' => (int) $largo];
      }
    }

    return $limites;
  }

  /** @param array<string, mixed> $hoja */
  private function cabeceraDe(array $hoja, string $clave): string
  {
    foreach ($hoja['columnas'] as $col) {
      if ($col['clave'] === $clave) {
        return (string) $col['cabecera'];
      }
    }
    return $clave;
  }

  /** @return array{hoja: string, fila: int, campo: string, mensaje: string} */
  private function errorFila(string $hoja, int $fila, string $campo, string $mensaje): array
  {
    return ['hoja' => $hoja, 'fila' => $fila, 'campo' => $campo, 'mensaje' => $mensaje];
  }
}
