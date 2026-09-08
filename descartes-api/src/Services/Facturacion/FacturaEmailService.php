<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Facturacion;

use PDO;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Envía por SMTP las facturas generadas automáticamente.
 *
 * Solo procesa clientes con Clientes.FacturasEmail activo. El destinatario es
 * EmailFacturacion y, si está vacío, Email. Los errores son individuales: una
 * factura ya creada nunca se revierte porque falle su correo.
 */
final class FacturaEmailService
{
  private PDO $pdo;
  private ImpresionFacturasService $impresion;

  public function __construct(PDO $pdo, ImpresionFacturasService $impresion)
  {
    $this->pdo = $pdo;
    $this->impresion = $impresion;
  }

  /**
   * @param list<array<string, mixed>> $facturas
   * @return array{
   *   candidatas: int,
   *   enviadas: int,
   *   omitidas: int,
   *   errores: int,
   *   detalles: list<array<string, mixed>>
   * }
   */
  public function enviarGeneradas(array $facturas): array
  {
    $resultado = [
      'candidatas' => count($facturas),
      'enviadas' => 0,
      'omitidas' => 0,
      'errores' => 0,
      'detalles' => [],
    ];

    foreach ($facturas as $factura) {
      $empresa = trim((string) ($factura['empresa'] ?? ''));
      $tipo = strtoupper(trim((string) ($factura['facturaTipo'] ?? '')));
      $numero = (int) ($factura['factura'] ?? 0);
      $cliente = trim((string) ($factura['cliente'] ?? ''));
      $base = [
        'empresa' => $empresa,
        'facturaTipo' => $tipo,
        'factura' => $numero,
        'cliente' => $cliente,
      ];

      if ($empresa === '' || !in_array($tipo, ['F', 'A'], true) || $numero <= 0) {
        $resultado['omitidas']++;
        $resultado['detalles'][] = $base + [
          'estado' => 'omitida',
          'motivo' => 'Documento no enviable',
        ];
        continue;
      }

      $datosCliente = $this->datosCliente($cliente);
      if ($datosCliente === null || !$datosCliente['facturasEmail']) {
        $resultado['omitidas']++;
        $resultado['detalles'][] = $base + [
          'estado' => 'omitida',
          'motivo' => 'Cliente no configurado para recibir facturas por email',
        ];
        continue;
      }

      $destinatario = $datosCliente['email'];
      if (!filter_var($destinatario, FILTER_VALIDATE_EMAIL)) {
        $resultado['errores']++;
        $resultado['detalles'][] = $base + [
          'estado' => 'error',
          'destinatario' => $destinatario,
          'motivo' => 'El cliente no tiene un email de facturación válido',
        ];
        continue;
      }

      $errorConfiguracion = $this->errorConfiguracionCorreo();
      if ($errorConfiguracion !== null) {
        $resultado['errores']++;
        $resultado['detalles'][] = $base + [
          'estado' => 'error',
          'destinatario' => $destinatario,
          'motivo' => $errorConfiguracion,
        ];
        continue;
      }

      try {
        $this->enviarUna(
          $empresa,
          $tipo,
          $numero,
          $destinatario,
          (string) $datosCliente['razonSocial']
        );
        $resultado['enviadas']++;
        $resultado['detalles'][] = $base + [
          'estado' => 'enviada',
          'destinatario' => $destinatario,
        ];
      } catch (\Throwable $e) {
        $resultado['errores']++;
        $resultado['detalles'][] = $base + [
          'estado' => 'error',
          'destinatario' => $destinatario,
          'motivo' => $e->getMessage(),
        ];
      }
    }

    return $resultado;
  }

