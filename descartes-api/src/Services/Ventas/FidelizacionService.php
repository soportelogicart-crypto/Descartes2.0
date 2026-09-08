<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Ventas;

use PDO;

/**
 * Acumulación de fidelización al cerrar documentos de venta (007-fidelizacion-clientes US3).
 * Resuelve tienda → tipo de cálculo → motor registrado (EUROS / PUNTOS / NINGUNO).
 */
final class FidelizacionService
{
  private const MOTORES_REGISTRADOS = ['NINGUNO', 'EUROS', 'PUNTOS'];

  private PDO $pdo;

  /** @var bool|null */
  private static $schemaReady = null;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  /**
   * @param array<string, mixed> $cabecera Ficha de venta con lineas (obtenerFicha)
   * @param array{sesion: int, facturaTipo: string, factura: int} $estadoPrevio Estado antes del cierre
   * @return array{avisos: list<string>, aplicado: bool}
   */
  public function procesarAlCierre(
    string $empresa,
    array $cabecera,
    string $opcionFinalizar,
    bool $esTicketAFactura,
    array $estadoPrevio
  ): array {
    $avisos = [];

    if (!$this->schemaListo()) {
      return ['avisos' => $avisos, 'aplicado' => false];
    }

    if (!$this->debeProcesar($opcionFinalizar, $esTicketAFactura, $estadoPrevio)) {
      return ['avisos' => $avisos, 'aplicado' => false];
    }

    $clienteCodigo = trim((string) ($cabecera['cliente'] ?? ''));
    if ($clienteCodigo === '' || strtoupper($clienteCodigo) === 'ZZZZZZZZZ') {
      return ['avisos' => $avisos, 'aplicado' => false];
    }

    $politica = $this->cargarPoliticaTienda($empresa);
    if ($politica === null) {
      return ['avisos' => $avisos, 'aplicado' => false];
    }

    if (!empty($politica['bloqueo'])) {
      return ['avisos' => $avisos, 'aplicado' => false];
    }

    $tipoCodigo = trim((string) ($politica['tipoCalculo'] ?? ''));
    if ($tipoCodigo === '') {
      return ['avisos' => $avisos, 'aplicado' => false];
    }

    $tipo = $this->cargarTipoCalculo($tipoCodigo);
    if ($tipo === null) {
      $avisos[] = "Fidelización: el tipo de cálculo «{$tipoCodigo}» no existe o está de baja; la venta se ha cerrado sin acumular.";
      return ['avisos' => $avisos, 'aplicado' => false];
    }

    $motor = strtoupper(trim((string) ($tipo['motor'] ?? 'NINGUNO')));
    if ($motor === 'NINGUNO') {
      return ['avisos' => $avisos, 'aplicado' => false];
    }

    if (!in_array($motor, self::MOTORES_REGISTRADOS, true)) {
      $avisos[] = "Fidelización: motor «{$motor}» no registrado en la API; la venta se ha cerrado sin acumular.";
      return ['avisos' => $avisos, 'aplicado' => false];
    }

    $socio = $this->cargarSocio($clienteCodigo);
    if ($socio === null) {
      return ['avisos' => $avisos, 'aplicado' => false];
    }

    if (!$this->socioElegible($motor, $socio)) {
      return ['avisos' => $avisos, 'aplicado' => false];
    }

    $base = $this->calcularBaseElegible($cabecera);
    $minimo = (float) ($politica['minimo'] ?? 0);
    if ($minimo > 0 && abs($base) < $minimo) {
      return ['avisos' => $avisos, 'aplicado' => false];
    }

    $factor = (float) ($tipo['factor'] ?? 1);
    if ($motor === 'EUROS') {
      $pje = (float) ($socio['pjeFidelizacion'] ?? 0);
      $delta = round($base * $pje / 100, 2);
      if (abs($delta) < 0.0001) {
        return ['avisos' => $avisos, 'aplicado' => false];
      }
      $this->actualizarAcumuladoEuros($clienteCodigo, $delta);
      return ['avisos' => $avisos, 'aplicado' => true];
    }

    // PUNTOS
    $deltaPuntos = (int) floor($base * $factor);
    if ($deltaPuntos === 0) {
      return ['avisos' => $avisos, 'aplicado' => false];
    }
    $this->actualizarAcumuladoPuntos($clienteCodigo, $deltaPuntos);
    return ['avisos' => $avisos, 'aplicado' => true];
  }

