<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Etiquetas;

use Descartes\Api\Database\SqlPagination;
use Descartes\Api\Services\Compras\AlbaranCompraConsultaService;
use PDO;

/**
 * Cola de etiquetas sobre `[EtiquetasArticulo]` (005 / T010).
 * `cantidad` API = SQL `[Etiquetas]` (copias). `[Cantidad]` = cantidadStock legado.
 */
final class EtiquetaColaService
{
  private PDO $pdo;
  private AlbaranCompraConsultaService $albaranesCompra;

  public function __construct(PDO $pdo, AlbaranCompraConsultaService $albaranesCompra)
  {
    $this->pdo = $pdo;
    $this->albaranesCompra = $albaranesCompra;
  }

  /**
   * @param array<string, mixed> $query
   * @return array{items: list<array<string, mixed>>, total: int, page: int, pageSize: int}
   */
  public function listar(array $query): array
  {
    $page = max(1, (int) ($query['page'] ?? 1));
    $pageSize = min(500, max(1, (int) ($query['pageSize'] ?? 100)));
    $offset = ($page - 1) * $pageSize;

    $where = ['1=1'];
    $params = [];

    if (!empty($query['puesto'])) {
      $where[] = 'RTRIM(ISNULL(e.Puesto, \'\')) = :puesto';
      $params['puesto'] = trim((string) $query['puesto']);
    }
    if (!empty($query['empresa'])) {
      $where[] = 'RTRIM(ISNULL(e.Empresa, \'\')) = :empresa';
      $params['empresa'] = trim((string) $query['empresa']);
    }

    $sqlWhere = implode(' AND ', $where);

    $countStmt = $this->pdo->prepare(
      "SELECT COUNT(*) FROM [EtiquetasArticulo] e WHERE {$sqlWhere}"
    );
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $innerSql = "SELECT e.Articulo, e.NroLin, e.Ean, e.Etiquetas, e.Cantidad, e.Precio,
                   e.PrecioVentaTeorico, e.Descripcion, e.Lote, e.Puesto, e.TipoDocumento,
                   e.Empresa, e.Albaran, e.FechaDocumento, e.Proveedor, e.FechaOferta,
                   e.GenStock, e.NumEti, e.NumEtiEnHoja
            FROM [EtiquetasArticulo] e
            WHERE {$sqlWhere}";
    $sql = SqlPagination::wrap($innerSql, 'e.NroLin DESC', $offset, $pageSize);
    $stmt = $this->pdo->prepare($sql);
    foreach ($params as $k => $v) {
      $stmt->bindValue(':' . $k, $v);
    }
    SqlPagination::bind($stmt, $offset, $pageSize);
    $stmt->execute();

    $items = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $items[] = $this->mapRow($row);
    }

