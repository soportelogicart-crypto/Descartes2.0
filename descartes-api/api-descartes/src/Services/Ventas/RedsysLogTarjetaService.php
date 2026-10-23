<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Ventas;

/**
 * Legacy no rellena siempre Autorizaciones; el TPV guarda cada cobro en LOG_TAR.
 * La devolución OPERCOMCONTABLE usa el identificadorRTS del cobro (ver LOG_TAR).
 */
final class RedsysLogTarjetaService
{
  /**
   * @return array<string, mixed>|null
   */
  public function buscarCobro(string $aut, string $clr, ?float $importe = null): ?array
  {
    $aut = trim($aut);
    if ($aut === '') {
      return null;
    }
    $clr = preg_replace('/\D/', '', trim($clr)) ?? '';
    $autCands = $this->candidatosAut($aut);
    foreach ($this->rutasLog() as $path) {
      if (!is_readable($path)) {
        continue;
      }
      $hit = $this->buscarEnArchivo($path, $autCands, $clr, $importe);
      if ($hit !== null) {
        $hit['origen'] = 'log_tar';
        $hit['logArchivo'] = $path;
        return $hit;
      }
    }

    return null;
  }

  /**
   * @param list<string> $autCands
   * @return array<string, mixed>|null
   */
  private function buscarEnArchivo(string $path, array $autCands, string $clr, ?float $importe): ?array
  {
    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
      return null;
    }
    $mejor = null;
    $mejorPuntos = -1;
    foreach ($lines as $line) {
      if (stripos($line, 'RESULTADO=<Operaciones') === false) {
        continue;
      }
      if (stripos($line, 'resultadoOperacion') === false) {
        continue;
      }
      if (!preg_match('/resultado>Autorizada/i', $line)) {
        continue;
      }
      $xml = $this->extraerXml($line);
      if ($xml === '') {
        continue;
      }
      $puntos = $this->puntuarCoincidencia($xml, $autCands, $clr, $importe);
      if ($puntos <= 0) {
        continue;
      }
      if ($puntos > $mejorPuntos) {
        $mejorPuntos = $puntos;
        $mejor = $this->mapXmlCobro($xml);
      }
    }

    return $mejor;
  }

  /**
   * @param list<string> $autCands
   */
  private function puntuarCoincidencia(string $xml, array $autCands, string $clr, ?float $importe): int
  {
    $p = 0;
    $conttrans = $this->tag($xml, 'conttrans');
    $codResp = $this->tag($xml, 'codigoRespuesta');
    $codAut = $this->tag($xml, 'codigoAutorizacion');
    $tarjeta = preg_replace('/\D/', '', $this->tag($xml, 'tarjetaClienteRecibo')) ?? '';
    $imp = (float) str_replace(',', '.', $this->tag($xml, 'importe'));

    foreach ($autCands as $aut) {
      if ($aut !== '' && ($aut === $conttrans || $aut === ltrim($conttrans, '0'))) {
        $p += 40;
      }
      if ($aut !== '' && ($aut === $codAut || $aut === ltrim($codAut, '0'))) {
        $p += 35;
      }
      if ($aut !== '' && ($aut === $codResp || $aut === ltrim($codResp, '0'))) {
        $p += 15;
      }
    }
    if ($clr !== '' && strlen($tarjeta) >= 4 && substr($tarjeta, -4) === substr($clr, -4)) {
      $p += 30;
    }
    if ($importe !== null && abs($importe) > 0.0001 && abs(abs($importe) - $imp) < 0.02) {
      $p += 20;
    }

    return $p;
  }

  /**
   * @return array<string, mixed>
   */
  private function mapXmlCobro(string $xml): array
  {
    $tarjeta = $this->tag($xml, 'tarjetaClienteRecibo');
    $digits = preg_replace('/\D/', '', $tarjeta) ?? '';

    return [
      'pedidoRedsys' => $this->tag($xml, 'pedido'),
      'identificadorRts' => $this->tag($xml, 'identificadorRTS'),
      'autorizacion' => $this->tag($xml, 'conttrans') ?: $this->tag($xml, 'codigoAutorizacion'),
      'clr' => strlen($digits) >= 4 ? substr($digits, -4) : '',
      'importe' => (float) str_replace(',', '.', $this->tag($xml, 'importe')),
      'operacion' => $this->tag($xml, 'pedido'),
      'codigoRespuesta' => $this->tag($xml, 'codigoRespuesta'),
    ];
  }

  private function extraerXml(string $line): string
  {
    $pos = stripos($line, 'RESULTADO=');
    if ($pos === false) {
      return '';
    }
    return trim(substr($line, $pos + strlen('RESULTADO=')));
  }

  private function tag(string $xml, string $name): string
  {
    if (preg_match('/<' . preg_quote($name, '/') . '>([^<]*)<\//i', $xml, $m)) {
      return trim($m[1]);
    }

    return '';
  }

  /**
   * @return list<string>
   */
  private function candidatosAut(string $aut): array
  {
    $c = [trim($aut)];
    $digits = preg_replace('/\D/', '', $aut) ?? '';
    if ($digits !== '') {
      $c[] = $digits;
      $c[] = ltrim($digits, '0');
      $c[] = str_pad(ltrim($digits, '0') ?: '0', 6, '0', STR_PAD_LEFT);
    }

    return array_values(array_unique(array_filter($c)));
  }

  /**
   * @return list<string>
   */
  private function rutasLog(): array
  {
    $out = [];
    $env = trim((string) ($_ENV['REDSYS_LOG_TAR'] ?? getenv('REDSYS_LOG_TAR') ?: ''));
    if ($env !== '') {
      $out[] = $env;
    }
    $desora = trim((string) ($_ENV['DESORA_GENERIC'] ?? getenv('DESORA_GENERIC') ?: 'C:\\desora\\Generica'));
    foreach (glob($desora . '\\*\\LOG_TAR.*') ?: [] as $path) {
      $out[] = $path;
    }
    $out[] = 'C:\\desora\\Generica\\Larasa\\LOG_TAR.001';

    return array_values(array_unique($out));
  }
}
