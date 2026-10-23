<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Ventas;

use PDO;

/**
 * Club saldo (CLUB_SALDO): lotes FIFO, vales bienvenida/cumpleaños, promos caja incompatibles.
 */
final class FidelizacionClubSaldoService
{
  private PDO $pdo;
  private FidelizacionService $fidelizacion;

  /** @var bool|null */
  private static $schemaReady = null;

  public function __construct(PDO $pdo, ?FidelizacionService $fidelizacion = null)
  {
    $this->pdo = $pdo;
    $this->fidelizacion = $fidelizacion ?? new FidelizacionService($pdo);
  }

  public function motorActivo(string $empresa): bool
  {
    return $this->fidelizacion->motorTienda($empresa) === 'CLUB_SALDO';
  }

  /**
   * @return array<string, mixed>
   */
  public function estadoSocio(string $empresa, string $cliente): array
  {
    $vacio = ['activo' => false, 'motor' => null];
    $cliente = trim($cliente);
    if ($cliente === '' || strcasecmp($cliente, 'ZZZZZZZZZ') === 0 || !$this->schemaListo()) {
      return $vacio;
    }
    $tipo = $this->fidelizacion->tipoCalculoTienda($empresa);
    if ($tipo === null || ($tipo['motor'] ?? '') !== 'CLUB_SALDO') {
      return $vacio;
    }
    if (!$this->esSocioClub($cliente)) {
      return array_merge($vacio, ['activo' => true, 'motor' => 'CLUB_SALDO', 'socio' => false]);
    }
    $cfg = $this->configClub($tipo['configuracion']);
    $this->caducarLotesVencidos($cliente);
    $centimos = $this->saldoCentimosDisponibles($cliente);
    $minCanje = (int) round($cfg['minSaldoCanjeVisibleEuros'] * 100);
    $vales = $this->valesActivos($empresa, $cliente);
    $promo = $this->promoActivaHoy($empresa, $cfg['promoIncompatiblePct']);

    return [
      'activo' => true,
      'motor' => 'CLUB_SALDO',
      'socio' => true,
      'saldoEuros' => round($centimos / 100, 2),
      'saldoCentimos' => $centimos,
      'canjeDisponible' => $centimos >= $minCanje,
      'proximaCaducidad' => $this->proximaCaducidad($cliente),
      'vales' => $vales,
      'promoIncompatible' => $promo,
      'config' => [
        'pjeAcumulacion' => $cfg['pjeAcumulacion'],
        'minSaldoCanjeVisibleEuros' => $cfg['minSaldoCanjeVisibleEuros'],
      ],
    ];
  }

  /**
   * @param array<string, mixed> $cabecera
   * @param array<string, mixed> $opts
   * @return array<string, mixed>
   */
  public function previewTicket(string $empresa, array $cabecera, array $opts = []): array
  {
    $cliente = trim((string) ($cabecera['cliente'] ?? ''));
    $tipo = $this->fidelizacion->tipoCalculoTienda($empresa);
    if ($tipo === null || ($tipo['motor'] ?? '') !== 'CLUB_SALDO' || !$this->schemaListo()) {
      return ['aplica' => false];
    }
    $cfg = $this->configClub($tipo['configuracion']);
    $excl = $this->mapExclusiones($cfg['exclusiones'] ?? []);
    $baseElegible = $this->fidelizacion->baseElegibleCabecera($cabecera, $excl);
    $importeTopeCanje = $this->importeTopeCanjeClub($cabecera);
    $usarPromo = !empty($opts['aplicarClubPromo']);
    $promo = $usarPromo ? $this->promoActivaHoy($empresa, $cfg['promoIncompatiblePct']) : null;
    $modoIncompatible = $promo !== null;
    $canjeEuros = 0.0;
    $valeEuros = 0.0;
    if (!$modoIncompatible && $this->esSocioClub($cliente)) {
      $this->caducarLotesVencidos($cliente);
      $centimos = $this->saldoCentimosDisponibles($cliente);
      $minCanje = (int) round($cfg['minSaldoCanjeVisibleEuros'] * 100);
      if (!empty($opts['aplicarClubSaldo']) && $centimos >= $minCanje) {
        $solicitado = isset($opts['clubSaldoCanjeEuros'])
          ? (int) round((float) $opts['clubSaldoCanjeEuros'] * 100)
          : $centimos;
        $solicitado = max(0, min($solicitado, $centimos));
        $maxEuros = min($importeTopeCanje, $solicitado / 100);
        $canjeEuros = round($maxEuros, 2);
      }
      $valeId = (int) ($opts['clubValeId'] ?? 0);
      if ($valeId > 0) {
        $vale = $this->cargarVale($valeId, $cliente);
        if ($vale !== null && !$vale['usado'] && $baseElegible >= $vale['minCompraEuros']) {
          $valeEuros = min($vale['importeEuros'], max(0, $importeTopeCanje - $canjeEuros));
        }
      }
    }
    $neto = max(0.0, round($baseElegible - $canjeEuros - $valeEuros, 2));
    $generadoCentimos = $modoIncompatible
      ? 0
      : (int) round($neto * $cfg['pjeAcumulacion'], 0);

    return [
      'aplica' => true,
      'baseElegible' => $baseElegible,
      'canjeEuros' => $canjeEuros,
      'valeEuros' => $valeEuros,
      'saldoGeneradoEuros' => round($generadoCentimos / 100, 2),
      'saldoGeneradoCentimos' => $generadoCentimos,
      'modoIncompatible' => $modoIncompatible,
      'promo' => $promo,
      'motivoIncompatible' => $modoIncompatible
        ? 'La promoción del ' . ($promo['pjeDescuento'] ?? $cfg['promoIncompatiblePct'])
          . '% no permite usar saldo, vales ni generar saldo en este ticket.'
        : null,
    ];
  }

