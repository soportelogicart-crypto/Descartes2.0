<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Ventas;

use Descartes\Api\Services\UsuarioCorreoService;
use Descartes\Api\Support\SimplePdf;
use PDO;
use PHPMailer\PHPMailer\PHPMailer;

/** Envía por SMTP una venta finalizada con el documento adjunto en PDF. */
final class VentaEmailService
{
  private VentaConsultaService $ventas;
  private PDO $pdo;
  private UsuarioCorreoService $correo;

  public function __construct(VentaConsultaService $ventas, PDO $pdo, UsuarioCorreoService $correo)
  {
    $this->ventas = $ventas;
    $this->pdo = $pdo;
    $this->correo = $correo;
  }

  /** @return array{destinatario: string, documento: string} */
  public function enviar(
    string $empresa,
    string $tipo,
    int $albaran,
    string $destinatario,
    ?string $pdfBase64 = null
  ): array {
    $destinatario = trim($destinatario);
    if (!filter_var($destinatario, FILTER_VALIDATE_EMAIL)) {
      throw new \InvalidArgumentException('Indique una dirección de email válida');
    }

    $venta = $this->ventas->obtener($empresa, $tipo, $albaran);
    if ($venta === null) {
      throw new \InvalidArgumentException('Venta no encontrada');
    }
    if (
      trim((string) ($venta['facturaTipo'] ?? '')) === ''
      && (int) ($venta['sesion'] ?? 0) <= 0
    ) {
      throw new \InvalidArgumentException('La venta debe estar finalizada antes de enviarla');
    }

    $documento = $this->etiquetaDocumento($venta);
    $mail = $this->correo->crearMailer();
    $mail->addAddress($destinatario);
    $this->correo->anadirCopia($mail, $destinatario);
    $mail->Subject = $documento;
    $mail->isHTML(true);
    $mail->Body = $this->html($venta, $documento);
    $mail->AltBody = $documento . "\nImporte: " . $this->euros((float) ($venta['importe'] ?? 0));
    $mail->addStringAttachment(
      $this->pdfAdjunto($pdfBase64) ?? $this->pdf($venta, $documento),
      $this->nombreArchivo($documento) . '.pdf',
      PHPMailer::ENCODING_BASE64,
      'application/pdf'
    );
    $mail->send();
    // El correo ya salió: un esquema legacy sin esta columna no debe provocar
    // un falso error y un posible reenvío duplicado.
    try {
      $this->marcarFacturaEnviada($venta);
    } catch (\Throwable $e) {
      // Marcado auxiliar; el envío SMTP confirmado prevalece.
    }

    return ['destinatario' => $destinatario, 'documento' => $documento];
  }

  /** @param array<string, mixed> $venta */
  private function etiquetaDocumento(array $venta): string
  {
    $clase = strtoupper(trim((string) ($venta['facturaTipo'] ?? '')));
    $numeroFactura = (int) ($venta['factura'] ?? 0);
    $etiquetas = ['T' => 'Ticket', 'F' => 'Factura', 'A' => 'Abono', 'R' => 'Presupuesto'];
    $numero = in_array($clase, ['T', 'F', 'A'], true) && $numeroFactura > 0
      ? $numeroFactura
      : (int) ($venta['albaran'] ?? 0);
    return ($etiquetas[$clase] ?? 'Albarán') . ' ' . $numero;
  }

