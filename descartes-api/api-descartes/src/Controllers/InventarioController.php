<?php

declare(strict_types=1);

namespace Descartes\Api\Controllers;

use Descartes\Api\Http\ErrorResponse;
use Descartes\Api\Services\Inventario\InventarioRecuentoService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class InventarioController
{
  private InventarioRecuentoService $recuento;

  public function __construct(InventarioRecuentoService $recuento)
  {
    $this->recuento = $recuento;
  }

  public function estado(Request $request, Response $response): Response
  {
    try {
      return $this->json($response, 200, $this->recuento->estado($request->getQueryParams()));
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function congelar(Request $request, Response $response): Response
  {
    return $this->accion($request, $response, fn (array $body) => $this->recuento->congelar($body));
  }

  public function anotar(Request $request, Response $response): Response
  {
    return $this->accion($request, $response, fn (array $body) => $this->recuento->anotar($body));
  }

  public function descartar(Request $request, Response $response): Response
  {
    return $this->accion($request, $response, fn (array $body) => $this->recuento->descartar($body));
  }

  public function actualizar(Request $request, Response $response): Response
  {
    return $this->accion($request, $response, fn (array $body) => $this->recuento->actualizar($body));
  }

  /** @param callable(array<string, mixed>): array<string, mixed> $fn */
  private function accion(Request $request, Response $response, callable $fn): Response
  {
    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      return $this->json($response, 200, $fn($body));
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      $code = (int) $e->getCode();
      if ($code === 404) {
        return ErrorResponse::json($response, 404, $e->getMessage(), 'NO_ENCONTRADO');
      }
      if ($code === 409) {
        return ErrorResponse::json($response, 409, $e->getMessage(), 'CONFLICTO');
      }

      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  /** @param array<string, mixed> $data */
  private function json(Response $response, int $status, array $data): Response
  {
    $response->getBody()->write((string) json_encode($data, JSON_UNESCAPED_UNICODE));

    return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
  }
}
