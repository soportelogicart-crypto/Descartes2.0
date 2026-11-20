<?php

declare(strict_types=1);

namespace Descartes\Api\Controllers;

use Descartes\Api\Http\ErrorResponse;
use Descartes\Api\Services\Instalacion\MejorasGestionSchema;
use Descartes\Api\Services\PermissionService;
use Descartes\Api\Services\UsuarioCorreoService;
use Descartes\Api\Support\PreferenciasUsuario;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;

final class AuthController
{
  private PDO $pdo;
  private PermissionService $permissionService;
  private UsuarioCorreoService $correo;

  public function __construct(PDO $pdo, PermissionService $permissionService, UsuarioCorreoService $correo)
  {
    $this->pdo = $pdo;
    $this->permissionService = $permissionService;
    $this->correo = $correo;
  }

  public function login(Request $request, Response $response): Response
  {
    $body = (array) json_decode((string) $request->getBody(), true);
    $usuario = trim((string) ($body['usuario'] ?? ''));
    $password = (string) ($body['password'] ?? '');

    if ($usuario === '' || $password === '') {
      return ErrorResponse::json($response, 400, 'Usuario y contrasena obligatorios', 'VALIDACION');
    }

    // No filtrar por Baja: esa columna se añadió para Descartes 2.0 y puede no existir aún.
    $stmt = $this->pdo->prepare(
      'SELECT RTRIM([Codigo]) AS [Codigo], [Nombre], RTRIM(CAST([PassWord] AS nvarchar(255))) AS [PassWord], RTRIM([Rol]) AS [Rol]
       FROM [Usuarios_Ges]
       WHERE RTRIM([Codigo]) = :codigo'
    );
    $stmt->execute(['codigo' => $usuario]);
    $row = $stmt->fetch();

    if (!$row) {
      return ErrorResponse::json($response, 401, 'Credenciales invalidas', 'NO_AUTENTICADO');
    }

    if (!$this->verifyPassword($password, (string) ($row['PassWord'] ?? ''))) {
      return ErrorResponse::json($response, 401, 'Credenciales invalidas', 'NO_AUTENTICADO');
    }

    $usuarioData = $this->usuarioConRol($row['Codigo'], $row['Nombre'], $row['Rol'] ?? null);

    $_SESSION['usuario'] = $usuarioData;

    return $this->json($response, 200, [
      'usuario' => $usuarioData,
      'permisos' => $this->permissionService->permisosPorRol((string) ($row['Rol'] ?? '')),
    ]);
  }

