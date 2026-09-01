<?php

declare(strict_types=1);

/**
 * Smoke del envío SMTP (006 T044f).
 * Comprueba la configuración MAIL_* del .env sin necesidad de cerrar una venta.
 * Uso: php scripts/smoke-email.php <destinatario>
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

use PHPMailer\PHPMailer\PHPMailer;

$destinatario = trim((string) ($argv[1] ?? ''));

if (!filter_var($destinatario, FILTER_VALIDATE_EMAIL)) {
  echo "Uso: php scripts/smoke-email.php <destinatario>\n";
  exit(1);
}

$host = trim((string) ($_ENV['MAIL_HOST'] ?? ''));
$from = trim((string) ($_ENV['MAIL_FROM_ADDRESS'] ?? ''));
$usuario = trim((string) ($_ENV['MAIL_USERNAME'] ?? ''));
$puerto = max(1, (int) ($_ENV['MAIL_PORT'] ?? 587));
$seguridad = strtolower(trim((string) ($_ENV['MAIL_ENCRYPTION'] ?? 'tls')));

echo "Host: " . ($host !== '' ? $host : '(vacío)') . "\n";
echo "Puerto: {$puerto}\n";
echo "Cifrado: " . ($seguridad !== '' ? $seguridad : '(ninguno)') . "\n";
echo "Remitente: " . ($from !== '' ? $from : '(vacío)') . "\n";
echo "Usuario: " . ($usuario !== '' ? $usuario : '(sin autenticación)') . "\n";
echo "Contraseña: " . (($_ENV['MAIL_PASSWORD'] ?? '') !== '' ? 'definida' : '(vacía)') . "\n\n";

if ($host === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
  echo "ERROR: defina MAIL_HOST y MAIL_FROM_ADDRESS en descartes-api/.env\n";
  exit(1);
}

try {
  $mail = new PHPMailer(true);
  $mail->CharSet = PHPMailer::CHARSET_UTF8;
  $mail->SMTPDebug = 2;
  $mail->Debugoutput = 'echo';
  $mail->isSMTP();
  $mail->Host = $host;
  $mail->Port = $puerto;
  $mail->SMTPAuth = $usuario !== '';
  if ($mail->SMTPAuth) {
    $mail->Username = $usuario;
    $mail->Password = (string) ($_ENV['MAIL_PASSWORD'] ?? '');
  }
  if ($seguridad === 'tls' || $seguridad === 'ssl') {
    $mail->SMTPSecure = $seguridad;
  } else {
    $mail->SMTPAutoTLS = false;
  }
  $mail->setFrom($from, trim((string) ($_ENV['MAIL_FROM_NAME'] ?? 'Descartes')));
  $mail->addAddress($destinatario);
  $mail->Subject = 'Prueba de configuración SMTP - Descartes';
  $mail->isHTML(true);
  $mail->Body = '<p>Si recibe este mensaje, el envío de documentos por email está operativo.</p>';
  $mail->AltBody = 'Si recibe este mensaje, el envío de documentos por email está operativo.';
  $mail->send();
  echo "\nOK: correo enviado a {$destinatario}\n";
} catch (\Throwable $e) {
  echo "\nERROR: " . $e->getMessage() . "\n";
  exit(1);
}
