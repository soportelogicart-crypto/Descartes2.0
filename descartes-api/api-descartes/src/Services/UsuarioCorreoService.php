<?php

declare(strict_types=1);

namespace Descartes\Api\Services;

use PDO;
use PHPMailer\PHPMailer\PHPMailer;

/** SMTP del usuario de la sesión. Si no tiene uno activo, se usa el .env. */
final class UsuarioCorreoService
{
  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  /** @return array<string, mixed> */
  public function obtener(string $usuario): array
  {
    $row = $this->fila($usuario);
    if ($row === null) {
      return $this->vacio();
    }
    return $this->publico($row);
  }

  /**
   * @param array<string, mixed> $body
   * @return array<string, mixed>
   */
  public function guardar(string $usuario, array $body): array
  {
    $usuario = $this->codigo($usuario);
    if ($usuario === '') {
      throw new \InvalidArgumentException('Sesión sin usuario');
    }

    $activo = !empty($body['activo']);
    $servidor = trim((string) ($body['servidor'] ?? ''));
    $puerto = (int) ($body['puerto'] ?? 25);
    $ssl = !empty($body['ssl']);
    $startTls = !empty($body['startTls']);
    $usuarioSmtp = trim((string) ($body['usuarioSmtp'] ?? ''));
    $claveNueva = (string) ($body['clave'] ?? '');
    $nombre = trim((string) ($body['nombreRemitente'] ?? ''));
    $email = trim((string) ($body['emailRemitente'] ?? ''));
    $copia = trim((string) ($body['copia'] ?? ''));

    if ($puerto < 1 || $puerto > 65535) {
      throw new \InvalidArgumentException('El puerto SMTP no es válido');
    }
    if ($ssl && $startTls) {
      throw new \InvalidArgumentException('Elija SSL o STARTTLS, no los dos');
    }
    if ($activo) {
      if ($servidor === '') {
        throw new \InvalidArgumentException('Indique el servidor SMTP');
      }
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new \InvalidArgumentException('El email del remitente no es válido');
      }
      if ($usuarioSmtp !== '' && $claveNueva === '' && !$this->tieneClave($usuario)) {
        throw new \InvalidArgumentException('Indique la contraseña del correo');
      }
    }
    if ($copia !== '' && !filter_var($copia, FILTER_VALIDATE_EMAIL)) {
      throw new \InvalidArgumentException('La copia no es un email válido');
    }

    $params = [
      'usuario' => $usuario,
      'activo' => $activo ? 1 : 0,
      'servidor' => mb_substr($servidor, 0, 200),
      'puerto' => $puerto,
      'ssl' => $ssl ? 1 : 0,
      'startTls' => $startTls ? 1 : 0,
      'usuarioSmtp' => mb_substr($usuarioSmtp, 0, 200),
      'nombre' => mb_substr($nombre, 0, 200),
      'email' => mb_substr($email, 0, 200),
      'copia' => mb_substr($copia, 0, 200),
    ];