  /**
   * @param array{sesion: int, facturaTipo: string, factura: int} $estadoPrevio
   */
  private function debeProcesar(string $opcionFinalizar, bool $esTicketAFactura, array $estadoPrevio): bool
  {
    $opcion = strtoupper(substr(trim($opcionFinalizar), 0, 1));
    if ($opcion === 'P') {
      return false;
    }
    if ($esTicketAFactura) {
      return false;
    }
    if ($opcion === 'F') {
      $sesion = (int) ($estadoPrevio['sesion'] ?? 0);
      $factura = (int) ($estadoPrevio['factura'] ?? 0);
      $ft = strtoupper(trim((string) ($estadoPrevio['facturaTipo'] ?? '')));
      // Albarán ya cerrado (acumuló al cerrar): la factura no duplica.
      if ($sesion > 0 && $factura <= 0 && ($ft === '' || $ft === 'Z')) {
        return false;
      }
    }
    return in_array($opcion, ['T', 'A', 'F'], true);
  }

  private function schemaListo(): bool
  {
    if (self::$schemaReady !== null) {
      return self::$schemaReady;
    }
    try {
      $col = $this->pdo->query("SELECT COL_LENGTH('dbo.Empresas_Ges', 'TipoCalculoFidelizacion')")->fetchColumn();
      if ($col === false || $col === null) {
        self::$schemaReady = false;
        return false;
      }
      $obj = $this->pdo->query("SELECT OBJECT_ID('dbo.TiposCalculoFidelizacion', 'U')")->fetchColumn();
      self::$schemaReady = $obj !== false && $obj !== null;
    } catch (\Throwable $e) {
      self::$schemaReady = false;
    }
    return self::$schemaReady;
  }

