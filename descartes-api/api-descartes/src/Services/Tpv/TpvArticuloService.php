<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Tpv;

use Descartes\Api\Repositories\ArtBarrasRepository;
use PDO;

/** Precio de artículo para TPV según tarifa del puesto (006 US2). */
final class TpvArticuloService
{
  private PDO $pdo;
  private ArtBarrasRepository $barras;

  public function __construct(PDO $pdo, ArtBarrasRepository $barras)
  {
    $this->pdo = $pdo;
    $this->barras = $barras;
  }

  /**
   * Entrada por teclado o pistola: código, Alternativo o EAN (006 US5).
   *
   * @return array<string, mixed>|null
   */
  public function resolver(string $query, int $tarifa): ?array
  {
    $q = trim($query);
    if ($q === '') {
      throw new \InvalidArgumentException('Código o EAN obligatorio');
    }

    $hit = $this->barras->resolverReferencia($q);
    if ($hit === null) {
      return null;
    }

    $datos = $this->obtenerPrecio((string) $hit['codigo'], $tarifa);
    if ($datos === null) {
      return null;
    }

    $datos['matchPor'] = $hit['matchPor'];
    $unidades = (float) ($hit['unidadesPaquete'] ?? 1);
    $datos['unidadesPaquete'] = $unidades > 0 ? $unidades : 1.0;

    return $datos;
  }

