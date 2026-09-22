<?php

declare(strict_types=1);

namespace Descartes\Api\Services;

use Descartes\Api\Repositories\ArtBarrasRepository;
use PDO;

/**
 * Generación legacy de código de artículo y EAN-13 (Empresas_Ges).
 *
 * Código artículo (GenArticulos): Prefijo 3 dígitos + contador UltEan (6 dígitos).
 * EAN (GenBarras): 4 dígitos A.E.C.O.C. + UltEan a 8 dígitos + dígito control EAN-13.
 */
final class ArticuloGeneracionCodigosService
{
  /** Primeros 4 dígitos legacy cuando Aecoc no está configurado (base 970000000000). */
  private const AECOC_DEFECTO = 9700;

  private PDO $pdo;
  private ArtBarrasRepository $artBarras;

  public function __construct(PDO $pdo, ArtBarrasRepository $artBarras)
  {
    $this->pdo = $pdo;
    $this->artBarras = $artBarras;
  }

  /**
   * Vista previa (no incrementa UltEan).
   *
   * @return array{
   *   automatico: bool,
   *   codigo: ?string,
   *   ean: ?string,
   *   empresaCodigo: ?string,
   *   prefijo: ?int,
   *   ultEan: ?int,
   *   genBarras: bool,
   *   mensaje: ?string
   * }
   */
  public function previewSiguiente(string $empresaCodigo): array
  {
    $empresa = $this->resolverEmpresa($empresaCodigo);
    if ($empresa === null) {
      $pedida = trim($empresaCodigo);
      $msg = $pedida !== ''
        ? "No se encontro la tienda «{$pedida}» configurada en el puesto."
        : 'No hay tienda configurada en el puesto.';
      return $this->respuesta(
        false,
        null,
        null,
        $pedida !== '' ? $pedida : null,
        null,
        null,
        false,
        $msg . ' Revise Mantenimiento → Tiendas y la configuracion del equipo.'
      );
    }

    if (!$empresa['genArticulos']) {
      return $this->respuesta(
        false,
        null,
        null,
        $empresa['codigo'],
        $empresa['prefijo'],
        $empresa['ultEan'],
        $empresa['genBarras'],
        sprintf(
          'La tienda %s no tiene activo «Generar articulos». Marquelo en Parametros, guarde la ficha y vuelva a intentarlo.',
          $empresa['codigo']
        )
      );
    }

    if ($empresa['prefijo'] <= 0) {
      return $this->respuesta(
        false,
        null,
        null,
        $empresa['codigo'],
        $empresa['prefijo'],
        $empresa['ultEan'],
        $empresa['genBarras'],
        'Configure el Prefijo de la tienda (Parametros / Series) antes de generar articulos'
      );
    }

    $siguiente = $empresa['ultEan'] + 1;
    $codigo = $this->formatearCodigoArticulo($empresa['prefijo'], $siguiente);
    $ean = $empresa['genBarras']
      ? $this->formatearEan13($empresa['aecoc'], $siguiente)
      : null;

    return $this->respuesta(
      true,
      $codigo,
      $ean,
      $empresa['codigo'],
      $empresa['prefijo'],
      $empresa['ultEan'],
      $empresa['genBarras'],
      null
    );
  }

