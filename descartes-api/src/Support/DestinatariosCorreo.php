<?php

declare(strict_types=1);

namespace Descartes\Api\Support;

use PHPMailer\PHPMailer\PHPMailer;

/** Varias direcciones en un envío. El límite es el del servidor de correo. */
final class DestinatariosCorreo
{
  /** @return list<string> */
  public static function lista(string $texto): array
  {
    $partes = preg_split('/[;,]+/', $texto) ?: [];
    $out = [];
    foreach ($partes as $parte) {
      $email = trim((string) $parte);
      if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $out[$email] = $email;
      }
    }

    return array_values($out);
  }

  public static function anadir(PHPMailer $mail, string $texto, string $nombre = ''): string
  {
    $lista = self::lista($texto);
    if ($lista === []) {
      throw new \InvalidArgumentException('Indique una dirección de email válida');
    }
    foreach ($lista as $i => $email) {
      $mail->addAddress($email, $i === 0 ? $nombre : '');
    }

    return implode(', ', $lista);
  }
}