    $existe = $this->fila($usuario) !== null;
    if ($existe) {
      $sql = 'UPDATE UsuarioCorreo SET
          Activo = :activo, Servidor = :servidor, Puerto = :puerto, Ssl = :ssl, StartTls = :startTls,
          UsuarioSmtp = :usuarioSmtp, NombreRemitente = :nombre, EmailRemitente = :email,
          Copia = :copia, Actualizado = GETDATE()
        WHERE Usuario = :usuario';
      if ($claveNueva !== '') {
        $sql = 'UPDATE UsuarioCorreo SET
            Activo = :activo, Servidor = :servidor, Puerto = :puerto, Ssl = :ssl, StartTls = :startTls,
            UsuarioSmtp = :usuarioSmtp, Clave = :clave, NombreRemitente = :nombre, EmailRemitente = :email,
            Copia = :copia, Actualizado = GETDATE()
          WHERE Usuario = :usuario';
        $params['clave'] = mb_substr($claveNueva, 0, 200);
      }
      $st = $this->pdo->prepare($sql);
      $st->execute($params);
    } else {
      $st = $this->pdo->prepare(
        'INSERT INTO UsuarioCorreo (
           Usuario, Activo, Servidor, Puerto, Ssl, StartTls, UsuarioSmtp, Clave,
           NombreRemitente, EmailRemitente, Copia, Actualizado
         ) VALUES (
           :usuario, :activo, :servidor, :puerto, :ssl, :startTls, :usuarioSmtp, :clave,
           :nombre, :email, :copia, GETDATE()
         )'
      );
      $params['clave'] = mb_substr($claveNueva, 0, 200);
      $st->execute($params);
    }

    return $this->obtener($usuario);
  }

  /**
   * Conecta con el SMTP del formulario y envía un correo de prueba al remitente.
   *
   * @param array<string, mixed> $body
   * @return array{ok: bool, mensaje: string}
   */
  public function probar(string $usuario, array $body): array
  {
    $cfg = $this->configDesdeFormulario($usuario, $body);
    $mail = $this->mailer($cfg);
    $mail->Timeout = 20;
    $destino = $cfg['fromEmail'];
    try {
      $mail->addAddress($destino);
      $mail->Subject = 'Prueba de correo - Descartes';
      $mail->isHTML(true);
      $mail->Body = '<p>La conexión SMTP es correcta. Descartes puede enviar documentos por email.</p>';
      $mail->AltBody = 'La conexión SMTP es correcta. Descartes puede enviar documentos por email.';
      if (!$mail->send()) {
        throw new \RuntimeException(trim($mail->ErrorInfo) ?: 'El servidor no ha aceptado el correo');
      }
    } catch (\Throwable $e) {
      $detalle = trim($e->getMessage());
      if ($detalle === '') {
        $detalle = trim($mail->ErrorInfo);
      }
      if ($detalle === '') {
        $detalle = 'No se ha podido enviar el correo. Revise el servidor, el puerto y la contraseña.';
      }
      throw new \RuntimeException('No se ha enviado el correo: ' . $detalle);
    }

    return [
      'ok' => true,
      'mensaje' => 'Conexión correcta. Se ha enviado un correo de prueba a ' . $destino . '.',
    ];
  }

  public function errorConfiguracion(): ?string
  {
    if ($this->configUsuario() !== null || $this->configEntorno() !== null) {
      return null;
    }
    return 'Correo no configurado: indique el SMTP en Configuración → Correo';
  }

  public function crearMailer(): PHPMailer
  {
    $cfg = $this->configUsuario() ?? $this->configEntorno();
    if ($cfg === null) {
      throw new \RuntimeException((string) $this->errorConfiguracion());
    }
    return $this->mailer($cfg);
  }

  /** @param array<string, mixed> $cfg */
  private function mailer(array $cfg): PHPMailer
  {
    $mail = new PHPMailer(true);
    $mail->CharSet = PHPMailer::CHARSET_UTF8;
    $mail->isSMTP();
    $mail->Host = $cfg['host'];
    $mail->Port = $cfg['port'];
    $mail->SMTPAuth = $cfg['usuario'] !== '';
    if ($mail->SMTPAuth) {
      $mail->Username = $cfg['usuario'];
      $mail->Password = $cfg['clave'];
    }
    if ($cfg['ssl']) {
      $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } elseif ($cfg['startTls']) {
      $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } else {
      $mail->SMTPAutoTLS = false;
    }
    $mail->setFrom($cfg['fromEmail'], $cfg['fromName'] !== '' ? $cfg['fromName'] : $cfg['fromEmail']);
    return $mail;
  }

  /**
   * @param array<string, mixed> $body
   * @return array<string, mixed>
   */
  private function configDesdeFormulario(string $usuario, array $body): array
  {
    $servidor = trim((string) ($body['servidor'] ?? ''));
    $puerto = (int) ($body['puerto'] ?? 25);
    $email = trim((string) ($body['emailRemitente'] ?? ''));
    $usuarioSmtp = trim((string) ($body['usuarioSmtp'] ?? ''));
    $clave = (string) ($body['clave'] ?? '');
    if ($clave === '') {
      $row = $this->fila($usuario);
      $clave = $row !== null ? (string) ($row['Clave'] ?? '') : '';
    }
    if ($servidor === '') {
      throw new \InvalidArgumentException('Indique el servidor SMTP');
    }
    if ($puerto < 1 || $puerto > 65535) {
      throw new \InvalidArgumentException('El puerto SMTP no es válido');
    }
    if (!empty($body['ssl']) && !empty($body['startTls'])) {
      throw new \InvalidArgumentException('Elija SSL o STARTTLS, no los dos');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      throw new \InvalidArgumentException('El email del remitente no es válido');
    }
    if ($usuarioSmtp !== '' && $clave === '') {
      throw new \InvalidArgumentException('Indique la contraseña del correo');
    }
    return [
      'host' => $servidor,
      'port' => $puerto,
      'usuario' => $usuarioSmtp,
      'clave' => $clave,
      'ssl' => !empty($body['ssl']),
      'startTls' => !empty($body['startTls']),
      'fromEmail' => $email,
      'fromName' => trim((string) ($body['nombreRemitente'] ?? '')),
      'copia' => '',
    ];
  }

  public function anadirCopia(PHPMailer $mail, string $destinatario): void
  {
    $cfg = $this->configUsuario();
    if ($cfg === null) {
      return;
    }
    $copia = $cfg['copia'];
    if ($copia === '' || strcasecmp($copia, trim($destinatario)) === 0) {
      return;
    }
    $mail->addCC($copia);
  }

  /** @return array<string, mixed> */
  private function vacio(): array
  {
    return [
      'activo' => false,
      'servidor' => '',
      'puerto' => 25,
      'ssl' => false,
      'startTls' => false,
      'usuarioSmtp' => '',
      'claveConfigurada' => false,
      'nombreRemitente' => '',
      'emailRemitente' => '',
      'copia' => '',
    ];
  }

  /** @param array<string, mixed> $row @return array<string, mixed> */
  private function publico(array $row): array
  {
    return [
      'activo' => !empty($row['Activo']),
      'servidor' => trim((string) ($row['Servidor'] ?? '')),
      'puerto' => (int) ($row['Puerto'] ?? 25),
      'ssl' => !empty($row['Ssl']),
      'startTls' => !empty($row['StartTls']),
      'usuarioSmtp' => trim((string) ($row['UsuarioSmtp'] ?? '')),
      'claveConfigurada' => trim((string) ($row['Clave'] ?? '')) !== '',
      'nombreRemitente' => trim((string) ($row['NombreRemitente'] ?? '')),
      'emailRemitente' => trim((string) ($row['EmailRemitente'] ?? '')),
      'copia' => trim((string) ($row['Copia'] ?? '')),
    ];
  }

  /** @return array<string, mixed>|null */
  private function configUsuario(): ?array
  {
    $usuario = trim((string) ($_SESSION['usuario']['codigo'] ?? ''));
    $row = $usuario === '' ? null : $this->fila($usuario);
    if ($row === null || empty($row['Activo'])) {
      return null;
    }
    $host = trim((string) ($row['Servidor'] ?? ''));
    $from = trim((string) ($row['EmailRemitente'] ?? ''));
    if ($host === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
      return null;
    }
    return [
      'host' => $host,
      'port' => max(1, (int) ($row['Puerto'] ?? 25)),
      'usuario' => trim((string) ($row['UsuarioSmtp'] ?? '')),
      'clave' => (string) ($row['Clave'] ?? ''),
      'ssl' => !empty($row['Ssl']),
      'startTls' => !empty($row['StartTls']),
      'fromEmail' => $from,
      'fromName' => trim((string) ($row['NombreRemitente'] ?? '')),
      'copia' => trim((string) ($row['Copia'] ?? '')),
    ];
  }

  /** @return array<string, mixed>|null */
  private function configEntorno(): ?array
  {
    $host = trim((string) ($_ENV['MAIL_HOST'] ?? ''));
    $from = trim((string) ($_ENV['MAIL_FROM_ADDRESS'] ?? ''));
    if ($host === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
      return null;
    }
    $seguridad = strtolower(trim((string) ($_ENV['MAIL_ENCRYPTION'] ?? 'tls')));
    return [
      'host' => $host,
      'port' => max(1, (int) ($_ENV['MAIL_PORT'] ?? 587)),
      'usuario' => trim((string) ($_ENV['MAIL_USERNAME'] ?? '')),
      'clave' => (string) ($_ENV['MAIL_PASSWORD'] ?? ''),
      'ssl' => $seguridad === 'ssl',
      'startTls' => $seguridad === 'tls',
      'fromEmail' => $from,
      'fromName' => trim((string) ($_ENV['MAIL_FROM_NAME'] ?? 'Descartes')),
      'copia' => '',
    ];
  }

  /** @return array<string, mixed>|null */
  private function fila(string $usuario): ?array
  {
    $usuario = $this->codigo($usuario);
    if ($usuario === '') {
      return null;
    }
    try {
      $st = $this->pdo->prepare(
        'SELECT TOP 1 Activo, Servidor, Puerto, Ssl, StartTls, UsuarioSmtp, Clave,
                NombreRemitente, EmailRemitente, Copia
         FROM UsuarioCorreo WHERE Usuario = :usuario'
      );
      $st->execute(['usuario' => $usuario]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      return $row === false ? null : $row;
    } catch (\Throwable $e) {
      return null;
    }
  }

  private function tieneClave(string $usuario): bool
  {
    $row = $this->fila($usuario);
    return $row !== null && trim((string) ($row['Clave'] ?? '')) !== '';
  }

  private function codigo(string $usuario): string
  {
    return mb_substr(trim($usuario), 0, 20);
  }
}
