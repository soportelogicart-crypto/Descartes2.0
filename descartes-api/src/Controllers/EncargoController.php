<?php

declare(strict_types=1);

namespace Descartes\Api\Controllers;

use Descartes\Api\Http\ErrorResponse;
use Descartes\Api\Services\Ventas\EncargoService;
use Descartes\Api\Services\Ventas\TiendaLogoEncargoService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;

final class EncargoController
{
  public function __construct(private EncargoService $encargos)
  {
  }

  public function list(Request $request, Response $response): Response
  {
    $q = $request->getQueryParams();
    try {
      return $this->json($response, 200, $this->encargos->listar(
        isset($q['q']) ? (string) $q['q'] : null,
        isset($q['cliente']) ? (string) $q['cliente'] : null
      ));
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function albaran(Request $request, Response $response): Response
  {
    $q = $request->getQueryParams();
    try {
      $encargo = $this->encargos->porAlbaran(
        (string) ($q['empresa'] ?? ''),
        (string) ($q['tipo'] ?? ''),
        (int) ($q['albaran'] ?? 0)
      );
      return $this->json($response, 200, ['encargo' => $encargo]);
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function clientes(Request $request, Response $response): Response
  {
    $q = trim((string) ($request->getQueryParams()['q'] ?? ''));
    try {
      return $this->json($response, 200, $this->encargos->buscarClientes($q));
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function qr(Request $request, Response $response): Response
  {
    $codigo = trim((string) ($request->getQueryParams()['codigo'] ?? ''));
    try {
      return $this->json($response, 200, $this->encargos->resolverQr($codigo));
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function get(Request $request, Response $response, array $args): Response
  {
    try {
      return $this->json($response, 200, $this->encargos->obtener((int) ($args['numero'] ?? 0)));
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 404, $e->getMessage(), 'NO_ENCONTRADO');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function tiendas(Request $request, Response $response): Response
  {
    try {
      return $this->json($response, 200, $this->encargos->tiendasCircuito());
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function siguiente(Request $request, Response $response): Response
  {
    try {
      return $this->json($response, 200, $this->encargos->proponerNumero());
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function create(Request $request, Response $response): Response
  {
    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      return $this->json($response, 201, $this->encargos->crear($body));
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function realizar(Request $request, Response $response, array $args): Response
  {
    return $this->accion($response, (int) ($args['numero'] ?? 0), fn (int $n) => $this->encargos->realizar($n));
  }

  public function expedir(Request $request, Response $response, array $args): Response
  {
    return $this->accion($response, (int) ($args['numero'] ?? 0), fn (int $n) => $this->encargos->expedir($n));
  }

  public function recibir(Request $request, Response $response): Response
  {
    $body = (array) json_decode((string) $request->getBody(), true);
    $codigos = $body['codigos'] ?? [];
    if (!is_array($codigos)) {
      $codigos = [];
    }
    try {
      return $this->json($response, 200, $this->encargos->recibirLecturas($codigos));
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function expedirDia(Request $request, Response $response): Response
  {
    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      return $this->json($response, 200, $this->encargos->expedirDia(
        (string) ($body['tiendaOrigen'] ?? ''),
        (string) ($body['tiendaDestino'] ?? '')
      ));
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function devolver(Request $request, Response $response, array $args): Response
  {
    return $this->accion($response, (int) ($args['numero'] ?? 0), fn (int $n) => $this->encargos->devolver($n));
  }

  public function entregar(Request $request, Response $response, array $args): Response
  {
    return $this->accion($response, (int) ($args['numero'] ?? 0), fn (int $n) => $this->encargos->entregar($n));
  }

  public function etiquetaTransporte(Request $request, Response $response, array $args): Response
  {
    try {
      return $this->json($response, 200, $this->encargos->etiquetaTransporte((int) ($args['numero'] ?? 0)));
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function etiqueta(Request $request, Response $response, array $args): Response
  {
    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      return $this->json($response, 200, $this->encargos->etiqueta(
        (int) ($args['numero'] ?? 0),
        !empty($body['vuelta'])
      ));
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function logoTienda(Request $request, Response $response, array $args): Response
  {
    try {
      $imagen = (new TiendaLogoEncargoService())->imagen((string) ($args['codigo'] ?? ''));
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    }
    if ($imagen === null) {
      return $response->withStatus(404);
    }
    $response = new SlimResponse(200);
    $response->getBody()->write($imagen['cuerpo']);
    return $response->withHeader('Content-Type', $imagen['mime'])->withHeader('Cache-Control', 'no-cache');
  }

  private function accion(Response $response, int $numero, callable $fn): Response
  {
    try {
      return $this->json($response, 200, $fn($numero));
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  private function json(Response $response, int $status, array $payload): Response
  {
    $response = new SlimResponse($status);
    $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
    return $response->withHeader('Content-Type', 'application/json');
  }
}