  /**
   * Búsqueda por código o descripción para configurar teclas de venta rápida.
   *
   * @return list<array<string, mixed>>
   */
  public function buscar(
    string $query,
    int $tarifa,
    int $limite = 30,
    string $ambito = 'todos'
  ): array
  {
    $q = trim($query);
    if ($q === '') {
      throw new \InvalidArgumentException('Texto de búsqueda obligatorio');
    }

    $tarifa = min(9, max(1, $tarifa > 0 ? $tarifa : 1));
    $col = 'PrecioVen' . $tarifa;
    $limite = min(100, max(1, $limite));
    $ambito = strtolower(trim($ambito));
    if (!in_array($ambito, ['todos', 'articulo', 'macrofamilia', 'familia', 'subfamilia', 'agrupacion'], true)) {
      throw new \InvalidArgumentException('Ámbito de búsqueda no válido');
    }

    $campos = [
      'articulo' => [
        "LTRIM(RTRIM(a.Codigo)) = :artExacto",
        "a.Codigo LIKE :artCodigo",
        "a.Descripcion LIKE :artDescripcion",
      ],
      'macrofamilia' => [
        "mf.Codigo LIKE :macroCodigo",
        "mf.Descripcion LIKE :macroDescripcion",
      ],
      'familia' => [
        "f.Codigo LIKE :familiaCodigo",
        "f.Descripcion LIKE :familiaDescripcion",
      ],
      'subfamilia' => [
        "sf.Subfamilia LIKE :subfamiliaCodigo",
        "sf.[Descripción] LIKE :subfamiliaDescripcion",
      ],
      'agrupacion' => [
        "CAST(ag.Codigo AS NVARCHAR(30)) LIKE :agrupacionCodigo",
        "ag.Descripcion LIKE :agrupacionDescripcion",
      ],
    ];
    $seleccion = $ambito === 'todos' ? array_keys($campos) : [$ambito];
    $condiciones = [];
    foreach ($seleccion as $tipo) {
      array_push($condiciones, ...$campos[$tipo]);
    }

    // ODBC no permite reutilizar un parámetro con nombre en varias posiciones.
    $st = $this->pdo->prepare(
      "SELECT TOP {$limite} a.Codigo, a.Descripcion, a.{$col} AS PrecioTarifa, a.FechaBaja,
              i.PjeIVA AS PjeIva,
              f.Codigo AS FamiliaCodigo, f.Descripcion AS FamiliaDescripcion,
              sf.Subfamilia AS SubfamiliaCodigo, sf.[Descripción] AS SubfamiliaDescripcion,
              mf.Codigo AS MacroFamiliaCodigo, mf.Descripcion AS MacroFamiliaDescripcion,
              ag.Codigo AS AgrupacionCodigo, ag.Descripcion AS AgrupacionDescripcion
       FROM Articulos a
       LEFT JOIN Impuestos i ON a.Impuesto = i.Codigo
       LEFT JOIN Familias f ON LTRIM(RTRIM(f.Codigo)) = LTRIM(RTRIM(a.Familia))
       LEFT JOIN Subfamilias sf
         ON LTRIM(RTRIM(sf.Subfamilia)) = LTRIM(RTRIM(a.Subfamilia))
        AND LTRIM(RTRIM(sf.Familia)) = LTRIM(RTRIM(a.Familia))
       LEFT JOIN MacroFamilias mf ON LTRIM(RTRIM(mf.Codigo)) = LTRIM(RTRIM(f.MacroFamilia))
       LEFT JOIN Agrupaciones ag
         ON LTRIM(RTRIM(CAST(ag.Codigo AS NVARCHAR(30)))) =
            LTRIM(RTRIM(CAST(a.Agrupacion AS NVARCHAR(30))))
       WHERE (a.FechaBaja IS NULL OR LTRIM(RTRIM(CAST(a.FechaBaja AS NVARCHAR(30)))) = '')
         AND (" . implode(' OR ', $condiciones) . ")
       ORDER BY CASE WHEN LTRIM(RTRIM(a.Codigo)) = :ordenExacto THEN 0 ELSE 1 END, a.Descripcion"
    );
    $params = ['ordenExacto' => $q];
    if (in_array('articulo', $seleccion, true)) {
      $params += [
        'artExacto' => $q,
        'artCodigo' => $q . '%',
        'artDescripcion' => '%' . $q . '%',
      ];
    }
    foreach ([
      'macro' => 'macrofamilia',
      'familia' => 'familia',
      'subfamilia' => 'subfamilia',
      'agrupacion' => 'agrupacion',
    ] as $prefijo => $tipo) {
      if (!in_array($tipo, $seleccion, true)) {
        continue;
      }
      $params[$prefijo . 'Codigo'] = $q . '%';
      $params[$prefijo . 'Descripcion'] = '%' . $q . '%';
    }
    $st->execute($params);

    $items = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $items[] = [
        'codigo' => trim((string) ($row['Codigo'] ?? '')),
        'descripcion' => trim((string) ($row['Descripcion'] ?? '')),
        'precio' => (float) ($row['PrecioTarifa'] ?? 0),
        'iva' => isset($row['PjeIva']) && (float) $row['PjeIva'] > 0 ? (float) $row['PjeIva'] : 21.0,
        'bloqueado' => false,
        'familiaCodigo' => trim((string) ($row['FamiliaCodigo'] ?? '')),
        'familiaDescripcion' => trim((string) ($row['FamiliaDescripcion'] ?? '')),
        'subfamiliaCodigo' => trim((string) ($row['SubfamiliaCodigo'] ?? '')),
        'subfamiliaDescripcion' => trim((string) ($row['SubfamiliaDescripcion'] ?? '')),
        'macroFamiliaCodigo' => trim((string) ($row['MacroFamiliaCodigo'] ?? '')),
        'macroFamiliaDescripcion' => trim((string) ($row['MacroFamiliaDescripcion'] ?? '')),
        'agrupacionCodigo' => trim((string) ($row['AgrupacionCodigo'] ?? '')),
        'agrupacionDescripcion' => trim((string) ($row['AgrupacionDescripcion'] ?? '')),
      ];
    }
    return $items;
  }

  /**
   * @return array<string, mixed>|null
   */
  public function obtenerPrecio(string $codigo, int $tarifa): ?array
  {
    $codigo = trim($codigo);
    if ($codigo === '') {
      throw new \InvalidArgumentException('Código de artículo obligatorio');
    }

    $tarifa = min(9, max(1, $tarifa > 0 ? $tarifa : 1));
    $col = 'PrecioVen' . $tarifa;

    $st = $this->pdo->prepare(
      "SELECT a.Codigo, a.Descripcion, a.{$col} AS PrecioTarifa, a.FechaBaja,
              i.PjeIVA AS PjeIva
       FROM Articulos a
       LEFT JOIN Impuestos i ON a.Impuesto = i.Codigo
       WHERE a.Codigo = :c"
    );
    $st->execute(['c' => $codigo]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
      return null;
    }

    $fechaBaja = $row['FechaBaja'] ?? null;
    $bloqueado = $fechaBaja !== null && trim((string) $fechaBaja) !== '';

    return [
      'codigo' => trim((string) ($row['Codigo'] ?? $codigo)),
      'descripcion' => trim((string) ($row['Descripcion'] ?? '')),
      'precio' => (float) ($row['PrecioTarifa'] ?? 0),
      'iva' => isset($row['PjeIva']) && (float) $row['PjeIva'] > 0 ? (float) $row['PjeIva'] : 21.0,
      'bloqueado' => $bloqueado,
    ];
  }
}
