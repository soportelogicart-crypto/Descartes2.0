<?php

declare(strict_types=1);

namespace Descartes\Api\Controllers;

use Descartes\Api\Http\ErrorResponse;
use Descartes\Api\Services\Listados\ExtractoClientesListadoService;
use Descartes\Api\Services\Listados\InformeIvaListadoService;
use Descartes\Api\Services\Listados\InformeTicketsListadoService;
use Descartes\Api\Services\Listados\StockListadoService;
use Descartes\Api\Services\Listados\StockMinimosListadoService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

final class ListadosController
{
  private StockListadoService $stock;
  private StockMinimosListadoService $stockMinimos;
  private InformeIvaListadoService $informeIva;
  private InformeTicketsListadoService $informeTickets;
  private ExtractoClientesListadoService $extractoClientes;
  private LoggerInterface $logger;

  public function __construct(
    StockListadoService $stock,
    StockMinimosListadoService $stockMinimos,
    InformeIvaListadoService $informeIva,
    InformeTicketsListadoService $informeTickets,
    ExtractoClientesListadoService $extractoClientes,
    LoggerInterface $logger
  ) {
    $this->stock = $stock;
    $this->stockMinimos = $stockMinimos;
    $this->informeIva = $informeIva;
    $this->informeTickets = $informeTickets;
    $this->extractoClientes = $extractoClientes;
    $this->logger = $logger;
  }

  public function listStock(Request $request, Response $response): Response
  {
    try {
      return $this->json($response, 200, $this->stock->generar($request->getQueryParams()));
    } catch (InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage());
    } catch (\Throwable $e) {
      $this->logger->error('listados.stock', ['error' => $e->getMessage()]);

      return ErrorResponse::json($response, 500, 'No se pudo generar el listado de stock');
    }
  }

  public function listStockMinimos(Request $request, Response $response): Response
  {
    try {
      return $this->json($response, 200, $this->stockMinimos->generar($request->getQueryParams()));
    } catch (\Throwable $e) {
      $this->logger->error('listados.stock-minimos', ['error' => $e->getMessage()]);

      return ErrorResponse::json($response, 500, 'No se pudo generar el listado de stock bajo mínimos');
    }
  }

  public function listInformeIva(Request $request, Response $response): Response
  {
    try {
      return $this->json($response, 200, $this->informeIva->generar($request->getQueryParams()));
    } catch (InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage());
    } catch (\Throwable $e) {
      $this->logger->error('listados.informe-iva', ['error' => $e->getMessage()]);

      return ErrorResponse::json($response, 500, 'No se pudo generar el informe de IVA');
    }
  }

  public function listInformeTickets(Request $request, Response $response): Response
  {
    try {
      return $this->json($response, 200, $this->informeTickets->generar($request->getQueryParams()));
    } catch (InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage());
    } catch (\Throwable $e) {
      $this->logger->error('listados.informe-tickets', ['error' => $e->getMessage()]);

      return ErrorResponse::json($response, 500, 'No se pudo generar el informe de tickets');
    }
  }

  public function listExtractoClientes(Request $request, Response $response): Response
  {
    try {
      return $this->json($response, 200, $this->extractoClientes->generar($request->getQueryParams()));
    } catch (InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage());
    } catch (\Throwable $e) {
      $this->logger->error('listados.extracto-clientes', ['error' => $e->getMessage()]);

      return ErrorResponse::json($response, 500, 'No se pudo generar el extracto de clientes');
    }
  }

  /** @param array<string, mixed>|list<mixed> $payload */
  private function json(Response $response, int $status, $payload): Response
  {
    $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus($status);
  }
}
