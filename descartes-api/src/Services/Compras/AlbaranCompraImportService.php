<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Compras;

use Descartes\Api\Repositories\ArtBarrasRepository;
use PDO;

/**
 * Vista previa de líneas importadas (CSV / PDF) sin persistir albarán.
 */
final class AlbaranCompraImportService
{
  private PDO $pdo;
  private ArtBarrasRepository $barras;

  public function __construct(PDO $pdo, ArtBarrasRepository $barras)
  {
    $this->pdo = $pdo;
    $this->barras = $barras;
  }

  /**
   * @param array<string, mixed> $body
   * @return array{lineas: list<array<string, mixed>>, avisos: list<string>}
   */
  public function previewFilas(array $body): array
  {
    $proveedor = trim((string) ($body['proveedor'] ?? ''));
    if ($proveedor === '') {
      throw new \InvalidArgumentException('Indique el proveedor');
    }
    $this->assertProveedorActivo($proveedor);

    $filas = $body['filas'] ?? [];
    if (!is_array($filas) || $filas === []) {
      throw new \InvalidArgumentException('No hay filas para importar');
    }

    $almacen = (int) ($body['almacen'] ?? 0);
    $lineas = [];
    $avisos = [];

    foreach ($filas as $i => $fila) {
      if (!is_array($fila)) {
        continue;
      }
      $codigoLeido = trim((string) ($fila['codigo'] ?? ''));
      if ($codigoLeido === '') {
        continue;
      }
      $cantidad = (float) ($fila['cantidad'] ?? 1);
      if ($cantidad <= 0) {
        $cantidad = 1;
      }
      $precio = (float) ($fila['precio'] ?? 0);
      $descripcionLeida = trim((string) ($fila['descripcion'] ?? ''));
      $dto = (float) ($fila['dto'] ?? 0);

      $hit = $this->barras->resolverReferencia($codigoLeido);
      if ($hit === null) {
        $lineas[] = $this->filaPreview(
          $codigoLeido,
          '',
          $descripcionLeida,
          $cantidad,
          $precio,
          $dto,
          'no_encontrado',
          'Artículo no encontrado',
          $almacen
        );
        $avisos[] = 'Fila ' . ($i + 1) . ': no se encontró «' . $codigoLeido . '»';
        continue;
      }

      $codigo = $hit['codigo'];
      $estadoArticulo = $this->estadoArticuloCompra($codigo);
      if ($estadoArticulo !== null) {
        $lineas[] = $this->filaPreview(
          $codigoLeido,
          $codigo,
          $descripcionLeida,
          $cantidad,
          $precio,
          $dto,
          'bloqueado',
          $estadoArticulo,
          $almacen
        );
        $avisos[] = 'Fila ' . ($i + 1) . ': ' . $estadoArticulo;
        continue;
      }

      $descMaestro = $this->descripcionArticulo($codigo);
      $lineas[] = $this->filaPreview(
        $codigoLeido,
        $codigo,
        $descripcionLeida !== '' ? $descripcionLeida : $descMaestro,
        $cantidad,
        $precio,
        $dto,
        'ok',
        'OK',
        $almacen
      );
    }

    if ($lineas === []) {
      throw new \InvalidArgumentException('Ninguna fila válida tras el análisis');
    }

    return ['lineas' => $lineas, 'avisos' => $avisos];
  }

  private function assertProveedorActivo(string $codigo): void
  {
    $stmt = $this->pdo->prepare(
      'SELECT ISNULL(Baja, 0) AS Baja FROM Proveedores WHERE RTRIM(Codigo) = :c'
    );
    $stmt->execute(['c' => $codigo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      throw new \InvalidArgumentException('Proveedor no encontrado: ' . $codigo);
    }
    if ((int) ($row['Baja'] ?? 0) === 1) {
      throw new \InvalidArgumentException('Proveedor dado de baja: ' . $codigo);
    }
  }

  private function estadoArticuloCompra(string $codigo): ?string
  {
    $stmt = $this->pdo->prepare(
      'SELECT ISNULL(a.BloqueoCompra, 0) AS BloqueoCompra,
              ISNULL(a2.BloqueadoCompra, 0) AS BloqueadoCompra
       FROM Articulos a
       LEFT JOIN Articulos2 a2 ON RTRIM(a2.Codigo) = RTRIM(a.Codigo)
       WHERE RTRIM(a.Codigo) = :c'
    );
    $stmt->execute(['c' => $codigo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      return 'Artículo no encontrado';
    }
    if ((int) ($row['BloqueoCompra'] ?? 0) === 1 || (int) ($row['BloqueadoCompra'] ?? 0) === 1) {
      return 'Bloqueado para compra';
    }
    return null;
  }

  private function descripcionArticulo(string $codigo): string
  {
    $stmt = $this->pdo->prepare(
      'SELECT TOP 1 RTRIM(ISNULL(Descripcion, \'\')) FROM Articulos WHERE RTRIM(Codigo) = :c'
    );
    $stmt->execute(['c' => $codigo]);
    $d = $stmt->fetchColumn();
    return $d !== false ? trim((string) $d) : '';
  }

  /**
   * @return array<string, mixed>
   */
  private function filaPreview(
    string $codigoLeido,
    string $articulo,
    string $descripcion,
    float $cantidad,
    float $precio,
    float $dto,
    string $estado,
    string $estadoLabel,
    int $almacen
  ): array {
    return [
      'codigoLeido' => $codigoLeido,
      'articulo' => $articulo,
      'descripcion' => $descripcion,
      'cantidad' => $cantidad,
      'precio' => $precio,
      'pjeDto' => 0,
      'dto1' => $dto,
      'dto2' => 0,
      'dto3' => 0,
      'almacen' => $almacen > 0 ? $almacen : null,
      'estado' => $estado,
      'estadoLabel' => $estadoLabel,
    ];
  }
}
