<?php

declare(strict_types=1);

namespace Descartes\Api\Controllers;

use Descartes\Api\Http\ErrorResponse;
use Descartes\Api\Services\EmpresaClienteService;
use Descartes\Api\Services\FiscalLockException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;

final class EmpresaClienteController
{
  private EmpresaClienteService $service;

  public function __construct(EmpresaClienteService $service)
  {
    $this->service = $service;
  }

  public function get(Request $request, Response $response): Response
  {
    $data = $this->service->obtener();
    if ($data === null) {
      return ErrorResponse::json($response, 404, 'No existe fila central (Central=1)', 'NO_ENCONTRADO');
    }

    return $this->json($response, 200, $data);
  }

  public function put(Request $request, Response $response): Response
  {
    $body = (array) json_decode((string) $request->getBody(), true);

    try {
      $data = $this->service->actualizar($body);
      return $this->json($response, 200, $data);
    } catch (FiscalLockException $e) {
      return ErrorResponse::json($response, 409, $e->getMessage(), 'CONFLICTO_FISCAL', $e->getCampos());
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\RuntimeException $e) {
      return ErrorResponse::json($response, 404, $e->getMessage(), 'NO_ENCONTRADO');
    }
  }

  private function json(Response $response, int $status, array $payload): Response
  {
    $response = new SlimResponse($status);
    $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
    return $response->withHeader('Content-Type', 'application/json');
  }
}
