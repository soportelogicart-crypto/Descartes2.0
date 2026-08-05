<?php

declare(strict_types=1);

namespace Descartes\Api\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class CorsMiddleware implements MiddlewareInterface
{
  public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
  {
    $origin = $request->getHeaderLine('Origin');
    $allowedOrigins = $this->allowedOrigins();

    if ($origin !== '' && !in_array($origin, $allowedOrigins, true)) {
      return $handler->handle($request);
    }

    if ($request->getMethod() === 'OPTIONS') {
      $response = new Response(204);
      return $this->withCorsHeaders($response, $origin);
    }

    $response = $handler->handle($request);

    if ($origin === '') {
      return $response;
    }

    return $this->withCorsHeaders($response, $origin);
  }

  /** @return list<string> */
  private function allowedOrigins(): array
  {
    $raw = trim((string) ($_ENV['CORS_ORIGINS'] ?? 'http://localhost:5173'));
    if ($raw === '') {
      return [];
    }

    return array_values(array_filter(array_map('trim', explode(',', $raw))));
  }

  private function withCorsHeaders(ResponseInterface $response, string $origin): ResponseInterface
  {
    return $response
      ->withHeader('Access-Control-Allow-Origin', $origin)
      ->withHeader('Access-Control-Allow-Credentials', 'true')
      ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With')
      ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
  }
}