  public function logout(Request $request, Response $response): Response
  {
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
      session_destroy();
    }
    return $response->withStatus(204);
  }

  public function me(Request $request, Response $response): Response
  {
    $usuario = $_SESSION['usuario'] ?? null;
    if ($usuario === null) {
      return ErrorResponse::json($response, 401, 'Sesion no iniciada', 'NO_AUTENTICADO');
    }

    $usuario = $this->usuarioConRol(
      (string) ($usuario['codigo'] ?? ''),
      (string) ($usuario['nombre'] ?? ''),
      $usuario['rolCodigo'] ?? null
    );

    return $this->json($response, 200, [
      'usuario' => $usuario,
      'permisos' => $this->permissionService->permisosPorRol((string) ($usuario['rolCodigo'] ?? '')),
    ]);
  }

  /**
   * Tienda de arqueo del puesto y las otras marcadas en el usuario de la sesión.
   */
  public function tiendasPuesto(Request $request, Response $response): Response
  {
    if (($_SESSION['usuario'] ?? null) === null) {
      return ErrorResponse::json($response, 401, 'Sesion no iniciada', 'NO_AUTENTICADO');
    }

    $puesto = trim((string) ($request->getQueryParams()['puesto'] ?? ''));
    if ($puesto === '') {
      return $this->json($response, 200, ['items' => []]);
    }

    try {
      MejorasGestionSchema::asegurar($this->pdo);
    } catch (\Throwable) {
      // Si no se puede crear la columna, se devuelve al menos la tienda de arqueo.
    }

    try {
      $stmt = $this->pdo->prepare(
        'SELECT RTRIM([EmpresaArqueo]) AS tienda
         FROM [Puestos]
         WHERE RTRIM([Puesto]) = :puesto'
      );
      $stmt->execute(['puesto' => $puesto]);
      $row = $stmt->fetch();
    } catch (\Throwable) {
      return $this->json($response, 200, ['items' => []]);
    }

    if (!is_array($row)) {
      return $this->json($response, 200, ['items' => []]);
    }

    $extra = '';
    $codigoUsuario = trim((string) ($_SESSION['usuario']['codigo'] ?? ''));
    if ($codigoUsuario !== '') {
      try {
        $stmtUsuario = $this->pdo->prepare(
          'SELECT RTRIM([TiendasExtra]) AS extra
           FROM [Usuarios_Ges]
           WHERE RTRIM([Codigo]) = :codigo'
        );
        $stmtUsuario->execute(['codigo' => $codigoUsuario]);
        $filaUsuario = $stmtUsuario->fetch();
        if (is_array($filaUsuario)) {
          $extra = (string) ($filaUsuario['extra'] ?? '');
        }
      } catch (\Throwable) {
        $extra = '';
      }
    }

    $codigos = [];
    $arqueo = trim((string) ($row['tienda'] ?? ''));
    if ($arqueo !== '') {
      $codigos[] = $arqueo;
    }
    foreach (preg_split('/[,;]+/', $extra) ?: [] as $codigo) {
      $codigo = trim((string) $codigo);
      if ($codigo !== '' && !in_array($codigo, $codigos, true)) {
        $codigos[] = $codigo;
      }
    }

    $nombres = $this->nombresTienda($codigos);
    $items = [];
    foreach ($codigos as $codigo) {
      $nombre = $nombres[$codigo] ?? '';
      $items[] = [
        'tienda' => $codigo,
        'tiendaNombre' => $nombre !== '' ? $nombre : $codigo,
      ];
    }

    return $this->json($response, 200, ['items' => $items]);
  }

  /** @param list<string> $codigos @return array<string, string> */
  private function nombresTienda(array $codigos): array
  {
    if ($codigos === []) {
      return [];
    }
    $marcas = [];
    $params = [];
    foreach ($codigos as $i => $codigo) {
      $marcas[] = ':c' . $i;
      $params['c' . $i] = $codigo;
    }
    try {
      $stmt = $this->pdo->prepare(
        'SELECT RTRIM([Codigo]) AS codigo, RTRIM([Nombre]) AS nombre
         FROM [Empresas_Ges]
         WHERE RTRIM([Codigo]) IN (' . implode(', ', $marcas) . ')'
      );
      $stmt->execute($params);
    } catch (\Throwable) {
      return [];
    }
    $nombres = [];
    foreach ($stmt->fetchAll() as $fila) {
      $codigo = trim((string) ($fila['codigo'] ?? ''));
      if ($codigo === '') {
        continue;
      }
      $nombres[$codigo] = trim((string) ($fila['nombre'] ?? ''));
    }
    return $nombres;
  }

  /**
   * Guarda idioma, color de base y/o ayudas del menu del usuario de la sesion.
   * Solo toca esas columnas: no exige el resto de la ficha ni modifica la contrasena.
   */
  public function putPreferencias(Request $request, Response $response): Response
  {
    $codigo = trim((string) ($_SESSION['usuario']['codigo'] ?? ''));
    if ($codigo === '') {
      return ErrorResponse::json($response, 401, 'Sesion no iniciada', 'NO_AUTENTICADO');
    }
    $body = (array) json_decode((string) $request->getBody(), true);

    $sets = [];
    $params = ['codigo' => $codigo];
    if (array_key_exists('idioma', $body)) {
      $idioma = PreferenciasUsuario::idiomaValido($body['idioma']);
      if ($idioma === null) {
        return ErrorResponse::json($response, 400, 'Idioma no valido', 'VALIDACION');
      }
      $sets[] = '[Idioma] = :idioma';
      $params['idioma'] = $idioma === '' ? null : $idioma;
    }
    if (array_key_exists('colorTema', $body)) {
      $tema = PreferenciasUsuario::temaValido($body['colorTema']);
      if ($tema === null) {
        return ErrorResponse::json($response, 400, 'Color no valido', 'VALIDACION');
      }
      $sets[] = '[ColorTema] = :tema';
      $params['tema'] = $tema === '' ? null : $tema;
    }
    if (array_key_exists('ayudasMenu', $body)) {
      $valor = $body['ayudasMenu'];
      if (!is_bool($valor) && !in_array($valor, [0, 1, '0', '1'], true)) {
        return ErrorResponse::json($response, 400, 'Valor de ayudas no valido', 'VALIDACION');
      }
      $sets[] = '[AyudasMenu] = :ayudas';
      $params['ayudas'] = (int) $valor === 1 || $valor === true ? 1 : 0;
    }
    if ($sets === []) {
      return ErrorResponse::json($response, 400, 'Nada que guardar', 'VALIDACION');
    }

    try {
      MejorasGestionSchema::asegurar($this->pdo);
      $stmt = $this->pdo->prepare(
        'UPDATE [Usuarios_Ges] SET ' . implode(', ', $sets) . ' WHERE RTRIM([Codigo]) = :codigo'
      );
      $stmt->execute($params);
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, 'No se pudieron guardar las preferencias', 'PREFERENCIAS');
    }

    $actual = $this->preferenciasUsuario($codigo);
    $_SESSION['usuario']['idioma'] = $actual['idioma'];
    $_SESSION['usuario']['colorTema'] = $actual['colorTema'];
    $_SESSION['usuario']['ayudasMenu'] = $actual['ayudasMenu'];

    return $this->json($response, 200, $actual);
  }

  /** @return array{idioma: string, colorTema: string, ayudasMenu: bool} */
  private function preferenciasUsuario(string $codigo): array
  {
    $out = [
      'idioma' => PreferenciasUsuario::IDIOMA_DEFECTO,
      'colorTema' => PreferenciasUsuario::TEMA_DEFECTO,
      'ayudasMenu' => true,
    ];
    try {
      // Las columnas son nuevas: en bases sin migrar se crean aqui (idempotente).
      MejorasGestionSchema::asegurar($this->pdo);
      $stmt = $this->pdo->prepare(
        'SELECT RTRIM([Idioma]) AS [Idioma], RTRIM([ColorTema]) AS [ColorTema], [AyudasMenu] AS [AyudasMenu]
         FROM [Usuarios_Ges]
         WHERE RTRIM([Codigo]) = :codigo'
      );
      $stmt->execute(['codigo' => $codigo]);
      $fila = $stmt->fetch();
    } catch (\Throwable) {
      return $out;
    }
    if (is_array($fila)) {
      $idioma = PreferenciasUsuario::idiomaValido($fila['Idioma'] ?? '');
      $tema = PreferenciasUsuario::temaValido($fila['ColorTema'] ?? '');
      if ($idioma !== null && $idioma !== '') {
        $out['idioma'] = $idioma;
      }
      if ($tema !== null && $tema !== '') {
        $out['colorTema'] = $tema;
      }
      if (array_key_exists('AyudasMenu', $fila) && $fila['AyudasMenu'] !== null && $fila['AyudasMenu'] !== '') {
        $out['ayudasMenu'] = (int) $fila['AyudasMenu'] === 1;
      }
    }
    return $out;
  }

  public function getCorreo(Request $request, Response $response): Response
  {
    $codigo = trim((string) ($_SESSION['usuario']['codigo'] ?? ''));
    if ($codigo === '') {
      return ErrorResponse::json($response, 401, 'Sesion no iniciada', 'NO_AUTENTICADO');
    }
    return $this->json($response, 200, $this->correo->obtener($codigo));
  }

  public function putCorreo(Request $request, Response $response): Response
  {
    $codigo = trim((string) ($_SESSION['usuario']['codigo'] ?? ''));
    if ($codigo === '') {
      return ErrorResponse::json($response, 401, 'Sesion no iniciada', 'NO_AUTENTICADO');
    }
    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      return $this->json($response, 200, $this->correo->guardar($codigo, $body));
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    }
  }

  public function probarCorreo(Request $request, Response $response): Response
  {
    $codigo = trim((string) ($_SESSION['usuario']['codigo'] ?? ''));
    if ($codigo === '') {
      return ErrorResponse::json($response, 401, 'Sesion no iniciada', 'NO_AUTENTICADO');
    }
    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      return $this->json($response, 200, $this->correo->probar($codigo, $body));
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'CORREO');
    }
  }

  /**
   * @return array{codigo: string, nombre: string, rolCodigo: ?string, rolNombre: ?string, idioma: string, colorTema: string, ayudasMenu: bool}
   */
  private function usuarioConRol(string $codigo, string $nombre, mixed $rolCodigo): array
  {
    $rol = trim((string) $rolCodigo);
    $rolNombre = null;
    if ($rol !== '') {
      $stmt = $this->pdo->prepare(
        'SELECT RTRIM([Nombre]) AS Nombre FROM [Roles] WHERE RTRIM([Codigo]) = :codigo'
      );
      $stmt->bindValue(':codigo', $rol);
      $stmt->execute();
      $nombreRol = $stmt->fetchColumn();
      $rolNombre = is_string($nombreRol) && trim($nombreRol) !== '' ? trim($nombreRol) : null;
    }

    return [
      'codigo' => $codigo,
      'nombre' => $nombre,
      'rolCodigo' => $rol !== '' ? $rol : null,
      'rolNombre' => $rolNombre,
    ] + $this->preferenciasUsuario($codigo);
  }

  private function verifyPassword(string $plain, string $stored): bool
  {
    $stored = trim($stored);
    if ($stored === '') {
      return false;
    }
    if (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$2a$')) {
      return password_verify($plain, $stored);
    }
    return hash_equals($stored, $plain);
  }

  private function json(Response $response, int $status, array $payload): Response
  {
    $response = new SlimResponse($status);
    $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
    return $response->withHeader('Content-Type', 'application/json');
  }
}
