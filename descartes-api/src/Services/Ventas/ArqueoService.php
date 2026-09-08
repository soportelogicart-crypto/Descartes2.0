<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Ventas;

use PDO;

final class ArqueoService
{
  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  /**
   * Consulta operativa: lineas CobroDeArqueo + cabecera Sesiones + acciones por estado.
   *
   * @return array<string, mixed>
   */
  public function obtener(string $empresa, string $puesto, int $sesion): array
  {
    $empresa = trim($empresa);
    $puesto = trim($puesto);

    $sesMeta = $this->obtenerSesionMeta($empresa, $puesto, $sesion);
    $cerrada = (bool) ($sesMeta['cerrada'] ?? false);
    $arqueada = (bool) ($sesMeta['arqueada'] ?? false);
    $estado = $cerrada ? 'cerrada' : ($arqueada ? 'abierta_arqueada' : 'abierta');

    // Formas que cuentan para arqueo (legacy táctil: excluye crédito Agrupacion=3).
    $formas = $this->formasParaArqueo();
    $porCodigo = [];
    foreach ($formas as $f) {
      $porCodigo[$f['codigo']] = [
        'formaPago' => $f['codigo'],
        'descripcion' => $f['descripcion'],
        'agrupacion' => $f['agrupacion'],
        'cuentaParaArqueo' => true,
        'cajonElectronico' => $f['cajonElectronico'],
        'acumulado' => 0.0,
        'entrado' => 0.0,
        'cantidad' => 0,
        'diferencia' => 0.0,
      ];
    }

    $sql = 'SELECT a.Codigo, a.Acumulado, a.Entrado, a.Cantidad,
                   f.Descripcion, f.CobroDeArqueo, f.Agrupacion, f.CajonElectronico
            FROM Arqueo a
            LEFT JOIN FormasPago f ON f.Codigo = a.Codigo
            WHERE a.Empresa = :empresa AND a.Puesto = :puesto AND a.Sesion = :sesion
            ORDER BY a.Codigo';
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute(['empresa' => $empresa, 'puesto' => $puesto, 'sesion' => $sesion]);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $codigo = trim((string) ($row['Codigo'] ?? ''));
      if ($codigo === '') {
        continue;
      }
      $cuenta = !empty($row['CobroDeArqueo']);
      $agrupacion = (int) ($row['Agrupacion'] ?? 0);
      // Si no está en el mapa de formas válidas, igual mostrar si existe fila.
      if (!isset($porCodigo[$codigo])) {
        if (!$cuenta) {
          continue;
        }
        $porCodigo[$codigo] = [
          'formaPago' => $codigo,
          'descripcion' => $row['Descripcion'],
          'agrupacion' => $agrupacion,
          'cuentaParaArqueo' => true,
          'cajonElectronico' => !empty($row['CajonElectronico']),
          'acumulado' => 0.0,
          'entrado' => 0.0,
          'cantidad' => 0,
          'diferencia' => 0.0,
        ];
      }
      $acumulado = (float) ($row['Acumulado'] ?? 0);
      $entrado = (float) ($row['Entrado'] ?? 0);
      $porCodigo[$codigo]['acumulado'] = $acumulado;
      $porCodigo[$codigo]['entrado'] = $entrado;
      $porCodigo[$codigo]['cantidad'] = (int) ($row['Cantidad'] ?? 0);
      $porCodigo[$codigo]['diferencia'] = round($entrado - $acumulado, 2);
    }

    // Orden: Agrupacion, Codigo.
    $lineas = array_values($porCodigo);
    usort($lineas, static function (array $a, array $b): int {
      $cmp = ($a['agrupacion'] ?? 0) <=> ($b['agrupacion'] ?? 0);
      return $cmp !== 0 ? $cmp : strcmp((string) $a['formaPago'], (string) $b['formaPago']);
    });

    $totalAcumulado = 0.0;
    $totalEntrado = 0.0;
    foreach ($lineas as $l) {
      if (!empty($l['cuentaParaArqueo'])) {
        $totalAcumulado += (float) $l['acumulado'];
        $totalEntrado += (float) $l['entrado'];
      }
    }
    $totalDiferencia = round($totalEntrado - $totalAcumulado, 2);

    $dispositivo = $this->metaDispositivoPuesto($puesto);
    $tieneCajon = !empty($dispositivo['cajonElectronico']);
    $formaEfectivoSugerida = $this->resolverFormaEfectivoCierre($empresa, $puesto, $sesion);

