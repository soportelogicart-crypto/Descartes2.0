<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Tpv;

use Descartes\Api\Services\Ventas\ArqueoService;
use PDO;

/**
 * Contexto de caja TPV (006 US1): puesto, tienda, sesión, teclado.
 */
final class TpvContextoService
{
  private PDO $pdo;
  private ArqueoService $arqueo;

  public function __construct(PDO $pdo, ArqueoService $arqueo)
  {
    $this->pdo = $pdo;
    $this->arqueo = $arqueo;
  }

  /**
   * @return array<string, mixed>
   */
  public function resolver(string $empresa, string $puesto): array
  {
    $empresa = trim($empresa);
    $puesto = trim($puesto);
    if ($empresa === '' || $puesto === '') {
      throw new \InvalidArgumentException('Empresa y puesto son obligatorios');
    }

    $stmt = $this->pdo->prepare(
      'SELECT Puesto, Teclado, Tarifa, ImpresoraTickets, ImpresoraTicketsF
       FROM Puestos WHERE RTRIM(Puesto) = :p'
    );
    $stmt->execute(['p' => $puesto]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
      throw new \InvalidArgumentException("No existe el puesto {$puesto}");
    }

    $tecladoCodigo = (int) ($row['Teclado'] ?? 0);
    if ($tecladoCodigo <= 0) {
      throw new \InvalidArgumentException("El puesto {$puesto} no tiene teclado táctil configurado");
    }

    $tecladoGeneral = str_pad((string) $tecladoCodigo, 3, '0', STR_PAD_LEFT);
    $ctxSesion = $this->arqueo->asegurarSesionPuesto($puesto, $empresa);

    $impresora = trim((string) ($row['ImpresoraTickets'] ?? ''));
    if ($impresora === '') {
      $impresora = trim((string) ($row['ImpresoraTicketsF'] ?? ''));
    }

    return [
      'empresa' => $ctxSesion['empresa'] !== '' ? $ctxSesion['empresa'] : $empresa,
      'puesto' => $puesto,
      'sesion' => (int) $ctxSesion['sesion'],
      'tecladoCodigo' => $tecladoCodigo,
      'tecladoGeneral' => $tecladoGeneral,
      'tarifa' => (int) ($row['Tarifa'] ?? 0),
      'impresoraTickets' => $impresora !== '' ? $impresora : null,
      'formatoTickets' => null,
      'vendedor' => $this->vendedorDelPuesto($puesto),
      'clienteRapido' => 'ZZZZZZZZZ',
      'formasPago' => $this->formasPagoContado(),
    ];
  }

  private function vendedorDelPuesto(string $puesto): ?string
  {
    try {
      $stmt = $this->pdo->prepare(
        'SELECT RTRIM(Trabajador) FROM Puestos WHERE RTRIM(Puesto) = :p'
      );
      $stmt->execute(['p' => $puesto]);
      $codigo = trim((string) ($stmt->fetchColumn() ?: ''));
      if ($codigo !== '') {
        return $codigo;
      }
    } catch (\Throwable $e) {
      /* columna Trabajador puede no existir */
    }
    $usuario = trim((string) ($_SESSION['usuario']['codigo'] ?? ''));
    if ($usuario === '') {
      return null;
    }
    try {
      $stmt = $this->pdo->prepare(
        'SELECT TOP 1 RTRIM(Codigo) FROM Vendedores
         WHERE RTRIM(Usuario) = :u AND ISNULL(Baja, 0) = 0
         ORDER BY CASE WHEN RTRIM(Codigo) = :mismo THEN 0 ELSE 1 END, Codigo'
      );
      $stmt->execute(['u' => $usuario, 'mismo' => $usuario]);
      $codigo = trim((string) ($stmt->fetchColumn() ?: ''));
      return $codigo !== '' ? $codigo : null;
    } catch (\Throwable $e) {
      return null;
    }
  }

  /**
   * Formas de pago de contado (legacy FrmVenta: CobroDeArqueo).
   * Van en el contexto para que la caja no dependa del permiso de mantenimiento.
   *
   * @return list<array<string, mixed>>
   */
  private function formasPagoContado(): array
  {
    $stmt = $this->pdo->query(
      'SELECT Codigo, Descripcion, Abreviacion, AbrirCajon, CopiasTicket
       FROM FormasPago
       WHERE ISNULL(CobroDeArqueo, 0) = 1 AND ISNULL(Baja, 0) = 0
       ORDER BY ISNULL(OrdenAparicionVenta, 999), Codigo'
    );

    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
      $codigo = trim((string) ($row['Codigo'] ?? ''));
      if ($codigo === '') {
        continue;
      }
      $etiqueta = trim((string) ($row['Abreviacion'] ?? ''));
      if ($etiqueta === '') {
        $etiqueta = trim((string) ($row['Descripcion'] ?? $codigo));
      }
      $items[] = [
        'codigo' => $codigo,
        'descripcion' => trim((string) ($row['Descripcion'] ?? $codigo)),
        'etiqueta' => $etiqueta,
        'abrirCajon' => (int) ($row['AbrirCajon'] ?? 0) === 1,
        'copiasTicket' => (int) ($row['CopiasTicket'] ?? 0),
      ];
    }

    return $items;
  }
}