  /**
   * Alta: reserva UltEan, asigna codigo y opcionalmente crea EAN en ArtBarras.
   *
   * @param array<string, mixed> $data
   */
  public function aplicarEnAlta(array &$data, string $empresaCodigo): void
  {
    $empresa = $this->resolverEmpresa($empresaCodigo);
    if ($empresa === null || !$empresa['genArticulos']) {
      return;
    }

    if ($empresa['prefijo'] <= 0) {
      throw new \InvalidArgumentException(
        'Configure el Prefijo de la tienda antes de generar articulos (GenArticulos activo)'
      );
    }

    $reserva = $this->reservarSiguienteUltEan($empresa['codigo']);
    $nuevoContador = $reserva['ultEan'];
    $codigo = $this->formatearCodigoArticulo($empresa['prefijo'], $nuevoContador);

    for ($i = 0; $i < 100; $i++) {
      if (!$this->existeCodigoArticulo($codigo)) {
        $data['codigo'] = $codigo;
        break;
      }
      $nuevoContador++;
      $this->actualizarUltEan($empresa['codigo'], $nuevoContador);
      $codigo = $this->formatearCodigoArticulo($empresa['prefijo'], $nuevoContador);
    }

    if (!isset($data['codigo']) || trim((string) $data['codigo']) === '') {
      throw new \InvalidArgumentException(
        'No se pudo generar un codigo de articulo libre (Prefijo / Codigos Barras / GenArticulos)'
      );
    }

    if (!$empresa['genBarras']) {
      return;
    }

    $ean = $this->formatearEan13($empresa['aecoc'], $nuevoContador);
    $data['_eanGenerado'] = $ean;
  }

  /** Tras INSERT en Articulos. */
  public function insertarEanGenerado(string $codigoArticulo, string $ean): void
  {
    $codigo = trim($codigoArticulo);
    $ean = trim($ean);
    if ($codigo === '' || $ean === '') {
      return;
    }
    $otro = $this->artBarras->codigoPorEan($ean);
    if ($otro !== null && strcasecmp(trim($otro), $codigo) !== 0) {
      throw new \InvalidArgumentException("El EAN generado {$ean} ya pertenece al articulo {$otro}");
    }
    $this->artBarras->replaceForArticulo($codigo, [
      [
        'ean' => $ean,
        'tipo' => '',
        'unidades' => 1.0,
      ],
    ]);
  }

  public function formatearCodigoArticulo(int $prefijo, int $contadorUltEan): string
  {
    if ($contadorUltEan <= 0 || $contadorUltEan > 999999) {
      throw new \InvalidArgumentException('Contador Codigos Barras fuera de rango (1–999999)');
    }
    if ($prefijo < 0 || $prefijo > 999) {
      throw new \InvalidArgumentException('Prefijo de tienda invalido (0–999)');
    }
    return sprintf('%03d%06d', $prefijo, $contadorUltEan);
  }

  public function formatearEan13(int $aecoc, int $contadorUltEan): string
  {
    if ($contadorUltEan <= 0 || $contadorUltEan > 99999999) {
      throw new \InvalidArgumentException('Contador Codigos Barras fuera de rango para EAN');
    }
    // Legacy: base A.E.C.O.C. (4) + 8 ceros (970000000000) + contador×10 en la cola; dígito control EAN-13.
    $pref = $this->normalizarAecoc4($aecoc);
    $base = (int) ($pref . '00000000');
    $cuerpo12 = str_pad((string) ($base + $contadorUltEan * 10), 12, '0', STR_PAD_LEFT);
    if (strlen($cuerpo12) !== 12) {
      throw new \InvalidArgumentException('No se pudo componer el EAN-13 (12 digitos base)');
    }
    $control = self::digitoControlEan13($cuerpo12);
    return $cuerpo12 . (string) $control;
  }