  /**
   * Canje y vale club antes del cierre (dentro de transacción).
   *
   * @return array{canjeCentimos: int, valeId: int|null, valeCentimos: int, promoId: int|null, incompatible: bool}
   */
  public function aplicarEnCobro(
    string $empresa,
    string $tipoDoc,
    int $albaran,
    array $cabecera,
    array $body
  ): array {
    $nada = [
      'canjeCentimos' => 0,
      'valeId' => null,
      'valeCentimos' => 0,
      'promoId' => null,
      'incompatible' => false,
    ];
    if (!$this->motorActivo($empresa) || !$this->schemaListo()) {
      return $nada;
    }
    $preview = $this->previewTicket($empresa, $cabecera, $body);
    if (empty($preview['aplica'])) {
      return $nada;
    }
    $cliente = trim((string) ($cabecera['cliente'] ?? ''));
    if ($cliente === '') {
      return $nada;
    }
    if (!empty($preview['modoIncompatible'])) {
      $promo = $preview['promo'] ?? null;
      $this->pdo->prepare(
        'UPDATE AlbaranesVentasCab SET ClubModoIncompatible = 1, ClubPromoId = :p
         WHERE Empresa = :e AND Tipo = :t AND Albaran = :a'
      )->execute([
        'p' => $promo['id'] ?? null,
        'e' => $empresa,
        't' => $tipoDoc,
        'a' => $albaran,
      ]);

      return [
        'canjeCentimos' => 0,
        'valeId' => null,
        'valeCentimos' => 0,
        'promoId' => isset($promo['id']) ? (int) $promo['id'] : null,
        'incompatible' => true,
      ];
    }
    $canjeCentimos = (int) round(((float) ($preview['canjeEuros'] ?? 0)) * 100);
    if ($canjeCentimos > 0) {
      $this->consumirSaldoFifo($cliente, $empresa, $canjeCentimos, $tipoDoc, $albaran);
    }
    $valeCentimos = 0;
    $valeId = null;
    $valeIdBody = (int) ($body['clubValeId'] ?? 0);
    if ($valeIdBody > 0 && (float) ($preview['valeEuros'] ?? 0) > 0) {
      $valeCentimos = (int) round((float) $preview['valeEuros'] * 100);
      $this->marcarValeUsado($valeIdBody, $cliente, $empresa, $tipoDoc, $albaran);
      $valeId = $valeIdBody;
    }
    if ($canjeCentimos > 0 || $valeCentimos > 0) {
      $this->pdo->prepare(
        'UPDATE AlbaranesVentasCab SET
           ClubSaldoCanjeadoCentimos = :c,
           ClubModoIncompatible = 0
         WHERE Empresa = :e AND Tipo = :t AND Albaran = :a'
      )->execute([
        'c' => $canjeCentimos,
        'e' => $empresa,
        't' => $tipoDoc,
        'a' => $albaran,
      ]);
    }
    $this->sincronizarAcumuladoVisible($cliente);

    return [
      'canjeCentimos' => $canjeCentimos,
      'valeId' => $valeId,
      'valeCentimos' => $valeCentimos,
      'promoId' => null,
      'incompatible' => false,
    ];
  }

