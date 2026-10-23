<?php

declare(strict_types=1);

namespace Descartes\Api\Http;

use Descartes\Api\Logging\FileLogger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

final class ErrorResponse
{
  private static ?LoggerInterface $logger = null;

  public static function setLogger(?LoggerInterface $logger): void
  {
    self::$logger = $logger;
  }

  public static function json(
    Response $response,
    int $status,
    string $error,
    string $codigo,
    array $dependencias = [],
    array $logContext = [],
    bool $log = true
  ): Response {
    if ($log && self::$logger !== null && FileLogger::shouldLogHttpStatus($status)) {
      self::$logger->log(
        FileLogger::levelForStatus($status),
        $error,
        array_merge([
          'source' => 'api',
          'action' => 'http.' . $status,
          'codigo' => $codigo,
          'status' => $status,
          'dependencias' => $dependencias,
        ], $logContext)
      );
    }

    $payload = ['error' => $error, 'codigo' => $codigo];
    if ($dependencias !== []) {
      $payload['dependencias'] = $dependencias;
    }

    $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
    return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
  }
}
