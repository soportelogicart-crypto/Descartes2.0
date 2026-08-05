<?php

declare(strict_types=1);

namespace Descartes\Api\Controllers;

use Descartes\Api\Http\ErrorResponse;
use Descartes\Api\Repositories\ArticuloStockRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;

final class ArticuloController
{
  private ArticuloStockRepository $articuloStockRepository;

  public function __construct(ArticuloStockRepository $articuloStockRepository)
  {
    $this->articuloStockRepository = $articuloStockRepository;
  }

  public function getStock(Request $request, Response $response, array $args): Response
  {
    $codigo = $args['codigo'];
    $stock = $this->articuloStockRepository->findByArticulo($codigo);

    $response = new SlimResponse(200);
    $response->getBody()->write((string) json_encode(['items' => $stock], JSON_UNESCAPED_UNICODE));

    return $response->withHeader('Content-Type', 'application/json');
  }
}
