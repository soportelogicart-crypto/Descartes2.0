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
    $config = EntityConfig::assertExists($entidad);
    if ($entidad === 'usuarios') {
      $this->validarUsuario($data, true);
    }
    if ($entidad === 'tiendas') {
      $this->validarTienda($data, null);
    }
    if ($entidad === 'articulos') {
      $this->validarArticulo($data, null);
    }
    if ($entidad === 'clientes') {
      $this->validarCliente($data, null);
    }
    if ($entidad === 'trabajadores') {
      $this->validarTrabajador($data, null);
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
      $value = $data[$apiField];
      if ($apiField === 'activo') {
        $mapped[$sqlColumn] = $this->activoToSql($config, (bool) $value);
        continue;
      }
      if ($this->isBooleanField($config, $apiField)) {
        $mapped[$sqlColumn] = $value ? 1 : 0;
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

    if ($isCreate && isset($config['softDelete']) && !isset($data['activo'])) {
      $soft = $config['softDelete'];
      if (($soft['inactiveValue'] ?? null) !== 'NOT_NULL') {
        $mapped[$soft['column']] = $soft['activeValue'] ?? 0;
      }
    }

    if ($isCreate && isset($config['createDefaults']) && is_array($config['createDefaults'])) {
      foreach ($config['createDefaults'] as $sqlColumn => $defaultValue) {
        if (!array_key_exists($sqlColumn, $mapped)) {
          $mapped[$sqlColumn] = $defaultValue;
        }
      }
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
    if (in_array('LUpdate', $config['fields'], true)) {
      $mapped['LUpdate'] = date('Y-m-d H:i:s');
    }

    return $mapped;
  }

  private function validarUsuario(array $data, bool $isCreate): void
  {
    if ($isCreate && trim((string) ($data['password'] ?? '')) === '') {
      throw new \InvalidArgumentException('La contrasena es obligatoria al crear un usuario');
    }
    if (isset($data['rolCodigo']) && trim((string) $data['rolCodigo']) === '') {
      throw new \InvalidArgumentException('El rol es obligatorio');
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
      $stmt = $this->pdo->prepare('SELECT 1 FROM [Empresas] WHERE RTRIM([Codigo]) = :codigo');
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

    if ($debe('desglosarBasesTicketIvaInc') && !array_key_exists('desglosarBasesTicketIvaInc', $data)) {
      throw new \InvalidArgumentException('El campo desglosar bases ticket IVA inc. es obligatorio');
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
      throw new \InvalidArgumentException('El nombre es obligatorio');
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
    if ($codigo === null) {
      $nuevoCodigo = trim((string) ($data['codigo'] ?? ''));
      if ($nuevoCodigo === '') {
        throw new \InvalidArgumentException('El codigo de articulo es obligatorio');
      }
      $this->articuloService->assertCodigoUnico($nuevoCodigo);
    }

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

  private function validarCliente(array $data, ?string $codigo): void
  {
    $codigoActual = trim((string) ($codigo ?? ''));
    if ($codigoActual === '') {
      $codigoActual = trim((string) ($data['codigo'] ?? ''));
    }

    if ($codigo === null) {
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

    $nif = trim((string) ($data['nif'] ?? ''));
    if ($nif === '') {
      return;
    }

    // Comparacion en PHP: excluir el propio codigo (RTRIM/PDO a veces no excluye bien en SQL).
    $stmt = $this->pdo->prepare(
      'SELECT RTRIM([Codigo]) AS Codigo
       FROM [Clientes]
       WHERE RTRIM(ISNULL([NIF], \'\')) = :nif
         AND ISNULL([Baja], 0) = 0'
    );
    $stmt->execute(['nif' => $nif]);
    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
      $encontrado = trim((string) ($row['Codigo'] ?? ''));
      if ($codigoActual !== '' && strcasecmp($encontrado, $codigoActual) === 0) {
        continue;
      }
      throw new \InvalidArgumentException('Ya existe un cliente activo con ese NIF');
    }
  }

  private function isBooleanField(array $config, string $apiField): bool
  {
    if (in_array($apiField, $config['booleanFields'] ?? [], true)) {
      return true;
    }

    return in_array($apiField, ['esCentral', 'externo', 'central', 'esVendedor', 'esOperario', 'agente', 'vendedor', 'operario', 'tecnico', 'facturaLaCentral'], true);
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
