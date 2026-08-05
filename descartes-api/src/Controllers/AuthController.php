<?php

declare(strict_types=1);

namespace Descartes\Api\Controllers;

use Descartes\Api\Http\ErrorResponse;
use Descartes\Api\Services\PermissionService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;

final class AuthController
{
  private PDO $pdo;
  private PermissionService $permissionService;

  public function __construct(PDO $pdo, PermissionService $permissionService)
  {
    $this->pdo = $pdo;
    $this->permissionService = $permissionService;
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
       FROM [Usuarios]
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

    $usuarioData = [
      'codigo' => $row['Codigo'],
      'nombre' => $row['Nombre'],
      'rolCodigo' => $row['Rol'] ?? null,
    ];

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

    return $this->json($response, 200, [
      'usuario' => $usuario,
      'permisos' => $this->permissionService->permisosPorRol((string) ($usuario['rolCodigo'] ?? '')),
    ]);
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