    return [
      'items' => $items,
      'total' => $total,
      'page' => $page,
      'pageSize' => $pageSize,
    ];
  }

  /**
   * @return array<string, mixed>|null
   */
  public function obtener(string $articulo, int $nroLin): ?array
  {
    $articulo = trim($articulo);
    if ($articulo === '' || $nroLin <= 0) {
      return null;
    }

    $stmt = $this->pdo->prepare(
      'SELECT Articulo, NroLin, Ean, Etiquetas, Cantidad, Precio, PrecioVentaTeorico,
              Descripcion, Lote, Puesto, TipoDocumento, Empresa, Albaran, FechaDocumento,
              Proveedor, FechaOferta, GenStock, NumEti, NumEtiEnHoja
       FROM [EtiquetasArticulo]
       WHERE RTRIM(Articulo) = :articulo AND NroLin = :nroLin'
    );
    $stmt->execute(['articulo' => $articulo, 'nroLin' => $nroLin]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $this->mapRow($row) : null;
  }

  /**
   * @param array<string, mixed> $body
   * @return array<string, mixed>
   */
  public function crear(array $body): array
  {
    $articulo = trim((string) ($body['articulo'] ?? ''));
    $eanRaw = $body['ean'] ?? null;

    if ($articulo === '' && $eanRaw !== null && trim((string) $eanRaw) !== '') {
      $resuelto = $this->codigoPorEan(trim((string) $eanRaw));
      if ($resuelto === null) {
        throw new \InvalidArgumentException('No se encontró artículo para el EAN indicado');
      }
      $articulo = $resuelto;
    }

    if ($articulo === '') {
      throw new \InvalidArgumentException('Artículo obligatorio');
    }
    if (mb_strlen($articulo) > 18) {
      throw new \InvalidArgumentException('Artículo demasiado largo (máx. 18)');
    }
    if (!$this->articuloExiste($articulo)) {
      throw new \InvalidArgumentException('Artículo no encontrado: ' . $articulo);
    }

    $cantidad = (int) ($body['cantidad'] ?? 0);
    if ($cantidad < 1) {
      throw new \InvalidArgumentException('Cantidad (copias) debe ser ≥ 1');
    }
    if ($cantidad > 32767) {
      throw new \InvalidArgumentException('Cantidad (copias) demasiado alta');
    }

    $defaults = $this->defaultsArticulo($articulo);
    $eanDigits = $this->normalizeEanOptional($eanRaw);
    if ($eanDigits === null) {
      $eanDigits = $defaults['ean'];
    }

    $descripcion = $this->nullIfEmpty($body['descripcion'] ?? null);
    if ($descripcion === null) {
      $descripcion = $defaults['descripcion'];
    }
    $descripcion = $this->trunc($descripcion, 50);

    $precio = array_key_exists('precio', $body) && $body['precio'] !== null && $body['precio'] !== ''
      ? (float) $body['precio']
      : $defaults['precio'];

    $sql = 'INSERT INTO [EtiquetasArticulo] (
        Articulo, Ean, Etiquetas, Precio, Descripcion, Lote, Puesto, TipoDocumento,
        Empresa, Albaran, FechaDocumento, Proveedor, PrecioVentaTeorico, FechaOferta,
        GenStock, NumEti, NumEtiEnHoja, Cantidad
      )
      OUTPUT INSERTED.NroLin
      VALUES (
        :articulo, :ean, :etiquetas, :precio, :descripcion, :lote, :puesto, :tipoDocumento,
        :empresa, :albaran, CONVERT(datetime, :fechaDocumento, 120), :proveedor,
        :precioVentaTeorico, CONVERT(datetime, :fechaOferta, 120),
        :genStock, :numEti, :numEtiEnHoja, :cantidadStock
      )';
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute([
      'articulo' => $articulo,
      'ean' => $eanDigits !== null ? (float) $eanDigits : 0.0,
      'etiquetas' => $cantidad,
      'precio' => $precio,
      'descripcion' => $descripcion,
      'lote' => $this->trunc($this->nullIfEmpty($body['lote'] ?? null), 30),
      'puesto' => $this->padNchar($this->nullIfEmpty($body['puesto'] ?? null), 2),
      'tipoDocumento' => $this->padNchar(
        $this->nullIfEmpty($body['tipoDocumento'] ?? null) ?? ' ',
        1
      ),
      'empresa' => $this->padNchar($this->nullIfEmpty($body['empresa'] ?? null), 3),
      'albaran' => $this->intOrZero($body['albaran'] ?? null),
      'fechaDocumento' => $this->normalizeFecha($body['fechaDocumento'] ?? null),
      'proveedor' => $this->padNchar($this->nullIfEmpty($body['proveedor'] ?? null), 6),
      'precioVentaTeorico' => isset($body['precioVentaTeorico'])
        ? (float) $body['precioVentaTeorico']
        : 0.0,
      'fechaOferta' => $this->normalizeFecha($body['fechaOferta'] ?? null),
      'genStock' => $this->padNchar($this->nullIfEmpty($body['genStock'] ?? null) ?? ' ', 1),
      'numEti' => $this->intOrZero($body['numEti'] ?? null),
      'numEtiEnHoja' => $this->intOrZero($body['numEtiEnHoja'] ?? null),
      'cantidadStock' => isset($body['cantidadStock']) ? (float) $body['cantidadStock'] : 0.0,
    ]);
    $nroLin = (int) $stmt->fetchColumn();
    if ($nroLin <= 0) {
      throw new \RuntimeException('Línea de cola creada pero no se pudo obtener NroLin');
    }

    $item = $this->obtener($articulo, $nroLin);
    if ($item === null) {
      throw new \RuntimeException('Línea de cola creada pero no se pudo releer');
    }
    return $item;
  }

  /**
   * @param array<string, mixed> $body
   * @return array<string, mixed>
   */
  public function actualizar(string $articulo, int $nroLin, array $body): array
  {
    $articulo = trim($articulo);
    $actual = $this->obtener($articulo, $nroLin);
    if ($actual === null) {
      throw new \RuntimeException('Línea de cola no encontrada', 404);
    }

    $cantidad = array_key_exists('cantidad', $body)
      ? (int) $body['cantidad']
      : (int) $actual['cantidad'];
    if ($cantidad < 1) {
      throw new \InvalidArgumentException('Cantidad (copias) debe ser ≥ 1');
    }
    if ($cantidad > 32767) {
      throw new \InvalidArgumentException('Cantidad (copias) demasiado alta');
    }

    $eanDigits = array_key_exists('ean', $body)
      ? $this->normalizeEanOptional($body['ean'])
      : ($actual['ean'] !== null && $actual['ean'] !== '' ? (string) $actual['ean'] : null);

    $descripcion = array_key_exists('descripcion', $body)
      ? $this->trunc($this->nullIfEmpty($body['descripcion']), 50)
      : ($actual['descripcion'] ?? null);

    $precio = array_key_exists('precio', $body)
      ? (float) $body['precio']
      : (float) ($actual['precio'] ?? 0);

    $lote = array_key_exists('lote', $body)
      ? $this->trunc($this->nullIfEmpty($body['lote']), 30)
      : ($actual['lote'] ?? null);

    $puesto = array_key_exists('puesto', $body)
      ? $this->padNchar($this->nullIfEmpty($body['puesto']), 2)
      : $this->padNchar($actual['puesto'] ?? null, 2);

    $sql = 'UPDATE [EtiquetasArticulo] SET
        Ean = :ean,
        Etiquetas = :etiquetas,
        Precio = :precio,
        Descripcion = :descripcion,
        Lote = :lote,
        Puesto = :puesto
      WHERE RTRIM(Articulo) = :articulo AND NroLin = :nroLin';
    $this->pdo->prepare($sql)->execute([
      'ean' => $eanDigits !== null ? (float) $eanDigits : 0.0,
      'etiquetas' => $cantidad,
      'precio' => $precio,
      'descripcion' => $descripcion,
      'lote' => $lote,
      'puesto' => $puesto,
      'articulo' => $articulo,
      'nroLin' => $nroLin,
    ]);

    $item = $this->obtener($articulo, $nroLin);
    if ($item === null) {
      throw new \RuntimeException('Línea actualizada pero no se pudo releer');
    }
    return $item;
  }

  public function eliminar(string $articulo, int $nroLin): void
  {
    $articulo = trim($articulo);
    if ($articulo === '' || $nroLin <= 0) {
      throw new \InvalidArgumentException('Artículo y nroLin obligatorios');
    }
    if ($this->obtener($articulo, $nroLin) === null) {
      throw new \RuntimeException('Línea de cola no encontrada', 404);
    }

    $stmt = $this->pdo->prepare(
      'DELETE FROM [EtiquetasArticulo]
       WHERE RTRIM(Articulo) = :articulo AND NroLin = :nroLin'
    );
    $stmt->execute(['articulo' => $articulo, 'nroLin' => $nroLin]);
  }

  /**
   * Confirma impresión OK: DELETE de las líneas indicadas (005 / T023).
   * Solo borra las que existan; ignora refs inválidas.
   *
   * @param list<array{articulo?: mixed, nroLin?: mixed}> $lineas
   * @return array{eliminadas: int}
   */
  public function confirmarImpresion(array $lineas): array
  {
    if ($lineas === []) {
      throw new \InvalidArgumentException('lineas es obligatorio y no puede estar vacío');
    }

    $eliminadas = 0;
    $stmt = $this->pdo->prepare(
      'DELETE FROM [EtiquetasArticulo]
       WHERE RTRIM(Articulo) = :articulo AND NroLin = :nroLin'
    );

    $this->pdo->beginTransaction();
    try {
      foreach ($lineas as $ref) {
        if (!is_array($ref)) {
          continue;
        }
        $articulo = trim((string) ($ref['articulo'] ?? ''));
        $nroLin = (int) ($ref['nroLin'] ?? 0);
        if ($articulo === '' || $nroLin <= 0) {
          continue;
        }
        $stmt->execute(['articulo' => $articulo, 'nroLin' => $nroLin]);
        $eliminadas += (int) $stmt->rowCount();
      }
      $this->pdo->commit();
    } catch (\Throwable $e) {
      if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      throw $e;
    }

    return ['eliminadas' => $eliminadas];
  }

  /**
   * Encola líneas desde un albarán de compra (005 / T030 / US5).
   * Copias = CEIL(ABS(cantidad línea)) mínimo 1.
   * Omite líneas sin artículo o sin EAN según flags de tienda.
   *
   * @param array<string, mixed> $body
   * @return array{items: list<array<string, mixed>>, omitidas: int}
   */
  public function desdeAlbaranCompra(array $body): array
  {
    $empresa = trim((string) ($body['empresa'] ?? ''));
    $albaran = (int) ($body['albaran'] ?? 0);
    $puesto = trim((string) ($body['puesto'] ?? ''));

    if ($empresa === '' || $albaran <= 0) {
      throw new \InvalidArgumentException('empresa y albaran (≥ 1) son obligatorios');
    }

    $detalle = $this->albaranesCompra->obtener($empresa, $albaran);
    if ($detalle === null) {
      throw new \RuntimeException('Albarán de compra no encontrado', 404);
    }

    $lineas = $detalle['lineas'] ?? [];
    if (!is_array($lineas) || $lineas === []) {
      return ['items' => [], 'omitidas' => 0];
    }

    $flags = $this->flagsEtiquetasTienda($empresa);
    $proveedor = $detalle['proveedor'] ?? null;
    $fechaDoc = $detalle['fechaAlbaran'] ?? null;

    $items = [];
    $omitidas = 0;

    foreach ($lineas as $lin) {
      if (!is_array($lin)) {
        $omitidas++;
        continue;
      }

      $articulo = trim((string) ($lin['articulo'] ?? ''));
      if ($articulo === '') {
        $omitidas++;
        continue;
      }

      if (!$this->articuloExiste($articulo)) {
        $omitidas++;
        continue;
      }

      $defaults = $this->defaultsArticulo($articulo);
      $ean = $defaults['ean'];

      // Flags Empresas (misma semántica que FE T028)
      if ($flags['soloPropios'] && ($ean === null || $ean === '')) {
        $omitidas++;
        continue;
      }
      if (!$flags['sinEans'] && ($ean === null || $ean === '')) {
        $omitidas++;
        continue;
      }

      $qtyLinea = (float) ($lin['cantidad'] ?? 0);
      $copias = (int) max(1, (int) ceil(abs($qtyLinea)));
      if ($copias > 32767) {
        $copias = 32767;
      }

      $descripcion = $this->nullIfEmpty($lin['descripcion'] ?? null);
      if ($descripcion === null) {
        $descripcion = $defaults['descripcion'];
      }

      // Precio de etiqueta = PVP artículo (no precio de compra)
      $precio = $defaults['precio'];

      $items[] = $this->crear([
        'articulo' => $articulo,
        'ean' => $ean,
        'cantidad' => $copias,
        'descripcion' => $descripcion,
        'precio' => $precio,
        'lote' => $lin['lote'] ?? null,
        'puesto' => $puesto !== '' ? $puesto : null,
        'tipoDocumento' => 'A',
        'empresa' => $empresa,
        'albaran' => $albaran,
        'fechaDocumento' => $fechaDoc,
        'proveedor' => $proveedor,
        'cantidadStock' => abs($qtyLinea),
      ]);
    }

    return [
      'items' => $items,
      'omitidas' => $omitidas,
    ];
  }

  /**
   * @return array{sinEans: bool, soloPropios: bool}
   */
  private function flagsEtiquetasTienda(string $empresa): array
  {
    $empresa = trim($empresa);
    if ($empresa === '') {
      return ['sinEans' => false, 'soloPropios' => false];
    }

    try {
      $stmt = $this->pdo->prepare(
        "SELECT TOP 1 ImpEtiquetasSinEans, ImpEtiquetasSoloEansPropios
         FROM [Empresas]
         WHERE RTRIM(Codigo) = RTRIM(:e)
            OR RTRIM(Codigo) = RIGHT('000' + RTRIM(:e2), 3)"
      );
      $stmt->execute(['e' => $empresa, 'e2' => $empresa]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$row) {
        return ['sinEans' => false, 'soloPropios' => false];
      }
      return [
        'sinEans' => $this->flagOn($row['ImpEtiquetasSinEans'] ?? 0),
        'soloPropios' => $this->flagOn($row['ImpEtiquetasSoloEansPropios'] ?? 0),
      ];
    } catch (\Throwable $e) {
      return ['sinEans' => false, 'soloPropios' => false];
    }
  }

  private function flagOn(mixed $v): bool
  {
    if ($v === true || $v === 1 || $v === '1') {
      return true;
    }
    if (is_string($v) && strtolower(trim($v)) === 'true') {
      return true;
    }
    return is_numeric($v) && (float) $v !== 0.0;
  }

  /**
   * @param array<string, mixed> $row
   * @return array<string, mixed>
   */
  private function mapRow(array $row): array
  {
    $ean = $this->eanFromSql($row['Ean'] ?? null);

    return [
      'articulo' => trim((string) ($row['Articulo'] ?? '')),
      'nroLin' => (int) ($row['NroLin'] ?? 0),
      'ean' => $ean !== '' ? $ean : null,
      'cantidad' => (int) ($row['Etiquetas'] ?? 0),
      'cantidadStock' => isset($row['Cantidad']) ? (float) $row['Cantidad'] : null,
      'precio' => (float) ($row['Precio'] ?? 0),
      'precioVentaTeorico' => isset($row['PrecioVentaTeorico'])
        ? (float) $row['PrecioVentaTeorico']
        : null,
      'descripcion' => $this->trimOrNull($row['Descripcion'] ?? null),
      'lote' => $this->trimOrNull($row['Lote'] ?? null),
      'puesto' => $this->trimOrNull($row['Puesto'] ?? null),
      'tipoDocumento' => $this->trimOrNull($row['TipoDocumento'] ?? null),
      'empresa' => $this->trimOrNull($row['Empresa'] ?? null),
      'albaran' => isset($row['Albaran']) && (int) $row['Albaran'] > 0
        ? (int) $row['Albaran']
        : null,
      'fechaDocumento' => $this->fechaApi($row['FechaDocumento'] ?? null),
      'proveedor' => $this->trimOrNull($row['Proveedor'] ?? null),
      'fechaOferta' => $this->fechaApi($row['FechaOferta'] ?? null),
      'genStock' => $this->trimOrNull($row['GenStock'] ?? null),
      'numEti' => isset($row['NumEti']) ? (int) $row['NumEti'] : null,
      'numEtiEnHoja' => isset($row['NumEtiEnHoja']) ? (int) $row['NumEtiEnHoja'] : null,
    ];
  }

  private function articuloExiste(string $codigo): bool
  {
    $stmt = $this->pdo->prepare(
      'SELECT 1 FROM [Articulos] WHERE RTRIM([Codigo]) = :codigo'
    );
    $stmt->execute(['codigo' => $codigo]);
    return (bool) $stmt->fetchColumn();
  }

  /**
   * @return array{descripcion: string|null, precio: float, ean: string|null}
   */
  private function defaultsArticulo(string $codigo): array
  {
    $stmt = $this->pdo->prepare(
      'SELECT RTRIM(ISNULL(Descripcion, \'\')) AS Descripcion,
              ISNULL(PrecioVen1, 0) AS PrecioVen1
       FROM [Articulos]
       WHERE RTRIM(Codigo) = :codigo'
    );
    $stmt->execute(['codigo' => $codigo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $eanStmt = $this->pdo->prepare(
      'SELECT TOP 1 Ean FROM [ArtBarras]
       WHERE RTRIM(Codigo) = :codigo
       ORDER BY Unidades ASC, Ean ASC'
    );
    $eanStmt->execute(['codigo' => $codigo]);
    $eanRaw = $eanStmt->fetchColumn();
    $ean = $eanRaw !== false ? $this->eanFromSql($eanRaw) : '';

    return [
      'descripcion' => $row ? ($this->trimOrNull($row['Descripcion'] ?? null)) : null,
      'precio' => $row ? (float) ($row['PrecioVen1'] ?? 0) : 0.0,
      'ean' => $ean !== '' ? $ean : null,
    ];
  }

  private function codigoPorEan(string $ean): ?string
  {
    $digits = $this->normalizeEanOptional($ean);
    if ($digits === null) {
      return null;
    }
    $stmt = $this->pdo->prepare(
      'SELECT TOP 1 RTRIM(Codigo) AS Codigo FROM [ArtBarras] WHERE Ean = :ean'
    );
    $stmt->execute(['ean' => (float) $digits]);
    $codigo = $stmt->fetchColumn();
    if ($codigo === false || trim((string) $codigo) === '') {
      return null;
    }
    return trim((string) $codigo);
  }

  private function normalizeEanOptional(mixed $value): ?string
  {
    if ($value === null) {
      return null;
    }
    $s = trim((string) $value);
    if ($s === '' || $s === '0') {
      return null;
    }
    if (preg_match('/^\d+\.0+$/', $s)) {
      $s = explode('.', $s, 2)[0];
    }
    if (!preg_match('/^\d{4,18}$/', $s)) {
      throw new \InvalidArgumentException("EAN inválido: {$s} (solo dígitos, 4–18)");
    }
    return $s;
  }

  private function eanFromSql(mixed $value): string
  {
    if ($value === null || $value === '') {
      return '';
    }
    $f = (float) $value;
    if ($f == 0.0) {
      return '';
    }
    return sprintf('%.0f', $f);
  }

  private function nullIfEmpty(mixed $value): ?string
  {
    if ($value === null) {
      return null;
    }
    $s = trim((string) $value);
    return $s === '' ? null : $s;
  }

  private function trimOrNull(mixed $value): ?string
  {
    if ($value === null) {
      return null;
    }
    $s = rtrim((string) $value);
    return $s === '' ? null : $s;
  }

  private function trunc(?string $value, int $max): ?string
  {
    if ($value === null) {
      return null;
    }
    if (mb_strlen($value) <= $max) {
      return $value;
    }
    return mb_substr($value, 0, $max);
  }

  private function padNchar(?string $value, int $len): ?string
  {
    if ($value === null) {
      return null;
    }
    $s = mb_substr($value, 0, $len);
    return str_pad($s, $len, ' ');
  }

  private function intOrZero(mixed $value): int
  {
    if ($value === null || $value === '') {
      return 0;
    }
    return (int) $value;
  }

  private function normalizeFecha(mixed $value): ?string
  {
    if ($value === null || $value === '') {
      return null;
    }
    $s = trim((string) $value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) {
      return substr($s, 0, 10) . ' 00:00:00';
    }
    return null;
  }

  private function fechaApi(mixed $value): ?string
  {
    if ($value === null || $value === '') {
      return null;
    }
    if ($value instanceof \DateTimeInterface) {
      return $value->format('Y-m-d');
    }
    $s = (string) $value;
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $s, $m)) {
      return $m[1];
    }
    $ts = strtotime($s);
    return $ts !== false ? date('Y-m-d', $ts) : null;
  }
}
