<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Tpv;

use Descartes\Api\Services\Ventas\VentaConsultaService;
use PDO;

/**
 * Tickets TPV aplazados según la convención legacy:
 * FacturaTipo=R, Estado=B, Sesion=0 y Mesa=-1.
 */
final class TpvTicketEsperaService
{
  private PDO $pdo;
  private VentaConsultaService $ventas;

  public function __construct(PDO $pdo, VentaConsultaService $ventas)
  {
    $this->pdo = $pdo;
    $this->ventas = $ventas;
  }

  /** @return list<array<string, mixed>> */
  public function listar(string $empresa, string $puesto): array
  {
    $this->validarContexto($empresa, $puesto);
    $st = $this->pdo->prepare(
      "SELECT c.Empresa, c.Tipo, c.Albaran, c.Fecha, c.Cliente, c.RazonSocial,
              c.Importe, COUNT(l.NroLin) AS Lineas
       FROM AlbaranesVentasCab c
       LEFT JOIN AlbaranesVentasLin l
         ON l.Empresa = c.Empresa AND l.Tipo = c.Tipo AND l.Albaran = c.Albaran
       WHERE c.Empresa = :empresa
         AND RTRIM(ISNULL(c.Puesto, '')) = :puesto
         AND c.Tipo = 'A'
         AND UPPER(RTRIM(ISNULL(c.FacturaTipo, ''))) = 'R'
         AND UPPER(RTRIM(ISNULL(c.Estado, ''))) = 'B'
         AND ISNULL(c.Factura, 0) = 0
         AND ISNULL(c.Sesion, 0) = 0
         AND ISNULL(c.RebajeStock, 0) = 0
         AND ISNULL(c.Mesa, 0) = -1
       GROUP BY c.Empresa, c.Tipo, c.Albaran, c.Fecha, c.Cliente, c.RazonSocial, c.Importe
       ORDER BY c.Fecha DESC, c.Albaran DESC"
    );
    $st->execute(['empresa' => $empresa, 'puesto' => $puesto]);

    $items = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
      $items[] = [
        'empresa' => trim((string) ($row['Empresa'] ?? '')),
        'tipo' => trim((string) ($row['Tipo'] ?? 'A')),
        'albaran' => (int) ($row['Albaran'] ?? 0),
        'fecha' => $this->fecha($row['Fecha'] ?? null),
        'cliente' => trim((string) ($row['Cliente'] ?? '')),
        'razonSocial' => trim((string) ($row['RazonSocial'] ?? '')),
        'importe' => round((float) ($row['Importe'] ?? 0), 2),
        'lineas' => (int) ($row['Lineas'] ?? 0),
      ];
    }
    return $items;
  }

  /** @return array<string, mixed> */
  public function ponerEnEspera(
    string $empresa,
    string $tipo,
    int $albaran,
    string $puesto
  ): array {
    if ($this->validarDocumento($empresa, $tipo, $albaran, $puesto, false)) {
      return $this->obtener($empresa, $tipo, $albaran);
    }

    $st = $this->pdo->prepare(
      "UPDATE AlbaranesVentasCab
       SET Mesa = -1
       WHERE Empresa = :empresa AND Tipo = :tipo AND Albaran = :albaran
         AND RTRIM(ISNULL(Puesto, '')) = :puesto
         AND ISNULL(Mesa, 0) <> -1
         AND UPPER(RTRIM(ISNULL(FacturaTipo, ''))) = 'R'
         AND UPPER(RTRIM(ISNULL(Estado, ''))) = 'B'
         AND ISNULL(Factura, 0) = 0
         AND ISNULL(Sesion, 0) = 0
         AND ISNULL(RebajeStock, 0) = 0"
    );
    $st->execute(compact('empresa', 'tipo', 'albaran', 'puesto'));
    if ($st->rowCount() !== 1) {
      throw new \RuntimeException('El ticket ha cambiado y no se pudo poner en espera', 409);
    }
    return $this->obtener($empresa, $tipo, $albaran);
  }

  /** @return array<string, mixed> */
  public function recuperar(
    string $empresa,
    string $tipo,
    int $albaran,
    string $puesto
  ): array {
    $this->validarDocumento($empresa, $tipo, $albaran, $puesto, true);

    // Condición Mesa=-1: solo una caja puede ganar una recuperación concurrente.
    $st = $this->pdo->prepare(
      "UPDATE AlbaranesVentasCab
       SET Mesa = 0
       WHERE Empresa = :empresa AND Tipo = :tipo AND Albaran = :albaran
         AND RTRIM(ISNULL(Puesto, '')) = :puesto
         AND ISNULL(Mesa, 0) = -1
         AND UPPER(RTRIM(ISNULL(FacturaTipo, ''))) = 'R'
         AND UPPER(RTRIM(ISNULL(Estado, ''))) = 'B'
         AND ISNULL(Factura, 0) = 0
         AND ISNULL(Sesion, 0) = 0
         AND ISNULL(RebajeStock, 0) = 0"
    );
    $st->execute(compact('empresa', 'tipo', 'albaran', 'puesto'));
    if ($st->rowCount() !== 1) {
      throw new \RuntimeException('Otro puesto ya ha recuperado este ticket', 409);
    }
    return $this->obtener($empresa, $tipo, $albaran);
  }

  private function validarDocumento(
    string $empresa,
    string $tipo,
    int $albaran,
    string $puesto,
    bool $debeEstarEnEspera
  ): bool {
    $this->validarContexto($empresa, $puesto);
    if ($albaran <= 0 || trim($tipo) === '') {
      throw new \InvalidArgumentException('Documento no válido');
    }

    $st = $this->pdo->prepare(
      "SELECT TOP 1
              RTRIM(ISNULL(Puesto, '')) AS Puesto,
              UPPER(RTRIM(ISNULL(FacturaTipo, ''))) AS FacturaTipo,
              UPPER(RTRIM(ISNULL(Estado, ''))) AS Estado,
              ISNULL(Factura, 0) AS Factura,
              ISNULL(Sesion, 0) AS Sesion,
              ISNULL(RebajeStock, 0) AS RebajeStock,
              ISNULL(Mesa, 0) AS Mesa,
              (SELECT COUNT(*) FROM AlbaranesVentasLin l
               WHERE l.Empresa = c.Empresa AND l.Tipo = c.Tipo AND l.Albaran = c.Albaran) AS Lineas
       FROM AlbaranesVentasCab c
       WHERE Empresa = :empresa AND Tipo = :tipo AND Albaran = :albaran"
    );
    $st->execute(compact('empresa', 'tipo', 'albaran'));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
      throw new \RuntimeException('El ticket no existe', 404);
    }
    if (trim((string) $row['Puesto']) !== $puesto) {
      throw new \RuntimeException('El ticket pertenece a otro puesto', 409);
    }
    if (
      (string) $row['FacturaTipo'] !== 'R'
      || (string) $row['Estado'] !== 'B'
      || (int) $row['Factura'] !== 0
      || (int) $row['Sesion'] !== 0
      || (int) $row['RebajeStock'] !== 0
    ) {
      throw new \RuntimeException('El documento ya no es un ticket TPV abierto', 409);
    }
    if ((int) $row['Lineas'] <= 0) {
      throw new \RuntimeException('No se puede poner en espera un ticket sin líneas', 409);
    }

    $enEspera = (int) $row['Mesa'] === -1;
    if ($debeEstarEnEspera && !$enEspera) {
      throw new \RuntimeException('El ticket ya no está en espera', 409);
    }
    return $enEspera;
  }

  private function validarContexto(string $empresa, string $puesto): void
  {
    if (trim($empresa) === '' || trim($puesto) === '') {
      throw new \InvalidArgumentException('Empresa y puesto son obligatorios');
    }
  }

  /** @return array<string, mixed> */
  private function obtener(string $empresa, string $tipo, int $albaran): array
  {
    $venta = $this->ventas->obtenerFicha($empresa, $tipo, $albaran);
    if ($venta === null) {
      throw new \RuntimeException('El ticket no existe', 404);
    }
    return $venta;
  }

  private function fecha(mixed $value): ?string
  {
    if ($value === null || $value === '') {
      return null;
    }
    if ($value instanceof \DateTimeInterface) {
      return $value->format('Y-m-d H:i:s');
    }
    return (string) $value;
  }
}