    return [
      'empresa' => $empresa,
      'puesto' => $puesto,
      'sesion' => $sesion,
      'estado' => $estado,
      'cerrada' => $cerrada,
      'arqueada' => $arqueada,
      'fechaInicio' => $sesMeta['fechaInicio'] ?? null,
      'fechaFin' => $sesMeta['fechaFin'] ?? null,
      'cajeroArqueo' => $sesMeta['cajeroArqueo'] ?? null,
      'cajeroCierre' => $sesMeta['cajeroCierre'] ?? null,
      'contadores' => $sesMeta['contadores'] ?? [],
      'totalArqueo' => round($totalAcumulado, 2),
      'totalAcumulado' => round($totalAcumulado, 2),
      'totalEntrado' => round($totalEntrado, 2),
      'totalDiferencia' => $totalDiferencia,
      'lineas' => $lineas,
      'formaPagoEfectivoSugerida' => $formaEfectivoSugerida,
      'dispositivo' => $dispositivo,
      'accionesPermitidas' => [
        'situacion' => true,
        'introducir' => !$cerrada && !$arqueada,
        'repetir' => !$cerrada && $arqueada,
        'cerrar' => !$cerrada && $arqueada,
        'entrada' => !$cerrada && !$arqueada,
        'salida' => !$cerrada && !$arqueada,
        'leerCajon' => !$cerrada && $tieneCajon,
        'imprimirTermica' => true,
      ],
    ];
  }

  public function desglose(string $empresa, string $puesto, int $sesion, ?string $formaPago = null): array
  {
    $sql = 'SELECT a.Codigo, a.Moneda01, a.Moneda02, a.Moneda03, a.Moneda04, a.Moneda05,
                   a.Moneda06, a.Moneda07, a.Moneda08, a.Moneda09, a.Moneda10,
                   a.Moneda11, a.Moneda12, a.Moneda13, a.Moneda14, a.Moneda15,
                   a.Moneda16, a.Moneda17, a.Moneda18, a.Moneda19, a.Moneda20,
                   f.CobroDeArqueo
            FROM Arqueo a
            LEFT JOIN FormasPago f ON f.Codigo = a.Codigo
            WHERE a.Empresa = :empresa AND a.Puesto = :puesto AND a.Sesion = :sesion';
    $params = ['empresa' => $empresa, 'puesto' => $puesto, 'sesion' => $sesion];
    if ($formaPago !== null && $formaPago !== '') {
      $sql .= ' AND a.Codigo = :formaPago';
      $params['formaPago'] = $formaPago;
    }
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);

    $denominaciones = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      if (!(bool) ($row['CobroDeArqueo'] ?? false) && ($formaPago === null || $formaPago === '')) {
        continue;
      }
      $fp = (string) $row['Codigo'];
      for ($i = 1; $i <= 20; $i++) {
        $key = 'Moneda' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
        $importe = (float) ($row[$key] ?? 0);
        if ($importe == 0.0) {
          continue;
        }
        $denominaciones[] = [
          'indice' => $i,
          'importe' => $importe,
          'formaPago' => $fp,
        ];
      }
    }

    return [
      'empresa' => $empresa,
      'puesto' => $puesto,
      'sesion' => $sesion,
      'denominaciones' => $denominaciones,
    ];
  }

  /**
   * Introduce Entrado por forma de pago y marca Sesiones.Arqueo (legacy ArqueoCaja / FrmArqueo).
   *
   * @param array{lineas?: list<array{formaPago: string, entrado: float|int|string, monedas?: list<float|int>|null}>, forzarRepeticion?: bool} $body
   * @return array<string, mixed>
   */
  public function introducir(
    string $empresa,
    string $puesto,
    int $sesion,
    array $body,
    string $cajeroCodigo
  ): array {
    $empresa = trim($empresa);
    $puesto = trim($puesto);
    if ($empresa === '' || $puesto === '' || $sesion <= 0) {
      throw new \InvalidArgumentException('empresa, puesto y sesion son obligatorios');
    }

    $this->asegurarSesionExiste($empresa, $puesto, $sesion);
    $meta = $this->obtenerSesionMeta($empresa, $puesto, $sesion);
    if (!empty($meta['cerrada'])) {
      throw new \RuntimeException('Sesion cerrada: no se puede introducir arqueo', 409);
    }

    $forzar = !empty($body['forzarRepeticion']);
    if (!empty($meta['arqueada']) && !$forzar) {
      throw new \RuntimeException(
        'Sesion ya arqueada. Use forzarRepeticion (permiso repetir) para volver a introducir.',
        409
      );
    }

    $lineasBody = $body['lineas'] ?? null;
    if (!is_array($lineasBody) || $lineasBody === []) {
      throw new \InvalidArgumentException('Debe enviar lineas [{ formaPago, entrado }]');
    }

    $formasValidas = [];
    foreach ($this->formasParaArqueo() as $f) {
      $formasValidas[$f['codigo']] = true;
    }

    $this->pdo->beginTransaction();
    try {
      if ($forzar && !empty($meta['arqueada'])) {
        // Legacy repetición: resetea Entrado antes de pedir de nuevo.
        $this->pdo->prepare(
          'UPDATE Arqueo SET Entrado = 0, Moneda = 0
           WHERE Empresa = :e AND Puesto = :p AND Sesion = :s AND Entrado <> 0'
        )->execute(['e' => $empresa, 'p' => $puesto, 's' => $sesion]);
      }

      foreach ($lineasBody as $lin) {
        if (!is_array($lin)) {
          continue;
        }
        $codigo = trim((string) ($lin['formaPago'] ?? ''));
        if ($codigo === '' || !isset($formasValidas[$codigo])) {
          throw new \InvalidArgumentException("Forma de pago no valida para arqueo: {$codigo}");
        }
        $entrado = (float) ($lin['entrado'] ?? 0);
        $monedas = isset($lin['monedas']) && is_array($lin['monedas']) ? $lin['monedas'] : null;
        $this->upsertEntrado($empresa, $puesto, $sesion, $codigo, $entrado, $monedas);
      }

      $cajero = substr(trim($cajeroCodigo), 0, 4);
      if ($cajero === '') {
        $cajero = null;
      }
      $this->pdo->prepare(
        'UPDATE Sesiones SET Arqueo = 1, CajeroArqueo = :cajero
         WHERE Empresa = :e AND Puesto = :p AND Sesion = :s'
      )->execute([
        'cajero' => $cajero,
        'e' => $empresa,
        'p' => $puesto,
        's' => $sesion,
      ]);

      $this->pdo->commit();
    } catch (\Throwable $e) {
      $this->pdo->rollBack();
      throw $e;
    }

    return $this->obtener($empresa, $puesto, $sesion);
  }

  /**
   * Cierre de sesion (legacy CierreSesion, ArqueoCajaAntesCierre=S).
   *
   * Aplica salidas de efectivo al Acumulado (Add_Arq) y abre la siguiente sesion.
   * Si hay salida a siguiente sesion, genera entrada de caja en la nueva.
   *
   * @param array{salidaBanco?: float|int, salidaSiguienteSesion?: float|int, aplicarDescuadre?: bool, forzar?: bool, formaPagoEfectivo?: string} $body
   * @return array{sesionCerrada: array<string, mixed>, sesionNueva: array<string, mixed>, descuadreEfectivo: float}
   */
  public function cerrarSesion(
    string $empresa,
    string $puesto,
    int $sesion,
    array $body,
    string $cajeroCodigo
  ): array {
    $empresa = trim($empresa);
    $puesto = trim($puesto);
    if ($empresa === '' || $puesto === '' || $sesion <= 0) {
      throw new \InvalidArgumentException('empresa, puesto y sesion son obligatorios');
    }

    $meta = $this->obtenerSesionMeta($empresa, $puesto, $sesion);
    if (empty($meta['existe'])) {
      throw new \RuntimeException('Sesion no encontrada', 404);
    }
    if (!empty($meta['cerrada'])) {
      throw new \RuntimeException('La sesion ya esta cerrada', 409);
    }
    if (empty($meta['arqueada'])) {
      throw new \RuntimeException('Debe arquear la sesion antes de cerrarla', 409);
    }

    $cont = $meta['contadores'] ?? [];
    $vacia =
      (int) ($cont['tickets'] ?? 0) === 0
      && (int) ($cont['facturas'] ?? 0) === 0
      && (int) ($cont['albaranes'] ?? 0) === 0
      && (float) ($cont['importeEntradaEfectivo'] ?? 0) == 0.0
      && (float) ($cont['importeTickets'] ?? 0) == 0.0
      && (float) ($cont['importeFacturas'] ?? 0) == 0.0;
    if ($vacia && empty($body['forzar'])) {
      throw new \RuntimeException(
        'Sesion sin movimientos. Confirme el cierre con forzar=true si desea continuar.',
        409
      );
    }

    // UI/API: diferencia = Entrado − Acumulado. Legacy Descuadre = Acumulado − Entrado.
    $diferencia = $this->descuadreEfectivo($empresa, $puesto, $sesion);
    $descuadreLegacy = round(-$diferencia, 2);
    $salidaBanco = abs((float) ($body['salidaBanco'] ?? 0));
    $salidaSig = abs((float) ($body['salidaSiguienteSesion'] ?? 0));
    $aplicarDescuadre = array_key_exists('aplicarDescuadre', $body)
      ? (bool) $body['aplicarDescuadre']
      : true;
    $salidaDescuadre = $aplicarDescuadre ? $descuadreLegacy : 0.0;

    $formaEfectivo = trim((string) ($body['formaPagoEfectivo'] ?? ''));
    if ($formaEfectivo === '') {
      $formaEfectivo = $this->resolverFormaEfectivoCierre($empresa, $puesto, $sesion) ?? '';
    }
    if (($salidaBanco > 0 || $salidaSig > 0 || ($aplicarDescuadre && abs($descuadreLegacy) > 0.0001))
      && $formaEfectivo === '') {
      throw new \InvalidArgumentException(
        'No se pudo determinar la forma de pago efectivo. Indique formaPagoEfectivo.'
      );
    }

    $cajero = substr(trim($cajeroCodigo), 0, 4);
    if ($cajero === '') {
      $cajero = null;
    }

    $this->pdo->beginTransaction();
    try {
      // Salidas de efectivo del teorico (legacy: salida banco / siguiente / descuadre).
      if ($salidaBanco > 0) {
        $this->addArq($empresa, $puesto, $sesion, $formaEfectivo, -$salidaBanco);
      }
      if ($salidaSig > 0) {
        $this->addArq($empresa, $puesto, $sesion, $formaEfectivo, -$salidaSig);
      }
      if ($aplicarDescuadre && abs($descuadreLegacy) > 0.0001) {
        // SalidaDescuadre(Descuadre) → Add_Arq(-Descuadre) ≡ Add_Arq(diferencia UI).
        $this->addArq($empresa, $puesto, $sesion, $formaEfectivo, $diferencia);
      }

      $this->pdo->prepare(
        'UPDATE Sesiones SET
            Cerrada = 1,
            FechaFin = GETDATE(),
            CajeroCierre = :cajero,
            SalidaBanco = :banco,
            SalidaSiguienteSesion = :sig,
            SalidaPorDescuadre = :descuadre
         WHERE Empresa = :e AND Puesto = :p AND Sesion = :s'
      )->execute([
        'cajero' => $cajero,
        'banco' => $salidaBanco,
        'sig' => $salidaSig,
        'descuadre' => $salidaDescuadre,
        'e' => $empresa,
        'p' => $puesto,
        's' => $sesion,
      ]);

      $stUlt = $this->pdo->prepare(
        'SELECT UltSesion FROM Puestos WITH (UPDLOCK, ROWLOCK) WHERE Puesto = :p'
      );
      $stUlt->execute(['p' => $puesto]);
      $ult = (int) ($stUlt->fetchColumn() ?: $sesion);
      $nuevaSesion = max($ult, $sesion) + 1;
      try {
        $this->pdo->prepare(
          'UPDATE Puestos SET UltSesion = :n, UltTicket = 0 WHERE Puesto = :p'
        )->execute(['n' => $nuevaSesion, 'p' => $puesto]);
      } catch (\Throwable $e) {
        $this->pdo->prepare('UPDATE Puestos SET UltSesion = :n WHERE Puesto = :p')->execute([
          'n' => $nuevaSesion,
          'p' => $puesto,
        ]);
      }

      $this->asegurarSesionExiste($empresa, $puesto, $nuevaSesion);

      // Legacy GenerarEntradaCajaAcumulaAnteriorArqueo: fondo inicial en la nueva sesion.
      if ($salidaSig > 0 && $formaEfectivo !== '') {
        $this->addArq($empresa, $puesto, $nuevaSesion, $formaEfectivo, $salidaSig);
        $this->pdo->prepare(
          'UPDATE Sesiones SET
              EntradaEfectivo = ISNULL(EntradaEfectivo, 0) + 1,
              ImporteEntradaEfectivo = ISNULL(ImporteEntradaEfectivo, 0) + :imp
           WHERE Empresa = :e AND Puesto = :p AND Sesion = :s'
        )->execute([
          'imp' => $salidaSig,
          'e' => $empresa,
          'p' => $puesto,
          's' => $nuevaSesion,
        ]);
      }

      $this->pdo->commit();
    } catch (\Throwable $e) {
      $this->pdo->rollBack();
      throw $e;
    }

    return [
      'sesionCerrada' => $this->obtener($empresa, $puesto, $sesion),
      'sesionNueva' => $this->obtener($empresa, $puesto, $nuevaSesion),
      'descuadreEfectivo' => round($diferencia, 2),
    ];
  }

  /**
   * Entrada (+) o salida (-) de caja: Add_Arq + contadores Sesiones.
   * Legacy: entrada → Add_Arq(+importe); salida → Add_Arq(-importe).
   * La columna Diferencia (Entrado − Acumulado) se vuelve mas negativa tras una
   * entrada si Entrado no se ha actualizado: es esperado, no un signo invertido.
   *
   * @param 'entrada'|'salida' $tipo
   * @param array{formaPago?: string, importe?: float|int, concepto?: string} $body
   * @return array<string, mixed>
   */
  public function movimientoCaja(
    string $empresa,
    string $puesto,
    int $sesion,
    string $tipo,
    array $body
  ): array {
    $empresa = trim($empresa);
    $puesto = trim($puesto);
    $tipo = strtolower(trim($tipo));
    if (!in_array($tipo, ['entrada', 'salida'], true)) {
      throw new \InvalidArgumentException('Tipo debe ser entrada o salida');
    }
    if ($empresa === '' || $puesto === '' || $sesion <= 0) {
      throw new \InvalidArgumentException('empresa, puesto y sesion son obligatorios');
    }

    $meta = $this->obtenerSesionMeta($empresa, $puesto, $sesion);
    if (empty($meta['existe'])) {
      $this->asegurarSesionExiste($empresa, $puesto, $sesion);
      $meta = $this->obtenerSesionMeta($empresa, $puesto, $sesion);
    }
    if (!empty($meta['cerrada'])) {
      throw new \RuntimeException('Sesion cerrada: no se pueden hacer movimientos de caja', 409);
    }
    if (!empty($meta['arqueada'])) {
      throw new \RuntimeException(
        'Sesion ya arqueada: no se permiten entradas/salidas hasta el cierre',
        409
      );
    }

    $formaPago = trim((string) ($body['formaPago'] ?? ''));
    $importe = abs((float) ($body['importe'] ?? 0));
    if ($formaPago === '' || $importe <= 0) {
      throw new \InvalidArgumentException('formaPago e importe (>0) son obligatorios');
    }

    $formasOk = [];
    foreach ($this->formasParaArqueo() as $f) {
      $formasOk[$f['codigo']] = $f;
    }
    if (!isset($formasOk[$formaPago])) {
      throw new \InvalidArgumentException("Forma de pago no valida para caja: {$formaPago}");
    }

    // Entrada suma al teorico; salida resta (legacy CmdEsp 4/5).
    $delta = $tipo === 'entrada' ? $importe : -$importe;
    $acumAntes = $this->acumuladoForma($empresa, $puesto, $sesion, $formaPago);
    if ($tipo === 'salida') {
      $acumTras = round($acumAntes + $delta, 2);
      if ($acumTras < 0) {
        throw new \InvalidArgumentException(
          sprintf(
            'La salida deja el acumulado de la forma de pago en negativo '
            . '(forma %s: acumulado actual %.2f, salida %.2f). '
            . 'Revise la columna Acumulado (no Diferencia): la entrada de caja '
            . 'debe haber aumentado el Acumulado.',
            $formaPago,
            $acumAntes,
            $importe
          )
        );
      }
    }

    $concepto = trim((string) ($body['concepto'] ?? ''));
    $valeTipo = $tipo === 'entrada' ? 'I' : 'S';

    $this->pdo->beginTransaction();
    try {
      $this->addArq($empresa, $puesto, $sesion, $formaPago, $delta);
      if ($tipo === 'entrada') {
        $this->pdo->prepare(
          'UPDATE Sesiones SET
              EntradaEfectivo = ISNULL(EntradaEfectivo, 0) + 1,
              ImporteEntradaEfectivo = ISNULL(ImporteEntradaEfectivo, 0) + :imp
           WHERE Empresa = :e AND Puesto = :p AND Sesion = :s'
        )->execute(['imp' => $importe, 'e' => $empresa, 'p' => $puesto, 's' => $sesion]);
      } else {
        $this->pdo->prepare(
          'UPDATE Sesiones SET
              SalidaEfectivo = ISNULL(SalidaEfectivo, 0) + 1,
              ImporteSalidaEfectivo = ISNULL(ImporteSalidaEfectivo, 0) + :imp
           WHERE Empresa = :e AND Puesto = :p AND Sesion = :s'
        )->execute(['imp' => $importe, 'e' => $empresa, 'p' => $puesto, 's' => $sesion]);
      }

      $valeCodigo = $this->insertarValeMovimientoCaja(
        $empresa,
        $puesto,
        $sesion,
        $valeTipo,
        $formaPago,
        $importe,
        $concepto
      );

      $this->pdo->commit();
    } catch (\Throwable $e) {
      $this->pdo->rollBack();
      throw $e;
    }

    $acumDespues = $this->acumuladoForma($empresa, $puesto, $sesion, $formaPago);
    $detalle = $this->obtener($empresa, $puesto, $sesion);
    $detalle['ultimoMovimiento'] = [
      'tipo' => $tipo,
      'formaPago' => $formaPago,
      'importe' => $importe,
      'deltaAcumulado' => $delta,
      'acumuladoAntes' => round($acumAntes, 2),
      'acumuladoDespues' => round($acumDespues, 2),
      'concepto' => $concepto,
      'valeCodigo' => $valeCodigo,
    ];
    return $detalle;
  }

  /**
   * Legacy: fila en Vales Tipo I/S para entrada/salida de caja.
   * Devuelve codigo del vale o null si el esquema no admite la insercion.
   */
  private function insertarValeMovimientoCaja(
    string $empresa,
    string $puesto,
    int $sesion,
    string $tipo,
    string $formaPago,
    float $importe,
    string $concepto
  ): ?int {
    $st = $this->pdo->prepare(
      'SELECT ISNULL(MAX(Codigo), 0) + 1 FROM Vales WITH (UPDLOCK, HOLDLOCK) WHERE Empresa = :e'
    );
    $st->execute(['e' => $empresa]);
    $codigo = (int) $st->fetchColumn();
    $motivo = $concepto !== '' ? substr($concepto, 0, 50) : ' ';
    $conceptoDb = $concepto !== '' ? substr($concepto, 0, 50) : ' ';

    try {
      $this->pdo->prepare(
        'INSERT INTO Vales (
            Empresa, EmpresaOrigen, Codigo, Tipo, Numero, Liquidado,
            Puesto, Sesion, Fecha, Importe, TrasModem, Cliente,
            Motivo, Concepto, FormaPago
         ) VALUES (
            :e, :e, :c, :tipo, 0, 0,
            :p, :s, GETDATE(), :imp, 0, \' \',
            :motivo, :concepto, :fp
         )'
      )->execute([
        'e' => $empresa,
        'c' => $codigo,
        'tipo' => $tipo,
        'p' => $puesto,
        's' => $sesion,
        'imp' => $importe,
        'motivo' => $motivo,
        'concepto' => $conceptoDb,
        'fp' => substr($formaPago, 0, 2),
      ]);
      return $codigo;
    } catch (\Throwable $e) {
      // Esquema reducido (p. ej. sin Tipo/Puesto): intento minimo.
      try {
        $this->pdo->prepare(
          'INSERT INTO Vales (Empresa, Codigo, Liquidado, Fecha, Importe, Cliente, FormaPago, TrasModem)
           VALUES (:e, :c, 0, GETDATE(), :imp, \' \', :fp, 0)'
        )->execute([
          'e' => $empresa,
          'c' => $codigo,
          'imp' => $importe,
          'fp' => substr($formaPago, 0, 2),
        ]);
        return $codigo;
      } catch (\Throwable $e2) {
        return null;
      }
    }
  }

  /** Informe HTML imprimible. */
  public function informeHtml(string $empresa, string $puesto, int $sesion): string
  {
    $data = $this->obtener($empresa, $puesto, $sesion);
    $estado = (string) ($data['estado'] ?? '');
    $esc = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $rows = '';
    foreach ($data['lineas'] as $l) {
      $dif = (float) ($l['diferencia'] ?? ($l['entrado'] - $l['acumulado']));
      $rows .= '<tr>'
        . '<td>' . $esc($l['formaPago']) . '</td>'
        . '<td>' . $esc($l['descripcion'] ?? '') . '</td>'
        . '<td class="num">' . number_format((float) $l['acumulado'], 2, '.', '') . '</td>'
        . '<td class="num">' . number_format((float) $l['entrado'], 2, '.', '') . '</td>'
        . '<td class="num">' . number_format($dif, 2, '.', '') . '</td>'
        . '</tr>';
    }

    return '<!doctype html><html><head><meta charset="utf-8"><title>Arqueo '
      . $esc("{$empresa}/{$puesto}/{$sesion}")
      . '</title><style>
body{font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#111;padding:18px}
h1{font-size:18px;margin:0 0 8px}
.meta{margin:2px 0;color:#333}
table{width:100%;border-collapse:collapse;margin-top:12px}
th,td{border-bottom:1px solid #ccc;padding:5px 6px;text-align:left}
th{background:#f1f5f9}
.num{text-align:right}
.tot{margin-top:12px;font-weight:700}
@media print{button{display:none}}
</style></head><body>
<button onclick="window.print()">Imprimir / Guardar PDF</button>
<h1>Arqueo de caja</h1>
<p class="meta">Empresa ' . $esc($empresa) . ' · Puesto ' . $esc($puesto) . ' · Sesion ' . $esc((string) $sesion) . '</p>
<p class="meta">Estado: ' . $esc($estado) . ' · Cajero arqueo: ' . $esc($data['cajeroArqueo'] ?? '—') . '</p>
<p class="meta">Inicio: ' . $esc($data['fechaInicio'] ?? '—') . ' · Fin: ' . $esc($data['fechaFin'] ?? '—') . '</p>
<table><thead><tr><th>Forma</th><th>Descripcion</th><th class="num">Acumulado</th><th class="num">Entrado</th><th class="num">Diferencia</th></tr></thead>
<tbody>' . $rows . '</tbody></table>
<p class="tot">Totales — Acumulado ' . number_format((float) ($data['totalAcumulado'] ?? 0), 2, '.', '')
      . ' · Entrado ' . number_format((float) ($data['totalEntrado'] ?? 0), 2, '.', '')
      . ' · Diferencia ' . number_format((float) ($data['totalDiferencia'] ?? 0), 2, '.', '') . '</p>
</body></html>';
  }

  /** Informe PDF binario (descarga directa). */
  public function informePdf(string $empresa, string $puesto, int $sesion): string
  {
    $data = $this->obtener($empresa, $puesto, $sesion);
    $pdf = new \Descartes\Api\Support\SimplePdf();
    $pdf->title('Arqueo de caja');
    $pdf->text("Empresa {$empresa}  ·  Puesto {$puesto}  ·  Sesion {$sesion}", 10);
    $pdf->text(
      'Estado: ' . (string) ($data['estado'] ?? '')
      . '  ·  Cajero arqueo: ' . (string) ($data['cajeroArqueo'] ?? '—'),
      10
    );
    $pdf->text(
      'Inicio: ' . (string) ($data['fechaInicio'] ?? '—')
      . '  ·  Fin: ' . (string) ($data['fechaFin'] ?? '—'),
      10
    );
    $pdf->spacer(10);

    $rows = [];
    foreach ($data['lineas'] as $l) {
      $dif = (float) ($l['diferencia'] ?? ((float) $l['entrado'] - (float) $l['acumulado']));
      $rows[] = [
        (string) $l['formaPago'],
        (string) ($l['descripcion'] ?? ''),
        number_format((float) $l['acumulado'], 2, '.', ''),
        number_format((float) $l['entrado'], 2, '.', ''),
        number_format($dif, 2, '.', ''),
      ];
    }
    $pdf->table(
      ['Forma', 'Descripcion', 'Acumulado', 'Entrado', 'Diferencia'],
      $rows,
      [50.0, 180.0, 80.0, 80.0, 80.0]
    );
    $pdf->spacer(12);
    $pdf->text(
      'Totales — Acumulado '
      . number_format((float) ($data['totalAcumulado'] ?? 0), 2, '.', '')
      . '  ·  Entrado '
      . number_format((float) ($data['totalEntrado'] ?? 0), 2, '.', '')
      . '  ·  Diferencia '
      . number_format((float) ($data['totalDiferencia'] ?? 0), 2, '.', ''),
      11,
      true
    );

    return $pdf->build();
  }

  private function descuadreEfectivo(string $empresa, string $puesto, int $sesion): float
  {
    try {
      $st = $this->pdo->prepare(
        'SELECT
            ISNULL(SUM(a.Entrado), 0) AS entrado,
            ISNULL(SUM(a.Acumulado), 0) AS acumulado
         FROM Arqueo a
         INNER JOIN FormasPago f ON f.Codigo = a.Codigo
         WHERE a.Empresa = :e AND a.Puesto = :p AND a.Sesion = :s
           AND ISNULL(f.Agrupacion, 0) = 0 AND f.CobroDeArqueo <> 0'
      );
      $st->execute(['e' => $empresa, 'p' => $puesto, 's' => $sesion]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if ($row === false) {
        return 0.0;
      }
      return round((float) $row['entrado'] - (float) $row['acumulado'], 2);
    } catch (\Throwable $e) {
      return 0.0;
    }
  }

  private function acumuladoForma(string $empresa, string $puesto, int $sesion, string $codigo): float
  {
    $st = $this->pdo->prepare(
      'SELECT Acumulado FROM Arqueo
       WHERE Empresa = :e AND Puesto = :p AND Sesion = :s AND RTRIM(Codigo) = :c'
    );
    $st->execute(['e' => $empresa, 'p' => $puesto, 's' => $sesion, 'c' => trim($codigo)]);
    $v = $st->fetchColumn();
    return $v === false ? 0.0 : (float) $v;
  }

  /**
   * Forma de pago “efectivo en cajón” para cierre (salida banco / siguiente / descuadre).
   *
   * No usar a ciegas Empresas_Ges.Divisa: en algunos maestros Divisa apunta a Bizum u otra
   * forma digital. Prioridad:
   * 1) Forma Agrupacion=0 con mas Entrado (o Acumulado) en la sesion
   * 2) FormasPago Agrupacion=0 con AbrirCajon
   * 3) FormasPago Agrupacion=0 con CajonElectronico
   * 4) Empresas_Ges.Divisa solo si es Agrupacion=0 y no es datafono/vale
   * 5) Primera Agrupacion=0 “caja” (sin Datafono/Vales)
   */
  private function resolverFormaEfectivoCierre(
    string $empresa,
    string $puesto,
    int $sesion
  ): ?string {
    $empresa = trim($empresa);
    $puesto = trim($puesto);

    $candidatasSesion = $this->formasEfectivoSesion($empresa, $puesto, $sesion);
    if ($candidatasSesion !== []) {
      $esCajaFisica = static function (array $x): bool {
        return empty($x['datafono']) && empty($x['vales']);
      };
      $cajaFisica = array_values(array_filter($candidatasSesion, $esCajaFisica));
      $conCajon = array_values(array_filter(
        $cajaFisica,
        static fn (array $x): bool => !empty($x['abrirCajon']) || !empty($x['cajonElectronico'])
      ));
      $pool = $conCajon !== [] ? $conCajon : ($cajaFisica !== [] ? $cajaFisica : $candidatasSesion);
      usort(
        $pool,
        static function (array $a, array $b): int {
          $cmp = ($b['entrado'] <=> $a['entrado']);
          if ($cmp !== 0) {
            return $cmp;
          }
          return $b['acumulado'] <=> $a['acumulado'];
        }
      );
      return $pool[0]['codigo'];
    }

    $metaFormas = $this->metasFormasCaja();
    foreach ($metaFormas as $f) {
      if ((int) ($f['agrupacion'] ?? 0) !== 0) {
        continue;
      }
      if (!empty($f['abrirCajon']) && empty($f['datafono']) && empty($f['vales'])) {
        return $f['codigo'];
      }
    }
    foreach ($metaFormas as $f) {
      if ((int) ($f['agrupacion'] ?? 0) !== 0) {
        continue;
      }
      if (!empty($f['cajonElectronico']) && empty($f['datafono']) && empty($f['vales'])) {
        return $f['codigo'];
      }
    }

    $divisa = $this->divisaEmpresa($empresa);
    if ($divisa !== null) {
      foreach ($metaFormas as $f) {
        if ($f['codigo'] !== $divisa) {
          continue;
        }
        if ((int) ($f['agrupacion'] ?? 0) === 0 && empty($f['datafono']) && empty($f['vales'])) {
          return $divisa;
        }
        break;
      }
    }

    foreach ($metaFormas as $f) {
      if ((int) ($f['agrupacion'] ?? 0) === 0 && empty($f['datafono']) && empty($f['vales'])) {
        return $f['codigo'];
      }
    }

    if ($candidatasSesion !== []) {
      return $candidatasSesion[0]['codigo'];
    }

    return $metaFormas[0]['codigo'] ?? null;
  }

  /**
   * @return list<array{codigo: string, entrado: float, acumulado: float, abrirCajon: bool, cajonElectronico: bool, datafono: bool, vales: bool}>
   */
  private function formasEfectivoSesion(string $empresa, string $puesto, int $sesion): array
  {
    if ($empresa === '' || $puesto === '' || $sesion <= 0) {
      return [];
    }
    try {
      $st = $this->pdo->prepare(
        'SELECT RTRIM(a.Codigo) AS Codigo,
                ISNULL(a.Entrado, 0) AS Entrado,
                ISNULL(a.Acumulado, 0) AS Acumulado,
                ISNULL(f.Agrupacion, 0) AS Agrupacion,
                ISNULL(f.AbrirCajon, 0) AS AbrirCajon,
                ISNULL(f.CajonElectronico, 0) AS CajonElectronico,
                ISNULL(f.Datafono, 0) AS Datafono,
                ISNULL(f.Vales, 0) AS Vales
         FROM Arqueo a
         INNER JOIN FormasPago f ON RTRIM(f.Codigo) = RTRIM(a.Codigo)
         WHERE a.Empresa = :e AND a.Puesto = :p AND a.Sesion = :s
           AND ISNULL(f.Agrupacion, 0) = 0 AND f.CobroDeArqueo <> 0'
      );
      $st->execute(['e' => $empresa, 'p' => $puesto, 's' => $sesion]);
      $out = [];
      while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $codigo = trim((string) ($row['Codigo'] ?? ''));
        if ($codigo === '') {
          continue;
        }
        $out[] = [
          'codigo' => $codigo,
          'entrado' => (float) ($row['Entrado'] ?? 0),
          'acumulado' => (float) ($row['Acumulado'] ?? 0),
          'abrirCajon' => !empty($row['AbrirCajon']),
          'cajonElectronico' => !empty($row['CajonElectronico']),
          'datafono' => !empty($row['Datafono']),
          'vales' => !empty($row['Vales']),
        ];
      }
      return $out;
    } catch (\Throwable $e) {
      // Esquema sin alguna columna: fallback simple.
      try {
        $st = $this->pdo->prepare(
          'SELECT RTRIM(a.Codigo) AS Codigo,
                  ISNULL(a.Entrado, 0) AS Entrado,
                  ISNULL(a.Acumulado, 0) AS Acumulado
           FROM Arqueo a
           INNER JOIN FormasPago f ON RTRIM(f.Codigo) = RTRIM(a.Codigo)
           WHERE a.Empresa = :e AND a.Puesto = :p AND a.Sesion = :s
             AND ISNULL(f.Agrupacion, 0) = 0 AND f.CobroDeArqueo <> 0'
        );
        $st->execute(['e' => $empresa, 'p' => $puesto, 's' => $sesion]);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
          $codigo = trim((string) ($row['Codigo'] ?? ''));
          if ($codigo === '') {
            continue;
          }
          $out[] = [
            'codigo' => $codigo,
            'entrado' => (float) ($row['Entrado'] ?? 0),
            'acumulado' => (float) ($row['Acumulado'] ?? 0),
            'abrirCajon' => false,
            'cajonElectronico' => false,
            'datafono' => false,
            'vales' => false,
          ];
        }
        return $out;
      } catch (\Throwable $e2) {
        return [];
      }
    }
  }

  /**
   * @return list<array{codigo: string, agrupacion: int, abrirCajon: bool, cajonElectronico: bool, datafono: bool, vales: bool}>
   */
  private function metasFormasCaja(): array
  {
    try {
      $sql = 'SELECT Codigo, Agrupacion, AbrirCajon, CajonElectronico, Datafono, Vales
              FROM FormasPago
              WHERE CobroDeArqueo <> 0
              ORDER BY Agrupacion, Codigo';
      $out = [];
      foreach ($this->pdo->query($sql) as $row) {
        $codigo = trim((string) ($row['Codigo'] ?? ''));
        if ($codigo === '') {
          continue;
        }
        $out[] = [
          'codigo' => $codigo,
          'agrupacion' => (int) ($row['Agrupacion'] ?? 0),
          'abrirCajon' => !empty($row['AbrirCajon']),
          'cajonElectronico' => !empty($row['CajonElectronico']),
          'datafono' => !empty($row['Datafono']),
          'vales' => !empty($row['Vales']),
        ];
      }
      return $out;
    } catch (\Throwable $e) {
      $out = [];
      foreach ($this->formasParaArqueo() as $f) {
        $out[] = [
          'codigo' => $f['codigo'],
          'agrupacion' => (int) ($f['agrupacion'] ?? 0),
          'abrirCajon' => false,
          'cajonElectronico' => !empty($f['cajonElectronico']),
          'datafono' => false,
          'vales' => false,
        ];
      }
      return $out;
    }
  }

  private function divisaEmpresa(string $empresa): ?string
  {
    $empresa = trim($empresa);
    if ($empresa === '') {
      return null;
    }
    try {
      $st = $this->pdo->prepare('SELECT Divisa FROM Empresas_Ges WHERE Codigo = :e');
      $st->execute(['e' => $empresa]);
      $v = $st->fetchColumn();
      if ($v !== false && trim((string) $v) !== '') {
        return trim((string) $v);
      }
    } catch (\Throwable $e) {
      // sin columna
    }
    return null;
  }

  /**
   * Legacy Add_Arq (ModuleOracle / VentaGenerica): suma Acumulado e incrementa Cantidad.
   */
  public function addArq(
    string $empresa,
    string $puesto,
    int $sesion,
    string $formaPago,
    float $importe
  ): void {
    $empresa = trim($empresa);
    $puesto = trim($puesto);
    $formaPago = trim($formaPago);
    if ($empresa === '' || $puesto === '' || $formaPago === '' || $sesion <= 0) {
      return;
    }

    $sel = $this->pdo->prepare(
      'SELECT Acumulado, Cantidad, Codigo FROM Arqueo
       WHERE Empresa = :e AND Puesto = :p AND Sesion = :s AND RTRIM(Codigo) = :c'
    );
    $sel->execute(['e' => $empresa, 'p' => $puesto, 's' => $sesion, 'c' => $formaPago]);
    $row = $sel->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      $this->pdo->prepare(
        'INSERT INTO Arqueo (Empresa, Puesto, Sesion, Codigo, Acumulado, Entrado, TrasModem, Cantidad, Moneda)
         VALUES (:e, :p, :s, :c, :acum, 0, 0, 1, 0)'
      )->execute([
        'e' => $empresa,
        'p' => $puesto,
        's' => $sesion,
        'c' => $formaPago,
        'acum' => $importe,
      ]);
      return;
    }

    $codigoDb = (string) ($row['Codigo'] ?? $formaPago);
    $this->pdo->prepare(
      'UPDATE Arqueo SET Acumulado = :acum, Cantidad = :cant
       WHERE Empresa = :e AND Puesto = :p AND Sesion = :s AND Codigo = :c'
    )->execute([
      'acum' => (float) ($row['Acumulado'] ?? 0) + $importe,
      'cant' => (int) ($row['Cantidad'] ?? 0) + 1,
      'e' => $empresa,
      'p' => $puesto,
      's' => $sesion,
      'c' => $codigoDb,
    ]);
  }

  public function empresaArqueoPuesto(string $puesto, string $fallbackEmpresa): string
  {
    $puesto = trim($puesto);
    if ($puesto === '') {
      return trim($fallbackEmpresa);
    }
    try {
      $st = $this->pdo->prepare('SELECT EmpresaArqueo FROM Puestos WHERE Puesto = :p');
      $st->execute(['p' => $puesto]);
      $v = $st->fetchColumn();
      if ($v !== false && trim((string) $v) !== '') {
        return trim((string) $v);
      }
    } catch (\Throwable $e) {
      // Columna puede no existir en algunos esquemas.
    }
    return trim($fallbackEmpresa);
  }

  /**
   * @return array{empresa: string, puesto: string, sesion: int}
   */
  public function asegurarSesionPuesto(string $puesto, string $fallbackEmpresa): array
  {
    $puesto = trim($puesto);
    $empresa = $this->empresaArqueoPuesto($puesto, $fallbackEmpresa);
    $sesion = 1;
    if ($puesto !== '') {
      try {
        $st = $this->pdo->prepare('SELECT UltSesion FROM Puestos WHERE Puesto = :p');
        $st->execute(['p' => $puesto]);
        $v = $st->fetchColumn();
        if ($v !== false && (int) $v > 0) {
          $sesion = (int) $v;
        }
      } catch (\Throwable $e) {
        // ignore
      }
    }

    if ($empresa === '' || $puesto === '') {
      return ['empresa' => $empresa, 'puesto' => $puesto, 'sesion' => $sesion];
    }

    $this->asegurarSesionExiste($empresa, $puesto, $sesion);

    return ['empresa' => $empresa, 'puesto' => $puesto, 'sesion' => $sesion];
  }

  /**
   * @param 'Tickets'|'Facturas' $tipoDoc
   */
  public function incrementarContadorDocumento(
    string $empresa,
    string $puesto,
    int $sesion,
    string $tipoDoc,
    float $importe
  ): void {
    $map = [
      'Tickets' => ['Tickets', 'ImporteTickets'],
      'Facturas' => ['Facturas', 'ImporteFacturas'],
    ];
    if (!isset($map[$tipoDoc]) || $sesion <= 0) {
      return;
    }
    [$colN, $colImp] = $map[$tipoDoc];
    $sql = "UPDATE Sesiones SET
              [{$colN}] = ISNULL([{$colN}], 0) + 1,
              [{$colImp}] = ISNULL([{$colImp}], 0) + :imp
            WHERE Empresa = :e AND Puesto = :p AND Sesion = :s";
    $this->pdo->prepare($sql)->execute([
      'imp' => $importe,
      'e' => $empresa,
      'p' => $puesto,
      's' => $sesion,
    ]);
  }

  /**
   * @return array{cajonElectronico: bool, tipoCajon: ?string}
   */
  private function metaDispositivoPuesto(string $puesto): array
  {
    $out = ['cajonElectronico' => false, 'tipoCajon' => null];
    $puesto = trim($puesto);
    if ($puesto === '') {
      return $out;
    }
    try {
      $st = $this->pdo->prepare('SELECT CajonElectronico FROM Puestos WHERE Puesto = :p');
      $st->execute(['p' => $puesto]);
      $tipo = trim((string) ($st->fetchColumn() ?: ''));
      if ($tipo !== '') {
        $out['cajonElectronico'] = true;
        $out['tipoCajon'] = $tipo;
      }
    } catch (\Throwable $e) {
      // sin columna
    }
    return $out;
  }

  /**
   * @return list<array{codigo: string, descripcion: ?string, agrupacion: int, cajonElectronico: bool}>
   */
  private function formasParaArqueo(): array
  {
    $out = [];
    try {
      $sql = 'SELECT Codigo, Descripcion, Agrupacion, CajonElectronico
              FROM FormasPago
              WHERE CobroDeArqueo <> 0 AND ISNULL(Agrupacion, 0) <> 3
              ORDER BY Agrupacion, Codigo';
      foreach ($this->pdo->query($sql) as $row) {
        $codigo = trim((string) ($row['Codigo'] ?? ''));
        if ($codigo === '') {
          continue;
        }
        $out[] = [
          'codigo' => $codigo,
          'descripcion' => $row['Descripcion'] ?? null,
          'agrupacion' => (int) ($row['Agrupacion'] ?? 0),
          'cajonElectronico' => !empty($row['CajonElectronico']),
        ];
      }
    } catch (\Throwable $e) {
      // Esquema antiguo sin Agrupacion/CajonElectronico.
      $sql = 'SELECT Codigo, Descripcion FROM FormasPago WHERE CobroDeArqueo <> 0 ORDER BY Codigo';
      foreach ($this->pdo->query($sql) as $row) {
        $codigo = trim((string) ($row['Codigo'] ?? ''));
        if ($codigo === '') {
          continue;
        }
        $out[] = [
          'codigo' => $codigo,
          'descripcion' => $row['Descripcion'] ?? null,
          'agrupacion' => 0,
          'cajonElectronico' => false,
        ];
      }
    }
    return $out;
  }

  /**
   * @return array<string, mixed>
   */
  private function obtenerSesionMeta(string $empresa, string $puesto, int $sesion): array
  {
    $empty = [
      'cerrada' => false,
      'arqueada' => false,
      'fechaInicio' => null,
      'fechaFin' => null,
      'cajeroArqueo' => null,
      'cajeroCierre' => null,
      'contadores' => [],
      'existe' => false,
    ];
    try {
      $st = $this->pdo->prepare(
        'SELECT Cerrada, Arqueo, FechaInicio, FechaFin, CajeroArqueo, CajeroCierre,
                Tickets, ImporteTickets, Facturas, ImporteFacturas, Albaranes, ImporteAlbaranes,
                EntradaEfectivo, ImporteEntradaEfectivo, SalidaEfectivo, ImporteSalidaEfectivo
         FROM Sesiones
         WHERE Empresa = :e AND Puesto = :p AND Sesion = :s'
      );
      $st->execute(['e' => $empresa, 'p' => $puesto, 's' => $sesion]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if ($row === false) {
        return $empty;
      }
      return [
        'existe' => true,
        'cerrada' => !empty($row['Cerrada']),
        'arqueada' => !empty($row['Arqueo']),
        'fechaInicio' => $this->fmtDate($row['FechaInicio'] ?? null),
        'fechaFin' => $this->fmtDate($row['FechaFin'] ?? null),
        'cajeroArqueo' => $row['CajeroArqueo'] !== null ? trim((string) $row['CajeroArqueo']) : null,
        'cajeroCierre' => $row['CajeroCierre'] !== null ? trim((string) $row['CajeroCierre']) : null,
        'contadores' => [
          'tickets' => (int) ($row['Tickets'] ?? 0),
          'importeTickets' => (float) ($row['ImporteTickets'] ?? 0),
          'facturas' => (int) ($row['Facturas'] ?? 0),
          'importeFacturas' => (float) ($row['ImporteFacturas'] ?? 0),
          'albaranes' => (int) ($row['Albaranes'] ?? 0),
          'importeAlbaranes' => (float) ($row['ImporteAlbaranes'] ?? 0),
          'entradaEfectivo' => (int) ($row['EntradaEfectivo'] ?? 0),
          'importeEntradaEfectivo' => (float) ($row['ImporteEntradaEfectivo'] ?? 0),
          'salidaEfectivo' => (int) ($row['SalidaEfectivo'] ?? 0),
          'importeSalidaEfectivo' => (float) ($row['ImporteSalidaEfectivo'] ?? 0),
        ],
      ];
    } catch (\Throwable $e) {
      return $empty;
    }
  }

  private function asegurarSesionExiste(string $empresa, string $puesto, int $sesion): void
  {
    $chk = $this->pdo->prepare(
      'SELECT 1 FROM Sesiones WHERE Empresa = :e AND Puesto = :p AND Sesion = :s'
    );
    $chk->execute(['e' => $empresa, 'p' => $puesto, 's' => $sesion]);
    if ($chk->fetchColumn() === false) {
      $this->pdo->prepare(
        'INSERT INTO Sesiones (Empresa, Puesto, Sesion, Cerrada, Arqueo, FechaInicio, TrasModem)
         VALUES (:e, :p, :s, 0, 0, GETDATE(), 0)'
      )->execute(['e' => $empresa, 'p' => $puesto, 's' => $sesion]);
    }
  }

  /** @param list<float|int>|null $monedas */
  private function upsertEntrado(
    string $empresa,
    string $puesto,
    int $sesion,
    string $codigo,
    float $entrado,
    ?array $monedas
  ): void {
    $sel = $this->pdo->prepare(
      'SELECT 1 FROM Arqueo WHERE Empresa = :e AND Puesto = :p AND Sesion = :s AND Codigo = :c'
    );
    $sel->execute(['e' => $empresa, 'p' => $puesto, 's' => $sesion, 'c' => $codigo]);
    $existe = $sel->fetchColumn() !== false;

    $usaMonedas = $monedas !== null && $monedas !== [];
    $valsMoneda = [];
    for ($i = 1; $i <= 20; $i++) {
      $valsMoneda[$i] = $usaMonedas ? (float) ($monedas[$i - 1] ?? 0) : 0.0;
    }

    if (!$existe) {
      $cols = 'Empresa, Puesto, Sesion, Codigo, Acumulado, Entrado, TrasModem, Cantidad, Moneda';
      $ph = ':e, :p, :s, :c, 0, :entrado, 0, 0, :monedaFlag';
      $params = [
        'e' => $empresa,
        'p' => $puesto,
        's' => $sesion,
        'c' => $codigo,
        'entrado' => $entrado,
        'monedaFlag' => $usaMonedas ? 1 : 0,
      ];
      for ($i = 1; $i <= 20; $i++) {
        $k = 'm' . $i;
        $cols .= ', Moneda' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
        $ph .= ', :' . $k;
        $params[$k] = $valsMoneda[$i];
      }
      $this->pdo->prepare("INSERT INTO Arqueo ({$cols}) VALUES ({$ph})")->execute($params);
      return;
    }

    $set = 'Entrado = :entrado, Moneda = :monedaFlag';
    $params = [
      'entrado' => $entrado,
      'monedaFlag' => $usaMonedas ? 1 : 0,
      'e' => $empresa,
      'p' => $puesto,
      's' => $sesion,
      'c' => $codigo,
    ];
    for ($i = 1; $i <= 20; $i++) {
      $col = 'Moneda' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
      $k = 'm' . $i;
      $set .= ", {$col} = :{$k}";
      $params[$k] = $valsMoneda[$i];
    }
    $this->pdo->prepare(
      "UPDATE Arqueo SET {$set}
       WHERE Empresa = :e AND Puesto = :p AND Sesion = :s AND Codigo = :c"
    )->execute($params);
  }

  private function fmtDate(mixed $v): ?string
  {
    if ($v === null || $v === '') {
      return null;
    }
    if ($v instanceof \DateTimeInterface) {
      return $v->format('Y-m-d H:i:s');
    }
    $ts = strtotime((string) $v);
    return $ts === false ? null : date('Y-m-d H:i:s', $ts);
  }
}
