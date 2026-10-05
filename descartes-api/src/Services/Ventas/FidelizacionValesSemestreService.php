<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Ventas;

use PDO;

/**
 * Programa «1 € = 1 punto, canje 3 %»: cada semestre se emite un vale
 * con caducidad a 6 meses. Los puntos suman las ventas cerradas de todas
 * las tiendas (Empresas_Ges) del mismo negocio.
 */
final class FidelizacionValesSemestreService
{
  public const PJE_CANJE = 3.0;
  public const MOTOR = 'VALE_SEMESTRAL';

  private PDO $pdo;
  private ValeService $vales;

  public function __construct(PDO $pdo, ValeService $vales)
  {
    $this->pdo = $pdo;
    $this->vales = $vales;
  }

  /**
   * @return array{inicio: string, fin: string}
   */
  public function semestreAnterior(?string $referencia = null): array
  {
    $d = new \DateTimeImmutable($referencia ?: 'today');
    $mes = (int) $d->format('n');
    $anio = (int) $d->format('Y');
    if ($mes <= 6) {
      return [
        'inicio' => ($anio - 1) . '-07-01',
        'fin' => ($anio - 1) . '-12-31',
      ];
    }
    return [
      'inicio' => $anio . '-01-01',
      'fin' => $anio . '-06-30',
    ];
  }

  /** @return array{inicio: string, fin: string} */
  public function semestreActual(?string $referencia = null): array
  {
    $d = new \DateTimeImmutable($referencia ?: 'today');
    $anio = $d->format('Y');
    if ((int) $d->format('n') <= 6) {
      return ['inicio' => "{$anio}-01-01", 'fin' => "{$anio}-06-30"];
    }
    return ['inicio' => "{$anio}-07-01", 'fin' => "{$anio}-12-31"];
  }

