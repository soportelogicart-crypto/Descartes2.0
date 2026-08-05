<?php

declare(strict_types=1);

namespace Descartes\Api\Services;

use Descartes\Api\Repositories\EmpresaClienteRepository;

final class EmpresaClienteService
{
  private EmpresaClienteRepository $repository;

  public function __construct(EmpresaClienteRepository $repository)
  {
    $this->repository = $repository;
  }

  public function obtener(): ?array
  {
    $row = $this->repository->findCentral();
    if ($row === null) {
      return null;
    }

    return $this->mapRowToApi($row);
  }

  public function actualizar(array $data): array
  {
    $actual = $this->repository->findCentral();
    if ($actual === null) {
      throw new \RuntimeException('No existe fila central (Central=1)');
    }

    $this->validar($data);

    if ($this->tieneFacturasEmitidas($actual)) {
      $this->validarBloqueoFiscal($actual, $data);
    }

    $columns = $this->mapApiToColumns($data);
    $this->repository->updateCentral($columns);

    return $this->obtener() ?? $data;
  }

  private function mapRowToApi(array $row): array
  {
    $territorio = trim((string) ($row['TicketSI_Territorio'] ?? ''));

    return [
      'codigo' => $row['Codigo'] ?? null,
      'nif' => $row['NIF'] ?? null,
      'razonSocial' => $row['NombreFiscal'] ?: ($row['Nombre'] ?? null),
      'direccion' => $row['Direccion'] ?? null,
      'poblacion' => $row['Poblacion'] ?? null,
      'codigoPostal' => $row['CodigoPostal'] ?? null,
      'provincia' => $row['Provincia'] ?? null,
      'pais' => $row['Pais'] ?? null,
      'email' => $row['EMail'] ?? null,
      'divisa' => $row['Divisa'] ?? null,
      'regimenFiscal' => $this->deriveRegimenFiscal($territorio),
      'ticketSITerritorio' => $territorio !== '' ? $territorio : null,
      'ticketSICertificado' => $row['TicketSI_Certificado'] ?? null,
      'facturaLaCentral' => (bool) ($row['FacturaLaCentral'] ?? false),
      'contadores' => [
        'ultFactura' => (int) ($row['UltFactura'] ?? 0),
        'ultTicket' => (int) ($row['UltTicket'] ?? 0),
        'ultAlbaranVen' => (int) ($row['UltAlbaranVen'] ?? 0),
      ],
    ];
  }

  private function mapApiToColumns(array $data): array
  {
    $columns = [];

    if (array_key_exists('nif', $data)) {
      $columns['NIF'] = trim((string) $data['nif']);
    }
    if (array_key_exists('razonSocial', $data)) {
      $columns['NombreFiscal'] = trim((string) $data['razonSocial']);
      $columns['Nombre'] = trim((string) $data['razonSocial']);
    }
    if (array_key_exists('direccion', $data)) {
      $columns['Direccion'] = $data['direccion'];
    }
    if (array_key_exists('poblacion', $data)) {
      $columns['Poblacion'] = $data['poblacion'];
    }
    if (array_key_exists('codigoPostal', $data)) {
      $columns['CodigoPostal'] = $data['codigoPostal'];
    }
    if (array_key_exists('provincia', $data)) {
      $columns['Provincia'] = $data['provincia'];
    }
    if (array_key_exists('pais', $data)) {
      $columns['Pais'] = $data['pais'];
    }
    if (array_key_exists('email', $data)) {
      $columns['EMail'] = $data['email'];
    }
    if (array_key_exists('divisa', $data)) {
      $columns['Divisa'] = $data['divisa'];
    }
    if (array_key_exists('facturaLaCentral', $data)) {
      $columns['FacturaLaCentral'] = $data['facturaLaCentral'] ? 1 : 0;
    }
    if (array_key_exists('ticketSICertificado', $data)) {
      $columns['TicketSI_Certificado'] = $data['ticketSICertificado'];
    }

    if (array_key_exists('regimenFiscal', $data) || array_key_exists('ticketSITerritorio', $data)) {
      $regimen = $data['regimenFiscal'] ?? $this->deriveRegimenFiscal((string) ($data['ticketSITerritorio'] ?? ''));
      if ($regimen === 'ticketbai') {
        $territorio = trim((string) ($data['ticketSITerritorio'] ?? ''));
        $columns['TicketSI_Territorio'] = $territorio !== '' ? $territorio : null;
      } else {
        $columns['TicketSI_Territorio'] = null;
      }
    }

    if (isset($data['contadores']) && is_array($data['contadores'])) {
      if (array_key_exists('ultFactura', $data['contadores'])) {
        $columns['UltFactura'] = (int) $data['contadores']['ultFactura'];
      }
      if (array_key_exists('ultTicket', $data['contadores'])) {
        $columns['UltTicket'] = (int) $data['contadores']['ultTicket'];
      }
      if (array_key_exists('ultAlbaranVen', $data['contadores'])) {
        $columns['UltAlbaranVen'] = (int) $data['contadores']['ultAlbaranVen'];
      }
    }

    return $columns;
  }

