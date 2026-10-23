<?php

declare(strict_types=1);

namespace Descartes\Api\Controllers;

use Descartes\Api\Http\ErrorResponse;
use Descartes\Api\Repositories\CodigoPostalRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;

final class CodigoPostalController
{
  private CodigoPostalRepository $repository;

  public function __construct(CodigoPostalRepository $repository)
  {
    $this->repository = $repository;
  }

  public function lookup(Request $request, Response $response, array $args): Response
  {
    $codigo = trim((string) ($args['codigo'] ?? ''));
    if ($codigo === '') {
      return ErrorResponse::json($response, 400, 'Codigo postal obligatorio', 'VALIDACION');
    }

    $data = $this->repository->lookup($codigo);
    $response = new SlimResponse(200);
    $response->getBody()->write((string) json_encode($data, JSON_UNESCAPED_UNICODE));
    return $response->withHeader('Content-Type', 'application/json');
  }
}