  /** @return array<string, mixed> */
  public function configuracion(string $empresa): array
  {
    $empresa = trim($empresa);
    if ($empresa === '') {
      throw new \InvalidArgumentException('empresa es obligatoria');
    }
    $st = $this->pdo->prepare(
      'SELECT RTRIM(ISNULL(TipoCalculoFidelizacion, \'\')) FROM Empresas_Ges
       WHERE RTRIM(Codigo) = :e'
    );
    $st->execute(['e' => $empresa]);
    $seleccionado = $st->fetchColumn();
    if ($seleccionado === false) {
      throw new \RuntimeException('Tienda no encontrada', 404);
    }

    $modelos = [];
    $q = $this->pdo->query(
      'SELECT RTRIM(Codigo) AS Codigo, RTRIM(Nombre) AS Nombre,
              RTRIM(Motor) AS Motor, Factor, Configuracion
       FROM TiposCalculoFidelizacion
       WHERE ISNULL(Baja, 0) = 0
       ORDER BY CASE WHEN RTRIM(Motor) = \'VALE_SEMESTRAL\' THEN 0 ELSE 1 END, Nombre'
    );
    while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
      $config = json_decode((string) ($row['Configuracion'] ?? ''), true);
      $modelos[] = [
        'codigo' => trim((string) ($row['Codigo'] ?? '')),
        'nombre' => trim((string) ($row['Nombre'] ?? '')),
        'motor' => trim((string) ($row['Motor'] ?? 'NINGUNO')),
        'factor' => (float) ($row['Factor'] ?? 1),
        'configuracion' => is_array($config) ? $config : [],
      ];
    }
    return [
      'empresa' => $empresa,
      'seleccionado' => trim((string) $seleccionado),
      'modelos' => $modelos,
      'tiendas' => $this->tiendasPuntos(),
    ];
  }

  /**
   * Tiendas activas y si están fuera del programa de puntos.
   *
   * @return list<array{codigo: string, nombre: string, sinPuntos: bool}>
   */
  public function tiendasPuntos(): array
  {
    $q = $this->pdo->query(
      'SELECT RTRIM([Codigo]) AS Codigo, RTRIM(ISNULL([Nombre], \'\')) AS Nombre,
              ISNULL([BloqueoFidelizacion], 0) AS Bloqueo
       FROM [Empresas_Ges]
       WHERE ISNULL([Baja], 0) = 0
       ORDER BY [Codigo]'
    );
    $tiendas = [];
    if ($q === false) {
      return $tiendas;
    }
    while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
      $tiendas[] = [
        'codigo' => trim((string) ($row['Codigo'] ?? '')),
        'nombre' => trim((string) ($row['Nombre'] ?? '')),
        'sinPuntos' => (int) ($row['Bloqueo'] ?? 0) !== 0,
      ];
    }

    return $tiendas;
  }

  /** La tienda deja de sumar puntos (o vuelve a sumarlos) sin cambiar el modelo. */
  public function marcarSinPuntos(string $empresa, bool $sinPuntos): array
  {
    $empresa = trim($empresa);
    if ($empresa === '') {
      throw new \InvalidArgumentException('empresa es obligatoria');
    }
    $upd = $this->pdo->prepare(
      'UPDATE [Empresas_Ges] SET [BloqueoFidelizacion] = :bloqueo WHERE RTRIM([Codigo]) = :empresa'
    );
    $upd->bindValue(':bloqueo', $sinPuntos ? 1 : 0, PDO::PARAM_INT);
    $upd->bindValue(':empresa', $empresa);
    $upd->execute();
    if ($upd->rowCount() === 0) {
      $existe = $this->pdo->prepare('SELECT 1 FROM [Empresas_Ges] WHERE RTRIM([Codigo]) = :empresa');
      $existe->bindValue(':empresa', $empresa);
      $existe->execute();
      if ($existe->fetchColumn() === false) {
        throw new \RuntimeException('Tienda no encontrada', 404);
      }
    }

    return ['empresa' => $empresa, 'sinPuntos' => $sinPuntos];
  }

  /** @return array<string, mixed> */
  public function seleccionarModelo(string $empresa, string $codigo): array
  {
    $empresa = trim($empresa);
    $codigo = trim($codigo);
    if ($empresa === '' || $codigo === '') {
      throw new \InvalidArgumentException('empresa y modelo son obligatorios');
    }
    $st = $this->pdo->prepare(
      'SELECT 1 FROM TiposCalculoFidelizacion
       WHERE RTRIM(Codigo) = :c AND ISNULL(Baja, 0) = 0'
    );
    $st->execute(['c' => $codigo]);
    if ($st->fetchColumn() === false) {
      throw new \InvalidArgumentException('El modelo de fidelización no existe o está de baja');
    }
    $upd = $this->pdo->prepare(
      'UPDATE Empresas_Ges SET TipoCalculoFidelizacion = :c WHERE RTRIM(Codigo) = :e'
    );
    $upd->execute(['c' => $codigo, 'e' => $empresa]);
    if ($upd->rowCount() === 0) {
      $existe = $this->pdo->prepare('SELECT 1 FROM Empresas_Ges WHERE RTRIM(Codigo) = :e');
      $existe->execute(['e' => $empresa]);
      if ($existe->fetchColumn() === false) {
        throw new \RuntimeException('Tienda no encontrada', 404);
      }
    }
    return $this->configuracion($empresa);
  }

  /**
   * Los vales solo se emiten al principio del semestre siguiente al liquidado:
   * fuera de esa ventana una tienda nueva no debe generar periodos antiguos.
   *
   * @param array<string, mixed> $configModelo
   * @return array{inicio: string, fin: string, dentro: bool}
   */
  public function ventanaGeneracion(array $configModelo = [], ?string $referencia = null): array
  {
    $hoy = new \DateTimeImmutable($referencia ?: 'today');
    $dias = max(1, min(90, (int) ($configModelo['diasGeneracion'] ?? 31)));
    $inicio = new \DateTimeImmutable($this->semestreActual($referencia)['inicio']);
    $fin = $inicio->modify('+' . ($dias - 1) . ' days');
    return [
      'inicio' => $inicio->format('Y-m-d'),
      'fin' => $fin->format('Y-m-d'),
      'dentro' => $hoy >= $inicio && $hoy <= $fin,
    ];
  }

  /** @return array<string, mixed> */
  public function estadoAutomatico(string $empresa): array
  {
    $empresa = trim($empresa);
    $semestre = $this->semestreAnterior();
    $modelo = $this->modeloSeleccionado($empresa);
    $aplica = ($modelo['motor'] ?? '') === self::MOTOR;
    $ventana = $this->ventanaGeneracion($modelo['configuracion'] ?? []);
    $liquidacion = $aplica
      ? $this->liquidacionExistente($semestre['inicio'], $semestre['fin'])
      : null;
    return [
      'empresa' => $empresa,
      'aplica' => $aplica,
      'pendiente' => $aplica && $ventana['dentro'] && $liquidacion === null,
      'fechaInicio' => $semestre['inicio'],
      'fechaFin' => $semestre['fin'],
      'ventanaInicio' => $ventana['inicio'],
      'ventanaFin' => $ventana['fin'],
      'dentroVentana' => $ventana['dentro'],
      'yaGenerado' => $liquidacion !== null,
    ];
  }

  /** @return array<string, mixed> */
  public function generarAutomaticamente(string $empresa, string $puesto): array
  {
    $estado = $this->estadoAutomatico($empresa);
    if (!$estado['pendiente']) {
      return [
        'simulado' => false,
        'generadoAhora' => false,
        'yaGenerado' => $estado['yaGenerado'],
        'fueraDeVentana' => !$estado['dentroVentana'],
        'empresa' => $estado['empresa'],
        'fechaInicio' => $estado['fechaInicio'],
        'fechaFin' => $estado['fechaFin'],
        'fechaCaducidad' => '',
        'pjeCanje' => self::PJE_CANJE,
        'formaPago' => '',
        'clientes' => [],
        'vales' => 0,
        'importeTotal' => 0.0,
      ];
    }
    return $this->ejecutar([
      'empresa' => $empresa,
      'puesto' => $puesto,
      'automatico' => true,
      'ignorarSiGenerado' => true,
    ]);
  }

  /** @return array<string, mixed>|null */
  public function puntosCliente(string $empresa, string $cliente, float $puntosCompra = 0): ?array
  {
    $empresa = trim($empresa);
    $cliente = trim($cliente);
    if ($empresa === '') {
      $empresa = $this->empresaConPrograma() ?? '';
    }
    $modelo = $this->modeloSeleccionado($empresa);
    if (($modelo['motor'] ?? '') !== self::MOTOR || $cliente === '' || strtoupper($cliente) === 'ZZZZZZZZZ') {
      return null;
    }
    $tarjeta = $this->pdo->prepare(
      "SELECT RTRIM(ISNULL(TarjetaFidelizacion, '')) FROM Clientes WHERE RTRIM(Codigo) = :c"
    );
    $tarjeta->execute(['c' => $cliente]);
    if (trim((string) $tarjeta->fetchColumn()) === '') {
      return null;
    }
    $semestre = $this->semestreActual();
    $exclusiones = $this->exclusionesDe($modelo['configuracion'] ?? []);
    $importe = $this->expresionImporte('a', $exclusiones);
    $st = $this->pdo->prepare(
      'SELECT ISNULL(SUM(t.Elegible), 0)
       FROM (
         SELECT ' . $importe['sql'] . ' AS Elegible
         FROM AlbaranesVentasCab a
         WHERE RTRIM(a.Cliente) = :c
           AND a.Fecha >= CONVERT(datetime, :inicio, 120)
           AND a.Fecha < DATEADD(day, 1, CONVERT(datetime, :fin, 120))
           AND ISNULL(a.Anulado, 0) = 0
           AND (ISNULL(a.Sesion, 0) > 0 OR ISNULL(a.Factura, 0) > 0)
           AND ' . $this->sqlTiendaHacePuntos('a') . '
       ) t'
    );
    $st->execute($importe['params'] + [
      'c' => $cliente,
      'inicio' => $semestre['inicio'],
      'fin' => $semestre['fin'],
    ]);
    return [
      'compra' => round(max(0, $puntosCompra), 2),
      'acumulados' => round(max(0, (float) $st->fetchColumn()), 2),
      'fechaInicio' => $semestre['inicio'],
      'fechaFin' => $semestre['fin'],
    ];
  }

  /**
   * @param array<string, mixed> $body
   * @return array<string, mixed>
   */
  public function ejecutar(array $body): array
  {
    $empresa = trim((string) ($body['empresa'] ?? ''));
    if ($empresa === '') {
      throw new \InvalidArgumentException('empresa es obligatoria');
    }
    $simular = !empty($body['simular']);
    $forzar = !empty($body['forzar']);
    $automatico = !empty($body['automatico']);
    $ignorarSiGenerado = !empty($body['ignorarSiGenerado']);
    $puesto = substr(trim((string) ($body['puesto'] ?? '')), 0, 20);
    $modelo = $this->modeloSeleccionado($empresa);
    if (($modelo['motor'] ?? '') !== self::MOTOR) {
      throw new \RuntimeException('El modelo de vale semestral no está seleccionado para esta tienda', 409);
    }
    $configModelo = is_array($modelo['configuracion'] ?? null) ? $modelo['configuracion'] : [];
    $pje = (float) ($body['pjeCanje'] ?? $configModelo['pjeCanje'] ?? self::PJE_CANJE);
    if ($pje <= 0 || $pje > 100) {
      throw new \InvalidArgumentException('pjeCanje debe estar entre 0 y 100');
    }

    $semestre = $this->semestreAnterior();
    $inicio = $this->fecha($body['fechaInicio'] ?? $semestre['inicio']);
    $fin = $this->fecha($body['fechaFin'] ?? $semestre['fin']);
    if ($inicio > $fin) {
      throw new \InvalidArgumentException('fechaInicio no puede ser posterior a fechaFin');
    }

    // El vale de fidelización es un descuento, no una forma de pago.
    $formaPago = '';
    $clientes = $this->calcularClientes(
      $inicio,
      $fin,
      $pje,
      $this->exclusionesDe($configModelo)
    );
    $mesesCaducidad = max(1, min(60, (int) ($configModelo['mesesCaducidad'] ?? 6)));
    $caducidad = (new \DateTimeImmutable('today'))->modify("+{$mesesCaducidad} months")->format('Y-m-d');
    $importeTotal = 0.0;
    foreach ($clientes as $c) {
      $importeTotal += $c['importeCanje'];
    }
    $importeTotal = round($importeTotal, 2);

    $ya = $this->liquidacionExistente($inicio, $fin);
    if ($ya !== null && !$simular && !$forzar) {
      if ($ignorarSiGenerado) {
        return [
          'simulado' => false,
          'generadoAhora' => false,
          'yaGenerado' => true,
          'empresa' => $empresa,
          'fechaInicio' => $inicio,
          'fechaFin' => $fin,
          'fechaCaducidad' => $caducidad,
          'pjeCanje' => $pje,
          'formaPago' => $formaPago,
          'clientes' => [],
          'vales' => $ya['valesEmitidos'],
          'importeTotal' => $ya['importeTotal'],
        ];
      }
      throw new \RuntimeException(
        "Este periodo ({$inicio} a {$fin}) ya tiene {$ya['valesEmitidos']} vales emitidos. Marque forzar para repetir.",
        409
      );
    }

    if ($simular) {
      return [
        'simulado' => true,
        'empresa' => $empresa,
        'fechaInicio' => $inicio,
        'fechaFin' => $fin,
        'fechaCaducidad' => $caducidad,
        'pjeCanje' => $pje,
        'formaPago' => $formaPago,
        'yaGenerado' => $ya !== null,
        'clientes' => $clientes,
        'vales' => count($clientes),
        'importeTotal' => $importeTotal,
      ];
    }

    $this->pdo->beginTransaction();
    try {
      $this->bloquearPeriodo($inicio, $fin);
      $ya = $this->liquidacionExistente($inicio, $fin);
      if ($ya !== null && !$forzar) {
        $this->pdo->commit();
        if ($ignorarSiGenerado) {
          return [
            'simulado' => false,
            'generadoAhora' => false,
            'yaGenerado' => true,
            'empresa' => $empresa,
            'fechaInicio' => $inicio,
            'fechaFin' => $fin,
            'fechaCaducidad' => $caducidad,
            'pjeCanje' => $pje,
            'formaPago' => $formaPago,
            'clientes' => [],
            'vales' => $ya['valesEmitidos'],
            'importeTotal' => $ya['importeTotal'],
          ];
        }
        throw new \RuntimeException(
          "Este periodo ({$inicio} a {$fin}) ya tiene {$ya['valesEmitidos']} vales emitidos.",
          409
        );
      }
      $emitidos = [];
      foreach ($clientes as $c) {
        $vale = $this->vales->emitir([
          'empresa' => $empresa,
          'cliente' => $c['cliente'],
          'importe' => $c['importeCanje'],
          'fechaCaducidad' => $caducidad,
          'tipoVale' => 'FIDELIZACION',
        ]);
        $emitidos[] = [
          ...$c,
          'vale' => $vale['codigo'],
        ];
      }
      $this->registrarLiquidacion(
        $empresa,
        $inicio,
        $fin,
        count($emitidos),
        $importeTotal,
        $formaPago,
        $puesto,
        $automatico
      );
      $this->pdo->commit();
    } catch (\Throwable $e) {
      if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      throw $e;
    }

    return [
      'simulado' => false,
      'generadoAhora' => true,
      'yaGenerado' => false,
      'empresa' => $empresa,
      'fechaInicio' => $inicio,
      'fechaFin' => $fin,
      'fechaCaducidad' => $caducidad,
      'pjeCanje' => $pje,
      'formaPago' => $formaPago,
      'clientes' => $emitidos,
      'vales' => count($emitidos),
      'importeTotal' => $importeTotal,
    ];
  }

  /**
   * @return list<array<string, mixed>>
   */
  /**
   * @param list<array{tipo: string, codigo: string, descripcion: string}> $exclusiones
   * @return list<array<string, mixed>>
   */
  private function calcularClientes(string $inicio, string $fin, float $pje, array $exclusiones = []): array
  {
    $importe = $this->expresionImporte('a', $exclusiones);
    $sql = "SELECT
        RTRIM(t.Cliente) AS Cliente,
        RTRIM(ISNULL(t.RazonSocial, '')) AS RazonSocial,
        RTRIM(ISNULL(t.TarjetaFidelizacion, '')) AS TarjetaFidelizacion,
        SUM(t.Elegible) AS ImporteTotal
      FROM (
        SELECT
          c.Codigo AS Cliente,
          c.RazonSocial,
          c.TarjetaFidelizacion,
          " . $importe['sql'] . " AS Elegible
        FROM AlbaranesVentasCab a
        INNER JOIN Clientes c ON RTRIM(a.Cliente) = RTRIM(c.Codigo)
        WHERE c.TarjetaFidelizacion IS NOT NULL
          AND RTRIM(c.TarjetaFidelizacion) <> ''
          AND RTRIM(c.Codigo) <> 'ZZZZZZZZZ'
          AND a.Fecha >= CONVERT(datetime, :inicio, 120)
          AND a.Fecha < DATEADD(day, 1, CONVERT(datetime, :fin, 120))
          AND ISNULL(a.Anulado, 0) = 0
          AND (ISNULL(a.Sesion, 0) > 0 OR ISNULL(a.Factura, 0) > 0)
          AND " . $this->sqlTiendaHacePuntos('a') . "
      ) t
      GROUP BY t.Cliente, t.RazonSocial, t.TarjetaFidelizacion
      HAVING SUM(t.Elegible) > 0
      ORDER BY SUM(t.Elegible) DESC";
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($importe['params'] + [
      'inicio' => $inicio,
      'fin' => $fin,
    ]);

    $items = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $importe = round((float) ($row['ImporteTotal'] ?? 0), 2);
      $puntos = $importe;
      $canje = round($puntos * $pje / 100, 2);
      if ($canje < 0.01) {
        continue;
      }
      $items[] = [
        'cliente' => trim((string) ($row['Cliente'] ?? '')),
        'razonSocial' => trim((string) ($row['RazonSocial'] ?? '')),
        'tarjetaFidelizacion' => trim((string) ($row['TarjetaFidelizacion'] ?? '')),
        'importeTotal' => $importe,
        'puntos' => $puntos,
        'importeCanje' => $canje,
      ];
    }
    return $items;
  }

  /**
   * @return array{valesEmitidos: int, importeTotal: float}|null
   */
  private function liquidacionExistente(string $inicio, string $fin): ?array
  {
    if (!$this->tablaLiquidaciones()) {
      return null;
    }
    $st = $this->pdo->prepare(
      'SELECT TOP 1 ValesEmitidos, ImporteTotal FROM FidelizacionLiquidaciones
       WHERE PeriodoInicio = CONVERT(date, :i, 120)
         AND PeriodoFin = CONVERT(date, :f, 120)
       ORDER BY FechaGeneracion DESC'
    );
    $st->execute(['i' => $inicio, 'f' => $fin]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      return null;
    }
    return [
      'valesEmitidos' => (int) ($row['ValesEmitidos'] ?? 0),
      'importeTotal' => (float) ($row['ImporteTotal'] ?? 0),
    ];
  }

  private function registrarLiquidacion(
    string $empresa,
    string $inicio,
    string $fin,
    int $vales,
    float $importe,
    string $formaPago,
    string $puesto,
    bool $automatico
  ): void {
    if (!$this->tablaLiquidaciones()) {
      return;
    }
    $st = $this->pdo->prepare(
      'UPDATE FidelizacionLiquidaciones
       SET FechaGeneracion = GETDATE(), ValesEmitidos = :v, ImporteTotal = :imp, FormaPago = :fp,
           PuestoGeneracion = :puesto, GeneracionAutomatica = :automatica
       WHERE RTRIM(Empresa) = :e AND PeriodoInicio = CONVERT(date, :i, 120)
         AND PeriodoFin = CONVERT(date, :f, 120)'
    );
    $st->execute([
      'v' => $vales,
      'imp' => $importe,
      'fp' => $formaPago,
      'puesto' => $puesto !== '' ? $puesto : null,
      'automatica' => $automatico ? 1 : 0,
      'e' => $empresa,
      'i' => $inicio,
      'f' => $fin,
    ]);
    if ($st->rowCount() > 0) {
      return;
    }
    $ins = $this->pdo->prepare(
      'INSERT INTO FidelizacionLiquidaciones
         (Empresa, PeriodoInicio, PeriodoFin, FechaGeneracion, ValesEmitidos, ImporteTotal, FormaPago,
          PuestoGeneracion, GeneracionAutomatica)
       VALUES (:e, CONVERT(date, :i, 120), CONVERT(date, :f, 120), GETDATE(), :v, :imp, :fp,
               :puesto, :automatica)'
    );
    $ins->execute([
      'e' => $empresa,
      'i' => $inicio,
      'f' => $fin,
      'v' => $vales,
      'imp' => $importe,
      'fp' => $formaPago,
      'puesto' => $puesto !== '' ? $puesto : null,
      'automatica' => $automatico ? 1 : 0,
    ]);
  }

  /** Ventas de una tienda con bloqueo de fidelización no suman puntos. */
  /**
   * Importe del documento que suma en el vale: el total, menos las líneas excluidas.
   * Null si esta tienda no usa el vale semestral o no hay exclusiones.
   */
  public function importeElegibleDocumento(string $empresa, string $tipo, int $albaran): ?float
  {
    $modelo = $this->modeloSeleccionado($empresa);
    if (($modelo['motor'] ?? '') !== self::MOTOR) {
      return null;
    }
    $exclusiones = $this->exclusionesDe($modelo['configuracion'] ?? []);
    if ($exclusiones === []) {
      return null;
    }
    $importe = $this->expresionImporte('a', $exclusiones);
    $st = $this->pdo->prepare(
      'SELECT ' . $importe['sql'] . '
       FROM AlbaranesVentasCab a
       WHERE RTRIM(a.Empresa) = :e AND a.Tipo = :t AND a.Albaran = :a'
    );
    $st->execute($importe['params'] + [
      'e' => trim($empresa),
      't' => $tipo,
      'a' => $albaran,
    ]);
    $valor = $st->fetchColumn();
    return $valor === false ? null : round(max(0, (float) $valor), 2);
  }

  /**
   * @param array<string, mixed> $body
   * @return array<string, mixed>
   */
  public function guardarConfiguracion(string $codigo, array $body): array
  {
    $codigo = trim($codigo);
    if ($codigo === '') {
      throw new \InvalidArgumentException('El modelo de vale semestral es obligatorio');
    }
    $st = $this->pdo->prepare(
      'SELECT Configuracion FROM TiposCalculoFidelizacion
       WHERE RTRIM(Codigo) = :c AND RTRIM(Motor) = :motor AND ISNULL(Baja, 0) = 0'
    );
    $st->execute(['c' => $codigo, 'motor' => self::MOTOR]);
    $actual = $st->fetchColumn();
    if ($actual === false) {
      throw new \InvalidArgumentException('El modelo seleccionado no es de vale semestral');
    }
    $pje = $body['pjeCanje'] ?? self::PJE_CANJE;
    if (is_string($pje)) {
      $pje = str_replace(',', '.', trim($pje));
    }
    if (!is_numeric($pje) || (float) $pje <= 0 || (float) $pje > 100) {
      throw new \InvalidArgumentException('El porcentaje de canje debe ser mayor que 0 y hasta 100');
    }
    $exclusionesBody = $body['exclusiones'] ?? [];
    if (!is_array($exclusionesBody)) {
      throw new \InvalidArgumentException('Las exclusiones no son válidas');
    }
    $exclusiones = $this->normalizarExclusiones($exclusionesBody);
    if (count($exclusiones) > 2000) {
      throw new \InvalidArgumentException('Se admiten hasta 2000 exclusiones');
    }

    $config = json_decode((string) $actual, true);
    if (!is_array($config)) {
      $config = [];
    }
    $config['pjeCanje'] = round((float) $pje, 4);
    $config['exclusiones'] = $exclusiones;
    $json = json_encode($config, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
      throw new \RuntimeException('No se pudo guardar la configuración del vale');
    }
    $upd = $this->pdo->prepare(
      'UPDATE TiposCalculoFidelizacion SET Configuracion = :c WHERE RTRIM(Codigo) = :codigo'
    );
    $upd->execute(['c' => $json, 'codigo' => $codigo]);

    return $config;
  }

  /**
   * @param array<string, mixed> $config
   * @return list<array{tipo: string, codigo: string, descripcion: string}>
   */
  private function exclusionesDe(array $config): array
  {
    return $this->normalizarExclusiones($config['exclusiones'] ?? []);
  }

  /**
   * @param mixed $lista
   * @return list<array{tipo: string, codigo: string, descripcion: string}>
   */
  private function normalizarExclusiones(mixed $lista): array
  {
    if (!is_array($lista)) {
      return [];
    }
    $vistos = [];
    $salida = [];
    foreach ($lista as $item) {
      if (!is_array($item)) {
        continue;
      }
      $tipo = strtoupper(trim((string) ($item['tipo'] ?? '')));
      $codigo = trim((string) ($item['codigo'] ?? ''));
      if (!in_array($tipo, ['M', 'F', 'S', 'A'], true) || ($codigo === '' && $tipo !== 'M')) {
        continue;
      }
      $clave = $tipo . ':' . strtoupper($codigo);
      if (isset($vistos[$clave])) {
        continue;
      }
      $vistos[$clave] = true;
      $salida[] = [
        'tipo' => $tipo,
        'codigo' => substr($codigo, 0, 18),
        'descripcion' => mb_substr(trim((string) ($item['descripcion'] ?? '')), 0, 60),
      ];
    }
    return $salida;
  }

  /**
   * Total de la venta, menos el importe de las líneas excluidas. Sin exclusiones, el total.
   *
   * @param list<array{tipo: string, codigo: string, descripcion: string}> $exclusiones
   * @return array{sql: string, params: array<string, string>}
   */
  private function expresionImporte(string $alias, array $exclusiones): array
  {
    if ($exclusiones === []) {
      return ['sql' => 'ISNULL(' . $alias . '.Importe, 0)', 'params' => []];
    }
    $filtros = [];
    $params = [];
    foreach ($exclusiones as $i => $item) {
      $nombre = 'ex' . $i;
      if ($item['tipo'] === 'A') {
        $filtros[] = 'RTRIM(l.Articulo) = :' . $nombre;
      } elseif ($item['tipo'] === 'F') {
        $filtros[] = 'RTRIM(CAST(ar.Familia AS nvarchar(18))) = :' . $nombre;
      } elseif ($item['tipo'] === 'S') {
        $filtros[] = 'RTRIM(CAST(ar.Subfamilia AS nvarchar(18))) = :' . $nombre;
      } elseif ($item['codigo'] === '') {
        $filtros[] = '(
          RTRIM(ISNULL(CAST(fa.MacroFamilia AS nvarchar(18)), \'\')) = \'\'
          OR NOT EXISTS (
            SELECT 1 FROM MacroFamilias m
            WHERE RTRIM(CAST(m.Codigo AS nvarchar(18))) = RTRIM(CAST(fa.MacroFamilia AS nvarchar(18)))
          )
        )';
        continue;
      } else {
        $filtros[] = 'RTRIM(CAST(fa.MacroFamilia AS nvarchar(18))) = :' . $nombre;
      }
      $params[$nombre] = $item['codigo'];
    }
    $sql = '(SELECT CASE WHEN v < 0 THEN 0 ELSE v END FROM (
        SELECT ISNULL(' . $alias . '.Importe, 0) - ISNULL((
          SELECT SUM(l.Importe)
          FROM AlbaranesVentasLin l
          INNER JOIN Articulos ar ON RTRIM(ar.Codigo) = RTRIM(l.Articulo)
          LEFT JOIN Familias fa
            ON RTRIM(CAST(fa.Codigo AS nvarchar(18))) = RTRIM(CAST(ar.Familia AS nvarchar(18)))
          WHERE l.Empresa = ' . $alias . '.Empresa
            AND l.Tipo = ' . $alias . '.Tipo
            AND l.Albaran = ' . $alias . '.Albaran
            AND UPPER(RTRIM(ISNULL(l.Articulo, \'\'))) <> \'NO\'
            AND (' . implode(' OR ', $filtros) . ')
        ), 0) AS v
      ) calcFid)';

    return ['sql' => $sql, 'params' => $params];
  }

  private function sqlTiendaHacePuntos(string $aliasCabecera): string
  {
    return 'NOT EXISTS (
      SELECT 1 FROM [Empresas_Ges] eFid
      WHERE RTRIM(eFid.[Codigo]) = RTRIM(' . $aliasCabecera . '.[Empresa])
        AND ISNULL(eFid.[BloqueoFidelizacion], 0) <> 0
    )';
  }

  /** Primera tienda con el programa semestral activo. */
  public function empresaConPrograma(): ?string
  {
    $st = $this->pdo->query(
      "SELECT TOP 1 RTRIM(e.Codigo)
       FROM Empresas_Ges e
       INNER JOIN TiposCalculoFidelizacion t
         ON RTRIM(t.Codigo) = RTRIM(ISNULL(e.TipoCalculoFidelizacion, ''))
       WHERE RTRIM(t.Motor) = 'VALE_SEMESTRAL' AND ISNULL(t.Baja, 0) = 0
       ORDER BY e.Codigo"
    );
    if ($st === false) {
      return null;
    }
    $codigo = $st->fetchColumn();
    return $codigo === false || $codigo === null ? null : trim((string) $codigo);
  }

  /** @return array{codigo: string, motor: string, factor: float, configuracion: array<string, mixed>}|null */
  private function modeloSeleccionado(string $empresa): ?array
  {
    if (trim($empresa) === '') {
      return null;
    }
    $st = $this->pdo->prepare(
      'SELECT TOP 1 RTRIM(t.Codigo) AS Codigo, RTRIM(t.Motor) AS Motor,
              t.Factor, t.Configuracion
       FROM Empresas_Ges e
       INNER JOIN TiposCalculoFidelizacion t
         ON RTRIM(t.Codigo) = RTRIM(ISNULL(e.TipoCalculoFidelizacion, \'\'))
       WHERE RTRIM(e.Codigo) = :e AND ISNULL(t.Baja, 0) = 0'
    );
    $st->execute(['e' => trim($empresa)]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      return null;
    }
    $config = json_decode((string) ($row['Configuracion'] ?? ''), true);
    return [
      'codigo' => trim((string) ($row['Codigo'] ?? '')),
      'motor' => trim((string) ($row['Motor'] ?? 'NINGUNO')),
      'factor' => (float) ($row['Factor'] ?? 1),
      'configuracion' => is_array($config) ? $config : [],
    ];
  }

  private function bloquearPeriodo(string $inicio, string $fin): void
  {
    $recurso = "fidelizacion-vales:{$inicio}:{$fin}";
    $st = $this->pdo->prepare(
      "DECLARE @resultado int;
       EXEC @resultado = sp_getapplock
         @Resource = :recurso,
         @LockMode = 'Exclusive',
         @LockOwner = 'Transaction',
         @LockTimeout = 15000;
       SELECT @resultado"
    );
    $st->execute(['recurso' => $recurso]);
    $resultado = (int) $st->fetchColumn();
    if ($resultado < 0) {
      throw new \RuntimeException(
        'Otro puesto está generando los vales de fidelización. Espere unos segundos.',
        409
      );
    }
  }

  private function tablaLiquidaciones(): bool
  {
    try {
      $id = $this->pdo->query("SELECT OBJECT_ID('dbo.FidelizacionLiquidaciones', 'U')")->fetchColumn();
      return $id !== false && $id !== null;
    } catch (\Throwable $e) {
      return false;
    }
  }

  private function fecha(mixed $value): string
  {
    $s = trim((string) $value);
    $dt = \DateTimeImmutable::createFromFormat('Y-m-d', substr($s, 0, 10));
    if ($dt === false) {
      throw new \InvalidArgumentException('Las fechas deben ser YYYY-MM-DD');
    }
    return $dt->format('Y-m-d');
  }
}
