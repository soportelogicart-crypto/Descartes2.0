<?php

declare(strict_types=1);

namespace Descartes\Api\Config;

/**
 * Normaliza servidor SQL (local o nube) para el driver sqlsrv.
 */
final class DatabaseConnectionParser
{
  /**
   * @return array{server: string, encrypt: bool}
   */
  public static function normalizeServer(string $raw, string $tipo = 'local'): array
  {
    $server = trim($raw);
    if ($server === '') {
      return ['server' => 'localhost', 'encrypt' => false];
    }

    // Cadena completa pegada: sqlserver://host:1433 o Server=tcp:...
    if (preg_match('#^sqlserver://#i', $server)) {
      $parsed = parse_url($server);
      if (is_array($parsed) && !empty($parsed['host'])) {
        $host = $parsed['host'];
        $port = isset($parsed['port']) ? (int) $parsed['port'] : 1433;
        $server = "tcp:{$host},{$port}";
      }
    } elseif (preg_match('/^Server\s*=\s*(.+)$/i', $server, $m)) {
      $server = trim($m[1], " \t;'");
    }

    $server = preg_replace('#^https?://#i', '', $server) ?? $server;
    $server = rtrim($server, '/');

    $esNube = $tipo === 'nube'
      || str_contains(strtolower($server), '.database.windows.net')
      || str_contains(strtolower($server), '.database.cloud');

    if ($esNube && !preg_match('/^tcp:/i', $server)) {
      if (!preg_match('/,\s*\d+\s*$/', $server)) {
        $server = 'tcp:' . $server . ',1433';
      } else {
        $server = 'tcp:' . $server;
      }
    }

    return [
      'server' => $server,
      'encrypt' => $esNube,
    ];
  }
}
