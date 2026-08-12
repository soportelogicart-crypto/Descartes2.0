<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Ventas;

use PDO;

/**
 * Contrato hardware puesto (Fase 3): proxy al agente local.
 * El navegador no habla con el cajon/termica; la API reenvia al agente (Electron u otro).
 */
final class DispositivoPuestoService
{
  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  /**
   * @return array{puesto: string, cajonElectronico: bool, tipoCajon: ?string, dispositivoCajon: ?string, agenteUrl: ?string}
   */
  public function metaPuesto(string $puesto): array
  {
    $puesto = trim($puesto);
    $meta = [
      'puesto' => $puesto,
      'cajonElectronico' => false,
      'tipoCajon' => null,
      'dispositivoCajon' => null,
      'agenteUrl' => $this->agenteUrl(),
    ];
    if ($puesto === '') {
      return $meta;
    }
    try {
      $st = $this->pdo->prepare(
        'SELECT CajonElectronico, DispositivoCajon FROM Puestos WHERE Puesto = :p'
      );
      $st->execute(['p' => $puesto]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if ($row !== false) {
        $tipo = trim((string) ($row['CajonElectronico'] ?? ''));
        $meta['tipoCajon'] = $tipo !== '' ? $tipo : null;
        $meta['cajonElectronico'] = $tipo !== '';
        $dev = trim((string) ($row['DispositivoCajon'] ?? ''));
        $meta['dispositivoCajon'] = $dev !== '' ? $dev : null;
      }
    } catch (\Throwable $e) {
      // Esquema antiguo sin columnas.
    }
    return $meta;
  }

  /**
   * Lee inventario/efectivo del cajon via agente local.
   *
   * @param array<string, mixed> $body
   * @return array<string, mixed>
   */
  public function leerCajon(string $puesto, array $body = []): array
  {
    $meta = $this->metaPuesto($puesto);
    if ($puesto === '') {
      throw new \InvalidArgumentException('puesto es obligatorio');
    }

    $formaPago = trim((string) ($body['formaPago'] ?? ''));
    if ($formaPago === '') {
      $formaPago = $this->formaPagoCajonPorDefecto();
    }

    $payload = [
      'puesto' => $puesto,
      'formaPago' => $formaPago,
      'tipoCajon' => $meta['tipoCajon'],
      'dispositivoCajon' => $meta['dispositivoCajon'],
    ];

    $agent = $this->llamarAgente('POST', '/leer-cajon', $payload);
    if ($agent === null) {
      return [
        'ok' => false,
        'stub' => true,
        'agenteOnline' => false,
        'puesto' => $puesto,
        'formaPago' => $formaPago,
        'importe' => 0.0,
        'monedas' => [],
        'message' => 'Agente local no disponible. Ejecute Descartes Electron en este equipo o configure DISPOSITIVO_AGENTE_URL.',
        'dispositivo' => $meta,
      ];
    }

    $importe = (float) ($agent['importe'] ?? 0);
    $monedas = [];
    if (isset($agent['monedas']) && is_array($agent['monedas'])) {
      for ($i = 0; $i < 20; $i++) {
        $monedas[] = (float) ($agent['monedas'][$i] ?? 0);
      }
    }

    return [
      'ok' => !empty($agent['ok']),
      'stub' => !empty($agent['stub']),
      'agenteOnline' => true,
      'puesto' => $puesto,
      'formaPago' => (string) ($agent['formaPago'] ?? $formaPago),
      'importe' => round($importe, 2),
      'monedas' => $monedas,
      'message' => (string) ($agent['message'] ?? ''),
      'dispositivo' => $meta,
    ];
  }

  /**
   * Envia texto termico al agente local.
   *
   * @param array<string, mixed> $body
   * @return array<string, mixed>
   */
  public function imprimir(string $puesto, array $body): array
  {
    $meta = $this->metaPuesto($puesto);
    if ($puesto === '') {
      throw new \InvalidArgumentException('puesto es obligatorio');
    }
    $texto = (string) ($body['texto'] ?? '');
    if (trim($texto) === '') {
      throw new \InvalidArgumentException('texto es obligatorio');
    }

    $impresora = trim((string) ($body['impresora'] ?? ''));
    if ($impresora === '') {
      $impresora = $this->impresoraTicketsPuesto($puesto);
    }

    $payload = [
      'puesto' => $puesto,
      'texto' => $texto,
      'tipo' => (string) ($body['tipo'] ?? 'ticket'),
      'empresa' => (string) ($body['empresa'] ?? ''),
      'sesion' => (int) ($body['sesion'] ?? 0),
      'impresora' => $impresora,
      'abrirCajon' => !empty($body['abrirCajon']),
      'cortar' => !array_key_exists('cortar', $body) || !empty($body['cortar']),
      'ancho' => isset($body['ancho']) ? (int) $body['ancho'] : 42,
    ];

    $agent = $this->llamarAgente('POST', '/imprimir', $payload);
    if ($agent === null) {
      return [
        'ok' => false,
        'stub' => true,
        'agenteOnline' => false,
        'puesto' => $puesto,
        'message' => 'Agente local no disponible para impresion termica. Ejecute Descartes Electron.',
        'dispositivo' => $meta,
      ];
    }

    return [
      'ok' => !empty($agent['ok']),
      'stub' => !empty($agent['stub']),
      'agenteOnline' => true,
      'puesto' => $puesto,
      'impresora' => $agent['impresora'] ?? $impresora,
      'message' => (string) ($agent['message'] ?? 'Impresion enviada'),
      'dispositivo' => $meta,
    ];
  }

  private function impresoraTicketsPuesto(string $puesto): string
  {
    try {
      $st = $this->pdo->prepare(
        'SELECT ImpresoraTickets FROM Puestos WHERE Puesto = :p'
      );
      $st->execute(['p' => $puesto]);
      $v = $st->fetchColumn();
      return $v !== false ? trim((string) $v) : '';
    } catch (\Throwable $e) {
      return '';
    }
  }

  /**
   * Lista impresoras del equipo via agente Electron.
   *
   * @return array{ok: bool, stub: bool, agenteOnline: bool, printers: list<array<string, mixed>>, message: string}
   */
  public function listarImpresoras(): array
  {
    $agent = $this->llamarAgente('GET', '/impresoras', []);
    if ($agent === null) {
      return [
        'ok' => false,
        'stub' => true,
        'agenteOnline' => false,
        'printers' => [],
        'message' => 'Agente local no disponible. Ejecute Descartes Electron en este equipo o configure DISPOSITIVO_AGENTE_URL.',
      ];
    }

    $printers = [];
    if (isset($agent['printers']) && is_array($agent['printers'])) {
      foreach ($agent['printers'] as $p) {
        if (!is_array($p)) {
          continue;
        }
        $printers[] = [
          'id' => (int) ($p['id'] ?? 0),
          'name' => trim((string) ($p['name'] ?? '')),
          'displayName' => trim((string) ($p['displayName'] ?? $p['name'] ?? '')),
          'description' => trim((string) ($p['description'] ?? '')),
          'isDefault' => !empty($p['isDefault']),
        ];
      }
    }

    return [
      'ok' => !empty($agent['ok']),
      'stub' => !empty($agent['stub']),
      'agenteOnline' => true,
      'printers' => $printers,
      'message' => (string) ($agent['message'] ?? ''),
    ];
  }

  private function agenteUrl(): ?string
  {
    $url = trim((string) ($_ENV['DISPOSITIVO_AGENTE_URL'] ?? getenv('DISPOSITIVO_AGENTE_URL') ?: ''));
    return $url !== '' ? rtrim($url, '/') : 'http://127.0.0.1:17321';
  }

  /**
   * @param array<string, mixed> $payload
   * @return array<string, mixed>|null
   */
  private function llamarAgente(string $method, string $path, array $payload): ?array
  {
    $base = $this->agenteUrl();
    if ($base === null) {
      return null;
    }
    $url = $base . $path;
    $method = strtoupper($method);
    $json = $method === 'GET' || $method === 'HEAD' ? null : json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($method !== 'GET' && $method !== 'HEAD' && $json === false) {
      return null;
    }

    if (function_exists('curl_init')) {
      $ch = curl_init($url);
      if ($ch === false) {
        return null;
      }
      $headers = ['Accept: application/json'];
      $opts = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 25,
      ];
      if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_HTTPHEADER] = $headers;
        $opts[CURLOPT_POSTFIELDS] = $json;
      }
      curl_setopt_array($ch, $opts);
      $raw = curl_exec($ch);
      $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);
      if ($raw === false || $code < 200 || $code >= 300) {
        return null;
      }
      $decoded = json_decode($raw, true);
      return is_array($decoded) ? $decoded : null;
    }

    $http = [
      'method' => $method,
      'header' => "Accept: application/json\r\n",
      'timeout' => 8,
      'ignore_errors' => true,
    ];
    if ($json !== null) {
      $http['header'] .= "Content-Type: application/json\r\n";
      $http['content'] = $json;
    }
    $ctx = stream_context_create(['http' => $http]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
      return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
  }

  private function formaPagoCajonPorDefecto(): string
  {
    try {
      $st = $this->pdo->query(
        'SELECT TOP 1 Codigo FROM FormasPago
         WHERE CobroDeArqueo <> 0 AND ISNULL(Agrupacion, 0) = 0
           AND ISNULL(AbrirCajon, 0) <> 0
           AND ISNULL(Datafono, 0) = 0 AND ISNULL(Vales, 0) = 0
         ORDER BY Codigo'
      );
      if ($st !== false) {
        $c = $st->fetchColumn();
        if ($c !== false && trim((string) $c) !== '') {
          return trim((string) $c);
        }
      }
    } catch (\Throwable $e) {
      // fallthrough
    }
    try {
      $st = $this->pdo->query(
        'SELECT TOP 1 Codigo FROM FormasPago
         WHERE CobroDeArqueo <> 0 AND ISNULL(Agrupacion, 0) = 0 AND CajonElectronico <> 0
           AND ISNULL(Datafono, 0) = 0 AND ISNULL(Vales, 0) = 0
         ORDER BY Codigo'
      );
      if ($st !== false) {
        $c = $st->fetchColumn();
        if ($c !== false && trim((string) $c) !== '') {
          return trim((string) $c);
        }
      }
    } catch (\Throwable $e) {
      // fallthrough
    }
    try {
      $st = $this->pdo->query(
        'SELECT TOP 1 Codigo FROM FormasPago
         WHERE CobroDeArqueo <> 0 AND ISNULL(Agrupacion, 0) = 0
           AND ISNULL(Datafono, 0) = 0 AND ISNULL(Vales, 0) = 0
         ORDER BY Codigo'
      );
      if ($st !== false) {
        $c = $st->fetchColumn();
        if ($c !== false) {
          return trim((string) $c);
        }
      }
    } catch (\Throwable $e) {
      // fallthrough
    }
    return 'EU';
  }
}
