<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Facturacion;

use PDO;

/**
 * Generación de albaranes periódicos (legacy Gen.Alb → CopiaAlbaran).
 */
final class AlbaranesPeriodicosService
{
  private PDO $pdo;

  /** Columnas de cabecera que no se copian tal cual / se regeneran. */
  private const CAB_SKIP = [
    'upsize_ts', 'rowguid', 'empresa', 'albaran', 'fecha',
    'facturatipo', 'factura', 'trasmodem', 'trasctb', 'impreso', 'sesion',
    'prefactura', 'prefacturatipo', 'preempresafacturacion',
  ];

  private const LIN_SKIP = ['upsize_ts', 'rowguid', 'nrolin', 'empresa', 'albaran'];

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  /**
   * @param array<string, mixed> $body
   * @return array{generados: list<array<string, mixed>>, totales: array{generados: int, omitidos: int}}
   */
  public function generar(array $body): array
  {
    $empresa = trim((string) ($body['empresa'] ?? ''));
    if ($empresa === '') {
      throw new \InvalidArgumentException('empresa obligatoria');
    }

    $fechaDesde = trim((string) ($body['fechaDesde'] ?? ''));
    $fechaHasta = trim((string) ($body['fechaHasta'] ?? ''));
    if ($fechaDesde === '' || $fechaHasta === ''
      || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaDesde)
      || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaHasta)
    ) {
      throw new \InvalidArgumentException('fechaDesde y fechaHasta obligatorias (YYYY-MM-DD)');
    }
    if ($fechaDesde > $fechaHasta) {
      throw new \InvalidArgumentException('fechaDesde no puede ser posterior a fechaHasta');
    }

    $desde = new \DateTimeImmutable($fechaDesde);
    $hasta = new \DateTimeImmutable($fechaHasta);

    $stmt = $this->pdo->prepare(
      'SELECT Empresa, Tipo, Albaran, UltimaGeneracion, Periodicidad
       FROM AlbaranesPeriodicos
       WHERE Empresa = :e'
    );
    $stmt->execute(['e' => $empresa]);
    $periodicos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $this->pdo->beginTransaction();
    try {
      $generados = [];
      $omitidos = 0;

      foreach ($periodicos as $per) {
        $periodicidad = (int) ($per['Periodicidad'] ?? 0);
        if ($periodicidad <= 0) {
          $omitidos++;
          continue;
        }

        $ultimaRaw = $per['UltimaGeneracion'] ?? null;
        if ($ultimaRaw === null || $ultimaRaw === '') {
          $omitidos++;
          continue;
        }
        try {
          $ultima = new \DateTimeImmutable(is_string($ultimaRaw) ? $ultimaRaw : (string) $ultimaRaw);
        } catch (\Throwable $e) {
          $omitidos++;
          continue;
        }

        if ($periodicidad % 30 === 0) {
          $meses = (int) ($periodicidad / 30);
          $fechaVen = $ultima->modify("+{$meses} months");
          $fechaVen2 = $fechaVen->modify("+{$meses} months");
        } else {
          $fechaVen = $ultima->modify("+{$periodicidad} days");
          $fechaVen2 = $fechaVen->modify("+{$periodicidad} days");
        }

        if ($fechaVen < $desde || $fechaVen > $hasta) {
          continue;
        }

        $tipoPlantilla = trim((string) ($per['Tipo'] ?? 'A'));
        $albPlantilla = (int) ($per['Albaran'] ?? 0);
        $empPlantilla = trim((string) ($per['Empresa'] ?? $empresa));

        if (!$this->clientePermitePeriodico($empPlantilla, $tipoPlantilla, $albPlantilla)) {
          $omitidos++;
          continue;
        }

        $fechaFinPeriodo = $fechaVen2->modify('-1 day');
        $nuevo = $this->copiarAlbaran(
          $empPlantilla,
          $albPlantilla,
          $empresa,
          $fechaVen,
          $fechaFinPeriodo
        );

        $this->pdo->prepare(
          'UPDATE AlbaranesPeriodicos
           SET UltimaGeneracion = :u
           WHERE Empresa = :e AND Tipo = :t AND Albaran = :a'
        )->execute([
          'u' => $fechaVen->format('Y-m-d H:i:s'),
          'e' => $empPlantilla,
          't' => $tipoPlantilla,
          'a' => $albPlantilla,
        ]);

        $generados[] = $nuevo;
      }

      $this->pdo->commit();
    } catch (\Throwable $e) {
      if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      throw $e;
    }

    return [
      'generados' => $generados,
      'totales' => [
        'generados' => count($generados),
        'omitidos' => $omitidos,
      ],
    ];
  }

  private function clientePermitePeriodico(string $empresa, string $tipo, int $albaran): bool
  {
    $st = $this->pdo->prepare(
      "SELECT c.FormaPago
       FROM AlbaranesVentasCab a
       INNER JOIN Clientes c ON c.Codigo = a.Cliente
       WHERE a.Empresa = :e AND a.Tipo = :t AND a.Albaran = :a"
    );
    $st->execute(['e' => $empresa, 't' => $tipo, 'a' => $albaran]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      return false;
    }
    $fp = trim((string) ($row['FormaPago'] ?? ''));
    if ($fp === '') {
      return true;
    }
    $fpSt = $this->pdo->prepare('SELECT CobroDeArqueo FROM FormasPago WHERE Codigo = :c');
    $fpSt->execute(['c' => $fp]);
    $fpRow = $fpSt->fetch(PDO::FETCH_ASSOC);
    if ($fpRow === false) {
      return true;
    }
    return empty($fpRow['CobroDeArqueo']);
  }

  /**
   * @return array{empresa: string, tipo: string, albaran: number, plantilla: int}
   */
  private function copiarAlbaran(
    string $empresaPlantilla,
    int $albaranPlantilla,
    string $empresaDestino,
    \DateTimeImmutable $fechaVen,
    \DateTimeImmutable $fechaFinPeriodo
  ): array {
    $st = $this->pdo->prepare(
      "SELECT * FROM AlbaranesVentasCab
       WHERE Empresa = :e AND Tipo = 'A' AND Albaran = :a"
    );
    $st->execute(['e' => $empresaPlantilla, 'a' => $albaranPlantilla]);
    $plantilla = $st->fetch(PDO::FETCH_ASSOC);
    if ($plantilla === false) {
      throw new \RuntimeException(
        "Plantilla periódica {$empresaPlantilla}-A-{$albaranPlantilla} no encontrada",
        404
      );
    }

    $nuevoNum = $this->nextUltAlbaranVen($empresaDestino);
    $nuevo = [];
    foreach ($plantilla as $col => $val) {
      $key = strtolower((string) $col);
      if (in_array($key, self::CAB_SKIP, true)) {
        continue;
      }
      $nuevo[$col] = $val;
    }

    $nuevo['Empresa'] = $empresaDestino;
    $nuevo['Tipo'] = 'A';
    $nuevo['Albaran'] = $nuevoNum;
    $nuevo['Fecha'] = date('Y-m-d H:i:s');
    $nuevo['FacturaTipo'] = null;
    $nuevo['Factura'] = 0;
    $nuevo['TrasModem'] = 0;
    $nuevo['TrasCtb'] = 0;
    $nuevo['Impreso'] = 0;
    $nuevo['Sesion'] = 0;
    if (array_key_exists('PreFactura', $plantilla) || array_key_exists('prefactura', array_change_key_case($plantilla))) {
      $nuevo['PreFactura'] = 0;
      $nuevo['PreFacturaTipo'] = null;
      if (array_key_exists('PreEmpresaFacturacion', $plantilla)) {
        $nuevo['PreEmpresaFacturacion'] = null;
      }
    }

    $cols = array_keys($nuevo);
    $placeholders = array_map(static fn ($c) => ':' . preg_replace('/[^a-zA-Z0-9_]/', '_', $c), $cols);
    $colSql = implode(', ', array_map(static fn ($c) => '[' . str_replace(']', ']]', $c) . ']', $cols));
    $phSql = implode(', ', $placeholders);
    $params = [];
    foreach ($cols as $i => $col) {
      $params[ltrim($placeholders[$i], ':')] = $nuevo[$col];
    }
    $this->pdo->prepare("INSERT INTO AlbaranesVentasCab ({$colSql}) VALUES ({$phSql})")
      ->execute($params);

    $linSt = $this->pdo->prepare(
      "SELECT * FROM AlbaranesVentasLin
       WHERE Empresa = :e AND Tipo = 'A' AND Albaran = :a
       ORDER BY NroLin"
    );
    $linSt->execute(['e' => $empresaPlantilla, 'a' => $albaranPlantilla]);
    $lineas = $linSt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Línea texto "Periodo dd/mm/yy al dd/mm/yy"
    $this->insertarLineaTexto(
      $empresaDestino,
      $nuevoNum,
      'Periodo ' . $fechaVen->format('d/m/y') . ' al ' . $fechaFinPeriodo->format('d/m/y')
    );

    foreach ($lineas as $lin) {
      $nuevaLin = [];
      foreach ($lin as $col => $val) {
        $key = strtolower((string) $col);
        if (in_array($key, self::LIN_SKIP, true)) {
          continue;
        }
        $nuevaLin[$col] = $val;
      }
      $nuevaLin['Empresa'] = $empresaDestino;
      $nuevaLin['Tipo'] = 'A';
      $nuevaLin['Albaran'] = $nuevoNum;

      $colsL = array_keys($nuevaLin);
      $phL = array_map(static fn ($c) => ':' . preg_replace('/[^a-zA-Z0-9_]/', '_', $c), $colsL);
      $colSqlL = implode(', ', array_map(static fn ($c) => '[' . str_replace(']', ']]', $c) . ']', $colsL));
      $phSqlL = implode(', ', $phL);
      $paramsL = [];
      foreach ($colsL as $i => $col) {
        $paramsL[ltrim($phL[$i], ':')] = $nuevaLin[$col];
      }
      $this->pdo->prepare("INSERT INTO AlbaranesVentasLin ({$colSqlL}) VALUES ({$phSqlL})")
        ->execute($paramsL);
    }

    return [
      'empresa' => $empresaDestino,
      'tipo' => 'A',
      'albaran' => $nuevoNum,
      'plantilla' => $albaranPlantilla,
      'fechaPeriodo' => $fechaVen->format('Y-m-d'),
    ];
  }

  private function insertarLineaTexto(string $empresa, int $albaran, string $descripcion): void
  {
    $this->pdo->prepare(
      "INSERT INTO AlbaranesVentasLin (
         Empresa, Tipo, Albaran, Articulo, Descripcion, Cantidad, Precio, Importe, PjeDto
       ) VALUES (
         :e, 'A', :a, 'NO', :d, 0, 0, 0, 0
       )"
    )->execute([
      'e' => $empresa,
      'a' => $albaran,
      'd' => substr($descripcion, 0, 50),
    ]);
  }

  private function nextUltAlbaranVen(string $empresa): int
  {
    $stmt = $this->pdo->prepare(
      'SELECT UltAlbaranVen FROM Empresas WITH (UPDLOCK, ROWLOCK) WHERE Codigo = :e'
    );
    $stmt->execute(['e' => $empresa]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      throw new \RuntimeException('Tienda no encontrada', 404);
    }
    $n = (int) ($row['UltAlbaranVen'] ?? 0) + 1;
    $this->pdo->prepare('UPDATE Empresas SET UltAlbaranVen = :n WHERE Codigo = :e')
      ->execute(['n' => $n, 'e' => $empresa]);
    return $n;
  }
}
