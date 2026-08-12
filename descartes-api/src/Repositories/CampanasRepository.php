<?php

declare(strict_types=1);

namespace Descartes\Api\Repositories;

use PDO;

final class CampanasRepository
{
  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  /**
   * @return array{items: list<array<string, mixed>>, total: int, page: int, pageSize: int}
   */
  public function list(array $query): array
  {
    $empresa = $this->str($query['empresa'] ?? null, 3);
    if ($empresa === null) {
      throw new \InvalidArgumentException('Empresa es obligatoria');
    }

    $page = max(1, (int) ($query['page'] ?? 1));
    $pageSize = min(500, max(1, (int) ($query['pageSize'] ?? 100)));
    $offset = ($page - 1) * $pageSize;

    $where = ['RTRIM(c.[Empresa]) = :empresa'];
    $params = ['empresa' => $empresa];

    if (isset($query['q']) && trim((string) $query['q']) !== '') {
      $where[] = '(c.[Descripcion] LIKE :q OR c.[Codigo] LIKE :q OR CAST(c.[Campaña] AS varchar(20)) LIKE :q)';
      $params['q'] = '%' . trim((string) $query['q']) . '%';
    }
    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $countSql = "SELECT COUNT(*) FROM [CampañasCab] c {$whereSql}";
    $countStmt = $this->pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $sql = "SELECT c.[Empresa], c.[Campaña] AS Campana, c.[Descripcion], c.[Fecha], c.[Observaciones],
      c.[TrasModem], c.[FechaFinalizacion], c.[Tipo], c.[Codigo], c.[TipoCampaña] AS TipoCampana,
      c.[ValeMultiple], c.[ImporteVale], c.[ImporteMinimo],
      c.[LiteralAviso1], c.[LiteralAviso2], c.[LiteralAviso3], c.[LiteralAviso4],
      c.[LiteralVale1], c.[LiteralVale2], c.[LiteralVale3], c.[LiteralVale4],
      c.[LUpdate], c.[DiasValidez], c.[FiltroWhere], c.[AplicarClienteVarios],
      c.[TipoAviso], c.[AvisoMultiple],
      c.[LiteralAviso1G], c.[LiteralAviso2G], c.[LiteralVale1G], c.[LiteralVale2G],
      c.[TipoLiquidacion], c.[CodigoLiquidacion], c.[ImporteLiquidacion], c.[TipoImporte],
      c.[FechaCaducidadVale], c.[FechaInicioCaducidadVale],
      c.[PorcentajeSobreCompra], c.[DiaSinIva]
      FROM [CampañasCab] c
      {$whereSql}
      ORDER BY c.[Campaña] DESC
      OFFSET {$offset} ROWS FETCH NEXT {$pageSize} ROWS ONLY";

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);
    $items = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $items[] = $this->mapCab($row);
    }

    return [
      'items' => $items,
      'total' => $total,
      'page' => $page,
      'pageSize' => $pageSize,
    ];
  }

  public function findOne(string $empresa, int $campana): ?array
  {
    $empresa = trim($empresa);
    $stmt = $this->pdo->prepare(
      'SELECT c.[Empresa], c.[Campaña] AS Campana, c.[Descripcion], c.[Fecha], c.[Observaciones],
        c.[TrasModem], c.[FechaFinalizacion], c.[Tipo], c.[Codigo], c.[TipoCampaña] AS TipoCampana,
        c.[ValeMultiple], c.[ImporteVale], c.[ImporteMinimo],
        c.[LiteralAviso1], c.[LiteralAviso2], c.[LiteralAviso3], c.[LiteralAviso4],
        c.[LiteralVale1], c.[LiteralVale2], c.[LiteralVale3], c.[LiteralVale4],
        c.[LUpdate], c.[DiasValidez], c.[FiltroWhere], c.[AplicarClienteVarios],
        c.[TipoAviso], c.[AvisoMultiple],
        c.[LiteralAviso1G], c.[LiteralAviso2G], c.[LiteralVale1G], c.[LiteralVale2G],
        c.[TipoLiquidacion], c.[CodigoLiquidacion], c.[ImporteLiquidacion], c.[TipoImporte],
        c.[FechaCaducidadVale], c.[FechaInicioCaducidadVale],
        c.[PorcentajeSobreCompra], c.[DiaSinIva]
       FROM [CampañasCab] c
       WHERE RTRIM(c.[Empresa]) = :empresa AND c.[Campaña] = :campana'
    );
    $stmt->execute([
      'empresa' => $empresa,
      'campana' => $campana,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
      return null;
    }

    $item = $this->mapCab($row);
    $item['lineas'] = $this->findLineas($empresa, $campana);
    return $item;
  }

  /** @param array<string, mixed> $data */
  public function create(array $data): array
  {
    $empresa = $this->str($data['empresa'] ?? null, 3);
    $descripcion = $this->strOrEmpty($data['descripcion'] ?? null, 50);
    if ($empresa === null || $descripcion === '') {
      throw new \InvalidArgumentException('Empresa y descripcion son obligatorias');
    }

    $campana = $this->nextCampana($empresa);
    $lUpdate = date('Y-m-d H:i:s');
    $payload = $this->bindCab($empresa, $campana, $data, $lUpdate);

    $this->pdo->beginTransaction();
    try {
      $stmt = $this->pdo->prepare(
        'INSERT INTO [CampañasCab] (
          [Empresa], [Campaña], [Descripcion], [Fecha], [Observaciones],
          [TrasModem], [FechaFinalizacion], [Tipo], [Codigo], [TipoCampaña],
          [ValeMultiple], [ImporteVale], [ImporteMinimo],
          [LiteralAviso1], [LiteralAviso2], [LiteralAviso3], [LiteralAviso4],
          [LiteralVale1], [LiteralVale2], [LiteralVale3], [LiteralVale4],
          [LUpdate], [DiasValidez], [FiltroWhere], [AplicarClienteVarios],
          [TipoAviso], [AvisoMultiple],
          [LiteralAviso1G], [LiteralAviso2G], [LiteralVale1G], [LiteralVale2G],
          [TipoLiquidacion], [CodigoLiquidacion], [ImporteLiquidacion], [TipoImporte],
          [FechaCaducidadVale], [FechaInicioCaducidadVale],
          [PorcentajeSobreCompra], [DiaSinIva]
        ) VALUES (
          :empresa, :campana, :descripcion, CONVERT(datetime, :fecha, 120), :observaciones,
          :trasModem, CONVERT(datetime, :fechaFinalizacion, 120), :tipo, :codigo, :tipoCampana,
          :valeMultiple, :importeVale, :importeMinimo,
          :literalAviso1, :literalAviso2, :literalAviso3, :literalAviso4,
          :literalVale1, :literalVale2, :literalVale3, :literalVale4,
          CONVERT(datetime, :lUpdate, 120), :diasValidez, :filtroWhere, :aplicarClienteVarios,
          :tipoAviso, :avisoMultiple,
          :literalAviso1G, :literalAviso2G, :literalVale1G, :literalVale2G,
          :tipoLiquidacion, :codigoLiquidacion, :importeLiquidacion, :tipoImporte,
          CONVERT(datetime, :fechaCaducidadVale, 120), CONVERT(datetime, :fechaInicioCaducidadVale, 120),
          :porcentajeSobreCompra, :diaSinIva
        )'
      );
      $stmt->execute($payload);

      if (array_key_exists('lineas', $data) && is_array($data['lineas'])) {
        $this->replaceLineas($empresa, $campana, $data['lineas']);
      }

      $this->pdo->commit();
    } catch (\Throwable $e) {
      if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      throw $e;
    }

    $created = $this->findOne($empresa, $campana);
    if ($created === null) {
      throw new \RuntimeException('No se pudo leer la campana creada');
    }
    return $created;
  }

  /** @param array<string, mixed> $data */
  public function update(string $empresa, int $campana, array $data): ?array
  {
    $empresa = trim($empresa);
    if ($empresa === '' || $campana < 1) {
      throw new \InvalidArgumentException('Empresa y campana son obligatorias');
    }
    if ($this->findOne($empresa, $campana) === null) {
      return null;
    }

    $descripcion = $this->strOrEmpty($data['descripcion'] ?? null, 50);
    if ($descripcion === '') {
      throw new \InvalidArgumentException('Empresa y descripcion son obligatorias');
    }

    $lUpdate = date('Y-m-d H:i:s');
    $payload = $this->bindCab($empresa, $campana, $data, $lUpdate);

    $this->pdo->beginTransaction();
    try {
      $stmt = $this->pdo->prepare(
        'UPDATE [CampañasCab] SET
          [Descripcion] = :descripcion,
          [Fecha] = CONVERT(datetime, :fecha, 120),
          [Observaciones] = :observaciones,
          [TrasModem] = :trasModem,
          [FechaFinalizacion] = CONVERT(datetime, :fechaFinalizacion, 120),
          [Tipo] = :tipo,
          [Codigo] = :codigo,
          [TipoCampaña] = :tipoCampana,
          [ValeMultiple] = :valeMultiple,
          [ImporteVale] = :importeVale,
          [ImporteMinimo] = :importeMinimo,
          [LiteralAviso1] = :literalAviso1,
          [LiteralAviso2] = :literalAviso2,
          [LiteralAviso3] = :literalAviso3,
          [LiteralAviso4] = :literalAviso4,
          [LiteralVale1] = :literalVale1,
          [LiteralVale2] = :literalVale2,
          [LiteralVale3] = :literalVale3,
          [LiteralVale4] = :literalVale4,
          [LUpdate] = CONVERT(datetime, :lUpdate, 120),
          [DiasValidez] = :diasValidez,
          [FiltroWhere] = :filtroWhere,
          [AplicarClienteVarios] = :aplicarClienteVarios,
          [TipoAviso] = :tipoAviso,
          [AvisoMultiple] = :avisoMultiple,
          [LiteralAviso1G] = :literalAviso1G,
          [LiteralAviso2G] = :literalAviso2G,
          [LiteralVale1G] = :literalVale1G,
          [LiteralVale2G] = :literalVale2G,
          [TipoLiquidacion] = :tipoLiquidacion,
          [CodigoLiquidacion] = :codigoLiquidacion,
          [ImporteLiquidacion] = :importeLiquidacion,
          [TipoImporte] = :tipoImporte,
          [FechaCaducidadVale] = CONVERT(datetime, :fechaCaducidadVale, 120),
          [FechaInicioCaducidadVale] = CONVERT(datetime, :fechaInicioCaducidadVale, 120),
          [PorcentajeSobreCompra] = :porcentajeSobreCompra,
          [DiaSinIva] = :diaSinIva
         WHERE RTRIM([Empresa]) = :empresa AND [Campaña] = :campana'
      );
      $stmt->execute($payload);

      if (array_key_exists('lineas', $data) && is_array($data['lineas'])) {
        $this->replaceLineas($empresa, $campana, $data['lineas']);
      }

      $this->pdo->commit();
    } catch (\Throwable $e) {
      if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      throw $e;
    }

    return $this->findOne($empresa, $campana);
  }

  public function delete(string $empresa, int $campana): bool
  {
    $empresa = trim($empresa);
    if ($this->findOne($empresa, $campana) === null) {
      return false;
    }

    $this->pdo->beginTransaction();
    try {
      $delLin = $this->pdo->prepare(
        'DELETE FROM [CampañasLin] WHERE RTRIM([Empresa]) = :empresa AND [Campaña] = :campana'
      );
      $delLin->execute(['empresa' => $empresa, 'campana' => $campana]);

      $delCab = $this->pdo->prepare(
        'DELETE FROM [CampañasCab] WHERE RTRIM([Empresa]) = :empresa AND [Campaña] = :campana'
      );
      $delCab->execute(['empresa' => $empresa, 'campana' => $campana]);

      $this->pdo->commit();
      return $delCab->rowCount() > 0;
    } catch (\Throwable $e) {
      if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      throw $e;
    }
  }

  private function nextCampana(string $empresa): int
  {
    $stmt = $this->pdo->prepare(
      'SELECT MAX([Campaña]) FROM [CampañasCab] WHERE RTRIM([Empresa]) = :empresa'
    );
    $stmt->execute(['empresa' => $empresa]);
    $max = $stmt->fetchColumn();
    return $max === null || $max === false ? 1 : ((int) $max) + 1;
  }

  /** @return list<array<string, mixed>> */
  private function findLineas(string $empresa, int $campana): array
  {
    $stmt = $this->pdo->prepare(
      'SELECT l.[Empresa], l.[Campaña] AS Campana, l.[NroLin], l.[Cliente], l.[FechaEnvio],
        l.[Asistencia], l.[Observaciones], l.[Vale], l.[FechaEmision],
        c.[RazonSocial] AS ClienteNombre
       FROM [CampañasLin] l
       LEFT JOIN [Clientes] c ON RTRIM(c.[Codigo]) = RTRIM(l.[Cliente])
       WHERE RTRIM(l.[Empresa]) = :empresa AND l.[Campaña] = :campana
       ORDER BY l.[NroLin]'
    );
    $stmt->execute([
      'empresa' => $empresa,
      'campana' => $campana,
    ]);

    $items = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $items[] = $this->mapLin($row);
    }
    return $items;
  }

  /**
   * @param list<array<string, mixed>> $lineas
   */
  private function replaceLineas(string $empresa, int $campana, array $lineas): void
  {
    $del = $this->pdo->prepare(
      'DELETE FROM [CampañasLin] WHERE RTRIM([Empresa]) = :empresa AND [Campaña] = :campana'
    );
    $del->execute(['empresa' => $empresa, 'campana' => $campana]);

    if ($lineas === []) {
      return;
    }

    $ins = $this->pdo->prepare(
      'INSERT INTO [CampañasLin] (
        [Empresa], [Campaña], [Cliente], [FechaEnvio], [Asistencia],
        [Observaciones], [Vale], [FechaEmision]
      ) VALUES (
        :empresa, :campana, :cliente, CONVERT(datetime, :fechaEnvio, 120),
        CONVERT(datetime, :asistencia, 120), :observaciones, :vale, CONVERT(datetime, :fechaEmision, 120)
      )'
    );

    foreach ($lineas as $lin) {
      if (!is_array($lin)) {
        continue;
      }
      $ins->execute([
        'empresa' => $empresa,
        'campana' => $campana,
        'cliente' => $this->strOrEmpty($lin['cliente'] ?? null, 9),
        'fechaEnvio' => $this->normalizeDate($lin['fechaEnvio'] ?? null),
        'asistencia' => $this->normalizeDate($lin['asistencia'] ?? null),
        'observaciones' => $this->strOrEmpty($lin['observaciones'] ?? null, 200),
        'vale' => $this->intOrNull($lin['vale'] ?? null),
        'fechaEmision' => $this->normalizeDate($lin['fechaEmision'] ?? null),
      ]);
    }
  }

  /**
   * @param array<string, mixed> $data
   * @return array<string, mixed>
   */
  private function bindCab(string $empresa, int $campana, array $data, string $lUpdate): array
  {
    return [
      'empresa' => $empresa,
      'campana' => $campana,
      'descripcion' => $this->strOrEmpty($data['descripcion'] ?? null, 50),
      'fecha' => $this->normalizeDate($data['fecha'] ?? null),
      'observaciones' => $this->strOrEmpty($data['observaciones'] ?? null, 200),
      'trasModem' => $this->bit($data['trasModem'] ?? null),
      'fechaFinalizacion' => $this->normalizeDate($data['fechaFinalizacion'] ?? null),
      'tipo' => $this->strOrEmpty($data['tipo'] ?? null, 1),
      'codigo' => $this->strOrEmpty($data['codigo'] ?? null, 18),
      'tipoCampana' => $this->intOrNull($data['tipoCampana'] ?? null),
      'valeMultiple' => $this->bit($data['valeMultiple'] ?? null),
      'importeVale' => $this->float($data['importeVale'] ?? null),
      'importeMinimo' => $this->float($data['importeMinimo'] ?? null),
      'literalAviso1' => $this->strOrEmpty($data['literalAviso1'] ?? null, 56),
      'literalAviso2' => $this->strOrEmpty($data['literalAviso2'] ?? null, 56),
      'literalAviso3' => $this->strOrEmpty($data['literalAviso3'] ?? null, 56),
      'literalAviso4' => $this->strOrEmpty($data['literalAviso4'] ?? null, 56),
      'literalVale1' => $this->strOrEmpty($data['literalVale1'] ?? null, 56),
      'literalVale2' => $this->strOrEmpty($data['literalVale2'] ?? null, 56),
      'literalVale3' => $this->strOrEmpty($data['literalVale3'] ?? null, 56),
      'literalVale4' => $this->strOrEmpty($data['literalVale4'] ?? null, 56),
      'lUpdate' => $lUpdate,
      'diasValidez' => $this->intOrNull($data['diasValidez'] ?? null),
      'filtroWhere' => $this->strOrEmpty($data['filtroWhere'] ?? null, 4000),
      'aplicarClienteVarios' => $this->bit($data['aplicarClienteVarios'] ?? null),
      'tipoAviso' => $this->intOrNull($data['tipoAviso'] ?? null),
      'avisoMultiple' => $this->bit($data['avisoMultiple'] ?? null),
      'literalAviso1G' => $this->strOrEmpty($data['literalAviso1G'] ?? null, 28),
      'literalAviso2G' => $this->strOrEmpty($data['literalAviso2G'] ?? null, 28),
      'literalVale1G' => $this->strOrEmpty($data['literalVale1G'] ?? null, 28),
      'literalVale2G' => $this->strOrEmpty($data['literalVale2G'] ?? null, 28),
      'tipoLiquidacion' => $this->strOrEmpty($data['tipoLiquidacion'] ?? null, 1),
      'codigoLiquidacion' => $this->strOrEmpty($data['codigoLiquidacion'] ?? null, 18),
      'importeLiquidacion' => $this->float($data['importeLiquidacion'] ?? null),
      'tipoImporte' => $this->strOrEmpty($data['tipoImporte'] ?? null, 1),
      'fechaCaducidadVale' => $this->normalizeDate($data['fechaCaducidadVale'] ?? null),
      'fechaInicioCaducidadVale' => $this->normalizeDate($data['fechaInicioCaducidadVale'] ?? null),
      'porcentajeSobreCompra' => $this->bit($data['porcentajeSobreCompra'] ?? null),
      'diaSinIva' => $this->bit($data['diaSinIva'] ?? null),
    ];
  }

  /** @param array<string, mixed> $row */
  private function mapCab(array $row): array
  {
    return [
      'empresa' => rtrim((string) $row['Empresa']),
      'campana' => (int) $row['Campana'],
      'descripcion' => $this->rtrimNull($row['Descripcion'] ?? null),
      'fecha' => $this->formatDateOut($row['Fecha'] ?? null),
      'observaciones' => $this->rtrimNull($row['Observaciones'] ?? null),
      'trasModem' => (bool) ($row['TrasModem'] ?? false),
      'fechaFinalizacion' => $this->formatDateOut($row['FechaFinalizacion'] ?? null),
      'tipo' => $this->rtrimNull($row['Tipo'] ?? null),
      'codigo' => $this->rtrimNull($row['Codigo'] ?? null),
      'tipoCampana' => $row['TipoCampana'] === null ? null : (int) $row['TipoCampana'],
      'valeMultiple' => (bool) ($row['ValeMultiple'] ?? false),
      'importeVale' => $row['ImporteVale'] === null ? null : (float) $row['ImporteVale'],
      'importeMinimo' => $row['ImporteMinimo'] === null ? null : (float) $row['ImporteMinimo'],
      'literalAviso1' => $this->rtrimNull($row['LiteralAviso1'] ?? null),
      'literalAviso2' => $this->rtrimNull($row['LiteralAviso2'] ?? null),
      'literalAviso3' => $this->rtrimNull($row['LiteralAviso3'] ?? null),
      'literalAviso4' => $this->rtrimNull($row['LiteralAviso4'] ?? null),
      'literalVale1' => $this->rtrimNull($row['LiteralVale1'] ?? null),
      'literalVale2' => $this->rtrimNull($row['LiteralVale2'] ?? null),
      'literalVale3' => $this->rtrimNull($row['LiteralVale3'] ?? null),
      'literalVale4' => $this->rtrimNull($row['LiteralVale4'] ?? null),
      'lUpdate' => $this->formatDateTimeOut($row['LUpdate'] ?? null),
      'diasValidez' => $row['DiasValidez'] === null ? null : (int) $row['DiasValidez'],
      'filtroWhere' => $this->rtrimNull($row['FiltroWhere'] ?? null),
      'aplicarClienteVarios' => (bool) ($row['AplicarClienteVarios'] ?? false),
      'tipoAviso' => $row['TipoAviso'] === null ? null : (int) $row['TipoAviso'],
      'avisoMultiple' => (bool) ($row['AvisoMultiple'] ?? false),
      'literalAviso1G' => $this->rtrimNull($row['LiteralAviso1G'] ?? null),
      'literalAviso2G' => $this->rtrimNull($row['LiteralAviso2G'] ?? null),
      'literalVale1G' => $this->rtrimNull($row['LiteralVale1G'] ?? null),
      'literalVale2G' => $this->rtrimNull($row['LiteralVale2G'] ?? null),
      'tipoLiquidacion' => $this->rtrimNull($row['TipoLiquidacion'] ?? null),
      'codigoLiquidacion' => $this->rtrimNull($row['CodigoLiquidacion'] ?? null),
      'importeLiquidacion' => $row['ImporteLiquidacion'] === null ? null : (float) $row['ImporteLiquidacion'],
      'tipoImporte' => $this->rtrimNull($row['TipoImporte'] ?? null),
      'fechaCaducidadVale' => $this->formatDateOut($row['FechaCaducidadVale'] ?? null),
      'fechaInicioCaducidadVale' => $this->formatDateOut($row['FechaInicioCaducidadVale'] ?? null),
      'porcentajeSobreCompra' => (bool) ($row['PorcentajeSobreCompra'] ?? false),
      'diaSinIva' => (bool) ($row['DiaSinIva'] ?? false),
    ];
  }

  /** @param array<string, mixed> $row */
  private function mapLin(array $row): array
  {
    $clienteNombre = $row['ClienteNombre'] ?? null;
    return [
      'nroLin' => (int) $row['NroLin'],
      'cliente' => $this->rtrimNull($row['Cliente'] ?? null),
      'clienteNombre' => $clienteNombre === null ? null : rtrim((string) $clienteNombre),
      'fechaEnvio' => $this->formatDateOut($row['FechaEnvio'] ?? null),
      'asistencia' => $this->formatDateOut($row['Asistencia'] ?? null),
      'observaciones' => $this->rtrimNull($row['Observaciones'] ?? null),
      'vale' => $row['Vale'] === null ? null : (int) $row['Vale'],
      'fechaEmision' => $this->formatDateOut($row['FechaEmision'] ?? null),
    ];
  }

  private function normalizeDate(mixed $value): ?string
  {
    if ($value === null || $value === '') {
      return null;
    }
    $s = trim((string) $value);
    $s = str_replace('T', ' ', $s);
    $s = preg_replace('/\.\d+$/', '', $s) ?? $s;
    if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:\s+(\d{2}:\d{2}:\d{2}))?/', $s, $m)) {
      return isset($m[2]) && $m[2] !== ''
        ? $m[1] . ' ' . $m[2]
        : $m[1] . ' 00:00:00';
    }
    return null;
  }

  private function formatDateOut(mixed $value): ?string
  {
    $full = $this->formatDateTimeOut($value);
    return $full === null ? null : substr($full, 0, 10);
  }

  private function formatDateTimeOut(mixed $value): ?string
  {
    if ($value instanceof \DateTimeInterface) {
      return $value->format('Y-m-d H:i:s');
    }
    if (!is_string($value) || trim($value) === '') {
      return null;
    }
    $s = preg_replace('/\.\d+$/', '', str_replace('T', ' ', trim($value))) ?? trim($value);
    return $s === '' ? null : $s;
  }

  private function float(mixed $value): ?float
  {
    if ($value === null || $value === '') {
      return null;
    }
    if (is_string($value)) {
      $value = str_replace(',', '.', $value);
    }
    return (float) $value;
  }

  private function intOrNull(mixed $value): ?int
  {
    if ($value === null || $value === '') {
      return null;
    }
    return (int) $value;
  }

  private function bit(mixed $value): int
  {
    if ($value === null || $value === '' || $value === false || $value === 0 || $value === '0') {
      return 0;
    }
    return 1;
  }

  private function str(mixed $value, int $maxLen): ?string
  {
    if ($value === null) {
      return null;
    }
    $s = trim((string) $value);
    if ($s === '') {
      return null;
    }
    return mb_substr($s, 0, $maxLen);
  }

  private function strOrEmpty(mixed $value, int $maxLen): string
  {
    if ($value === null) {
      return '';
    }
    $s = trim((string) $value);
    if ($s === '') {
      return '';
    }
    return mb_substr($s, 0, $maxLen);
  }

  private function rtrimNull(mixed $value): string
  {
    if ($value === null) {
      return '';
    }
    return rtrim((string) $value);
  }
}
