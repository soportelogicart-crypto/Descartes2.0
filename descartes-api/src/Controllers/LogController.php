<?php

declare(strict_types=1);

namespace Descartes\Api\Controllers;

use Descartes\Api\Http\ErrorResponse;
use Descartes\Api\Logging\FileLogger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Response as SlimResponse;

/**
 * Recibe errores / eventos desde el frontend Gestion.
 */
final class LogController
{
  private LoggerInterface $logger;

  public function __construct(LoggerInterface $logger)
  {
    $this->logger = $logger;
  }

  public function create(Request $request, Response $response): Response
  {
    $body = (array) json_decode((string) $request->getBody(), true);
    $message = trim((string) ($body['message'] ?? ''));
    if ($message === '') {
      return ErrorResponse::json($response, 400, 'message obligatorio', 'VALIDACION');
    }

    $level = strtolower(trim((string) ($body['level'] ?? 'error')));
    $allowed = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];
    if (!in_array($level, $allowed, true)) {
      $level = 'error';
    }

    $context = is_array($body['context'] ?? null) ? $body['context'] : [];
    $context['source'] = 'gestion';
    if (!empty($body['action'])) {
      $context['action'] = (string) $body['action'];
    }
    if (!empty($body['url'])) {
      $context['url'] = (string) $body['url'];
    }
    if (!empty($body['userAgent'])) {
      $context['userAgent'] = substr((string) $body['userAgent'], 0, 200);
    }

    // Evitar spam de debug desde cliente
    if ($level === 'debug') {
      $level = 'info';
    }

    $this->logger->log($level, $message, $context);

    $out = new SlimResponse();
    $out->getBody()->write((string) json_encode(['ok' => true], JSON_UNESCAPED_UNICODE));
    return $out->withHeader('Content-Type', 'application/json')->withStatus(200);
  }
}