  /**
   * @return array{facturasEmail: bool, email: string, razonSocial: string}|null
   */
  private function datosCliente(string $codigo): ?array
  {
    if ($codigo === '') {
      return null;
    }
    $stmt = $this->pdo->prepare(
      "SELECT TOP 1
          ISNULL(FacturasEmail, 0) AS FacturasEmail,
          ISNULL(
            NULLIF(LTRIM(RTRIM(EmailFacturacion)), ''),
            LTRIM(RTRIM(ISNULL(Email, '')))
          ) AS EmailDestino,
          RTRIM(ISNULL(RazonSocial, '')) AS RazonSocial
       FROM Clientes
       WHERE Codigo = :codigo"
    );
    $stmt->execute(['codigo' => $codigo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      return null;
    }
    return [
      'facturasEmail' => !empty($row['FacturasEmail']),
      'email' => trim((string) ($row['EmailDestino'] ?? '')),
      'razonSocial' => trim((string) ($row['RazonSocial'] ?? '')),
    ];
  }

  private function enviarUna(
    string $empresa,
    string $tipo,
    int $numero,
    string $destinatario,
    string $razonSocial
  ): void {
    $etiqueta = $tipo === 'A' ? 'Factura rectificativa' : 'Factura';
    $referencia = "{$etiqueta} {$numero}";
    $pdf = $this->impresion->informePdf([
      'facturas' => [[
        'empresa' => $empresa,
        'facturaTipo' => $tipo,
        'factura' => $numero,
      ]],
      'marcarImpresa' => false,
    ]);

    $mail = $this->crearMailer();
    $mail->addAddress($destinatario, $razonSocial);
    $mail->Subject = $referencia;
    $mail->isHTML(true);
    $mail->Body = '<div style="font-family:Arial,sans-serif">'
      . '<p>Adjuntamos su ' . htmlspecialchars(strtolower($etiqueta), ENT_QUOTES, 'UTF-8')
      . '.</p><p>Documento: <strong>' . htmlspecialchars($referencia, ENT_QUOTES, 'UTF-8')
      . '</strong></p></div>';
    $mail->AltBody = "Adjuntamos su {$referencia}.";
    $mail->addStringAttachment(
      $pdf,
      $this->nombreArchivo($referencia) . '.pdf',
      PHPMailer::ENCODING_BASE64,
      'application/pdf'
    );
    $mail->send();

    // El SMTP ya confirmó el envío. Un esquema legacy sin EnviadaPorEmail no
    // debe convertirlo en error ni provocar un reenvío dentro de este lote.
    try {
      $this->marcarEnviada($empresa, $tipo, $numero);
    } catch (\Throwable $e) {
      // Marcado auxiliar: el envío confirmado prevalece.
    }
  }

  private function crearMailer(): PHPMailer
  {
    $host = trim((string) ($_ENV['MAIL_HOST'] ?? ''));
    $from = trim((string) ($_ENV['MAIL_FROM_ADDRESS'] ?? ''));

    $mail = new PHPMailer(true);
    $mail->CharSet = PHPMailer::CHARSET_UTF8;
    $mail->isSMTP();
    $mail->Host = $host;
    $mail->Port = max(1, (int) ($_ENV['MAIL_PORT'] ?? 587));
    $usuario = trim((string) ($_ENV['MAIL_USERNAME'] ?? ''));
    $mail->SMTPAuth = $usuario !== '';
    if ($mail->SMTPAuth) {
      $mail->Username = $usuario;
      $mail->Password = (string) ($_ENV['MAIL_PASSWORD'] ?? '');
    }
    $seguridad = strtolower(trim((string) ($_ENV['MAIL_ENCRYPTION'] ?? 'tls')));
    if ($seguridad === 'tls' || $seguridad === 'ssl') {
      $mail->SMTPSecure = $seguridad;
    } else {
      $mail->SMTPAutoTLS = false;
    }
    $mail->setFrom($from, trim((string) ($_ENV['MAIL_FROM_NAME'] ?? 'Descartes')));
    return $mail;
  }

  private function errorConfiguracionCorreo(): ?string
  {
    $host = trim((string) ($_ENV['MAIL_HOST'] ?? ''));
    $from = trim((string) ($_ENV['MAIL_FROM_ADDRESS'] ?? ''));
    if ($host === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
      return 'Correo no configurado: defina MAIL_HOST y MAIL_FROM_ADDRESS en descartes-api/.env';
    }
    return null;
  }

  private function marcarEnviada(string $empresa, string $tipo, int $numero): void
  {
    $stmt = $this->pdo->prepare(
      'UPDATE Facturas SET EnviadaPorEmail = 1
       WHERE Empresa = :empresa AND FacturaTipo = :tipo AND Factura = :factura'
    );
    $stmt->execute([
      'empresa' => $empresa,
      'tipo' => $tipo,
      'factura' => $numero,
    ]);
  }

  private function nombreArchivo(string $nombre): string
  {
    return preg_replace('/[^A-Za-z0-9_-]+/', '_', $nombre) ?: 'factura';
  }
}