  private function deriveRegimenFiscal(string $territorio): string
  {
    return $territorio !== '' ? 'ticketbai' : 'comun';
  }

  private function validar(array $data): void
  {
    if (isset($data['nif']) && trim((string) $data['nif']) === '') {
      throw new \InvalidArgumentException('El NIF es obligatorio');
    }

    if (isset($data['razonSocial']) && trim((string) $data['razonSocial']) === '') {
      throw new \InvalidArgumentException('La razon social es obligatoria');
    }

    $regimen = $data['regimenFiscal'] ?? null;
    if ($regimen === 'ticketbai') {
      $territorio = trim((string) ($data['ticketSITerritorio'] ?? ''));
      if ($territorio === '') {
        throw new \InvalidArgumentException('El territorio TicketBAI es obligatorio con regimen País Vasco');
      }
    }
  }

  private function tieneFacturasEmitidas(array $row): bool
  {
    if ((int) ($row['UltFactura'] ?? 0) > 0) {
      return true;
    }

    return $this->repository->countFacturasEmitidas() > 0;
  }

  private function validarBloqueoFiscal(array $actual, array $data): void
  {
    $camposBloqueados = [];

    if (isset($data['nif']) && trim((string) $data['nif']) !== trim((string) ($actual['NIF'] ?? ''))) {
      $camposBloqueados[] = 'nif';
    }

    $territorioActual = trim((string) ($actual['TicketSI_Territorio'] ?? ''));
    $territorioNuevo = array_key_exists('ticketSITerritorio', $data)
      ? trim((string) ($data['ticketSITerritorio'] ?? ''))
      : $territorioActual;

    if (isset($data['regimenFiscal']) || array_key_exists('ticketSITerritorio', $data)) {
      $regimenActual = $this->deriveRegimenFiscal($territorioActual);
      $regimenNuevo = $data['regimenFiscal'] ?? $this->deriveRegimenFiscal($territorioNuevo);
      if ($regimenActual !== $regimenNuevo || $territorioActual !== $territorioNuevo) {
        $camposBloqueados[] = 'regimenFiscal';
      }
    }

    if (isset($data['ticketSICertificado'])
      && (string) $data['ticketSICertificado'] !== (string) ($actual['TicketSI_Certificado'] ?? '')) {
      $camposBloqueados[] = 'ticketSICertificado';
    }

    if ($camposBloqueados !== []) {
      throw new FiscalLockException(
        'No se pueden modificar datos fiscales criticos con facturas emitidas',
        $camposBloqueados
      );
    }
  }
}

final class FiscalLockException extends \RuntimeException
{
  private array $campos;

  public function __construct(string $message, array $campos)
  {
    parent::__construct($message);
    $this->campos = $campos;
  }

  public function getCampos(): array
  {
    return $this->campos;
  }
}
