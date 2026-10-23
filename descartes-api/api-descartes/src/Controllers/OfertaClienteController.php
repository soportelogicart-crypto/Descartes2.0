<?php

declare(strict_types=1);

namespace Descartes\Api\Controllers;

use Descartes\Api\Http\ErrorResponse;
use Descartes\Api\Repositories\OfertasClientesRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;

final class OfertaClienteController
{
  private OfertasClientesRepository $repository;

  public function __construct(OfertasClientesRepository $repository)
  {
    $this->repository = $repository;
  }

  public function list(Request $request, Response $response): Response
  {
    $query = $request->getQueryParams();
    $data = $this->repository->list($query);
    return $this->json($response, 200, $data);
  }

  public function get(Request $request, Response $response, array $args): Response
  {
    $articulo = trim((string) ($args['articulo'] ?? ''));
    $cliente = trim((string) ($args['cliente'] ?? ''));
    if ($articulo === '' || $cliente === '') {
      return ErrorResponse::json($response, 400, 'Articulo y cliente obligatorios', 'VALIDACION');
    }
    $item = $this->repository->findOne($articulo, $cliente);
    if ($item === null) {
      return ErrorResponse::json($response, 404, 'Oferta no encontrada', 'NO_ENCONTRADO');
    }
    return $this->json($response, 200, $item);
  }

  public function create(Request $request, Response $response): Response
  {
    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      $item = $this->repository->create($body);
      return $this->json($response, 201, $item);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function update(Request $request, Response $response, array $args): Response
  {
    $articulo = trim((string) ($args['articulo'] ?? ''));
    $cliente = trim((string) ($args['cliente'] ?? ''));
    if ($articulo === '' || $cliente === '') {
      return ErrorResponse::json($response, 400, 'Articulo y cliente obligatorios', 'VALIDACION');
    }
    $body = (array) json_decode((string) $request->getBody(), true);
    try {
      $item = $this->repository->update($articulo, $cliente, $body);
      if ($item === null) {
        return ErrorResponse::json($response, 404, 'Oferta no encontrada', 'NO_ENCONTRADO');
      }
      return $this->json($response, 200, $item);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function delete(Request $request, Response $response, array $args): Response
  {
    $articulo = trim((string) ($args['articulo'] ?? ''));
    $cliente = trim((string) ($args['cliente'] ?? ''));
    if ($articulo === '' || $cliente === '') {
      return ErrorResponse::json($response, 400, 'Articulo y cliente obligatorios', 'VALIDACION');
    }
    try {
      $ok = $this->repository->delete($articulo, $cliente);
      if (!$ok) {
        return ErrorResponse::json($response, 404, 'Oferta no encontrada', 'NO_ENCONTRADO');
      }
      return new SlimResponse(204);
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
