<?php

declare(strict_types=1);

namespace Descartes\Api\Services;

use Descartes\Api\Config\EntityConfig;
use PDO;
use PDOException;

final class MantenimientoService
{
  private PDO $pdo;
  private DependencyCheckService $dependencyCheckService;
  private TiendaAlmacenService $tiendaAlmacenService;
  private ArticuloService $articuloService;

  public function __construct(
    PDO $pdo,
    DependencyCheckService $dependencyCheckService,
    TiendaAlmacenService $tiendaAlmacenService,
    ArticuloService $articuloService
  ) {
    $this->pdo = $pdo;
    $this->dependencyCheckService = $dependencyCheckService;
    $this->tiendaAlmacenService = $tiendaAlmacenService;
    $this->articuloService = $articuloService;
  }

  private function columnExists(string $table, string $column): bool
  {
    $sql = sprintf("SELECT COL_LENGTH('dbo.%s', '%s')", $table, $column);
    $length = $this->pdo->query($sql)->fetchColumn();

    return $length !== false && $length !== null;
  }

  /**
   * Formato* = plantilla; Imp* / ImpresoraTickets* = nombre Windows (antes 8–10 / lógico OPOS).
   */
  private function ensurePuestosFormatoPlantillas(): void
  {
    static $done = false;
    if ($done) {
      return;
    }
    $columnas = [
      'FormatoAlbaranes',
      'FormatoFacturasContado',
      'FormatoPresupuestos',
      'FormatoFacturas',
      'FormatoRecibos',
      'FormatoPedidos',
      'FormatoEtiquetasEnvio',
      'FormatoAlbaranCompras',
      'FormatoPedidoCompras',
      'FormatoFabricacion',
      'FormatoCorte',
      'ImpresoraTickets',
      'ImpresoraTicketsF',
      'ImpresoraEtiquetas',
      'Imp80',
      'ImpFax',
      'ImpTarjetas',
      'ImpAlbaranes',
      'ImpPresupuestos',
      'ImpFacturasContado',
      'ImpFacturas',
      'ImpRecibos',
      'ImpPedidos',
      'ImpEtiquetasEnvio',
      'ImpAlbaranCompras',
      'ImpPedidoCompras',
      'ImpFabricacion',
      'ImpCorte',
    ];
    foreach ($columnas as $col) {
      if (!$this->columnExists('Puestos', $col)) {
        continue;
      }
      // nvarchar(N) => COL_LENGTH = 2*N; ampliar si sigue corto (< 200 = nvarchar(100))
      $len = (int) $this->pdo->query(sprintf("SELECT COL_LENGTH('dbo.Puestos', '%s')", $col))->fetchColumn();
      if ($len > 0 && $len < 200) {
        $this->pdo->exec(sprintf(
          'ALTER TABLE [Puestos] ALTER COLUMN [%s] nvarchar(100) NULL',
          $col
        ));
      }
    }
    $done = true;
  }

  private function canFilterActivo(array $config, string $table): bool
  {
    $soft = $config['softDelete'] ?? null;
    if ($soft === null) {
      return false;
    }

    return $this->columnExists($table, $soft['column']);
  }