  /**
   * @return array{bloqueo: bool, minimo: float, tipoCalculo: string}|null
   */
  private function cargarPoliticaTienda(string $empresa): ?array
  {
    $stmt = $this->pdo->prepare(
      'SELECT TOP 1
          ISNULL([BloqueoFidelizacion], 0) AS BloqueoFidelizacion,
          ISNULL([MinimoFidelizacion], 0) AS MinimoFidelizacion,
          RTRIM(ISNULL([TipoCalculoFidelizacion], \'\')) AS TipoCalculoFidelizacion
       FROM [Empresas_Ges]
       WHERE RTRIM([Codigo]) = :empresa'
    );
    $stmt->execute(['empresa' => trim($empresa)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      return null;
    }
    return [
      'bloqueo' => !empty($row['BloqueoFidelizacion']),
      'minimo' => (float) ($row['MinimoFidelizacion'] ?? 0),
      'tipoCalculo' => trim((string) ($row['TipoCalculoFidelizacion'] ?? '')),
    ];
  }

  /**
   * @return array{motor: string, factor: float}|null
   */
  private function cargarTipoCalculo(string $codigo): ?array
  {
    $stmt = $this->pdo->prepare(
      'SELECT TOP 1 RTRIM([Motor]) AS Motor, [Factor]
       FROM [TiposCalculoFidelizacion]
       WHERE RTRIM([Codigo]) = :codigo AND ISNULL([Baja], 0) = 0'
    );
    $stmt->execute(['codigo' => $codigo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      return null;
    }
    return [
      'motor' => trim((string) ($row['Motor'] ?? 'NINGUNO')),
      'factor' => (float) ($row['Factor'] ?? 1),
    ];
  }

  /**
   * @return array{pjeFidelizacion: float, tarjetaFidelizacion: string}|null
   */
  private function cargarSocio(string $clienteCodigo): ?array
  {
    $stmt = $this->pdo->prepare(
      'SELECT TOP 1
          ISNULL([PjeFidelizacion], 0) AS PjeFidelizacion,
          RTRIM(ISNULL([TarjetaFidelizacion], \'\')) AS TarjetaFidelizacion
       FROM [Clientes]
       WHERE RTRIM([Codigo]) = :codigo'
    );
    $stmt->execute(['codigo' => $clienteCodigo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      return null;
    }
    return [
      'pjeFidelizacion' => (float) ($row['PjeFidelizacion'] ?? 0),
      'tarjetaFidelizacion' => trim((string) ($row['TarjetaFidelizacion'] ?? '')),
    ];
  }

  /**
   * @param array{pjeFidelizacion: float, tarjetaFidelizacion: string} $socio
   */
  private function socioElegible(string $motor, array $socio): bool
  {
    $tarjeta = trim((string) ($socio['tarjetaFidelizacion'] ?? ''));
    if ($motor === 'PUNTOS') {
      return $tarjeta !== '';
    }
    $pje = (float) ($socio['pjeFidelizacion'] ?? 0);
    return $pje > 0;
  }

  /**
   * @param array<string, mixed> $cabecera
   */
  private function calcularBaseElegible(array $cabecera): float
  {
    $lineas = $cabecera['lineas'] ?? [];
    if (!is_array($lineas) || $lineas === []) {
      return 0.0;
    }

    $articulos = [];
    foreach ($lineas as $lin) {
      if (!is_array($lin)) {
        continue;
      }
      $art = trim((string) ($lin['articulo'] ?? ''));
      if ($art === '' || strtoupper($art) === 'NO') {
        continue;
      }
      $articulos[$art] = true;
    }

    $bloqueados = $this->articulosBloqueados(array_keys($articulos));

    $base = 0.0;
    foreach ($lineas as $lin) {
      if (!is_array($lin)) {
        continue;
      }
      $art = trim((string) ($lin['articulo'] ?? ''));
      if ($art === '' || strtoupper($art) === 'NO') {
        continue;
      }
      if (!empty($bloqueados[$art])) {
        continue;
      }
      if (isset($lin['importe'])) {
        $base += (float) $lin['importe'];
        continue;
      }
      $cant = (float) ($lin['cantidad'] ?? 0);
      $precio = (float) ($lin['precio'] ?? 0);
      $pjeDto = (float) ($lin['pjeDto'] ?? 0);
      $base += round($cant * $precio * (1 - $pjeDto / 100), 2);
    }

    return round($base, 2);
  }

  /**
   * @param list<string> $codigos
   * @return array<string, true>
   */
  private function articulosBloqueados(array $codigos): array
  {
    if ($codigos === []) {
      return [];
    }
    $bloqueados = [];
    $chunks = array_chunk($codigos, 100);
    foreach ($chunks as $chunk) {
      $placeholders = [];
      $params = [];
      foreach ($chunk as $i => $codigo) {
        $key = 'a' . $i;
        $placeholders[] = ':' . $key;
        $params[$key] = $codigo;
      }
      $sql = 'SELECT RTRIM([Codigo]) AS Codigo
              FROM [Articulos]
              WHERE RTRIM([Codigo]) IN (' . implode(', ', $placeholders) . ')
                AND ISNULL([BloqueoFidelizacion], 0) <> 0';
      $stmt = $this->pdo->prepare($sql);
      $stmt->execute($params);
      while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $cod = trim((string) ($row['Codigo'] ?? ''));
        if ($cod !== '') {
          $bloqueados[$cod] = true;
        }
      }
    }
    return $bloqueados;
  }

  private function actualizarAcumuladoEuros(string $clienteCodigo, float $delta): void
  {
    $stmt = $this->pdo->prepare(
      'UPDATE [Clientes]
       SET [AcumuladoFidelizacion] = ISNULL([AcumuladoFidelizacion], 0) + :delta
       WHERE RTRIM([Codigo]) = :codigo'
    );
    $stmt->execute(['delta' => $delta, 'codigo' => $clienteCodigo]);
  }

  private function actualizarAcumuladoPuntos(string $clienteCodigo, int $delta): void
  {
    $stmt = $this->pdo->prepare(
      'UPDATE [Clientes]
       SET [AcumuladoPuntos] = ISNULL([AcumuladoPuntos], 0) + :delta
       WHERE RTRIM([Codigo]) = :codigo'
    );
    $stmt->execute(['delta' => $delta, 'codigo' => $clienteCodigo]);
  }
}
