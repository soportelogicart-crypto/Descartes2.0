<?php

declare(strict_types=1);

namespace Descartes\Api\Http;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Response as SlimResponse;
use Throwable;

final class ApiErrorHandler
{
  public static function handle(
    ServerRequestInterface $request,
    Throwable $exception,
    bool $displayErrorDetails,
    bool $logErrors,
    bool $logErrorDetails,
    ?LoggerInterface $logger = null
  ): Response {
    if ($logErrors && $logger !== null) {
      $context = [
        'source' => 'api',
        'action' => 'uncaught',
        'method' => $request->getMethod(),
        'path' => (string) $request->getUri()->getPath(),
        'exception' => $exception,
      ];
      if ($exception instanceof HttpException) {
        $code = $exception->getCode() >= 400 ? $exception->getCode() : 400;
        if ($code >= 500) {
          $logger->error($exception->getMessage(), $context);
        } elseif ($code !== 404) {
          $logger->warning($exception->getMessage(), array_merge($context, ['status' => $code]));
        }
        // 404: no log (ruido de rutas)
      } else {
        $logger->error($exception->getMessage(), $context);
      }
    }

    $response = new SlimResponse();

    // Ya registrado arriba con excepcion: no volver a loguear en ErrorResponse.
    if ($exception instanceof HttpMethodNotAllowedException) {
      return ErrorResponse::json($response, 405, 'Metodo no permitido', 'METODO_NO_PERMITIDO', [], [], false);
    }

    if ($exception instanceof HttpNotFoundException) {
      return ErrorResponse::json($response, 404, 'Recurso no encontrado', 'NO_ENCONTRADO', [], [], false);
    }

    if ($exception instanceof HttpException) {
      return ErrorResponse::json(
        $response,
        $exception->getCode() >= 400 ? $exception->getCode() : 400,
        $exception->getMessage(),
        'ERROR_HTTP',
        [],
        [],
        false
      );
    }

    $mensaje = $displayErrorDetails ? $exception->getMessage() : 'Error interno del servidor';

    return ErrorResponse::json($response, 500, $mensaje, 'ERROR_INTERNO', [], [], false);
  }
}
