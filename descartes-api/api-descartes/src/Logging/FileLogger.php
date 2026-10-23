<?php

declare(strict_types=1);

namespace Descartes\Api\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Throwable;

/**
 * Logger simple a fichero diario (JSON por linea).
 * Ruta: storage/logs/app-YYYY-MM-DD.log
 */
final class FileLogger extends AbstractLogger
{
  private string $dir;

  public function __construct(?string $dir = null)
  {
    $this->dir = $dir ?? dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($this->dir)) {
      @mkdir($this->dir, 0775, true);
    }
  }

  /** @param mixed $level */
  public function log($level, $message, array $context = []): void
  {
    $level = (string) $level;
    $file = $this->dir . DIRECTORY_SEPARATOR . 'app-' . date('Y-m-d') . '.log';

    $user = null;
    if (session_status() === PHP_SESSION_ACTIVE) {
      $user = $_SESSION['usuario']['codigo'] ?? $_SESSION['usuario'] ?? null;
      if (is_array($user)) {
        $user = $user['codigo'] ?? $user['usuario'] ?? null;
      }
    }

    $entry = [
      'ts' => date('c'),
      'level' => $level,
      'source' => (string) ($context['source'] ?? 'api'),
      'user' => $user !== null ? (string) $user : null,
      'action' => isset($context['action']) ? (string) $context['action'] : null,
      'message' => $this->interpolate((string) $message, $context),
      'context' => $this->sanitizeContext($context),
    ];

    if (isset($context['exception']) && $context['exception'] instanceof Throwable) {
      $ex = $context['exception'];
      $entry['exception'] = [
        'class' => get_class($ex),
        'message' => $ex->getMessage(),
        'file' => $ex->getFile() . ':' . $ex->getLine(),
      ];
    }

    @file_put_contents(
      $file,
      json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
      FILE_APPEND | LOCK_EX
    );
  }

  public function exception(Throwable $e, string $action = '', array $context = []): void
  {
    $this->error($e->getMessage(), array_merge($context, [
      'action' => $action !== '' ? $action : 'exception',
      'exception' => $e,
    ]));
  }

  /** @param array<string, mixed> $context */
  private function interpolate(string $message, array $context): string
  {
    $replace = [];
    foreach ($context as $key => $val) {
      if ($val instanceof Throwable || is_array($val) || is_object($val)) {
        continue;
      }
      $replace['{' . $key . '}'] = (string) $val;
    }
    return strtr($message, $replace);
  }

  /** @param array<string, mixed> $context */
  private function sanitizeContext(array $context): array
  {
    $out = [];
    $skip = ['exception', 'source', 'action', 'password', 'passwordConfirm', 'PassWord'];
    foreach ($context as $key => $val) {
      if (in_array((string) $key, $skip, true)) {
        continue;
      }
      if (is_string($key) && preg_match('/pass|secret|token/i', $key)) {
        $out[$key] = '***';
        continue;
      }
      if ($val instanceof Throwable) {
        continue;
      }
      if (is_scalar($val) || $val === null) {
        $out[$key] = $val;
      } elseif (is_array($val)) {
        $out[$key] = $this->sanitizeContext($val);
      } else {
        $out[$key] = get_class($val);
      }
    }
    return $out;
  }

  public static function shouldLogHttpStatus(int $status): bool
  {
    return $status >= 400;
  }

  public static function levelForStatus(int $status): string
  {
    if ($status >= 500) {
      return LogLevel::ERROR;
    }
    if ($status === 401 || $status === 403) {
      return LogLevel::WARNING;
    }
    return LogLevel::NOTICE;
  }
}