  /**
   * @param array<string, mixed> $cabecera
   * @return array<string, mixed>
   */
  public function procesarAlCierre(
    string $empresa,
    array $cabecera,
    string $tipoDoc,
    int $albaran,
    string $opcionFinalizar,
    bool $esTicketAFactura,
    array $bodyAlta = []
  ): array {
    $nada = ['aplicado' => false, 'clubFidelizacion' => null, 'avisos' => []];
    if (!$this->motorActivo($empresa) || !$this->schemaListo()) {
      return $nada;
    }
    $opcion = strtoupper(substr(trim($opcionFinalizar), 0, 1));
    if (!in_array($opcion, ['T', 'A', 'F'], true) || $esTicketAFactura) {
      return $nada;
    }
    $cliente = trim((string) ($cabecera['cliente'] ?? ''));
    if ($cliente === '' || strcasecmp($cliente, 'ZZZZZZZZZ') === 0) {
      return $nada;
    }
    if (!empty($bodyAlta['altaClub'])) {
      $this->darAltaClub($cliente, $bodyAlta);
    }
    if (!$this->esSocioClub($cliente)) {
      return $nada;
    }
    $tipo = $this->fidelizacion->tipoCalculoTienda($empresa);
    if ($tipo === null) {
      return $nada;
    }
    $cfg = $this->configClub($tipo['configuracion']);
    $incompatible = (int) ($cabecera['clubModoIncompatible'] ?? 0) === 1
      || !empty($cabecera['ClubModoIncompatible']);
    if (!$incompatible) {
      $st = $this->pdo->prepare(
        'SELECT ISNULL(ClubModoIncompatible, 0) FROM AlbaranesVentasCab
         WHERE Empresa = :e AND Tipo = :t AND Albaran = :a'
      );
      $st->execute(['e' => $empresa, 't' => $tipoDoc, 'a' => $albaran]);
      $incompatible = (int) $st->fetchColumn() !== 0;
    }
    $excl = $this->mapExclusiones($cfg['exclusiones'] ?? []);
    $base = $this->fidelizacion->baseElegibleCabecera($cabecera, $excl);
    $canjeEuros = (float) ($cabecera['clubSaldoCanjeadoCentimos'] ?? 0) / 100;
    if ($canjeEuros < 0.001) {
      $stC = $this->pdo->prepare(
        'SELECT ISNULL(ClubSaldoCanjeadoCentimos, 0) FROM AlbaranesVentasCab
         WHERE Empresa = :e AND Tipo = :t AND Albaran = :a'
      );
      $stC->execute(['e' => $empresa, 't' => $tipoDoc, 'a' => $albaran]);
      $canjeEuros = ((int) $stC->fetchColumn()) / 100;
    }
    $dtoFid = (float) ($cabecera['descuentoFidelizacion'] ?? 0);
    $neto = max(0.0, round($base - $canjeEuros, 2));
    $generadoCentimos = 0;
    if (!$incompatible && $neto > 0.001) {
      $generadoCentimos = (int) round($neto * $cfg['pjeAcumulacion'], 0);
      if ($generadoCentimos > 0) {
        $this->crearLote(
          $cliente,
          $empresa,
          $generadoCentimos,
          $cfg['caducidadMeses'],
          $tipoDoc,
          $albaran
        );
      }
    }
    $this->pdo->prepare(
      'UPDATE AlbaranesVentasCab SET ClubSaldoGeneradoCentimos = :g
       WHERE Empresa = :e AND Tipo = :t AND Albaran = :a'
    )->execute([
      'g' => $generadoCentimos,
      'e' => $empresa,
      't' => $tipoDoc,
      'a' => $albaran,
    ]);
    $this->evaluarValeBienvenida($empresa, $cliente, $cfg, $base, $bodyAlta);
    $this->evaluarValeCumpleanos($empresa, $cliente, $cfg, $base);
    $this->sincronizarAcumuladoVisible($cliente);
    $this->caducarLotesVencidos($cliente);

    return [
      'aplicado' => true,
      'avisos' => [],
      'clubFidelizacion' => [
        'elegible' => $base,
        'generadoEuros' => round($generadoCentimos / 100, 2),
        'usadoEuros' => round($canjeEuros, 2),
        'disponibleEuros' => round($this->saldoCentimosDisponibles($cliente) / 100, 2),
        'proximaCaducidad' => $this->proximaCaducidad($cliente),
        'modoIncompatible' => $incompatible,
      ],
    ];
  }

  public function revertirDocumento(string $empresa, string $tipoDoc, int $albaran, string $cliente): void
  {
    if (!$this->schemaListo()) {
      return;
    }
    $cliente = trim($cliente);
    if ($cliente === '') {
      return;
    }
    $st = $this->pdo->prepare(
      'SELECT ISNULL(ClubSaldoGeneradoCentimos, 0) AS Gen,
              ISNULL(ClubSaldoCanjeadoCentimos, 0) AS Canje
       FROM AlbaranesVentasCab
       WHERE Empresa = :e AND Tipo = :t AND Albaran = :a'
    );
    $st->execute(['e' => $empresa, 't' => $tipoDoc, 'a' => $albaran]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      return;
    }
    $gen = (int) ($row['Gen'] ?? 0);
    $canje = (int) ($row['Canje'] ?? 0);
    if ($gen > 0) {
      $this->revertirLotesDocumento($cliente, $tipoDoc, $albaran, $gen);
    }
    if ($canje > 0) {
      $this->restaurarCanjeDocumento($cliente, $empresa, $canje, $tipoDoc, $albaran);
    }
    $this->restaurarValesDocumento($cliente, $tipoDoc, $albaran);
    $this->sincronizarAcumuladoVisible($cliente);
  }

  /**
   * Devuelve saldo club proporcional a un abono (euros de canje club en origen).
   */
  public function devolverCanjeAbono(string $empresa, string $cliente, float $eurosCanjeClub): void
  {
    if ($eurosCanjeClub < 0.005 || !$this->motorActivo($empresa)) {
      return;
    }
    $centimos = (int) round($eurosCanjeClub * 100);
    if ($centimos < 1) {
      return;
    }
    $tipo = $this->fidelizacion->tipoCalculoTienda($empresa);
    $meses = $tipo !== null ? $this->configClub($tipo['configuracion'])['caducidadMeses'] : 12;
    $this->crearLote($cliente, $empresa, $centimos, $meses, null, null, 'DEVOLUCION');
    $this->sincronizarAcumuladoVisible($cliente);
  }