  public function list(string $entidad, array $query): array
  {
    $config = EntityConfig::assertExists($entidad);
    $table = $config['table'];
    $pk = $config['primaryKey'];
    $page = max(1, (int) ($query['page'] ?? 1));
    $pageSize = min(500, max(1, (int) ($query['pageSize'] ?? 25)));
    $offset = ($page - 1) * $pageSize;

    $where = [];
    $params = [];

    if (isset($query['q']) && $query['q'] !== '') {
      $searchCols = $config['searchColumns'] ?? [$pk];
      $parts = [];
      foreach ($searchCols as $i => $col) {
        $key = "q{$i}";
        $parts[] = "[{$table}].[{$col}] LIKE :{$key}";
        $params[$key] = '%' . $query['q'] . '%';
      }
      if ($entidad === 'articulos') {
        // ODBC/SQL Server: cada placeholder debe ser unico (no reutilizar :nombre).
        // Codigo FK o descripcion de Familia / Impuesto / Proveedor habitual
        $like = '%' . $query['q'] . '%';
        $parts[] = "EXISTS (SELECT 1 FROM [Familias] f WHERE RTRIM(f.[Codigo]) = RTRIM([{$table}].[Familia]) AND (f.[Codigo] LIKE :qFamCod OR f.[Descripcion] LIKE :qFamDesc))";
        $params['qFamCod'] = $like;
        $params['qFamDesc'] = $like;
        $parts[] = "EXISTS (SELECT 1 FROM [Impuestos] i WHERE RTRIM(i.[Codigo]) = RTRIM([{$table}].[Impuesto]) AND (i.[Codigo] LIKE :qImpCod OR i.[Descripcion] LIKE :qImpDesc))";
        $params['qImpCod'] = $like;
        $params['qImpDesc'] = $like;
        $parts[] = "EXISTS (SELECT 1 FROM [Proveedores] p WHERE RTRIM(p.[Codigo]) = RTRIM([{$table}].[UltProveedor]) AND (p.[Codigo] LIKE :qProvCod OR ISNULL(p.[RazonSocial],'') LIKE :qProvNom))";
        $params['qProvCod'] = $like;
        $params['qProvNom'] = $like;
      }
      $where[] = '(' . implode(' OR ', $parts) . ')';
    }

    if (isset($query['activo'])) {
      $activo = filter_var($query['activo'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
      if ($activo !== null && $this->canFilterActivo($config, $table)) {
        $where[] = $this->activoSqlCondition($config, $activo);
      }
    }

    if (isset($query['empresaCodigo']) && $query['empresaCodigo'] !== '') {
      if ($entidad === 'clientes') {
        $where[] = '[Empresa] = :empresaCodigo';
        $params['empresaCodigo'] = $query['empresaCodigo'];
      }
    }

    if ($entidad === 'tiendas' && filter_var($query['conParametros'] ?? false, FILTER_VALIDATE_BOOL)) {
      $where[] = 'EXISTS (SELECT 1 FROM [Parametros] p WHERE RTRIM(p.[Empresa]) = RTRIM([Empresas].[Codigo]))';
    }

    $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

    $countStmt = $this->pdo->prepare("SELECT COUNT(*) AS total FROM [{$table}] {$whereSql}");
    $countStmt->execute($params);
    $total = (int) ($countStmt->fetch()['total'] ?? 0);

    $sql = "SELECT * FROM [{$table}] {$whereSql} ORDER BY [{$pk}] OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY";
    $stmt = $this->pdo->prepare($sql);
    foreach ($params as $key => $value) {
      $stmt->bindValue(':' . $key, $value);
    }
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmt->execute();

    $items = [];
    while ($row = $stmt->fetch()) {
      $items[] = $this->enrichItem($entidad, $this->mapRowToApi($config, $row));
    }

    return [
      'items' => $items,
      'page' => $page,
      'pageSize' => $pageSize,
      'total' => $total,
    ];
  }

  public function get(string $entidad, string $codigo): ?array
  {
    $config = EntityConfig::assertExists($entidad);

    foreach ($this->codigoVariants($entidad, $codigo) as $variant) {
      $stmt = $this->pdo->prepare("SELECT * FROM [{$config['table']}] WHERE [{$config['primaryKey']}] = :codigo");
      $this->bindPrimaryKey($stmt, ':codigo', $variant, $config);
      $stmt->execute();
      $row = $stmt->fetch();
      if ($row) {
        return $this->enrichItem($entidad, $this->mapRowToApi($config, $row));
      }
    }

    return null;
  }

  private function codigoVariants(string $entidad, string $codigo): array
  {
    $codigo = trim($codigo);
    $variants = [$codigo];

    if ($entidad === 'puestos-trabajo') {
      $variants[] = str_pad($codigo, 2, '0', STR_PAD_LEFT);
    }

    if ($entidad === 'tiendas') {
      $variants[] = str_pad($codigo, 3, '0', STR_PAD_LEFT);
    }

    return array_values(array_unique($variants));
  }

  public function create(string $entidad, array $data): array
  {
    if ($entidad === 'puestos-trabajo') {
      $this->ensurePuestosFormatoPlantillas();
    }
    $config = EntityConfig::assertExists($entidad);
    if ($entidad === 'usuarios') {
      $this->validarUsuario($data, true);
    }
    if ($entidad === 'tiendas') {
      $this->validarTienda($data, null);
    }
    if ($entidad === 'articulos') {
      $this->asignarCodigoArticuloSiCorresponde($data);
      $this->validarArticulo($data, null);
    }
    if ($entidad === 'clientes') {
      // Validar datos de negocio antes de reservar UltCliente (GenClientes).
      $this->validarClienteDatosObligatorios($data, true);
      $empresaCodigo = trim((string) ($data['tiendaCodigo'] ?? ''));
      $this->asignarCodigoClienteSiCorresponde($data, $empresaCodigo);
      $this->validarCliente($data, null);
    }
    if ($entidad === 'trabajadores') {
      $this->validarTrabajador($data, null);
    }
    if ($entidad === 'almacenes') {
      $this->validarAlmacen($data, null);
    }
    if ($entidad === 'impuestos') {
      $this->validarImpuesto($data, null);
    }
    if ($entidad === 'formas-pago') {
      $this->validarFormaPago($data, null);
    }
    if ($entidad === 'macrofamilias') {
      $this->validarMacrofamilia($data, null);
    }
    if ($entidad === 'actividades') {
      $this->validarActividad($data, null);
    }
    if ($entidad === 'intereses-comerciales') {
      $this->validarInteresComercial($data, null);
    }
    if ($entidad === 'agrupaciones') {
      $this->validarAgrupacion($data, null);
    }
    if ($entidad === 'familias') {
      $this->validarFamilia($data, null);
    }
    if ($entidad === 'subfamilias') {
      $this->validarSubfamilia($data, null);
    }
    if ($entidad === 'proveedores') {
      $empresaCodigo = trim((string) ($data['empresaCodigo'] ?? ''));
      unset($data['empresaCodigo']);
      $this->asignarCodigoProveedorSiCorresponde($data, $empresaCodigo);
    }
    $table = $config['table'];
    $pk = $config['primaryKey'];

    $mapped = $this->mapApiToRow($config, $data, true);
    if (!isset($mapped[$pk]) || $mapped[$pk] === '' || $mapped[$pk] === null) {
      throw new \InvalidArgumentException('Codigo obligatorio');
    }

    $codigo = (string) $mapped[$pk];
    $useTransaction = $entidad === 'tiendas';
    if ($useTransaction) {
      $this->pdo->beginTransaction();
    }

    try {
      if ($entidad === 'tiendas') {
        $this->ensureParametrosTienda($codigo, $data);
      }

      $columns = array_keys($mapped);
      $valueExprs = [];
      foreach (array_values($columns) as $i => $col) {
        $valueExprs[] = $this->sqlValueExpression($col, 'p' . $i);
      }
      $sql = sprintf(
        'INSERT INTO [%s] (%s) VALUES (%s)',
        $table,
        implode(', ', array_map(static fn (string $c) => "[{$c}]", $columns)),
        implode(', ', $valueExprs)
      );

      $stmt = $this->pdo->prepare($sql);
      $this->bindPlaceholderValues($stmt, array_values($mapped));
      $stmt->execute();

      if ($useTransaction) {
        $this->pdo->commit();
      }
    } catch (\Throwable $e) {
      if ($useTransaction && $this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      throw $e;
    }
    if ($entidad === 'articulos') {
      $this->articuloService->saveExtras($codigo, $data);
    }

    return $this->get($entidad, $codigo) ?? $data;
  }

  public function update(string $entidad, string $codigo, array $data): ?array
  {
    if ($entidad === 'puestos-trabajo') {
      $this->ensurePuestosFormatoPlantillas();
    }
    $config = EntityConfig::assertExists($entidad);
    if ($entidad === 'tiendas') {
      $this->validarTienda($data, $codigo);
    }
    if ($entidad === 'articulos') {
      $this->validarArticulo($data, $codigo);
    }
    if ($entidad === 'clientes') {
      $this->validarCliente($data, $codigo);
    }
    if ($entidad === 'trabajadores') {
      $this->validarTrabajador($data, $codigo);
    }
    if ($entidad === 'almacenes') {
      $this->validarAlmacenBaja($data, $codigo);
    }
    if ($entidad === 'formas-pago') {
      $this->validarFormaPago($data, $codigo);
    }
    if ($entidad === 'usuarios') {
      $this->validarUsuario($data, false);
    }
    if ($entidad === 'macrofamilias') {
      $this->validarMacrofamilia($data, $codigo);
    }
    if ($entidad === 'actividades') {
      $this->validarActividad($data, $codigo);
    }
    if ($entidad === 'intereses-comerciales') {
      $this->validarInteresComercial($data, $codigo);
    }
    if ($entidad === 'agrupaciones') {
      $this->validarAgrupacion($data, $codigo);
    }
    if ($entidad === 'familias') {
      $this->validarFamilia($data, $codigo);
    }
    if ($entidad === 'subfamilias') {
      $this->validarSubfamilia($data, $codigo);
    }
    $table = $config['table'];
    $pk = $config['primaryKey'];

    $mapped = $this->mapApiToRow($config, $data, false);
    unset($mapped[$pk]);

    if ($mapped === []) {
      return $this->get($entidad, $codigo);
    }

    $sets = [];
    $i = 0;
    foreach (array_keys($mapped) as $col) {
      $sets[] = "[{$col}] = " . $this->sqlValueExpression($col, 'p' . $i);
      $i++;
    }

    $sql = sprintf('UPDATE [%s] SET %s WHERE [%s] = :pk', $table, implode(', ', $sets), $pk);
    $stmt = $this->pdo->prepare($sql);
    $this->bindPlaceholderValues($stmt, array_values($mapped));
    $this->bindPrimaryKey($stmt, ':pk', $codigo, $config);
    $stmt->execute();

    if ($entidad === 'tiendas') {
      $this->syncParametrosNombreTienda($codigo, $data);
    }

    if ($entidad === 'articulos') {
      $this->articuloService->saveExtras($codigo, $data);
    }

    return $this->get($entidad, $codigo);
  }

  public function softDelete(string $entidad, string $codigo): void
  {
    $check = $this->dependencyCheckService->puedeDarDeBaja($entidad, $codigo);
    if (!$check['ok']) {
      throw new DependencyException(
        'No se puede dar de baja el registro porque tiene dependencias activas',
        $check['dependencias']
      );
    }

    $config = EntityConfig::assertExists($entidad);
    $soft = $config['softDelete'] ?? null;
    if ($soft !== null) {
      $this->aplicarBajaLogica($config, $codigo, $soft);
      return;
    }

    if (empty($config['hardDelete'])) {
      throw new \RuntimeException('Eliminacion no configurada para esta entidad');
    }

    $this->aplicarBajaFisica($config, $codigo);
  }

  private function aplicarBajaLogica(array $config, string $codigo, array $soft): void
  {
    $table = $config['table'];
    $pk = $config['primaryKey'];
    $column = $soft['column'];
    $inactive = $soft['inactiveValue'];

    $this->assertRegistroExiste($config, $codigo);
    $this->assertSoftDeleteColumn($table, $column);

    if ($inactive === 'NOT_NULL') {
      $sql = "UPDATE [{$table}] SET [{$column}] = GETDATE() WHERE [{$pk}] = :codigo";
      $stmt = $this->pdo->prepare($sql);
    } else {
      $sql = "UPDATE [{$table}] SET [{$column}] = :inactive WHERE [{$pk}] = :codigo";
      $stmt = $this->pdo->prepare($sql);
      $stmt->bindValue(':inactive', $inactive, PDO::PARAM_INT);
    }

    $this->bindPrimaryKey($stmt, ':codigo', $codigo, $config);
    $stmt->execute();

    // PDO/SQL Server a veces reporta rowCount=0 aunque el UPDATE haya afectado filas.
    if (!$this->registroEstaDeBaja($config, $codigo, $soft)) {
      throw new \RuntimeException('No se pudo dar de baja el registro');
    }
  }

  private function registroEstaDeBaja(array $config, string $codigo, array $soft): bool
  {
    $table = $config['table'];
    $pk = $config['primaryKey'];
    $column = $soft['column'];
    $inactive = $soft['inactiveValue'];

    if ($inactive === 'NOT_NULL') {
      $sql = "SELECT 1 FROM [{$table}] WHERE [{$pk}] = :codigo AND [{$column}] IS NOT NULL";
      $stmt = $this->pdo->prepare($sql);
      $this->bindPrimaryKey($stmt, ':codigo', $codigo, $config);
      $stmt->execute();
      return (bool) $stmt->fetchColumn();
    }

    $sql = "SELECT 1 FROM [{$table}] WHERE [{$pk}] = :codigo AND [{$column}] = :inactive";
    $stmt = $this->pdo->prepare($sql);
    $this->bindPrimaryKey($stmt, ':codigo', $codigo, $config);
    $stmt->bindValue(':inactive', $inactive, PDO::PARAM_INT);
    $stmt->execute();
    return (bool) $stmt->fetchColumn();
  }

  private function aplicarBajaFisica(array $config, string $codigo): void
  {
    $codigo = trim($codigo);
    $this->assertRegistroExiste($config, $codigo);

    $table = $config['table'];
    $pk = $config['primaryKey'];
    $pkType = $config['primaryKeyType'] ?? 'string';

    if ($pkType === 'int') {
      $sql = "DELETE FROM [{$table}] WHERE [{$pk}] = :codigo";
    } else {
      // Evita fallos por espacios en nvarchar (p.ej. Codigo de Proveedores).
      $sql = "DELETE FROM [{$table}] WHERE RTRIM([{$pk}]) = :codigo";
    }

    $stmt = $this->pdo->prepare($sql);
    $this->bindPrimaryKey($stmt, ':codigo', $codigo, $config);
    $stmt->execute();

    if ($this->assertRegistroExisteSilent($config, $codigo)) {
      throw new \RuntimeException('No se pudo eliminar el registro');
    }
  }

  private function assertRegistroExisteSilent(array $config, string $codigo): bool
  {
    $codigo = trim($codigo);
    $table = $config['table'];
    $pk = $config['primaryKey'];
    $pkType = $config['primaryKeyType'] ?? 'string';
    $sql = $pkType === 'int'
      ? "SELECT 1 FROM [{$table}] WHERE [{$pk}] = :codigo"
      : "SELECT 1 FROM [{$table}] WHERE RTRIM([{$pk}]) = :codigo";
    $stmt = $this->pdo->prepare($sql);
    $this->bindPrimaryKey($stmt, ':codigo', $codigo, $config);
    $stmt->execute();
    return (bool) $stmt->fetchColumn();
  }

  private function activoSqlCondition(array $config, bool $activo): string
  {
    $soft = $config['softDelete'] ?? ['column' => 'Baja', 'inactiveValue' => 1, 'activeValue' => 0];
    $column = $soft['column'];

    if (($soft['inactiveValue'] ?? null) === 'NOT_NULL') {
      return $activo ? "[{$column}] IS NULL" : "[{$column}] IS NOT NULL";
    }

    $activeValue = $soft['activeValue'] ?? 0;
    $inactiveValue = $soft['inactiveValue'] ?? 1;
    return $activo
      ? "[{$column}] = {$activeValue}"
      : "[{$column}] = {$inactiveValue}";
  }

  private function sqlPlaceholders(int $count, string $prefix = 'p'): array
  {
    $placeholders = [];
    for ($i = 0; $i < $count; $i++) {
      $placeholders[] = ':' . $prefix . $i;
    }

    return $placeholders;
  }

  private function bindPlaceholderValues(\PDOStatement $stmt, array $values, string $prefix = 'p'): void
  {
    foreach (array_values($values) as $i => $value) {
      $param = ':' . $prefix . $i;
      if ($value === null) {
        $stmt->bindValue($param, null, \PDO::PARAM_NULL);
      } else {
        $stmt->bindValue($param, $value);
      }
    }
  }

  private function mapRowToApi(array $config, array $row): array
  {
    $result = [];
    foreach ($config['fields'] as $apiField => $sqlColumn) {
      if (!array_key_exists($sqlColumn, $row)) {
        continue;
      }
      $value = $row[$sqlColumn];
      if ($apiField === 'activo') {
        $result[$apiField] = $this->isActivo($config, $value);
        continue;
      }
      if ($this->isBooleanField($config, $apiField)) {
        $result[$apiField] = (bool) $value;
        continue;
      }
      if ($this->isIntField($config, $apiField)) {
        $result[$apiField] = $value === null || $value === '' ? null : (int) $value;
        continue;
      }
      if ($this->isTimeField($config, $apiField)) {
        $result[$apiField] = $this->normalizeTimeValue($value);
        continue;
      }
      if ($apiField === 'almacenCodigo') {
        $result[$apiField] = $value === null || (float) $value <= 0 ? null : (int) $value;
        continue;
      }
      if ($sqlColumn === 'LUpdate') {
        $result[$apiField] = $this->normalizeDateValue($value);
        continue;
      }
      if ($this->isDateField($config, $apiField) || $this->isDateSqlColumn($sqlColumn)) {
        $normalized = $this->normalizeDateValue($value);
        // API: solo fecha para inputs HTML date
        $result[$apiField] = $normalized === null ? null : substr($normalized, 0, 10);
        continue;
      }
      $result[$apiField] = $value;
    }
    return $result;
  }

  private function mapApiToRow(array $config, array $data, bool $isCreate): array
  {
    $mapped = [];
    foreach ($config['fields'] as $apiField => $sqlColumn) {
      if (!array_key_exists($apiField, $data)) {
        continue;
      }
      if (in_array($apiField, $config['hidden'] ?? [], true)) {
        continue;
      }
      if (in_array($apiField, $config['readOnlyFields'] ?? [], true)) {
        continue;
      }
      $value = $data[$apiField];
      if ($apiField === 'activo') {
        $mapped[$sqlColumn] = $this->activoToSql($config, (bool) $value);
        continue;
      }
      if ($this->isBooleanField($config, $apiField)) {
        $mapped[$sqlColumn] = $value ? 1 : 0;
        continue;
      }
      if ($this->isIntField($config, $apiField)) {
        $mapped[$sqlColumn] = $value === null || $value === '' ? null : (int) $value;
        continue;
      }
      if ($this->isTimeField($config, $apiField)) {
        $mapped[$sqlColumn] = $this->timeToSqlDateTime($value);
        continue;
      }
      if ($apiField === 'almacenCodigo') {
        $mapped[$sqlColumn] = $value === null || $value === '' ? 0 : (int) $value;
        continue;
      }
      if ($apiField === 'tarjeta') {
        $mapped[$sqlColumn] = $value === null || $value === '' ? 0 : (int) $value;
        continue;
      }
      if ($apiField === 'conceptoDescuadre') {
        $concepto = trim((string) ($value ?? ''));
        $mapped[$sqlColumn] = $concepto === '' ? null : substr($concepto, 0, 2);
        continue;
      }
      if ($this->isDateField($config, $apiField) || $this->isDateSqlColumn($sqlColumn)) {
        $mapped[$sqlColumn] = $this->normalizeDateValue($value);
        continue;
      }
      $mapped[$sqlColumn] = $value;
    }

    foreach ($mapped as $col => $val) {
      if ($val === '') {
        $mapped[$col] = null;
      }
    }

    $this->truncateStringMaxLengths($config, $mapped);
    $this->assertStringMaxLengths($config, $mapped);

    if ($isCreate && isset($config['softDelete']) && !isset($data['activo'])) {
      $soft = $config['softDelete'];
      if (($soft['inactiveValue'] ?? null) !== 'NOT_NULL') {
        $mapped[$soft['column']] = $soft['activeValue'] ?? 0;
      }
    }

    if ($isCreate && isset($config['createDefaults']) && is_array($config['createDefaults'])) {
      foreach ($config['createDefaults'] as $sqlColumn => $defaultValue) {
        // Legacy rellena 0/'' en alta; no dejar NULL si el cliente omite el campo.
        if (!array_key_exists($sqlColumn, $mapped) || $mapped[$sqlColumn] === null) {
          $mapped[$sqlColumn] = $defaultValue;
        }
      }
    }

    // Almacenes.CentroCoste: mantener en blanco ('') aunque el payload vacio se normalice a null.
    if (($config['table'] ?? '') === 'Almacenes' && array_key_exists('CentroCoste', $mapped) && $mapped['CentroCoste'] === null) {
      $mapped['CentroCoste'] = '';
    }

    // FormasPago: legacy guarda '' (no NULL) en textos opcionales; FACTEFPCodigo a 0.
    if (($config['table'] ?? '') === 'FormasPago') {
      foreach (
        [
          'Abreviacion',
          'Tipo',
          'Nota',
          'FACTEIban',
          'FACTEBanco',
          'FACTESucursal',
          'FACTEBanDir',
          'FACTEBanCodPos',
          'FACTEBanPob',
          'FACTEBanPrv',
          'FACTEBanPai',
        ] as $col
      ) {
        if (array_key_exists($col, $mapped) && $mapped[$col] === null) {
          $mapped[$col] = '';
        }
      }
      if (array_key_exists('FACTEFPCodigo', $mapped) && $mapped['FACTEFPCodigo'] === null) {
        $mapped['FACTEFPCodigo'] = 0;
      }
    }

    // Familias.CuentaCtbIta: legacy en blanco (''), no NULL.
    if (($config['table'] ?? '') === 'Familias' && array_key_exists('CuentaCtbIta', $mapped) && $mapped['CuentaCtbIta'] === null) {
      $mapped['CuentaCtbIta'] = '';
    }

    // Actividades / InteresesComerciales: Descripcion en '' (no NULL).
    if (
      in_array(($config['table'] ?? ''), ['Actividades', 'InteresesComerciales'], true)
      && array_key_exists('Descripcion', $mapped)
      && $mapped['Descripcion'] === null
    ) {
      $mapped['Descripcion'] = '';
    }

    // Clientes: textos/numeros opcionales en ''/0 (legacy), no NULL.
    // Fechas vacias → 1995-01-01 (sentinel legacy).
    if (($config['table'] ?? '') === 'Clientes') {
      foreach (
        [
          'PersonaContacto',
          'Direccion',
          'Poblacion',
          'Provincia',
          'Pais',
          'CodigoPostal',
          'DireccionEnvio',
          'PoblacionEnvio',
          'ProvinciaEnvio',
          'PaisEnvio',
          'Telefono1',
          'Telefono2',
          'Fax',
          'Email',
          'EmailComercial',
          'EmailFacturacion',
          'Vendedor',
          'AgenteOrigen',
          'Agencia',
          'CuentaBancaria',
          'CuentaCtb',
          'CuentaCtbIta',
          'RazonSocial2',
          'Swift',
          'IBAN',
          'Banco',
          'DescripcionPago',
          'ObserRecibo',
          'InteresesComerciales',
          'Observaciones',
          'ObservacionesInternas',
          'Portes',
          'Actividad',
          'TratamientoFiscal',
          'Transportista',
          'TarjetaFidelizacion',
          'TipoDescuento',
          'TipoDescuentoFidelizacion',
          'ClasificacionComercial',
          'Tipologia',
          'CodigoPaisCliente',
          'CodigoPaisDireccion',
          'RegimenImpuestos',
          'CodigoDireccion',
          'CodigoClienteEmpresa',
          'EmpresaFacturacion',
          'ReferenciaMandato',
          'OficinaContable',
          'OrganoGestor',
          'UnidadTramitadora',
          'OrganoProponente',
          'CarnetManipulador',
          'CodigoTransaccionSII',
          'FidPregunta1',
          'FidPregunta2',
          'FidPregunta3',
          'FidPregunta4',
          'FidPregunta5',
          'Sexo',
        ] as $col
      ) {
        if (array_key_exists($col, $mapped) && $mapped[$col] === null) {
          $mapped[$col] = '';
        }
      }
      foreach (
        [
          'CuentaCtb2',
          'Naturaleza',
          'LimiteCredito',
          'TarifaTrans',
          'AlmacenTraspaso',
          'NumeroSubvenciones',
          'SaldoMenuDiario',
          'AdressId',
        ] as $col
      ) {
        if (array_key_exists($col, $mapped) && $mapped[$col] === null) {
          $mapped[$col] = 0;
        }
      }
      foreach (
        [
          'UltimaCompra',
          'FechaFirmaMandato',
          'FechaCaducidadCarnet',
          'FechaTx3',
          'FechaEntradaAdonix',
          'FechaAltaFidelizacion',
          'UltimaSubvencion',
        ] as $col
      ) {
        if (array_key_exists($col, $mapped) && $mapped[$col] === null) {
          $mapped[$col] = '1995-01-01 00:00:00';
        }
      }
      if ($isCreate && !array_key_exists('FechaAlta', $mapped)) {
        $mapped['FechaAlta'] = date('Y-m-d H:i:s');
      }
      // Legacy: ReferenciaMandato suele coincidir con Codigo si viene vacia.
      if (
        $isCreate
        && array_key_exists('Codigo', $mapped)
        && trim((string) ($mapped['ReferenciaMandato'] ?? '')) === ''
      ) {
        $mapped['ReferenciaMandato'] = (string) $mapped['Codigo'];
      }
      // Legacy actualiza LUpdate en alta/modificacion.
      $mapped['LUpdate'] = date('Y-m-d H:i:s');
    }

    if ($isCreate && ($config['table'] ?? '') === 'Vendedores' && !array_key_exists('ComisionVenta', $mapped)) {
      $mapped['ComisionVenta'] = 0;
    }

    foreach ($config['writeOnly'] ?? [] as $apiField => $sqlColumn) {
      if (!array_key_exists($apiField, $data)) {
        continue;
      }
      $plain = trim((string) $data[$apiField]);
      if ($plain === '') {
        continue;
      }
      // Vendedores.PassWord es int (PIN numerico legacy).
      if ($sqlColumn === 'PassWord' && ($config['table'] ?? '') === 'Vendedores') {
        if (!preg_match('/^-?\d+$/', $plain)) {
          throw new \InvalidArgumentException('La contrasena del trabajador debe ser numerica');
        }
        $mapped[$sqlColumn] = (int) $plain;
        continue;
      }
      // Usuarios.PassWord se guarda en texto plano (legacy).
      $mapped[$sqlColumn] = $plain;
    }

    // Sello de modificacion (servidor): no depende del payload del cliente.
    // Articulos.LUpdate: legacy lo pone en alta/modificacion.
    $fieldValues = array_values($config['fields'] ?? []);
    if (in_array('LUpdate', $fieldValues, true) || ($config['table'] ?? '') === 'Articulos') {
      $mapped['LUpdate'] = date('Y-m-d H:i:s');
    }

    return $mapped;
  }

  /**
   * Recorta textos al maximo de columna antes de validar (p.ej. Imp* de Puestos = 10).
   *
   * @param array<string, mixed> $config
   * @param array<string, mixed> $mapped
   */
  private function truncateStringMaxLengths(array $config, array &$mapped): void
  {
    $limits = $config['stringMaxLengths'] ?? null;
    if (!is_array($limits) || $limits === []) {
      return;
    }

    foreach ($limits as $sqlColumn => $maxLen) {
      if (!array_key_exists($sqlColumn, $mapped)) {
        continue;
      }
      $value = $mapped[$sqlColumn];
      if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
        continue;
      }
      $text = (string) $value;
      $max = (int) $maxLen;
      if ($max <= 0) {
        continue;
      }
      $len = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
      if ($len <= $max) {
        continue;
      }
      $mapped[$sqlColumn] = function_exists('mb_substr')
        ? mb_substr($text, 0, $max, 'UTF-8')
        : substr($text, 0, $max);
    }
  }

  private function assertStringMaxLengths(array $config, array $mapped): void
  {
    $limits = $config['stringMaxLengths'] ?? null;
    if (!is_array($limits) || $limits === []) {
      return;
    }

    $labels = array_flip($config['fields'] ?? []);
    foreach ($limits as $sqlColumn => $maxLen) {
      if (!array_key_exists($sqlColumn, $mapped)) {
        continue;
      }
      $value = $mapped[$sqlColumn];
      if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
        continue;
      }
      $text = (string) $value;
      $len = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
      if ($len <= (int) $maxLen) {
        continue;
      }
      $apiField = $labels[$sqlColumn] ?? $sqlColumn;
      throw new \InvalidArgumentException(
        "El campo \"{$apiField}\" supera la longitud maxima de {$maxLen} caracteres (tiene {$len})"
      );
    }
  }

  private function validarUsuario(array $data, bool $isCreate): void
  {
    if ($isCreate && trim((string) ($data['codigo'] ?? '')) === '') {
      throw new \InvalidArgumentException('El codigo es obligatorio');
    }
    if ($isCreate || array_key_exists('nombre', $data)) {
      if (trim((string) ($data['nombre'] ?? '')) === '') {
        throw new \InvalidArgumentException('El nombre es obligatorio');
      }
    }
    if ($isCreate || array_key_exists('rolCodigo', $data)) {
      if (trim((string) ($data['rolCodigo'] ?? '')) === '') {
        throw new \InvalidArgumentException('El rol es obligatorio');
      }
    }
    if ($isCreate || array_key_exists('password', $data)) {
      if (trim((string) ($data['password'] ?? '')) === '') {
        throw new \InvalidArgumentException('La contrasena es obligatoria');
      }
    }
  }

  private function ensureParametrosTienda(string $codigo, array $data): void
  {
    $stmt = $this->pdo->prepare('SELECT 1 FROM [Parametros] WHERE RTRIM([Empresa]) = :codigo');
    $stmt->execute(['codigo' => $codigo]);
    if ($stmt->fetch()) {
      return;
    }

    $nombre = trim((string) ($data['nombre'] ?? $data['nombreFiscal'] ?? ''));
    if ($nombre === '') {
      $nombre = $codigo;
    }
    if (strlen($nombre) > 50) {
      $nombre = substr($nombre, 0, 50);
    }

    $insert = $this->pdo->prepare(
      'INSERT INTO [Parametros] ([Empresa], [Nombre]) VALUES (:codigo, :nombre)'
    );
    $insert->execute(['codigo' => $codigo, 'nombre' => $nombre]);
  }

  /** Al renombrar la tienda en Empresas, mantener Parametros.Nombre alineado. */
  private function syncParametrosNombreTienda(string $codigo, array $data): void
  {
    if (!array_key_exists('nombre', $data) && !array_key_exists('nombreFiscal', $data)) {
      return;
    }

    $nombre = trim((string) ($data['nombre'] ?? $data['nombreFiscal'] ?? ''));
    if ($nombre === '') {
      return;
    }
    if (strlen($nombre) > 50) {
      $nombre = substr($nombre, 0, 50);
    }

    $stmt = $this->pdo->prepare(
      'UPDATE [Parametros] SET [Nombre] = :nombre WHERE RTRIM([Empresa]) = :codigo'
    );
    $stmt->execute(['nombre' => $nombre, 'codigo' => trim($codigo)]);
  }

  private function validarAlmacen(array $data, ?string $codigo): void
  {
    $isCreate = $codigo === null;

    if ($isCreate) {
      if (!array_key_exists('codigo', $data) || $data['codigo'] === null || $data['codigo'] === '') {
        throw new \InvalidArgumentException('El codigo es obligatorio');
      }

      $nuevoCodigo = (int) $data['codigo'];
      if ($nuevoCodigo <= 0) {
        throw new \InvalidArgumentException('El codigo es obligatorio');
      }

      $stmt = $this->pdo->prepare('SELECT 1 FROM [Almacenes] WHERE [Codigo] = :codigo');
      $stmt->execute(['codigo' => $nuevoCodigo]);
      if ($stmt->fetch()) {
        throw new \InvalidArgumentException('Ya existe un almacen con ese codigo');
      }
    }

    if ($isCreate || array_key_exists('descripcion', $data)) {
      if (trim((string) ($data['descripcion'] ?? '')) === '') {
        throw new \InvalidArgumentException('La descripcion es obligatoria');
      }
    }
  }

  private function validarAlmacenBaja(array $data, string $codigo): void
  {
    if (!array_key_exists('activo', $data) || $data['activo'] !== false) {
      return;
    }

    $check = $this->dependencyCheckService->puedeDarDeBaja('almacenes', $codigo);
    if (!$check['ok']) {
      throw new DependencyException(
        'No se puede dar de baja el registro porque tiene dependencias activas',
        $check['dependencias']
      );
    }
  }

  private function validarImpuesto(array $data, ?string $codigo): void
  {
    if ($codigo !== null) {
      return;
    }

    $nuevoCodigo = trim((string) ($data['codigo'] ?? ''));
    if ($nuevoCodigo === '') {
      throw new \InvalidArgumentException('El codigo es obligatorio');
    }
    if (strlen($nuevoCodigo) > 2) {
      throw new \InvalidArgumentException('El codigo de impuesto no puede superar 2 caracteres');
    }

    $stmt = $this->pdo->prepare('SELECT 1 FROM [Impuestos] WHERE RTRIM([Codigo]) = :codigo');
    $stmt->execute(['codigo' => $nuevoCodigo]);
    if ($stmt->fetch()) {
      throw new \InvalidArgumentException('Ya existe un impuesto con ese codigo');
    }

    if (trim((string) ($data['descripcion'] ?? '')) === '') {
      throw new \InvalidArgumentException('La descripcion es obligatoria');
    }

    if (!array_key_exists('porcentajeIVA', $data) || $data['porcentajeIVA'] === null || $data['porcentajeIVA'] === '') {
      throw new \InvalidArgumentException('El % IVA es obligatorio');
    }
  }

  private function validarFormaPago(array $data, ?string $codigo): void
  {
    $esAlta = $codigo === null;

    if ($esAlta) {
      $nuevoCodigo = trim((string) ($data['codigo'] ?? ''));
      if ($nuevoCodigo === '') {
        throw new \InvalidArgumentException('El codigo es obligatorio');
      }
      if (strlen($nuevoCodigo) > 2) {
        throw new \InvalidArgumentException('El codigo tiene maximo 2 caracteres');
      }
      $stmt = $this->pdo->prepare('SELECT 1 FROM [FormasPago] WHERE RTRIM([Codigo]) = :codigo');
      $stmt->execute(['codigo' => $nuevoCodigo]);
      if ($stmt->fetch()) {
        throw new \InvalidArgumentException('Ya existe una forma de pago con ese codigo');
      }
    }

    if ($esAlta || array_key_exists('descripcion', $data)) {
      if (trim((string) ($data['descripcion'] ?? '')) === '') {
        throw new \InvalidArgumentException('La descripcion es obligatoria');
      }
    }
  }

  private function validarMacrofamilia(array $data, ?string $codigo): void
  {
    $esAlta = $codigo === null;

    if ($esAlta) {
      $nuevoCodigo = trim((string) ($data['codigo'] ?? ''));
      if ($nuevoCodigo === '') {
        throw new \InvalidArgumentException('El codigo es obligatorio');
      }
      if (strlen($nuevoCodigo) > 6) {
        throw new \InvalidArgumentException('El codigo admite como maximo 6 caracteres');
      }
      $stmt = $this->pdo->prepare('SELECT 1 FROM [MacroFamilias] WHERE RTRIM([Codigo]) = :codigo');
      $stmt->execute(['codigo' => $nuevoCodigo]);
      if ($stmt->fetch()) {
        throw new \InvalidArgumentException('Ya existe una macrofamilia con ese codigo');
      }
    }

    if ($esAlta || array_key_exists('descripcion', $data)) {
      if (trim((string) ($data['descripcion'] ?? '')) === '') {
        throw new \InvalidArgumentException('La descripcion es obligatoria');
      }
    }
  }

  private function validarActividad(array $data, ?string $codigo): void
  {
    $esAlta = $codigo === null;

    if ($esAlta) {
      $nuevoCodigo = trim((string) ($data['codigo'] ?? ''));
      if ($nuevoCodigo === '') {
        throw new \InvalidArgumentException('El codigo es obligatorio');
      }
      if (strlen($nuevoCodigo) > 6) {
        throw new \InvalidArgumentException('El codigo admite como maximo 6 caracteres');
      }
      $stmt = $this->pdo->prepare('SELECT 1 FROM [Actividades] WHERE RTRIM([Codigo]) = :codigo');
      $stmt->execute(['codigo' => $nuevoCodigo]);
      if ($stmt->fetch()) {
        throw new \InvalidArgumentException('Ya existe una actividad con ese codigo');
      }
    }

    if ($esAlta || array_key_exists('descripcion', $data)) {
      if (trim((string) ($data['descripcion'] ?? '')) === '') {
        throw new \InvalidArgumentException('La descripcion es obligatoria');
      }
    }
  }

  private function validarInteresComercial(array $data, ?string $codigo): void
  {
    $esAlta = $codigo === null;

    if ($esAlta) {
      $nuevoCodigo = trim((string) ($data['codigo'] ?? ''));
      if ($nuevoCodigo === '') {
        throw new \InvalidArgumentException('El codigo es obligatorio');
      }
      if (strlen($nuevoCodigo) > 2) {
        throw new \InvalidArgumentException('El codigo admite como maximo 2 caracteres');
      }
      $stmt = $this->pdo->prepare(
        'SELECT 1 FROM [InteresesComerciales] WHERE RTRIM([Codigo]) = :codigo'
      );
      $stmt->execute(['codigo' => $nuevoCodigo]);
      if ($stmt->fetch()) {
        throw new \InvalidArgumentException('Ya existe un interes comercial con ese codigo');
      }
    }

    if ($esAlta || array_key_exists('descripcion', $data)) {
      $desc = trim((string) ($data['descripcion'] ?? ''));
      if ($desc === '') {
        throw new \InvalidArgumentException('La descripcion es obligatoria');
      }
      if (strlen($desc) > 50) {
        throw new \InvalidArgumentException('La descripcion admite como maximo 50 caracteres');
      }
    }
  }

  private function validarAgrupacion(array $data, ?string $codigo): void
  {
    $esAlta = $codigo === null;

    if ($esAlta) {
      $nuevoCodigo = trim((string) ($data['codigo'] ?? ''));
      if ($nuevoCodigo === '') {
        throw new \InvalidArgumentException('El codigo es obligatorio');
      }
      if (strlen($nuevoCodigo) > 6) {
        throw new \InvalidArgumentException('El codigo admite como maximo 6 caracteres');
      }
      $stmt = $this->pdo->prepare('SELECT 1 FROM [Agrupaciones] WHERE RTRIM([Codigo]) = :codigo');
      $stmt->execute(['codigo' => $nuevoCodigo]);
      if ($stmt->fetch()) {
        throw new \InvalidArgumentException('Ya existe una agrupacion con ese codigo');
      }
    }

    if ($esAlta || array_key_exists('descripcion', $data)) {
      if (trim((string) ($data['descripcion'] ?? '')) === '') {
        throw new \InvalidArgumentException('La descripcion es obligatoria');
      }
    }
  }

  private function validarFamilia(array $data, ?string $codigo): void
  {
    $esAlta = $codigo === null;

    if ($esAlta) {
      $nuevoCodigo = trim((string) ($data['codigo'] ?? ''));
      if ($nuevoCodigo === '') {
        throw new \InvalidArgumentException('El codigo es obligatorio');
      }
      if (strlen($nuevoCodigo) > 6) {
        throw new \InvalidArgumentException('El codigo admite como maximo 6 caracteres');
      }
      $stmt = $this->pdo->prepare('SELECT 1 FROM [Familias] WHERE RTRIM([Codigo]) = :codigo');
      $stmt->execute(['codigo' => $nuevoCodigo]);
      if ($stmt->fetch()) {
        throw new \InvalidArgumentException('Ya existe una familia con ese codigo');
      }
    }

    if ($esAlta || array_key_exists('descripcion', $data)) {
      if (trim((string) ($data['descripcion'] ?? '')) === '') {
        throw new \InvalidArgumentException('La descripcion es obligatoria');
      }
    }

    if ($esAlta || array_key_exists('macroFamiliaCodigo', $data)) {
      if (trim((string) ($data['macroFamiliaCodigo'] ?? '')) === '') {
        throw new \InvalidArgumentException('La macrofamilia es obligatoria');
      }
    }
  }

  private function validarSubfamilia(array $data, ?string $codigo): void
  {
    $esAlta = $codigo === null;

    if ($esAlta) {
      $nuevoCodigo = trim((string) ($data['codigo'] ?? ''));
      if ($nuevoCodigo === '') {
        throw new \InvalidArgumentException('El codigo es obligatorio');
      }
      if (strlen($nuevoCodigo) > 6) {
        throw new \InvalidArgumentException('El codigo admite como maximo 6 caracteres');
      }
      $stmt = $this->pdo->prepare('SELECT 1 FROM [Subfamilias] WHERE RTRIM([Subfamilia]) = :codigo');
      $stmt->execute(['codigo' => $nuevoCodigo]);
      if ($stmt->fetch()) {
        throw new \InvalidArgumentException('Ya existe una subfamilia con ese codigo');
      }
    }

    if ($esAlta || array_key_exists('descripcion', $data)) {
      if (trim((string) ($data['descripcion'] ?? '')) === '') {
        throw new \InvalidArgumentException('La descripcion es obligatoria');
      }
    }

    if ($esAlta || array_key_exists('familiaCodigo', $data)) {
      if (trim((string) ($data['familiaCodigo'] ?? '')) === '') {
        throw new \InvalidArgumentException('La familia es obligatoria');
      }
    }
  }

  private function assertRegistroExiste(array $config, string $codigo): void
  {
    $codigo = trim($codigo);
    $table = $config['table'];
    $pk = $config['primaryKey'];
    $pkType = $config['primaryKeyType'] ?? 'string';
    $sql = $pkType === 'int'
      ? "SELECT 1 FROM [{$table}] WHERE [{$pk}] = :codigo"
      : "SELECT 1 FROM [{$table}] WHERE RTRIM([{$pk}]) = :codigo";
    $stmt = $this->pdo->prepare($sql);
    $this->bindPrimaryKey($stmt, ':codigo', $codigo, $config);
    $stmt->execute();
    if (!$stmt->fetch()) {
      throw new \InvalidArgumentException('Registro no encontrado');
    }
  }

  private function assertSoftDeleteColumn(string $table, string $column): void
  {
    $sql = sprintf("SELECT COL_LENGTH('dbo.%s', '%s')", $table, $column);
    $length = $this->pdo->query($sql)->fetchColumn();
    if ($length === false || $length === null) {
      throw new \RuntimeException(
        "Falta la columna [{$column}] en [{$table}]. Ejecute db/migrations/001-mantenimiento-extensiones.sql"
      );
    }
  }

  private function bindPrimaryKey(\PDOStatement $stmt, string $param, string $codigo, array $config): void
  {
    if (($config['primaryKeyType'] ?? 'string') === 'int') {
      $stmt->bindValue($param, (int) $codigo, PDO::PARAM_INT);
      return;
    }

    $stmt->bindValue($param, $codigo);
  }

  private function validarTienda(array $data, ?string $codigo): void
  {
    if ($codigo === null) {
      $nuevoCodigo = trim((string) ($data['codigo'] ?? ''));
      if ($nuevoCodigo === '') {
        throw new \InvalidArgumentException('El codigo de tienda es obligatorio');
      }
      if (strlen($nuevoCodigo) > 3) {
        throw new \InvalidArgumentException('El codigo de tienda admite como maximo 3 caracteres');
      }
      $stmt = $this->pdo->prepare(
        'SELECT 1 FROM [Empresas] WHERE RTRIM(LTRIM([Codigo])) = :codigo'
      );
      $stmt->execute(['codigo' => $nuevoCodigo]);
      if ($stmt->fetch()) {
        throw new \InvalidArgumentException('Ya existe una tienda con ese codigo');
      }

      $this->assertTiendaCamposObligatorios($data, true);
      return;
    }

    // Update parcial (p.ej. rejilla solo envia columnas visibles):
    // validar solo los campos presentes en el payload.
    $this->assertTiendaCamposObligatorios($data, false);

    $stmt = $this->pdo->prepare('SELECT [Central], [Baja] FROM [Empresas] WHERE [Codigo] = :codigo');
    $stmt->execute(['codigo' => $codigo]);
    $row = $stmt->fetch();
    if (!$row) {
      return;
    }

    $esCentral = (int) $row['Central'] === 1;
    if ($esCentral && array_key_exists('activo', $data) && $data['activo'] === false) {
      throw new \InvalidArgumentException('No se puede dar de baja la tienda central');
    }

    if ($esCentral && array_key_exists('esCentral', $data) && $data['esCentral'] === false) {
      throw new \InvalidArgumentException('No se puede quitar la marca de tienda central');
    }

    if (array_key_exists('activo', $data) && $data['activo'] === false) {
      $check = $this->dependencyCheckService->puedeDarDeBaja('tiendas', $codigo);
      if (!$check['ok']) {
        throw new DependencyException(
          'No se puede dar de baja el registro porque tiene dependencias activas',
          $check['dependencias']
        );
      }
    }
  }

  /** @param bool $obligatoriosSiFaltan true = alta (todos requeridos); false = solo si vienen en el payload */
  private function assertTiendaCamposObligatorios(array $data, bool $obligatoriosSiFaltan): void
  {
    $debe = static function (string $key) use ($data, $obligatoriosSiFaltan): bool {
      return $obligatoriosSiFaltan || array_key_exists($key, $data);
    };

    if ($debe('almacenCodigo')) {
      $almacen = $data['almacenCodigo'] ?? null;
      if ($almacen === null || $almacen === '' || (int) $almacen <= 0) {
        throw new \InvalidArgumentException('El almacen es obligatorio');
      }
      $this->tiendaAlmacenService->assertAlmacenActivo((int) $almacen);
    }

    if ($debe('divisa') && trim((string) ($data['divisa'] ?? '')) === '') {
      throw new \InvalidArgumentException('La divisa es obligatoria');
    }

    if ($debe('divisaAlt') && trim((string) ($data['divisaAlt'] ?? '')) === '') {
      throw new \InvalidArgumentException('La divisa alternativa es obligatoria');
    }

    if ($debe('tarifa') && (!isset($data['tarifa']) || $data['tarifa'] === '' || $data['tarifa'] === null)) {
      throw new \InvalidArgumentException('La tarifa es obligatoria');
    }

    if ($debe('desglosarBasesTicketIvaInc')) {
      if (!array_key_exists('desglosarBasesTicketIvaInc', $data)) {
        throw new \InvalidArgumentException('Debe marcar Desglosar bases ticket');
      }
      if (empty($data['desglosarBasesTicketIvaInc'])) {
        throw new \InvalidArgumentException('Debe marcar Desglosar bases ticket');
      }
    }
  }

  private function validarTrabajador(array $data, ?string $codigo): void
  {
    $esAlta = $codigo === null;

    if ($esAlta) {
      $nuevoCodigo = trim((string) ($data['codigo'] ?? ''));
      if ($nuevoCodigo === '') {
        throw new \InvalidArgumentException('El codigo es obligatorio');
      }
      $stmt = $this->pdo->prepare(
        'SELECT 1 FROM [Vendedores] WHERE RTRIM([Codigo]) = :codigo'
      );
      $stmt->execute(['codigo' => $nuevoCodigo]);
      if ($stmt->fetch()) {
        throw new \InvalidArgumentException('Ya existe un trabajador con ese codigo');
      }
    }

    $exige = static function (string $key) use ($data, $esAlta): bool {
      return $esAlta || array_key_exists($key, $data);
    };

    if ($exige('nombre') && trim((string) ($data['nombre'] ?? '')) === '') {
      throw new \InvalidArgumentException('La descripcion es obligatoria');
    }

    if ($exige('usuarioCodigo') && trim((string) ($data['usuarioCodigo'] ?? '')) === '') {
      throw new \InvalidArgumentException('El usuario es obligatorio');
    }

    $rolesPresentes = array_key_exists('agente', $data)
      || array_key_exists('vendedor', $data)
      || array_key_exists('operario', $data)
      || array_key_exists('tecnico', $data);
    if ($esAlta || $rolesPresentes) {
      $tieneRol = !empty($data['agente'])
        || !empty($data['vendedor'])
        || !empty($data['operario'])
        || !empty($data['tecnico']);
      if (!$tieneRol) {
        throw new \InvalidArgumentException(
          'Debe marcar al menos un tipo: Agente, Vendedor, Operario o Tecnico'
        );
      }
    }

    if (array_key_exists('password', $data) && trim((string) $data['password']) !== '') {
      if (!preg_match('/^-?\d+$/', trim((string) $data['password']))) {
        throw new \InvalidArgumentException('La contrasena debe ser numerica');
      }
    }
  }

  private function enrichItem(string $entidad, array $item): array
  {
    if ($entidad === 'almacenes' && isset($item['codigo'])) {
      $item['tiendasVinculadas'] = $this->tiendaAlmacenService->listTiendasPorAlmacen((int) $item['codigo']);
    }

    if ($entidad === 'articulos') {
      $item = $this->articuloService->enrich($item);
    }

    return $item;
  }

  private function validarArticulo(array $data, ?string $codigo): void
  {
    $isCreate = $codigo === null;
    if ($isCreate) {
      $nuevoCodigo = trim((string) ($data['codigo'] ?? ''));
      if ($nuevoCodigo === '') {
        throw new \InvalidArgumentException('El codigo de articulo es obligatorio');
      }
      $this->articuloService->assertCodigoUnico($nuevoCodigo);
    }

    $this->assertArticuloCamposObligatorios($data, $isCreate);

    if ($codigo !== null && array_key_exists('activo', $data) && $data['activo'] === false) {
      $check = $this->dependencyCheckService->puedeDarDeBaja('articulos', $codigo);
      if (!$check['ok']) {
        throw new DependencyException(
          'No se puede dar de baja el registro porque tiene dependencias activas',
          $check['dependencias']
        );
      }
    }
  }

  /**
   * Obligatorios de ficha alineados con legacy.
   * macroFamilia no es columna de Articulos: se valida vía Familias.MacroFamilia.
   *
   * @param array<string, mixed> $data
   */
  private function assertArticuloCamposObligatorios(array $data, bool $isCreate): void
  {
    $debe = static function (string $key) use ($data, $isCreate): bool {
      return $isCreate || array_key_exists($key, $data);
    };

    if ($debe('descripcion') && trim((string) ($data['descripcion'] ?? '')) === '') {
      throw new \InvalidArgumentException('La descripcion es obligatoria');
    }
    if ($debe('familia') && trim((string) ($data['familia'] ?? '')) === '') {
      throw new \InvalidArgumentException('La familia es obligatoria');
    }
    if ($debe('subfamilia') && trim((string) ($data['subfamilia'] ?? '')) === '') {
      throw new \InvalidArgumentException('La subfamilia es obligatoria');
    }
    if ($debe('agrupacion') && trim((string) ($data['agrupacion'] ?? '')) === '') {
      throw new \InvalidArgumentException('La agrupacion es obligatoria');
    }
    if ($debe('proveedorHabitual') && trim((string) ($data['proveedorHabitual'] ?? '')) === '') {
      throw new \InvalidArgumentException('El proveedor es obligatorio');
    }
    if ($debe('impuestoCodigo') && trim((string) ($data['impuestoCodigo'] ?? '')) === '') {
      throw new \InvalidArgumentException('El impuesto es obligatorio');
    }

    $familia = trim((string) ($data['familia'] ?? ''));
    $macroPayload = trim((string) ($data['macroFamilia'] ?? ''));
    $debeMacro = $isCreate || array_key_exists('macroFamilia', $data) || array_key_exists('familia', $data);
    if ($debeMacro) {
      $macroDeFamilia = $familia !== '' ? $this->macroFamiliaDeFamilia($familia) : '';
      $macro = $macroPayload !== '' ? $macroPayload : $macroDeFamilia;
      if ($macro === '') {
        throw new \InvalidArgumentException('La macrofamilia es obligatoria');
      }
      if ($familia !== '' && $macroDeFamilia !== '' && strcasecmp($macroDeFamilia, $macro) !== 0) {
        throw new \InvalidArgumentException('La familia no pertenece a la macrofamilia indicada');
      }
      if ($familia !== '' && $macroDeFamilia === '') {
        throw new \InvalidArgumentException('La familia seleccionada no tiene macrofamilia');
      }
    }
  }

  private function macroFamiliaDeFamilia(string $familiaCodigo): string
  {
    $stmt = $this->pdo->prepare(
      'SELECT RTRIM(ISNULL([MacroFamilia], \'\')) FROM [Familias] WHERE RTRIM([Codigo]) = :codigo'
    );
    $stmt->execute(['codigo' => trim($familiaCodigo)]);
    $val = $stmt->fetchColumn();
    return $val === false || $val === null ? '' : trim((string) $val);
  }

  /**
   * Campos de negocio obligatorios (legacy): razon social, NIF, forma de pago.
   * El codigo se valida aparte (puede generarse con GenClientes).
   */
  private function validarClienteDatosObligatorios(array $data, bool $esAlta): void
  {
    if ($esAlta || array_key_exists('nombre', $data)) {
      if (trim((string) ($data['nombre'] ?? '')) === '') {
        throw new \InvalidArgumentException('La razon social es obligatoria');
      }
    }

    if ($esAlta || array_key_exists('nif', $data)) {
      if (trim((string) ($data['nif'] ?? '')) === '') {
        throw new \InvalidArgumentException('El NIF es obligatorio');
      }
    }

    if ($esAlta || array_key_exists('formaPago', $data)) {
      if (trim((string) ($data['formaPago'] ?? '')) === '') {
        throw new \InvalidArgumentException('Debe asignar una forma de pago al cliente');
      }
    }
  }

  private function validarCliente(array $data, ?string $codigo): void
  {
    $codigoActual = trim((string) ($codigo ?? ''));
    if ($codigoActual === '') {
      $codigoActual = trim((string) ($data['codigo'] ?? ''));
    }

    $esAlta = $codigo === null;

    if ($esAlta) {
      if ($codigoActual === '') {
        throw new \InvalidArgumentException('El codigo de cliente es obligatorio');
      }
      $stmt = $this->pdo->prepare(
        'SELECT 1 FROM [Clientes] WHERE RTRIM([Codigo]) = :codigo'
      );
      $stmt->execute(['codigo' => $codigoActual]);
      if ($stmt->fetch()) {
        throw new \InvalidArgumentException('Ya existe un cliente con ese codigo');
      }
    }

    $this->validarClienteDatosObligatorios($data, $esAlta);

    $nif = trim((string) ($data['nif'] ?? ''));
    if ($nif === '') {
      return;
    }

    $duplicado = $this->buscarClienteActivoPorNif($nif, $codigoActual);
    if ($duplicado !== null) {
      throw new \InvalidArgumentException('Ya existe un cliente activo con ese NIF');
    }
  }

  /**
   * Busca otro cliente activo con el mismo NIF (excluye $excluirCodigo).
   *
   * @return array{codigo: string}|null
   */
  public function buscarClienteActivoPorNif(string $nif, string $excluirCodigo = ''): ?array
  {
    $nif = trim($nif);
    if ($nif === '') {
      return null;
    }

    $excluir = trim($excluirCodigo);
    $stmt = $this->pdo->prepare(
      'SELECT RTRIM([Codigo]) AS Codigo
       FROM [Clientes]
       WHERE RTRIM(ISNULL([NIF], \'\')) = :nif
         AND ISNULL([Baja], 0) = 0'
    );
    $stmt->execute(['nif' => $nif]);
    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
      $encontrado = trim((string) ($row['Codigo'] ?? ''));
      if ($excluir !== '' && strcasecmp($encontrado, $excluir) === 0) {
        continue;
      }
      return ['codigo' => $encontrado];
    }

    return null;
  }

  private function isBooleanField(array $config, string $apiField): bool
  {
    if (in_array($apiField, $config['booleanFields'] ?? [], true)) {
      return true;
    }

    return in_array($apiField, ['esCentral', 'externo', 'central', 'esVendedor', 'esOperario', 'agente', 'vendedor', 'operario', 'tecnico', 'facturaLaCentral'], true);
  }

  private function isIntField(array $config, string $apiField): bool
  {
    return in_array($apiField, $config['intFields'] ?? [], true);
  }

  private function isTimeField(array $config, string $apiField): bool
  {
    return in_array($apiField, $config['timeFields'] ?? [], true);
  }

  private function isDateField(array $config, string $apiField): bool
  {
    return in_array($apiField, $config['dateFields'] ?? [], true);
  }

  private function isDateSqlColumn(string $sqlColumn): bool
  {
    static $datetimeColumns = [
      'FechaAlta',
      'UltimaCompra',
      'LUpdate',
      'FechaNacimiento',
      'FechaTx3',
      'FechaEntradaAdonix',
      'UltimaSubvencion',
      'FechaAltaFidelizacion',
      'FechaFirmaMandato',
      'FechaCaducidadCarnet',
      'FechaBaja',
      'HoraInicio',
      'HoraFinal',
      'HoraInicio2',
      'HoraFinal2',
    ];
    return in_array($sqlColumn, $datetimeColumns, true);
  }

  /**
   * Extrae HH:MM desde datetime / string para inputs type=time.
   */
  private function normalizeTimeValue(mixed $value): ?string
  {
    if ($value === null || $value === '') {
      return null;
    }
    if ($value instanceof \DateTimeInterface) {
      return $value->format('H:i');
    }

    $s = trim((string) $value);
    if ($s === '' || strcasecmp($s, 'null') === 0) {
      return null;
    }

    if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $s, $m)) {
      return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
    }

    $normalized = $this->normalizeDateValue($s);
    if ($normalized !== null && preg_match('/\s(\d{2}):(\d{2}):\d{2}$/', $normalized, $m)) {
      return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
    }

    $ts = strtotime($s);
    if ($ts !== false) {
      return date('H:i', $ts);
    }

    return null;
  }

