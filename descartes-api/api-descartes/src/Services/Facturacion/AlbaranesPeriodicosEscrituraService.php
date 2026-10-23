<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Facturacion;

use PDO;

final class AlbaranesPeriodicosEscrituraService
{
  private PDO $pdo;
  private AlbaranesPeriodicosConsultaService $consulta;

  public function __construct(PDO $pdo, AlbaranesPeriodicosConsultaService $consulta)
  {
    $this->pdo = $pdo;
    $this->consulta = $consulta;
  }

  /**
   * @param array<string, mixed> $body
   * @return array<string, mixed>
   */
  public function crear(array $body): array
  {
    $empresa = trim((string) ($body['empresa'] ?? ''));
    $tipo = trim((string) ($body['tipo'] ?? ''));
    $albaran = (int) ($body['albaran'] ?? 0);
    $periodicidad = (int) ($body['periodicidad'] ?? 0);
    $ultimaGeneracion = $this->parseDateTime($body['ultimaGeneracion'] ?? null);
    $marcarReferencia = !array_key_exists('marcarReferenciaPeriodico', $body)
      || (bool) $body['marcarReferenciaPeriodico'];

    $this->validarClave($empresa, $tipo, $albaran);
    if ($periodicidad <= 0) {
      throw new \InvalidArgumentException('periodicidad debe ser mayor que 0');
    }
    if ($ultimaGeneracion === null) {
      throw new \InvalidArgumentException('ultimaGeneracion obligatoria');
    }

    if ($this->existe($empresa, $tipo, $albaran)) {
      throw new \RuntimeException('Ya existe como base periódica', 409);
    }

    $plantilla = $this->obtenerPlantilla($empresa, $tipo, $albaran);
    if ($plantilla === null) {
      throw new \RuntimeException('Plantilla no encontrada', 404);
    }
    if (!$this->tieneLineas($empresa, $tipo, $albaran)) {
      throw new \InvalidArgumentException('La plantilla debe tener al menos una línea');
    }

    $this->pdo->beginTransaction();
    try {
      $this->pdo->prepare(
        'INSERT INTO AlbaranesPeriodicos (Empresa, Tipo, Albaran, Periodicidad, UltimaGeneracion)
         VALUES (:e, :t, :a, :p, CONVERT(datetime, :u, 120))'
      )->execute([
        'e' => $empresa,
        't' => $tipo,
        'a' => $albaran,
        'p' => $periodicidad,
        'u' => $ultimaGeneracion,
      ]);

      if ($marcarReferencia) {
        $ref = trim((string) ($plantilla['Referencia1'] ?? ''));
        if ($ref === '') {
          $this->pdo->prepare(
            'UPDATE AlbaranesVentasCab SET Referencia1 = :r
             WHERE Empresa = :e AND Tipo = :t AND Albaran = :a'
          )->execute([
            'r' => 'PERIODICO',
            'e' => $empresa,
            't' => $tipo,
            'a' => $albaran,
          ]);
        }
      }

      $this->pdo->commit();
    } catch (\Throwable $e) {
      if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      throw $e;
    }

    $item = $this->consulta->obtener($empresa, $tipo, $albaran);
    if ($item === null) {
      throw new \RuntimeException('No se pudo leer la base periódica creada', 500);
    }

    return $item;
  }