  /**
   * @param array<string, mixed> $body
   */
  public function guardarConfiguracionClub(string $codigo, array $body): array
  {
    $codigo = trim($codigo);
    $stmt = $this->pdo->prepare(
      'SELECT RTRIM([Motor]) FROM [TiposCalculoFidelizacion]
       WHERE RTRIM([Codigo]) = :codigo AND ISNULL([Baja], 0) = 0'
    );
    $stmt->execute(['codigo' => $codigo]);
    $motor = strtoupper(trim((string) $stmt->fetchColumn()));
    if ($motor !== 'CLUB_SALDO') {
      throw new \InvalidArgumentException('El modelo seleccionado no es club saldo');
    }
    $exclusionesBody = $body['exclusiones'] ?? [];
    if (!is_array($exclusionesBody)) {
      throw new \InvalidArgumentException('Las exclusiones no son válidas');
    }
    $stmtCfg = $this->pdo->prepare(
      'SELECT [Configuracion] FROM [TiposCalculoFidelizacion] WHERE RTRIM([Codigo]) = :codigo'
    );
    $stmtCfg->execute(['codigo' => $codigo]);
    $actual = json_decode((string) $stmtCfg->fetchColumn(), true);
    if (!is_array($actual)) {
      $actual = [];
    }
    $actual['exclusiones'] = array_values($exclusionesBody);
    $json = json_encode($actual, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
      throw new \RuntimeException('No se pudo guardar la configuración del club');
    }
    $this->pdo->prepare(
      'UPDATE [TiposCalculoFidelizacion] SET [Configuracion] = :cfg WHERE RTRIM([Codigo]) = :codigo'
    )->execute(['cfg' => $json, 'codigo' => $codigo]);

    return $actual;
  }

  /**
   * @return array<string, mixed>
   */
  public function informeMensual(string $empresa, int $anio, int $mes): array
  {
    if (!$this->schemaListo()) {
      return ['sociosNuevos' => 0, 'generadoCentimos' => 0, 'canjeadoCentimos' => 0, 'caducadoCentimos' => 0];
    }
    $desde = sprintf('%04d-%02d-01 00:00:00', $anio, $mes);
    $hasta = date('Y-m-t 23:59:59', strtotime($desde));
    $stN = $this->pdo->prepare(
      'SELECT COUNT(*) FROM Clientes
       WHERE FechaAltaClub >= :d AND FechaAltaClub <= :h'
    );
    $stN->execute(['d' => $desde, 'h' => $hasta]);
    $sociosNuevos = (int) $stN->fetchColumn();
    $stG = $this->pdo->prepare(
      'SELECT ISNULL(SUM(ClubSaldoGeneradoCentimos), 0) FROM AlbaranesVentasCab
       WHERE Empresa = :e AND Fecha >= :d AND Fecha <= :h'
    );
    $stG->execute(['e' => $empresa, 'd' => $desde, 'h' => $hasta]);
    $generado = (int) $stG->fetchColumn();
    $stC = $this->pdo->prepare(
      'SELECT ISNULL(SUM(ClubSaldoCanjeadoCentimos), 0) FROM AlbaranesVentasCab
       WHERE Empresa = :e AND Fecha >= :d AND Fecha <= :h'
    );
    $stC->execute(['e' => $empresa, 'd' => $desde, 'h' => $hasta]);
    $canjeado = (int) $stC->fetchColumn();
    $stCad = $this->pdo->prepare(
      'SELECT ISNULL(SUM(ABS(Centimos)), 0) FROM FidelizacionSaldoMovimientos
       WHERE Empresa = :e AND TipoMov = \'CADUCIDAD\' AND CreadoEn >= :d AND CreadoEn <= :h'
    );
    $stCad->execute(['e' => $empresa, 'd' => $desde, 'h' => $hasta]);
    $caducado = (int) $stCad->fetchColumn();

    return [
      'anio' => $anio,
      'mes' => $mes,
      'sociosNuevos' => $sociosNuevos,
      'generadoEuros' => round($generado / 100, 2),
      'canjeadoEuros' => round($canjeado / 100, 2),
      'caducadoEuros' => round($caducado / 100, 2),
    ];
  }

  private function schemaListo(): bool
  {
    if (self::$schemaReady !== null) {
      return self::$schemaReady;
    }
    try {
      if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $stmt = $this->pdo->query(
          "SELECT 1 FROM information_schema.TABLES
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'FidelizacionSaldoLotes' LIMIT 1"
        );
        self::$schemaReady = (bool) $stmt->fetchColumn();
      } else {
        $obj = $this->pdo->query("SELECT OBJECT_ID('dbo.FidelizacionSaldoLotes', 'U')")->fetchColumn();
        self::$schemaReady = $obj !== false && $obj !== null;
      }
    } catch (\Throwable $e) {
      self::$schemaReady = false;
    }

