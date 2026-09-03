<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Instalacion;

use Descartes\Api\Config\Database;
use Descartes\Api\Config\InstalacionConfig;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class InstalacionService
{
  private MigrationService $migrations;
  private InstalacionBootstrapService $bootstrap;
  private SchemaRepairService $schemaRepair;

  public function __construct(
    MigrationService $migrations,
    InstalacionBootstrapService $bootstrap,
    SchemaRepairService $schemaRepair
  ) {
    $this->migrations = $migrations;
    $this->bootstrap = $bootstrap;
    $this->schemaRepair = $schemaRepair;
  }

  /**
   * @return array<string, mixed>
   */
  public function obtenerEstado(): array
  {
    $configurado = InstalacionConfig::isConfigured();
    $config = Database::resolveConfig();
    $origen = $configurado ? 'instalacion' : 'env';

    $migraciones = $this->migrations->estado($config);
    $adminListo = false;
    $esquemaOk = false;
    $diagnostico = null;
    if ($migraciones['conexionOk']) {
      try {
        $pdo = Database::createPdo($config);
        $diagnostico = $this->diagnosticoEsquema($pdo, $config);
        $esquemaOk = $this->schemaRepair->extensionesFaltantes($pdo) === [];
        $adminListo = $this->bootstrap->existeAdministrador($pdo);
      } catch (\Throwable $e) {
        $adminListo = false;
        $esquemaOk = false;
      }
    }

    $primeraVez = !$configurado;
    $requiereAccion = !$configurado
      || !$migraciones['conexionOk']
      || !$esquemaOk
      || $migraciones['pendientes'] !== []
      || !$adminListo;

    return [
      'configurado' => $configurado,
      'primeraVez' => $primeraVez,
      'origen' => $origen,
      'tipo' => $config['tipo'] ?? 'local',
      'server' => $config['server'],
      'database' => $config['database'],
      'user' => $config['user'] ?? '',
      'conexionOk' => $migraciones['conexionOk'],
      'errorConexion' => $migraciones['errorConexion'],
      'migraciones' => [
        'aplicadas' => $migraciones['aplicadas'],
        'pendientes' => $migraciones['pendientes'],
        'total' => $migraciones['total'],
      ],
      'adminListo' => $adminListo,
      'esquemaOk' => $esquemaOk,
      'diagnostico' => $diagnostico,
      'requiereAccion' => $requiereAccion,
      'acceso' => [
        'usuario' => InstalacionBootstrapService::USUARIO_ADMIN,
        'password' => InstalacionBootstrapService::PASSWORD_ADMIN,
      ],
    ];
  }

  /**
   * @param array<string, mixed> $body
   * @return array<string, mixed>
   */
  public function probarConexion(array $body): array
  {
    $config = $this->normalizarConfig($body);
    $estado = $this->migrations->estado($config);
    if (!$estado['conexionOk']) {
      return [
        'ok' => false,
        'mensaje' => $estado['errorConexion'] ?? 'No se pudo conectar',
      ];
    }

    InstalacionConfig::save($config);

    $pendientes = count($estado['pendientes']);
    $mensaje = 'Conexion correcta y configuracion guardada.';
    if ($pendientes > 0) {
      $mensaje .= " Hay {$pendientes} actualizacion(es) de estructura pendientes: pulse «Conectar y preparar programa» para aplicarlas.";
    } else {
      $mensaje .= ' Pulse «Conectar y preparar programa» para ejecutar las actualizaciones de tablas.';
    }

    $diagnostico = null;
    try {
      $pdo = Database::createPdo($config);
      $diagnostico = $this->diagnosticoEsquema($pdo, $config);
      if ($diagnostico['tieneRol'] && $diagnostico['tieneBaja']) {
        $mensaje .= ' Usuarios.Rol y Usuarios.Baja ya existen en esta BD.';
      } else {
        $mensaje .= ' Faltan columnas Rol/Baja: se crearan al preparar el programa.';
      }
    } catch (\Throwable $e) {
      // ignorar
    }

    return [
      'ok' => true,
      'mensaje' => $mensaje,
      'guardado' => true,
      'diagnostico' => $diagnostico,
      'migraciones' => [
        'pendientes' => $estado['pendientes'],
        'aplicadas' => $estado['aplicadas'],
        'total' => $estado['total'],
      ],
    ];
  }

  /**
   * @param array<string, mixed> $body
   * @return array<string, mixed>
   */
  public function configurarYAplicar(array $body): array
  {
    $config = $this->normalizarConfig($body);
    $prueba = $this->migrations->estado($config);
    if (!$prueba['conexionOk']) {
      throw new InvalidArgumentException(
        $prueba['errorConexion'] ?? 'No se pudo conectar a la base de datos'
      );
    }

    InstalacionConfig::save($config);
    $resultado = $this->migrations->aplicarTodasIdempotentes($config);
    if (!$resultado['ok']) {
      throw new RuntimeException(
        'Error al aplicar migraciones: ' . implode('; ', $resultado['errores'])
      );
    }

    $pdo = Database::createPdo($config);
    $reparacion = $this->schemaRepair->asegurarExtensionesBase($pdo);
    if (!$reparacion['ok']) {
      throw new RuntimeException(
        'Error al actualizar tablas: ' . implode('; ', $reparacion['errores'])
      );
    }

    $admin = $this->bootstrap->asegurarUsuarioAdministrador($pdo);

    $estado = $this->obtenerEstado();
    $estado['diagnostico'] = $this->diagnosticoEsquema($pdo, $config);
    $estado['mensaje'] = $this->mensajeExito($resultado['aplicadas'], $admin, $reparacion, $estado['diagnostico']);
    $estado['ultimaEjecucion'] = [
      'aplicadas' => $resultado['aplicadas'],
      'errores' => $resultado['errores'],
      'reparacionEsquema' => $reparacion,
    ];
    $estado['admin'] = $admin;
    return $estado;
  }

  /** @return array<string, mixed> */
  public function aplicarMigracionesActivas(): array
  {
    $config = Database::resolveConfig();
    $resultado = $this->migrations->aplicarTodasIdempotentes($config);
    if (!$resultado['ok']) {
      throw new RuntimeException(
        'Error al aplicar migraciones: ' . implode('; ', $resultado['errores'])
      );
    }
    $pdo = Database::createPdo($config);
    $reparacion = $this->schemaRepair->asegurarExtensionesBase($pdo);
    if (!$reparacion['ok']) {
      throw new RuntimeException(
        'Error al actualizar tablas: ' . implode('; ', $reparacion['errores'])
      );
    }
    $admin = $this->bootstrap->asegurarUsuarioAdministrador($pdo);

    $estado = $this->obtenerEstado();
    $estado['diagnostico'] = $this->diagnosticoEsquema($pdo, $config);
    $estado['ultimaEjecucion'] = [
      'aplicadas' => $resultado['aplicadas'],
      'errores' => $resultado['errores'],
      'reparacionEsquema' => $reparacion,
    ];
    $estado['admin'] = $admin;
    return $estado;
  }

  /**
   * @param array{server: string, database: string} $config
   * @return array<string, mixed>
   */
  private function diagnosticoEsquema(PDO $pdo, array $config): array
  {
    $row = $pdo->query('SELECT @@SERVERNAME AS servidorSql, DB_NAME() AS baseDatos')->fetch(PDO::FETCH_ASSOC);
    $columnas = [];
    $stmt = $pdo->query(
      "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
       WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'Usuarios'
       ORDER BY ORDINAL_POSITION"
    );
    while ($col = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $columnas[] = (string) ($col['COLUMN_NAME'] ?? '');
    }

    return [
      'servidorConfigurado' => $config['server'] ?? '',
      'baseDatosConfigurada' => $config['database'] ?? '',
      'servidorSql' => trim((string) ($row['servidorSql'] ?? '')),
      'baseDatos' => trim((string) ($row['baseDatos'] ?? '')),
      'columnasUsuarios' => $columnas,
      'tieneRol' => in_array('Rol', $columnas, true),
      'tieneBaja' => in_array('Baja', $columnas, true),
    ];
  }

  /**
   * @param list<string> $aplicadas
   * @param array{usuario: string, password: string, creado: bool, actualizado: bool} $admin
   * @param array{reparado: bool, faltantes: list<string>} $reparacion
   * @param array<string, mixed>|null $diagnostico
   */
  private function mensajeExito(
    array $aplicadas,
    array $admin,
    array $reparacion = ['reparado' => false, 'faltantes' => []],
    ?array $diagnostico = null
  ): string {
    $partes = ['Instalacion completada.'];
    $partes[] = count($aplicadas) . ' script(s) de estructura ejecutados.';
    if ($diagnostico !== null) {
      $srv = $diagnostico['servidorSql'] ?? '';
      $db = $diagnostico['baseDatos'] ?? '';
      $partes[] = "Conectado a {$srv} / {$db}.";
      if (($diagnostico['tieneRol'] ?? false) && ($diagnostico['tieneBaja'] ?? false)) {
        $partes[] = 'Usuarios.Rol y Usuarios.Baja confirmados.';
      } else {
        $faltan = [];
        if (!($diagnostico['tieneRol'] ?? false)) {
          $faltan[] = 'Rol';
        }
        if (!($diagnostico['tieneBaja'] ?? false)) {
          $faltan[] = 'Baja';
        }
        $partes[] = 'ATENCION: faltan columnas en Usuarios: ' . implode(', ', $faltan) . '.';
      }
    }
    if ($admin['creado']) {
      $partes[] = 'Usuario administrador creado.';
    } elseif ($admin['actualizado']) {
      $partes[] = 'Usuario administrador actualizado.';
    }
    return implode(' ', $partes);
  }

  /**
   * @param array<string, mixed> $body
   * @return array{tipo: string, server: string, database: string, user: string, password: string, trust_cert: bool, encrypt: bool}
   */
  private function normalizarConfig(array $body): array
  {
    $database = trim((string) ($body['database'] ?? $body['dbName'] ?? ''));
    if ($database === '') {
      throw new InvalidArgumentException('Indique el nombre de la base de datos');
    }

    $tipo = strtolower(trim((string) ($body['tipo'] ?? 'local')));
    if (!in_array($tipo, ['local', 'nube'], true)) {
      $tipo = 'local';
    }

    $serverRaw = trim((string) ($body['server'] ?? $body['dbServer'] ?? $body['serverUrl'] ?? ''));
    if ($serverRaw === '') {
      $serverRaw = $tipo === 'nube' ? '' : 'localhost';
    }
    if ($serverRaw === '') {
      throw new InvalidArgumentException('Indique el servidor o la URL de la base de datos');
    }

    $user = trim((string) ($body['user'] ?? $body['dbUser'] ?? ''));
    if ($user === '') {
      throw new InvalidArgumentException('Indique el usuario de SQL Server');
    }

    return Database::prepareConfig([
      'tipo' => $tipo,
      'server' => $serverRaw,
      'database' => $database,
      'user' => $user,
      'password' => (string) ($body['password'] ?? $body['dbPassword'] ?? ''),
      'trust_cert' => filter_var($body['trust_cert'] ?? $body['trustCert'] ?? true, FILTER_VALIDATE_BOOL),
      'encrypt' => filter_var(
        $body['encrypt'] ?? ($tipo === 'nube'),
        FILTER_VALIDATE_BOOL
      ),
    ]);
  }
}