  /**
   * @param array<string, mixed> $body
   * @return array<string, mixed>|null
   */
  public function actualizar(string $empresa, string $tipo, int $albaran, array $body): ?array
  {
    $empresa = trim($empresa);
    $tipo = trim($tipo);
    $this->validarClave($empresa, $tipo, $albaran);

    if (!$this->existe($empresa, $tipo, $albaran)) {
      return null;
    }

    $sets = [];
    $params = ['e' => $empresa, 't' => $tipo, 'a' => $albaran];

    if (array_key_exists('periodicidad', $body)) {
      $periodicidad = (int) $body['periodicidad'];
      if ($periodicidad <= 0) {
        throw new \InvalidArgumentException('periodicidad debe ser mayor que 0');
      }
      $sets[] = 'Periodicidad = :p';
      $params['p'] = $periodicidad;
    }

    // Pausar no toca la configuracion: solo cambia Activo.
    if (array_key_exists('activo', $body)) {
      if (!AlbaranesPeriodicosEsquema::tieneActivo($this->pdo)) {
        throw new \RuntimeException(
          'No se pudo crear la columna AlbaranesPeriodicos.Activo: revise los permisos '
            . 'del usuario de base de datos',
          409
        );
      }
      $sets[] = 'Activo = :act';
      $params['act'] = filter_var($body['activo'], FILTER_VALIDATE_BOOL) ? 1 : 0;
    }

    if (array_key_exists('ultimaGeneracion', $body)) {
      $ultima = $this->parseDateTime($body['ultimaGeneracion']);
      if ($ultima === null) {
        throw new \InvalidArgumentException('ultimaGeneracion inválida');
      }
      $sets[] = 'UltimaGeneracion = CONVERT(datetime, :u, 120)';
      $params['u'] = $ultima;
    }

    if ($sets === []) {
      throw new \InvalidArgumentException('Debe indicar periodicidad, ultimaGeneracion o activo');
    }

    $sql = 'UPDATE AlbaranesPeriodicos SET ' . implode(', ', $sets)
      . ' WHERE RTRIM(Empresa) = :e AND RTRIM(Tipo) = :t AND Albaran = :a';
    $this->pdo->prepare($sql)->execute($params);

    return $this->consulta->obtener($empresa, $tipo, $albaran);
  }

  public function eliminar(string $empresa, string $tipo, int $albaran): bool
  {
    $empresa = trim($empresa);
    $tipo = trim($tipo);
    $this->validarClave($empresa, $tipo, $albaran);

    $stmt = $this->pdo->prepare(
      'DELETE FROM AlbaranesPeriodicos
       WHERE RTRIM(Empresa) = :e AND RTRIM(Tipo) = :t AND Albaran = :a'
    );
    $stmt->execute(['e' => $empresa, 't' => $tipo, 'a' => $albaran]);

    return $stmt->rowCount() > 0;
  }

  private function validarClave(string $empresa, string $tipo, int $albaran): void
  {
    if ($empresa === '' || $tipo === '' || $albaran < 1) {
      throw new \InvalidArgumentException('empresa, tipo y albaran obligatorios');
    }
  }

  private function existe(string $empresa, string $tipo, int $albaran): bool
  {
    $stmt = $this->pdo->prepare(
      'SELECT 1 FROM AlbaranesPeriodicos
       WHERE RTRIM(Empresa) = :e AND RTRIM(Tipo) = :t AND Albaran = :a'
    );
    $stmt->execute(['e' => trim($empresa), 't' => trim($tipo), 'a' => $albaran]);

    return $stmt->fetchColumn() !== false;
  }

  /** @return array<string, mixed>|null */
  private function obtenerPlantilla(string $empresa, string $tipo, int $albaran): ?array
  {
    $stmt = $this->pdo->prepare(
      'SELECT Empresa, Tipo, Albaran, Referencia1
       FROM AlbaranesVentasCab
       WHERE RTRIM(Empresa) = :e AND RTRIM(Tipo) = :t AND Albaran = :a'
    );
    $stmt->execute(['e' => trim($empresa), 't' => trim($tipo), 'a' => $albaran]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row === false ? null : $row;
  }

  private function tieneLineas(string $empresa, string $tipo, int $albaran): bool
  {
    $stmt = $this->pdo->prepare(
      'SELECT COUNT(*) FROM AlbaranesVentasLin
       WHERE RTRIM(Empresa) = :e AND RTRIM(Tipo) = :t AND Albaran = :a'
    );
    $stmt->execute(['e' => trim($empresa), 't' => trim($tipo), 'a' => $albaran]);

    return (int) $stmt->fetchColumn() > 0;
  }

  private function parseDateTime(mixed $value): ?string
  {
    if ($value === null || $value === '') {
      return null;
    }
    $raw = trim((string) $value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
      return $raw . ' 00:00:00';
    }
    try {
      return (new \DateTimeImmutable($raw))->format('Y-m-d H:i:s');
    } catch (\Throwable $e) {
      return null;
    }
  }
}