    return self::$schemaReady;
  }

  /**
   * @param array<string, mixed> $cfgRaw
   * @return array{pjeAcumulacion: float, caducidadMeses: int, minSaldoCanjeVisibleEuros: float, promoIncompatiblePct: float, exclusiones: list<mixed>, bienvenida: array<string, float|int>, cumpleanos: array<string, float|int>}
   */
  private function configClub(array $cfgRaw): array
  {
    return [
      'pjeAcumulacion' => (float) ($cfgRaw['pjeAcumulacion'] ?? 2.5),
      'caducidadMeses' => max(1, (int) ($cfgRaw['caducidadMeses'] ?? 12)),
      'minSaldoCanjeVisibleEuros' => (float) ($cfgRaw['minSaldoCanjeVisibleEuros'] ?? 1),
      'promoIncompatiblePct' => (float) ($cfgRaw['promoIncompatiblePct'] ?? 10),
      'exclusiones' => is_array($cfgRaw['exclusiones'] ?? null) ? $cfgRaw['exclusiones'] : [],
      'bienvenida' => is_array($cfgRaw['bienvenida'] ?? null) ? $cfgRaw['bienvenida'] : [],
      'cumpleanos' => is_array($cfgRaw['cumpleanos'] ?? null) ? $cfgRaw['cumpleanos'] : [],
    ];
  }

  /**
   * @param list<mixed> $lista
   * @return array<string, true>
   */
  /**
   * Importe del ticket antes de descontar saldo/vale club (neto a pagar + dto. fidelización ya aplicada).
   */
  private function importeTopeCanjeClub(array $cabecera): float
  {
    $neto = max(0.0, (float) ($cabecera['importe'] ?? 0));
    $dtoFid = max(0.0, (float) ($cabecera['descuentoFidelizacion'] ?? 0));

    return round($neto + $dtoFid, 2);
  }

  private function mapExclusiones(array $lista): array
  {
    $out = [];
    foreach ($lista as $item) {
      if (!is_array($item)) {
        continue;
      }
      $tipo = strtoupper(trim((string) ($item['tipo'] ?? '')));
      $codigo = trim((string) ($item['codigo'] ?? ''));
      if (!in_array($tipo, ['M', 'F', 'S', 'A'], true)) {
        continue;
      }
      if ($codigo === '' && $tipo !== 'M') {
        continue;
      }
      $out[$tipo . ':' . strtoupper($codigo)] = true;
    }

    return $out;
  }

  private function esSocioClub(string $cliente): bool
  {
    $st = $this->pdo->prepare(
      'SELECT FechaAltaClub, RTRIM(ISNULL(TarjetaFidelizacion, \'\')) AS Tarjeta
       FROM Clientes WHERE RTRIM(Codigo) = :c'
    );
    $st->execute(['c' => trim($cliente)]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      return false;
    }
    if (!empty($row['FechaAltaClub'])) {
      return true;
    }

    return trim((string) ($row['Tarjeta'] ?? '')) !== '';
  }

  private function saldoCentimosDisponibles(string $cliente): int
  {
    if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
      $st = $this->pdo->prepare(
        'SELECT IFNULL(SUM(SaldoCentimosRestante), 0) FROM FidelizacionSaldoLotes
         WHERE TRIM(Cliente) = :c AND SaldoCentimosRestante > 0 AND CaducaEn > NOW()'
      );
    } else {
      $st = $this->pdo->prepare(
        'SELECT ISNULL(SUM(SaldoCentimosRestante), 0) FROM FidelizacionSaldoLotes
         WHERE RTRIM(Cliente) = :c AND SaldoCentimosRestante > 0 AND CaducaEn > GETDATE()'
      );
    }
    $st->execute(['c' => trim($cliente)]);

    return (int) $st->fetchColumn();
  }

  private function proximaCaducidad(string $cliente): ?string
  {
    $sql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
      ? 'SELECT MIN(CaducaEn) FROM FidelizacionSaldoLotes
         WHERE TRIM(Cliente) = :c AND SaldoCentimosRestante > 0 AND CaducaEn > NOW()'
      : 'SELECT MIN(CaducaEn) FROM FidelizacionSaldoLotes
         WHERE RTRIM(Cliente) = :c AND SaldoCentimosRestante > 0 AND CaducaEn > GETDATE()';
    $st = $this->pdo->prepare($sql);
    $st->execute(['c' => trim($cliente)]);
    $v = $st->fetchColumn();
    if ($v === false || $v === null) {
      return null;
    }

    return is_string($v) ? $v : date('Y-m-d H:i:s', strtotime((string) $v));
  }

  private function caducarLotesVencidos(string $cliente): void
  {
    $sql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
      ? 'SELECT Id, SaldoCentimosRestante, Empresa FROM FidelizacionSaldoLotes
         WHERE TRIM(Cliente) = :c AND SaldoCentimosRestante > 0 AND CaducaEn <= NOW()'
      : 'SELECT Id, SaldoCentimosRestante, Empresa FROM FidelizacionSaldoLotes
         WHERE RTRIM(Cliente) = :c AND SaldoCentimosRestante > 0 AND CaducaEn <= GETDATE()';
    $st = $this->pdo->prepare($sql);
    $st->execute(['c' => trim($cliente)]);
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $rest = (int) ($row['SaldoCentimosRestante'] ?? 0);
      if ($rest < 1) {
        continue;
      }
      $id = (int) $row['Id'];
      $this->pdo->prepare(
        'UPDATE FidelizacionSaldoLotes SET SaldoCentimosRestante = 0 WHERE Id = :id'
      )->execute(['id' => $id]);
      $this->insertMovimiento(
        $id,
        $cliente,
        'CADUCIDAD',
        -$rest,
        (string) ($row['Empresa'] ?? ''),
        null,
        null
      );
    }
  }

  private function consumirSaldoFifo(
    string $cliente,
    string $empresa,
    int $centimos,
    string $tipoDoc,
    int $albaran
  ): void {
    $pendiente = $centimos;
    $order = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
      ? 'CaducaEn ASC, Id ASC'
      : 'CaducaEn ASC, Id ASC';
    $filtro = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
      ? 'TRIM(Cliente) = :c AND SaldoCentimosRestante > 0 AND CaducaEn > NOW()'
      : 'RTRIM(Cliente) = :c AND SaldoCentimosRestante > 0 AND CaducaEn > GETDATE()';
    $st = $this->pdo->prepare(
      "SELECT Id, SaldoCentimosRestante FROM FidelizacionSaldoLotes
       WHERE {$filtro} ORDER BY {$order}"
    );
    $st->execute(['c' => trim($cliente)]);
    while ($pendiente > 0 && ($row = $st->fetch(PDO::FETCH_ASSOC))) {
      $id = (int) $row['Id'];
      $rest = (int) $row['SaldoCentimosRestante'];
      $usar = min($rest, $pendiente);
      $this->pdo->prepare(
        'UPDATE FidelizacionSaldoLotes SET SaldoCentimosRestante = SaldoCentimosRestante - :u WHERE Id = :id'
      )->execute(['u' => $usar, 'id' => $id]);
      $this->insertMovimiento($id, $cliente, 'CANJE', -$usar, $empresa, $tipoDoc, $albaran);
      $pendiente -= $usar;
    }
    if ($pendiente > 0) {
      throw new \RuntimeException('Saldo club insuficiente para el canje solicitado');
    }
  }

  private function crearLote(
    string $cliente,
    string $empresa,
    int $centimos,
    int $mesesCaducidad,
    ?string $tipoDoc,
    ?int $albaran,
    string $tipoMov = 'GENERACION'
  ): void {
    $caduca = date('Y-m-d H:i:s', strtotime('+' . $mesesCaducidad . ' months'));
    if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
      $this->pdo->prepare(
        'INSERT INTO FidelizacionSaldoLotes
         (Cliente, Empresa, ImporteCentimos, SaldoCentimosRestante, CaducaEn, TipoDocOrigen, AlbaranOrigen)
         VALUES (:c, :e, :i, :i, :cad, :td, :alb)'
      )->execute([
        'c' => $cliente,
        'e' => $empresa,
        'i' => $centimos,
        'cad' => $caduca,
        'td' => $tipoDoc,
        'alb' => $albaran,
      ]);
    } else {
      $this->pdo->prepare(
        'INSERT INTO FidelizacionSaldoLotes
         (Cliente, Empresa, ImporteCentimos, SaldoCentimosRestante, GeneradoEn, CaducaEn, TipoDocOrigen, AlbaranOrigen)
         VALUES (:c, :e, :i, :i, GETDATE(), :cad, :td, :alb)'
      )->execute([
        'c' => $cliente,
        'e' => $empresa,
        'i' => $centimos,
        'cad' => $caduca,
        'td' => $tipoDoc,
        'alb' => $albaran,
      ]);
    }
    $loteId = (int) $this->pdo->lastInsertId();
    $this->insertMovimiento($loteId, $cliente, $tipoMov, $centimos, $empresa, $tipoDoc, $albaran);
  }

  private function insertMovimiento(
    ?int $loteId,
    string $cliente,
    string $tipoMov,
    int $centimos,
    string $empresa,
    ?string $tipoDoc,
    ?int $albaran
  ): void {
    $this->pdo->prepare(
      'INSERT INTO FidelizacionSaldoMovimientos
       (LoteId, Cliente, TipoMov, Centimos, Empresa, TipoDoc, Albaran)
       VALUES (:l, :c, :t, :n, :e, :td, :a)'
    )->execute([
      'l' => $loteId,
      'c' => $cliente,
      't' => $tipoMov,
      'n' => $centimos,
      'e' => $empresa,
      'td' => $tipoDoc,
      'a' => $albaran,
    ]);
  }

  private function sincronizarAcumuladoVisible(string $cliente): void
  {
    $euros = round($this->saldoCentimosDisponibles($cliente) / 100, 2);
    $this->pdo->prepare(
      'UPDATE Clientes SET AcumuladoFidelizacion = :s WHERE RTRIM(Codigo) = :c'
    )->execute(['s' => $euros, 'c' => trim($cliente)]);
  }

  /**
   * @return list<array<string, mixed>>
   */
  private function valesActivos(string $empresa, string $cliente): array
  {
    if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
      $st = $this->pdo->prepare(
        'SELECT Id, Tipo, ImporteCentimos, MinCompraCentimos, ValidoHasta
         FROM FidelizacionValesClub WHERE TRIM(Cliente) = :c AND TRIM(Empresa) = :e
         AND Usado = 0 AND ValidoDesde <= NOW() AND ValidoHasta >= NOW()'
      );
    } else {
      $st = $this->pdo->prepare(
        'SELECT Id, Tipo, ImporteCentimos, MinCompraCentimos, ValidoHasta
         FROM FidelizacionValesClub
         WHERE RTRIM(Cliente) = :c AND RTRIM(Empresa) = :e AND Usado = 0
           AND ValidoDesde <= GETDATE() AND ValidoHasta >= GETDATE()'
      );
    }
    $st->execute(['c' => trim($cliente), 'e' => trim($empresa)]);
    $out = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $out[] = [
        'id' => (int) $row['Id'],
        'tipo' => (string) $row['Tipo'],
        'importeEuros' => round(((int) $row['ImporteCentimos']) / 100, 2),
        'minCompraEuros' => round(((int) $row['MinCompraCentimos']) / 100, 2),
        'validoHasta' => (string) ($row['ValidoHasta'] ?? ''),
      ];
    }

    return $out;
  }

  /**
   * @return array{id: int, importeEuros: float, minCompraEuros: float, usado: bool}|null
   */
  private function cargarVale(int $id, string $cliente): ?array
  {
    $st = $this->pdo->prepare(
      'SELECT Id, ImporteCentimos, MinCompraCentimos, Usado FROM FidelizacionValesClub
       WHERE Id = :id AND RTRIM(Cliente) = :c'
    );
    $st->execute(['id' => $id, 'c' => trim($cliente)]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      return null;
    }

    return [
      'id' => (int) $row['Id'],
      'importeEuros' => round(((int) $row['ImporteCentimos']) / 100, 2),
      'minCompraEuros' => round(((int) $row['MinCompraCentimos']) / 100, 2),
      'usado' => (int) ($row['Usado'] ?? 0) !== 0,
    ];
  }

  private function marcarValeUsado(
    int $valeId,
    string $cliente,
    string $empresa,
    string $tipoDoc,
    int $albaran
  ): void {
    $this->pdo->prepare(
      'UPDATE FidelizacionValesClub SET Usado = 1, TipoDocUso = :t, AlbaranUso = :a
       WHERE Id = :id AND RTRIM(Cliente) = :c'
    )->execute([
      't' => $tipoDoc,
      'a' => $albaran,
      'id' => $valeId,
      'c' => trim($cliente),
    ]);
  }

  /**
   * @return array{id: int, pjeDescuento: float, nombre: string}|null
   */
  private function promoActivaHoy(string $empresa, float $pjeEsperado): ?array
  {
    if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
      $st = $this->pdo->prepare(
        'SELECT Id, Nombre, PjeDescuento FROM ClubPromociones
         WHERE TRIM(Empresa) = :e AND Activa = 1 AND FechaInicio <= NOW() AND FechaFin >= NOW()
         AND ABS(PjeDescuento - :p) < 0.01 ORDER BY Id DESC LIMIT 1'
      );
    } else {
      $st = $this->pdo->prepare(
        'SELECT TOP 1 Id, Nombre, PjeDescuento FROM ClubPromociones
         WHERE RTRIM(Empresa) = :e AND Activa = 1 AND FechaInicio <= GETDATE() AND FechaFin >= GETDATE()
         AND ABS(PjeDescuento - :p) < 0.01 ORDER BY Id DESC'
      );
    }
    $st->execute(['e' => trim($empresa), 'p' => $pjeEsperado]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      return null;
    }

    return [
      'id' => (int) $row['Id'],
      'nombre' => (string) $row['Nombre'],
      'pjeDescuento' => (float) $row['PjeDescuento'],
    ];
  }

  /**
   * @param array<string, mixed> $bodyAlta
   */
  private function darAltaClub(string $cliente, array $bodyAlta): void
  {
    $ahora = date('Y-m-d H:i:s');
    $email = !empty($bodyAlta['consentimientoEmail']);
    $wa = !empty($bodyAlta['consentimientoWhatsApp']);
    if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
      $this->pdo->prepare(
        'UPDATE Clientes SET
           FechaAltaClub = IFNULL(FechaAltaClub, :f),
           ConsentimientoEmailClub = :e,
           ConsentimientoWhatsAppClub = :w,
           ConsentimientoClubEn = IF(:cons = 1, :f, ConsentimientoClubEn)
         WHERE TRIM(Codigo) = :c'
      )->execute([
        'f' => $ahora,
        'e' => $email ? 1 : 0,
        'w' => $wa ? 1 : 0,
        'cons' => ($email || $wa) ? 1 : 0,
        'c' => trim($cliente),
      ]);

      return;
    }
    $this->pdo->prepare(
      'UPDATE Clientes SET
         FechaAltaClub = COALESCE(FechaAltaClub, :f),
         ConsentimientoEmailClub = :e,
         ConsentimientoWhatsAppClub = :w,
         ConsentimientoClubEn = CASE WHEN :cons = 1 THEN :f ELSE ConsentimientoClubEn END
       WHERE RTRIM(Codigo) = :c'
    )->execute([
      'f' => $ahora,
      'e' => $email ? 1 : 0,
      'w' => $wa ? 1 : 0,
      'cons' => ($email || $wa) ? 1 : 0,
      'c' => trim($cliente),
    ]);
  }

  /**
   * @param array<string, mixed> $cfg
   * @param array<string, mixed> $bodyAlta
   */
  private function evaluarValeBienvenida(
    string $empresa,
    string $cliente,
    array $cfg,
    float $baseElegible,
    array $bodyAlta
  ): void {
    if (empty($bodyAlta['emitirBienvenida']) && empty($bodyAlta['altaClub'])) {
      return;
    }
    $b = $cfg['bienvenida'];
    $min = (float) ($b['minCompraElegible'] ?? 30);
    if ($baseElegible + 0.001 < $min) {
      return;
    }
    $st = $this->pdo->prepare(
      'SELECT COUNT(*) FROM FidelizacionValesClub WHERE RTRIM(Cliente) = :c AND Tipo = \'BIENVENIDA\''
    );
    $st->execute(['c' => trim($cliente)]);
    if ((int) $st->fetchColumn() > 0) {
      return;
    }
    $this->emitirValeClub($empresa, $cliente, 'BIENVENIDA', $b);
  }

  /** @param array<string, mixed> $cfg */
  private function evaluarValeCumpleanos(string $empresa, string $cliente, array $cfg, float $baseElegible): void
  {
    $b = $cfg['cumpleanos'];
    $min = (float) ($b['minCompraElegible'] ?? 30);
    if ($baseElegible + 0.001 < $min) {
      return;
    }
    $st = $this->pdo->prepare(
      'SELECT FechaNacimiento, FechaAltaClub FROM Clientes WHERE RTRIM(Codigo) = :c'
    );
    $st->execute(['c' => trim($cliente)]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row === false || empty($row['FechaNacimiento']) || empty($row['FechaAltaClub'])) {
      return;
    }
    $nac = strtotime((string) $row['FechaNacimiento']);
    $alta = strtotime((string) $row['FechaAltaClub']);
    if ($nac === false || $alta === false) {
      return;
    }
    $diasMin = (int) ($b['diasAltaMinimos'] ?? 30);
    if ((time() - $alta) < $diasMin * 86400) {
      return;
    }
    if ((int) date('m-d') !== (int) date('m-d', $nac)) {
      return;
    }
    $st2 = $this->pdo->prepare(
      'SELECT COUNT(*) FROM FidelizacionValesClub
       WHERE RTRIM(Cliente) = :c AND Tipo = \'CUMPLE\'
         AND YEAR(ValidoDesde) = YEAR(GETDATE())'
    );
    if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
      $st2 = $this->pdo->prepare(
        'SELECT COUNT(*) FROM FidelizacionValesClub
         WHERE TRIM(Cliente) = :c AND Tipo = \'CUMPLE\' AND YEAR(ValidoDesde) = YEAR(NOW())'
      );
    }
    $st2->execute(['c' => trim($cliente)]);
    if ((int) $st2->fetchColumn() > 0) {
      return;
    }
    $this->emitirValeClub($empresa, $cliente, 'CUMPLE', $b);
  }

  /** @param array<string, mixed> $reglas */
  private function emitirValeClub(string $empresa, string $cliente, string $tipo, array $reglas): void
  {
    $importe = (int) round(((float) ($reglas['importe'] ?? 5)) * 100);
    $min = (int) round(((float) ($reglas['minCompraElegible'] ?? 30)) * 100);
    $dias = (int) ($reglas['diasValidez'] ?? 30);
    $desde = date('Y-m-d H:i:s');
    $hasta = date('Y-m-d H:i:s', strtotime('+' . max(1, $dias) . ' days'));
    $this->pdo->prepare(
      'INSERT INTO FidelizacionValesClub
       (Cliente, Empresa, Tipo, ImporteCentimos, MinCompraCentimos, ValidoDesde, ValidoHasta, Usado)
       VALUES (:c, :e, :t, :i, :m, :d, :h, 0)'
    )->execute([
      'c' => trim($cliente),
      'e' => trim($empresa),
      't' => $tipo,
      'i' => $importe,
      'm' => $min,
      'd' => $desde,
      'h' => $hasta,
    ]);
  }

  private function revertirLotesDocumento(string $cliente, string $tipoDoc, int $albaran, int $genEsperado): void
  {
    $st = $this->pdo->prepare(
      'SELECT Id, SaldoCentimosRestante, ImporteCentimos, Empresa FROM FidelizacionSaldoLotes
       WHERE RTRIM(Cliente) = :c AND TipoDocOrigen = :t AND AlbaranOrigen = :a'
    );
    $st->execute(['c' => trim($cliente), 't' => $tipoDoc, 'a' => $albaran]);
    $revertido = 0;
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $id = (int) $row['Id'];
      $rest = (int) $row['SaldoCentimosRestante'];
      if ($rest < 1) {
        continue;
      }
      $this->pdo->prepare('DELETE FROM FidelizacionSaldoLotes WHERE Id = :id')->execute(['id' => $id]);
      $this->insertMovimiento(
        $id,
        $cliente,
        'DEVOLUCION',
        -$rest,
        (string) ($row['Empresa'] ?? ''),
        $tipoDoc,
        $albaran
      );
      $revertido += $rest;
    }
    if ($revertido > 0 && $revertido < $genEsperado) {
      // Lote parcialmente consumido: ajuste residual.
      $diff = $genEsperado - $revertido;
      if ($diff > 0) {
        $this->crearLote($cliente, '', $diff, 12, $tipoDoc, $albaran, 'AJUSTE');
      }
    }
  }

  private function restaurarCanjeDocumento(
    string $cliente,
    string $empresa,
    int $canjeCentimos,
    string $tipoDoc,
    int $albaran
  ): void {
    $st = $this->pdo->prepare(
      'SELECT LoteId, Centimos FROM FidelizacionSaldoMovimientos
       WHERE RTRIM(Cliente) = :c AND TipoMov = \'CANJE\' AND TipoDoc = :t AND Albaran = :a'
    );
    $st->execute(['c' => trim($cliente), 't' => $tipoDoc, 'a' => $albaran]);
    $restaurado = 0;
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $loteId = (int) ($row['LoteId'] ?? 0);
      $c = abs((int) ($row['Centimos'] ?? 0));
      if ($loteId > 0 && $c > 0) {
        $this->pdo->prepare(
          'UPDATE FidelizacionSaldoLotes SET SaldoCentimosRestante = SaldoCentimosRestante + :c WHERE Id = :id'
        )->execute(['c' => $c, 'id' => $loteId]);
        $restaurado += $c;
      }
    }
    if ($restaurado < $canjeCentimos) {
      $this->crearLote(
        $cliente,
        $empresa,
        $canjeCentimos - $restaurado,
        12,
        $tipoDoc,
        $albaran,
        'DEVOLUCION'
      );
    }
  }

  private function restaurarValesDocumento(string $cliente, string $tipoDoc, int $albaran): void
  {
    $this->pdo->prepare(
      'UPDATE FidelizacionValesClub SET Usado = 0, TipoDocUso = NULL, AlbaranUso = NULL
       WHERE RTRIM(Cliente) = :c AND TipoDocUso = :t AND AlbaranUso = :a'
    )->execute(['c' => trim($cliente), 't' => $tipoDoc, 'a' => $albaran]);
  }
}