  /**
   * Convierte HH:MM (o HH:MM:SS) a datetime SQL con fecha base 1900-01-01.
   */
  private function timeToSqlDateTime(mixed $value): ?string
  {
    $time = $this->normalizeTimeValue($value);
    if ($time === null) {
      return null;
    }

    return '1900-01-01 ' . $time . ':00';
  }

  /**
   * Style 120 = yyyy-mm-dd hh:mi:ss (sin ms), independiente del idioma de SQL Server.
   */
  private function sqlValueExpression(string $sqlColumn, string $paramName): string
  {
    if ($this->isDateSqlColumn($sqlColumn)) {
      return "CONVERT(datetime, :{$paramName}, 120)";
    }
    return ':' . $paramName;
  }

  /**
   * Normaliza fechas API a Y-m-d H:i:s (sin milisegundos) o null.
   * SQL Server datetime no admite bien literales nvarchar con .000 via ODBC.
   */
  private function normalizeDateValue(mixed $value): ?string
  {
    if ($value === null || $value === '') {
      return null;
    }
    if ($value instanceof \DateTimeInterface) {
      $year = (int) $value->format('Y');
      return $year >= 1753 && $year <= 9999 ? $value->format('Y-m-d H:i:s') : null;
    }

    $s = trim((string) $value);
    if ($s === '' || strcasecmp($s, 'null') === 0 || str_starts_with($s, '0000-00-00')) {
      return null;
    }

    // Quitar zona y milisegundos: 2026-01-26T18:25:05.000Z -> 2026-01-26 18:25:05
    $s = str_replace('T', ' ', $s);
    $s = preg_replace('/(Z|[+-]\d{2}:?\d{2})$/', '', $s) ?? $s;
    $s = preg_replace('/\.\d+$/', '', trim($s)) ?? $s;

    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:\s+(\d{2}):(\d{2}):(\d{2}))?$/', $s, $m)) {
      $year = (int) $m[1];
      $month = (int) $m[2];
      $day = (int) $m[3];
      if ($year < 1753 || $year > 9999 || !checkdate($month, $day, $year)) {
        return null;
      }
      $h = isset($m[4]) ? (int) $m[4] : 0;
      $i = isset($m[5]) ? (int) $m[5] : 0;
      $sec = isset($m[6]) ? (int) $m[6] : 0;
      return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $h, $i, $sec);
    }

    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})$/', $s, $m)) {
      $year = strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3];
      $month = (int) $m[2];
      $day = (int) $m[1];
      if ($year < 1753 || $year > 9999 || !checkdate($month, $day, $year)) {
        return null;
      }
      return sprintf('%04d-%02d-%02d 00:00:00', $year, $month, $day);
    }

    $ts = strtotime($s);
    if ($ts !== false) {
      $year = (int) date('Y', $ts);
      if ($year >= 1753 && $year <= 9999) {
        return date('Y-m-d H:i:s', $ts);
      }
    }

    return null;
  }

  private function isActivo(array $config, mixed $value): bool
  {
    $soft = $config['softDelete'] ?? null;
    if ($soft === null) {
      return true;
    }
    if (($soft['inactiveValue'] ?? null) === 'NOT_NULL') {
      return $value === null || $value === '';
    }
    return (int) $value === (int) ($soft['activeValue'] ?? 0);
  }

  private function activoToSql(array $config, bool $activo): mixed
  {
    $soft = $config['softDelete'];
    if (($soft['inactiveValue'] ?? null) === 'NOT_NULL') {
      return $activo ? null : date('Y-m-d H:i:s');
    }
    return $activo ? ($soft['activeValue'] ?? 0) : ($soft['inactiveValue'] ?? 1);
  }

  /**
   * Preview del siguiente codigo de proveedor (no incrementa UltProveedor).
   *
   * @return array{automatico: bool, codigo: ?string, empresaCodigo: ?string, ultProveedor: ?int}
   */
  public function siguienteCodigoProveedor(string $empresaCodigo = ''): array
  {
    $empresa = $this->resolverEmpresaNumeracionProveedor($empresaCodigo);
    if ($empresa === null) {
      return [
        'automatico' => false,
        'codigo' => null,
        'empresaCodigo' => null,
        'ultProveedor' => null,
      ];
    }

    if (!$empresa['genProveedores']) {
      return [
        'automatico' => false,
        'codigo' => null,
        'empresaCodigo' => $empresa['codigo'],
        'ultProveedor' => $empresa['ultProveedor'],
      ];
    }

    $candidato = $empresa['ultProveedor'] + 1;
    for ($i = 0; $i < 100; $i++) {
      $codigo = $this->formatearCodigoProveedor($candidato);
      if (!$this->existeCodigoProveedor($codigo)) {
        return [
          'automatico' => true,
          'codigo' => $codigo,
          'empresaCodigo' => $empresa['codigo'],
          'ultProveedor' => $empresa['ultProveedor'],
        ];
      }
      $candidato++;
    }

    return [
      'automatico' => true,
      'codigo' => $this->formatearCodigoProveedor($empresa['ultProveedor'] + 1),
      'empresaCodigo' => $empresa['codigo'],
      'ultProveedor' => $empresa['ultProveedor'],
    ];
  }

  /** @param array<string, mixed> $data */
  private function asignarCodigoProveedorSiCorresponde(array &$data, string $empresaCodigo): void
  {
    $empresa = $this->resolverEmpresaNumeracionProveedor($empresaCodigo);
    if ($empresa === null || !$empresa['genProveedores']) {
      return;
    }

    for ($i = 0; $i < 100; $i++) {
      $nuevo = $this->reservarSiguienteCodigoProveedor($empresa['codigo']);
      $codigo = $this->formatearCodigoProveedor($nuevo);
      if (!$this->existeCodigoProveedor($codigo)) {
        $data['codigo'] = $codigo;
        return;
      }
    }

    throw new \InvalidArgumentException(
      'No se pudo generar un codigo de proveedor libre (revise UltProveedor en Empresas)'
    );
  }

  private function existeCodigoProveedor(string $codigo): bool
  {
    $stmt = $this->pdo->prepare(
      'SELECT 1 FROM [Proveedores] WHERE RTRIM([Codigo]) = :codigo'
    );
    $stmt->execute(['codigo' => trim($codigo)]);
    return (bool) $stmt->fetchColumn();
  }

  /**
   * @return array{codigo: string, genProveedores: bool, ultProveedor: int}|null
   */
  private function resolverEmpresaNumeracionProveedor(string $empresaCodigo): ?array
  {
    $codigo = trim($empresaCodigo);
    if ($codigo !== '') {
      $row = $this->leerEmpresaNumeracion($codigo);
      if ($row !== null) {
        return $row;
      }
    }

    $stmt = $this->pdo->query(
      "SELECT TOP 1 RTRIM([Codigo]) AS Codigo
       FROM [Empresas]
       WHERE ISNULL([Central], 0) = 1
       ORDER BY [Codigo]"
    );
    $central = $stmt ? $stmt->fetchColumn() : false;
    if ($central) {
      $row = $this->leerEmpresaNumeracion((string) $central);
      if ($row !== null) {
        return $row;
      }
    }

    $stmt = $this->pdo->query(
      "SELECT TOP 1 RTRIM([Codigo]) AS Codigo
       FROM [Empresas]
       WHERE ISNULL([GenProveedores], 0) = 1
       ORDER BY [Codigo]"
    );
    $any = $stmt ? $stmt->fetchColumn() : false;
    return $any ? $this->leerEmpresaNumeracion((string) $any) : null;
  }

  /**
   * @return array{codigo: string, genProveedores: bool, ultProveedor: int}|null
   */
  private function leerEmpresaNumeracion(string $codigo): ?array
  {
    $stmt = $this->pdo->prepare(
      'SELECT RTRIM([Codigo]) AS Codigo,
              CAST(ISNULL([GenProveedores], 0) AS int) AS GenProveedores,
              CAST(ISNULL([UltProveedor], 0) AS int) AS UltProveedor
       FROM [Empresas]
       WHERE RTRIM([Codigo]) = :codigo'
    );
    $stmt->execute(['codigo' => trim($codigo)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
      return null;
    }

    return [
      'codigo' => (string) $row['Codigo'],
      'genProveedores' => ((int) $row['GenProveedores']) === 1,
      'ultProveedor' => (int) $row['UltProveedor'],
    ];
  }

  private function reservarSiguienteCodigoProveedor(string $empresaCodigo): int
  {
    $this->pdo->beginTransaction();
    try {
      // Empresas tiene triggers: no usar OUTPUT sin INTO.
      $stmt = $this->pdo->prepare(
        'SELECT CAST(ISNULL([UltProveedor], 0) AS int)
         FROM [Empresas] WITH (UPDLOCK, ROWLOCK)
         WHERE RTRIM([Codigo]) = :codigo AND ISNULL([GenProveedores], 0) = 1'
      );
      $stmt->execute(['codigo' => trim($empresaCodigo)]);
      $actual = $stmt->fetchColumn();
      if ($actual === false || $actual === null) {
        throw new \InvalidArgumentException(
          'No se pudo generar el codigo de proveedor (GenProveedores inactivo o empresa no encontrada)'
        );
      }

      $nuevoInt = (int) $actual + 1;
      if ($nuevoInt <= 0 || $nuevoInt > 999999) {
        throw new \InvalidArgumentException('El siguiente codigo de proveedor no es valido');
      }

      $upd = $this->pdo->prepare(
        'UPDATE [Empresas]
         SET [UltProveedor] = :nuevo
         WHERE RTRIM([Codigo]) = :codigo AND ISNULL([GenProveedores], 0) = 1'
      );
      $upd->execute([
        'nuevo' => $nuevoInt,
        'codigo' => trim($empresaCodigo),
      ]);
      if ($upd->rowCount() === 0) {
        // SQL Server puede reportar 0; verificar valor final.
        $check = $this->pdo->prepare(
          'SELECT CAST(ISNULL([UltProveedor], 0) AS int)
           FROM [Empresas]
           WHERE RTRIM([Codigo]) = :codigo'
        );
        $check->execute(['codigo' => trim($empresaCodigo)]);
        $leido = (int) $check->fetchColumn();
        if ($leido !== $nuevoInt) {
          throw new \InvalidArgumentException(
            'No se pudo generar el codigo de proveedor (GenProveedores inactivo o empresa no encontrada)'
          );
        }
      }

      $this->pdo->commit();
      return $nuevoInt;
    } catch (\Throwable $e) {
      if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      throw $e;
    }
  }

  private function formatearCodigoProveedor(int $numero): string
  {
    return (string) $numero;
  }

  /**
   * Preview del siguiente codigo de cliente (Prefijo + UltCliente+1).
   * Legacy: Prefijo a 3 digitos + secuencia a 6 (ej. Prefijo=1, Ult=12099 → 001012100).
   *
   * @return array{automatico: bool, codigo: ?string, empresaCodigo: ?string, prefijo: ?int, ultCliente: ?int}
   */
  public function siguienteCodigoCliente(string $empresaCodigo = ''): array
  {
    $empresa = $this->resolverEmpresaNumeracionCliente($empresaCodigo);
    if ($empresa === null) {
      return [
        'automatico' => false,
        'codigo' => null,
        'empresaCodigo' => null,
        'prefijo' => null,
        'ultCliente' => null,
      ];
    }

    if (!$empresa['genClientes']) {
      return [
        'automatico' => false,
        'codigo' => null,
        'empresaCodigo' => $empresa['codigo'],
        'prefijo' => $empresa['prefijo'],
        'ultCliente' => $empresa['ultCliente'],
      ];
    }

    $candidato = $empresa['ultCliente'] + 1;
    for ($i = 0; $i < 100; $i++) {
      $codigo = $this->formatearCodigoCliente($empresa['prefijo'], $candidato);
      if (!$this->existeCodigoCliente($codigo)) {
        return [
          'automatico' => true,
          'codigo' => $codigo,
          'empresaCodigo' => $empresa['codigo'],
          'prefijo' => $empresa['prefijo'],
          'ultCliente' => $empresa['ultCliente'],
        ];
      }
      $candidato++;
    }

    return [
      'automatico' => true,
      'codigo' => $this->formatearCodigoCliente($empresa['prefijo'], $empresa['ultCliente'] + 1),
      'empresaCodigo' => $empresa['codigo'],
      'prefijo' => $empresa['prefijo'],
      'ultCliente' => $empresa['ultCliente'],
    ];
  }

  /** @param array<string, mixed> $data */
  private function asignarCodigoClienteSiCorresponde(array &$data, string $empresaCodigo): void
  {
    $empresa = $this->resolverEmpresaNumeracionCliente($empresaCodigo);
    if ($empresa === null || !$empresa['genClientes']) {
      return;
    }

    for ($i = 0; $i < 100; $i++) {
      $nuevo = $this->reservarSiguienteCodigoCliente($empresa['codigo']);
      $codigo = $this->formatearCodigoCliente($empresa['prefijo'], $nuevo);
      if (!$this->existeCodigoCliente($codigo)) {
        $data['codigo'] = $codigo;
        return;
      }
    }

    throw new \InvalidArgumentException(
      'No se pudo generar un codigo de cliente libre (revise Prefijo / UltCliente / GenClientes en Empresas)'
    );
  }

  private function existeCodigoCliente(string $codigo): bool
  {
    $stmt = $this->pdo->prepare(
      'SELECT 1 FROM [Clientes] WHERE RTRIM([Codigo]) = :codigo'
    );
    $stmt->execute(['codigo' => trim($codigo)]);
    return (bool) $stmt->fetchColumn();
  }

  /**
   * @return array{codigo: string, genClientes: bool, prefijo: int, ultCliente: int}|null
   */
  private function resolverEmpresaNumeracionCliente(string $empresaCodigo): ?array
  {
    $codigo = trim($empresaCodigo);
    if ($codigo !== '') {
      $row = $this->leerEmpresaNumeracionCliente($codigo);
      if ($row !== null) {
        return $row;
      }
    }

    $stmt = $this->pdo->query(
      "SELECT TOP 1 RTRIM([Codigo]) AS Codigo
       FROM [Empresas]
       WHERE ISNULL([Central], 0) = 1
       ORDER BY [Codigo]"
    );
    $central = $stmt ? $stmt->fetchColumn() : false;
    if ($central) {
      $row = $this->leerEmpresaNumeracionCliente((string) $central);
      if ($row !== null) {
        return $row;
      }
    }

    $stmt = $this->pdo->query(
      "SELECT TOP 1 RTRIM([Codigo]) AS Codigo
       FROM [Empresas]
       WHERE ISNULL([GenClientes], 0) = 1
       ORDER BY [Codigo]"
    );
    $any = $stmt ? $stmt->fetchColumn() : false;
    return $any ? $this->leerEmpresaNumeracionCliente((string) $any) : null;
  }

  /**
   * @return array{codigo: string, genClientes: bool, prefijo: int, ultCliente: int}|null
   */
  private function leerEmpresaNumeracionCliente(string $codigo): ?array
  {
    $stmt = $this->pdo->prepare(
      'SELECT RTRIM([Codigo]) AS Codigo,
              CAST(ISNULL([GenClientes], 0) AS int) AS GenClientes,
              CAST(ISNULL([Prefijo], 0) AS int) AS Prefijo,
              CAST(ISNULL([UltCliente], 0) AS int) AS UltCliente
       FROM [Empresas]
       WHERE RTRIM([Codigo]) = :codigo'
    );
    $stmt->execute(['codigo' => trim($codigo)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
      return null;
    }

    return [
      'codigo' => (string) $row['Codigo'],
      'genClientes' => ((int) $row['GenClientes']) === 1,
      'prefijo' => (int) $row['Prefijo'],
      'ultCliente' => (int) $row['UltCliente'],
    ];
  }

  private function reservarSiguienteCodigoCliente(string $empresaCodigo): int
  {
    $this->pdo->beginTransaction();
    try {
      $stmt = $this->pdo->prepare(
        'SELECT CAST(ISNULL([UltCliente], 0) AS int)
         FROM [Empresas] WITH (UPDLOCK, ROWLOCK)
         WHERE RTRIM([Codigo]) = :codigo AND ISNULL([GenClientes], 0) = 1'
      );
      $stmt->execute(['codigo' => trim($empresaCodigo)]);
      $actual = $stmt->fetchColumn();
      if ($actual === false || $actual === null) {
        throw new \InvalidArgumentException(
          'No se pudo generar el codigo de cliente (GenClientes inactivo o empresa no encontrada)'
        );
      }

      $nuevoInt = (int) $actual + 1;
      if ($nuevoInt <= 0 || $nuevoInt > 999999) {
        throw new \InvalidArgumentException('El siguiente codigo de cliente no es valido');
      }

      $upd = $this->pdo->prepare(
        'UPDATE [Empresas]
         SET [UltCliente] = :nuevo
         WHERE RTRIM([Codigo]) = :codigo AND ISNULL([GenClientes], 0) = 1'
      );
      $upd->execute([
        'nuevo' => $nuevoInt,
        'codigo' => trim($empresaCodigo),
      ]);
      if ($upd->rowCount() === 0) {
        $check = $this->pdo->prepare(
          'SELECT CAST(ISNULL([UltCliente], 0) AS int)
           FROM [Empresas]
           WHERE RTRIM([Codigo]) = :codigo'
        );
        $check->execute(['codigo' => trim($empresaCodigo)]);
        $leido = (int) $check->fetchColumn();
        if ($leido !== $nuevoInt) {
          throw new \InvalidArgumentException(
            'No se pudo generar el codigo de cliente (GenClientes inactivo o empresa no encontrada)'
          );
        }
      }

      $this->pdo->commit();
      return $nuevoInt;
    } catch (\Throwable $e) {
      if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      throw $e;
    }
  }

  private function formatearCodigoCliente(int $prefijo, int $secuencia): string
  {
    // Legacy: Prefijo (3) + secuencia (6) = 9 caracteres max de Clientes.Codigo.
    return sprintf('%03d%06d', max(0, $prefijo), max(0, $secuencia));
  }

  /**
   * Preview del siguiente codigo de articulo (si Empresas.GenArticulos=1).
   * Sin UltArticulo en schema: se calcula como MAX(codigos numericos)+1, con ceros a la izquierda.
   *
   * @return array{automatico: bool, codigo: ?string, empresaCodigo: ?string, ancho: ?int}
   */
  public function siguienteCodigoArticulo(string $empresaCodigo = ''): array
  {
    $empresa = $this->resolverEmpresaNumeracionArticulo($empresaCodigo);
    if ($empresa === null) {
      return [
        'automatico' => false,
        'codigo' => null,
        'empresaCodigo' => null,
        'ancho' => null,
      ];
    }

    if (!$empresa['genArticulos']) {
      return [
        'automatico' => false,
        'codigo' => null,
        'empresaCodigo' => $empresa['codigo'],
        'ancho' => null,
      ];
    }

    $info = $this->leerMaxCodigoArticuloNumerico();
    $candidato = $info['maxNum'] + 1;
    $ancho = $info['ancho'];
    for ($i = 0; $i < 100; $i++) {
      $codigo = $this->formatearCodigoArticulo($candidato, $ancho);
      if (!$this->existeCodigoArticulo($codigo)) {
        return [
          'automatico' => true,
          'codigo' => $codigo,
          'empresaCodigo' => $empresa['codigo'],
          'ancho' => $ancho,
        ];
      }
      $candidato++;
    }

    return [
      'automatico' => true,
      'codigo' => $this->formatearCodigoArticulo($info['maxNum'] + 1, $ancho),
      'empresaCodigo' => $empresa['codigo'],
      'ancho' => $ancho,
    ];
  }

  /** @param array<string, mixed> $data */
  private function asignarCodigoArticuloSiCorresponde(array &$data): void
  {
    $empresa = $this->resolverEmpresaNumeracionArticulo('');
    if ($empresa === null || !$empresa['genArticulos']) {
      return;
    }

    // Con GenArticulos activo, legacy asigna el codigo (como GenProveedores).
    $info = $this->leerMaxCodigoArticuloNumerico();
    $candidato = $info['maxNum'] + 1;
    $ancho = $info['ancho'];
    for ($i = 0; $i < 100; $i++) {
      $codigo = $this->formatearCodigoArticulo($candidato, $ancho);
      if (!$this->existeCodigoArticulo($codigo)) {
        $data['codigo'] = $codigo;
        return;
      }
      $candidato++;
    }

    throw new \InvalidArgumentException(
      'No se pudo generar un codigo de articulo libre (revise GenArticulos / codigos existentes)'
    );
  }

  private function existeCodigoArticulo(string $codigo): bool
  {
    $stmt = $this->pdo->prepare(
      'SELECT 1 FROM [Articulos] WHERE RTRIM([Codigo]) = :codigo'
    );
    $stmt->execute(['codigo' => trim($codigo)]);
    return (bool) $stmt->fetchColumn();
  }

  /**
   * @return array{codigo: string, genArticulos: bool}|null
   */
  private function resolverEmpresaNumeracionArticulo(string $empresaCodigo): ?array
  {
    $codigo = trim($empresaCodigo);
    if ($codigo !== '') {
      $row = $this->leerEmpresaNumeracionArticulo($codigo);
      if ($row !== null) {
        return $row;
      }
    }

    $stmt = $this->pdo->query(
      "SELECT TOP 1 RTRIM([Codigo]) AS Codigo
       FROM [Empresas]
       WHERE ISNULL([Central], 0) = 1
       ORDER BY [Codigo]"
    );
    $central = $stmt ? $stmt->fetchColumn() : false;
    if ($central) {
      $row = $this->leerEmpresaNumeracionArticulo((string) $central);
      if ($row !== null) {
        return $row;
      }
    }

    $stmt = $this->pdo->query(
      "SELECT TOP 1 RTRIM([Codigo]) AS Codigo
       FROM [Empresas]
       WHERE ISNULL([GenArticulos], 0) = 1
       ORDER BY [Codigo]"
    );
    $any = $stmt ? $stmt->fetchColumn() : false;
    return $any ? $this->leerEmpresaNumeracionArticulo((string) $any) : null;
  }

  /**
   * @return array{codigo: string, genArticulos: bool}|null
   */
  private function leerEmpresaNumeracionArticulo(string $codigo): ?array
  {
    $stmt = $this->pdo->prepare(
      'SELECT RTRIM([Codigo]) AS Codigo,
              CAST(ISNULL([GenArticulos], 0) AS int) AS GenArticulos
       FROM [Empresas]
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
    ];
  }

  /**
   * @return array{maxNum: int, ancho: int}
   */
  private function leerMaxCodigoArticuloNumerico(): array
  {
    $stmt = $this->pdo->query(
      "SELECT
         MAX(CONVERT(decimal(18,0), RTRIM([Codigo]))) AS MaxNum,
         MAX(LEN(RTRIM([Codigo]))) AS MaxLen
       FROM [Articulos]
       WHERE RTRIM([Codigo]) NOT LIKE '%[^0-9]%'
         AND LEN(RTRIM([Codigo])) BETWEEN 1 AND 18
         AND ISNUMERIC(RTRIM([Codigo])) = 1"
    );
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    $maxNum = $row && $row['MaxNum'] !== null ? (int) $row['MaxNum'] : 0;
    $ancho = $row && $row['MaxLen'] !== null ? (int) $row['MaxLen'] : 10;
    if ($ancho < 1) {
      $ancho = 10;
    }
    if ($ancho > 18) {
      $ancho = 18;
    }
    // Si el siguiente numero supera el ancho actual, ampliar (sin pasar de 18).
    $siguienteLen = strlen((string) ($maxNum + 1));
    if ($siguienteLen > $ancho) {
      $ancho = min(18, $siguienteLen);
    }

    return ['maxNum' => $maxNum, 'ancho' => $ancho];
  }

  private function formatearCodigoArticulo(int $numero, int $ancho): string
  {
    if ($numero < 0) {
      throw new \InvalidArgumentException('Codigo de articulo invalido');
    }
    $s = (string) $numero;
    if (strlen($s) > 18) {
      throw new \InvalidArgumentException('Codigo de articulo fuera de rango');
    }
    $pad = max($ancho, strlen($s));
    if ($pad > 18) {
      $pad = 18;
    }
    return str_pad($s, $pad, '0', STR_PAD_LEFT);
  }
}

final class DependencyException extends \RuntimeException
{
  private array $dependencias;

  public function __construct(string $message, array $dependencias)
  {
    parent::__construct($message);
    $this->dependencias = $dependencias;
  }

  public function getDependencias(): array
  {
    return $this->dependencias;
  }
}