  public static function digitoControlEan13(string $doceDigitos): int
  {
    $s = preg_replace('/\D/', '', $doceDigitos) ?? '';
    if (strlen($s) !== 12) {
      throw new \InvalidArgumentException('EAN-13 requiere 12 digitos para calcular control');
    }
    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
      $d = (int) $s[$i];
      $sum += ($i % 2 === 0) ? $d : $d * 3;
    }
    return (10 - ($sum % 10)) % 10;
  }

  /**
   * @return array{
   *   codigo: string,
   *   genArticulos: bool,
   *   genBarras: bool,
   *   prefijo: int,
   *   aecoc: int,
   *   ultEan: int
   * }|null
   */
  private function resolverEmpresa(string $empresaCodigo): ?array
  {
    $pedida = trim($empresaCodigo);
    if ($pedida !== '') {
      return $this->buscarEmpresaPorCodigo($pedida);
    }

    $stmt = $this->pdo->query(
      "SELECT TOP 1 RTRIM([Codigo]) AS Codigo
       FROM [Empresas_Ges]
       WHERE ISNULL([GenArticulos], 0) = 1
       ORDER BY [Codigo]"
    );
    $any = $stmt ? $stmt->fetchColumn() : false;
    if ($any) {
      return $this->leerEmpresa((string) $any);
    }

    $stmt = $this->pdo->query(
      "SELECT TOP 1 RTRIM([Codigo]) AS Codigo
       FROM [Empresas_Ges]
       WHERE ISNULL([Central], 0) = 1
       ORDER BY [Codigo]"
    );
    $central = $stmt ? $stmt->fetchColumn() : false;
    return $central ? $this->leerEmpresa((string) $central) : null;
  }

  /**
   * @return array{
   *   codigo: string,
   *   genArticulos: bool,
   *   genBarras: bool,
   *   prefijo: int,
   *   aecoc: int,
   *   ultEan: int
   * }|null
   */
  private function buscarEmpresaPorCodigo(string $empresaCodigo): ?array
  {
    foreach ($this->variantesCodigoEmpresa($empresaCodigo) as $variante) {
      $row = $this->leerEmpresa($variante);
      if ($row !== null) {
        return $row;
      }
    }

    if (preg_match('/^\d+$/', trim($empresaCodigo))) {
      $n = (int) ltrim(trim($empresaCodigo), '0');
      $stmt = $this->pdo->prepare(
        "SELECT TOP 1 RTRIM([Codigo]) AS Codigo
         FROM [Empresas_Ges]
         WHERE TRY_CAST(RTRIM([Codigo]) AS int) = :n
         ORDER BY [Codigo]"
      );
      $stmt->execute(['n' => $n]);
      $codigo = $stmt->fetchColumn();
      if ($codigo) {
        return $this->leerEmpresa((string) $codigo);
      }
    }

    return null;
  }

  /** @return list<string> */
  private function variantesCodigoEmpresa(string $empresaCodigo): array
  {
    $codigo = trim($empresaCodigo);
    if ($codigo === '') {
      return [];
    }
    $variants = [$codigo];
    if (preg_match('/^\d+$/', $codigo)) {
      $sinCeros = ltrim($codigo, '0');
      $sinCeros = $sinCeros === '' ? '0' : $sinCeros;
      $variants[] = $sinCeros;
      $variants[] = str_pad($sinCeros, 3, '0', STR_PAD_LEFT);
      $variants[] = str_pad($codigo, 3, '0', STR_PAD_LEFT);
    }
    return array_values(array_unique($variants));
  }

  /**
   * @return array{
   *   codigo: string,
   *   genArticulos: bool,
   *   genBarras: bool,
   *   prefijo: int,
   *   aecoc: int,
   *   ultEan: int
   * }|null
   */
  private function leerEmpresa(string $codigo): ?array
  {
    $stmt = $this->pdo->prepare(
      'SELECT RTRIM([Codigo]) AS Codigo,
              CAST(ISNULL([GenArticulos], 0) AS int) AS GenArticulos,
              CAST(ISNULL([GenBarras], 0) AS int) AS GenBarras,
              CAST(ISNULL([Prefijo], 0) AS float) AS Prefijo,
              CAST(ISNULL([Aecoc], 0) AS float) AS Aecoc,
              CAST(ISNULL([UltEan], 0) AS int) AS UltEan
       FROM [Empresas_Ges]
       WHERE RTRIM([Codigo]) = :codigo'
    );
    $stmt->execute(['codigo' => trim($codigo)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
      return null;
    }

    return [
      'codigo' => (string) $row['Codigo'],
      'genArticulos' => ((int) $row['GenArticulos']) === 1,
      'genBarras' => ((int) $row['GenBarras']) === 1,
      'prefijo' => $this->enteroDesdeLegacy($row['Prefijo']),
      'aecoc' => $this->enteroDesdeLegacy($row['Aecoc']),
      'ultEan' => max(0, (int) $row['UltEan']),
    ];
  }

  /** @return array{ultEan: int} */
  private function reservarSiguienteUltEan(string $empresaCodigo): array
  {
    $this->pdo->beginTransaction();
    try {
      $stmt = $this->pdo->prepare(
        'SELECT CAST(ISNULL([UltEan], 0) AS int)
         FROM [Empresas_Ges] WITH (UPDLOCK, ROWLOCK)
         WHERE RTRIM([Codigo]) = :codigo'
      );
      $stmt->execute(['codigo' => trim($empresaCodigo)]);
      $actual = $stmt->fetchColumn();
      if ($actual === false || $actual === null) {
        throw new \InvalidArgumentException('Empresa no encontrada para reservar Codigos Barras');
      }

      $nuevo = (int) $actual + 1;
      if ($nuevo <= 0 || $nuevo > 99999999) {
        throw new \InvalidArgumentException('Contador Codigos Barras fuera de rango');
      }

      $upd = $this->pdo->prepare(
        'UPDATE [Empresas_Ges] SET [UltEan] = :nuevo WHERE RTRIM([Codigo]) = :codigo'
      );
      $upd->execute(['nuevo' => $nuevo, 'codigo' => trim($empresaCodigo)]);

      $this->pdo->commit();
      return ['ultEan' => $nuevo];
    } catch (\Throwable $e) {
      if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      throw $e;
    }
  }

  private function actualizarUltEan(string $empresaCodigo, int $valor): void
  {
    $stmt = $this->pdo->prepare(
      'UPDATE [Empresas_Ges] SET [UltEan] = :nuevo WHERE RTRIM([Codigo]) = :codigo'
    );
    $stmt->execute(['nuevo' => $valor, 'codigo' => trim($empresaCodigo)]);
  }

  private function existeCodigoArticulo(string $codigo): bool
  {
    $stmt = $this->pdo->prepare(
      'SELECT 1 FROM [Articulos] WHERE RTRIM([Codigo]) = :codigo'
    );
    $stmt->execute(['codigo' => trim($codigo)]);
    return (bool) $stmt->fetchColumn();
  }

  private function enteroDesdeLegacy(mixed $value): int
  {
    if ($value === null || $value === '') {
      return 0;
    }
    if (is_int($value)) {
      return $value;
    }
    if (is_float($value)) {
      return (int) round($value);
    }
    $s = trim((string) $value);
    if ($s === '') {
      return 0;
    }
    if (preg_match('/^\d+\.0+$/', $s)) {
      $s = explode('.', $s, 2)[0];
    }
    return (int) $s;
  }

  private function normalizarAecoc4(int $aecoc): string
  {
    $n = $aecoc > 0 ? $aecoc : self::AECOC_DEFECTO;
    if ($n > 9999) {
      $n = (int) substr((string) $n, 0, 4);
    }
    return str_pad((string) $n, 4, '0', STR_PAD_LEFT);
  }

  /**
   * @return array{
   *   automatico: bool,
   *   codigo: ?string,
   *   ean: ?string,
   *   empresaCodigo: ?string,
   *   prefijo: ?int,
   *   ultEan: ?int,
   *   genBarras: bool,
   *   mensaje: ?string
   * }
   */
  private function respuesta(
    bool $automatico,
    ?string $codigo,
    ?string $ean,
    ?string $empresaCodigo,
    ?int $prefijo,
    ?int $ultEan,
    bool $genBarras,
    ?string $mensaje
  ): array {
    return [
      'automatico' => $automatico,
      'codigo' => $codigo,
      'ean' => $ean,
      'empresaCodigo' => $empresaCodigo,
      'prefijo' => $prefijo,
      'ultEan' => $ultEan,
      'genBarras' => $genBarras,
      'mensaje' => $mensaje,
    ];
  }
}