  /** @param array<string, mixed> $venta */
  private function html(array $venta, string $documento): string
  {
    $filas = '';
    foreach ((array) ($venta['lineas'] ?? []) as $linea) {
      $filas .= sprintf(
        '<tr><td>%s</td><td>%s</td><td style="text-align:right">%s</td><td style="text-align:right">%s</td><td style="text-align:right">%s</td></tr>',
        $this->e((string) ($linea['articulo'] ?? '')),
        $this->e((string) ($linea['descripcion'] ?? '')),
        $this->numero((float) ($linea['cantidad'] ?? 0)),
        $this->euros((float) ($linea['precio'] ?? 0)),
        $this->euros((float) ($linea['importe'] ?? 0))
      );
    }
    return '<div style="font-family:Arial,sans-serif;max-width:720px">'
      . '<h2>' . $this->e($documento) . '</h2>'
      . '<p><strong>Fecha:</strong> ' . $this->e((string) ($venta['fecha'] ?? '')) . '<br>'
      . '<strong>Cliente:</strong> ' . $this->e((string) ($venta['razonSocial'] ?? '')) . '</p>'
      . '<table style="width:100%;border-collapse:collapse" border="1" cellpadding="6">'
      . '<thead><tr><th>Artículo</th><th>Descripción</th><th>Cant.</th><th>Precio</th><th>Importe</th></tr></thead>'
      . '<tbody>' . $filas . '</tbody></table>'
      . '<p style="text-align:right;font-size:1.25em"><strong>Total: '
      . $this->euros((float) ($venta['importe'] ?? 0)) . '</strong></p></div>';
  }

  /** PDF de la plantilla generado en el escritorio. Si no viene, se usa el genérico. */
  private function pdfAdjunto(?string $pdfBase64): ?string
  {
    $b64 = trim((string) $pdfBase64);
    if ($b64 === '') {
      return null;
    }
    $pdf = base64_decode($b64, true);
    if ($pdf === false || !str_starts_with($pdf, '%PDF')) {
      throw new \InvalidArgumentException('El PDF de la plantilla no es válido');
    }
    return $pdf;
  }

  /** @param array<string, mixed> $venta */
  private function pdf(array $venta, string $documento): string
  {
    $pdf = new SimplePdf();
    $pdf->title($documento);
    $pdf->text('Fecha: ' . (string) ($venta['fecha'] ?? ''));
    $pdf->text('Cliente: ' . (string) ($venta['razonSocial'] ?? ''));
    $pdf->spacer();
    $filas = [];
    foreach ((array) ($venta['lineas'] ?? []) as $linea) {
      $filas[] = [
        (string) ($linea['articulo'] ?? ''),
        (string) ($linea['descripcion'] ?? ''),
        $this->numero((float) ($linea['cantidad'] ?? 0)),
        $this->euros((float) ($linea['precio'] ?? 0)),
        $this->euros((float) ($linea['importe'] ?? 0)),
      ];
    }
    $pdf->table(['Artículo', 'Descripción', 'Cant.', 'Precio', 'Importe'], $filas, [80, 225, 55, 70, 80]);
    $pdf->spacer();
    $pdf->text('TOTAL: ' . $this->euros((float) ($venta['importe'] ?? 0)), 13, true);
    return $pdf->build();
  }

  private function e(string $valor): string
  {
    return htmlspecialchars($valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

  private function numero(float $valor): string
  {
    return number_format($valor, 2, ',', '.');
  }

  private function euros(float $valor): string
  {
    return $this->numero($valor) . ' €';
  }

  private function nombreArchivo(string $documento): string
  {
    return preg_replace('/[^A-Za-z0-9_-]+/', '_', $documento) ?: 'documento';
  }

  /** @param array<string, mixed> $venta */
  private function marcarFacturaEnviada(array $venta): void
  {
    $facturaTipo = strtoupper(trim((string) ($venta['facturaTipo'] ?? '')));
    $factura = (int) ($venta['factura'] ?? 0);
    if (!in_array($facturaTipo, ['F', 'A'], true) || $factura <= 0) {
      return;
    }
    $st = $this->pdo->prepare(
      'UPDATE Facturas SET EnviadaPorEmail = 1
       WHERE Empresa = :e AND FacturaTipo = :ft AND Factura = :f'
    );
    $st->execute([
      'e' => trim((string) ($venta['empresa'] ?? '')),
      'ft' => $facturaTipo,
      'f' => $factura,
    ]);
  }
}
