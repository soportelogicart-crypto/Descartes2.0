<?php

declare(strict_types=1);

namespace Descartes\Api\Controllers;

use Descartes\Api\Http\ErrorResponse;
use Descartes\Api\Repositories\ArtBarrasRepository;
use Descartes\Api\Repositories\ArticuloStockRepository;
use Descartes\Api\Repositories\EscandallosRepository;
use Descartes\Api\Repositories\PlantasRepository;
use Descartes\Api\Services\MantenimientoService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;

final class ArticuloController
{
  private ArticuloStockRepository $articuloStockRepository;
  private ArtBarrasRepository $artBarrasRepository;
  private EscandallosRepository $escandallosRepository;
  private PlantasRepository $plantasRepository;
  private MantenimientoService $mantenimientoService;
  private PDO $pdo;

  public function __construct(
    ArticuloStockRepository $articuloStockRepository,
    ArtBarrasRepository $artBarrasRepository,
    EscandallosRepository $escandallosRepository,
    PlantasRepository $plantasRepository,
    MantenimientoService $mantenimientoService,
    PDO $pdo
  ) {
    $this->articuloStockRepository = $articuloStockRepository;
    $this->artBarrasRepository = $artBarrasRepository;
    $this->escandallosRepository = $escandallosRepository;
    $this->plantasRepository = $plantasRepository;
    $this->mantenimientoService = $mantenimientoService;
    $this->pdo = $pdo;
  }

  public function siguienteCodigo(Request $request, Response $response): Response
  {
    $params = $request->getQueryParams();
    $empresa = trim((string) ($params['empresa'] ?? ''));
    try {
      $info = $this->mantenimientoService->siguienteCodigoArticulo($empresa);
      return $this->json($response, 200, $info);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  /**
   * Resuelve referencia de teclado/escáner: código, Alternativo o EAN (ArtBarras).
   * GET /api/mantenimiento/articulos/resolver?q=...
   */
  public function resolver(Request $request, Response $response): Response
  {
    $q = trim((string) ($request->getQueryParams()['q'] ?? ''));
    if ($q === '') {
      return ErrorResponse::json($response, 400, 'Parámetro q obligatorio', 'VALIDACION');
    }

    try {
      $hit = $this->artBarrasRepository->resolverReferencia($q);
      if ($hit === null) {
        return ErrorResponse::json($response, 404, 'Artículo no encontrado', 'NO_ENCONTRADO');
      }
      $item = $this->mantenimientoService->get('articulos', $hit['codigo']);
      if ($item === null) {
        return ErrorResponse::json($response, 404, 'Artículo no encontrado', 'NO_ENCONTRADO');
      }
      $item['matchPor'] = $hit['matchPor'];
      $item['unidadesPaquete'] = $hit['unidadesPaquete'];
      $item['query'] = $q;
      return $this->json($response, 200, $item);
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function getStock(Request $request, Response $response, array $args): Response
  {
    $codigo = trim((string) ($args['codigo'] ?? ''));
    $stock = $this->articuloStockRepository->findByArticulo($codigo);

    return $this->json($response, 200, ['items' => $stock]);
  }

  public function listEans(Request $request, Response $response, array $args): Response
  {
    $codigo = trim((string) ($args['codigo'] ?? ''));
    if ($codigo === '') {
      return ErrorResponse::json($response, 400, 'Codigo de articulo obligatorio', 'VALIDACION');
    }
    if (!$this->artBarrasRepository->articuloExiste($codigo)) {
      return ErrorResponse::json($response, 404, 'Articulo no encontrado', 'NO_ENCONTRADO');
    }

    return $this->json($response, 200, ['items' => $this->artBarrasRepository->findByArticulo($codigo)]);
  }

  public function putEans(Request $request, Response $response, array $args): Response
  {
    $codigo = trim((string) ($args['codigo'] ?? ''));
    if ($codigo === '') {
      return ErrorResponse::json($response, 400, 'Código de artículo obligatorio', 'VALIDACION');
    }
    if (!$this->artBarrasRepository->articuloExiste($codigo)) {
      return ErrorResponse::json($response, 404, 'Artículo no encontrado', 'NO_ENCONTRADO');
    }

    $body = (array) ($request->getParsedBody() ?? []);
    $items = $body['items'] ?? null;
    if (!is_array($items)) {
      return ErrorResponse::json($response, 400, 'Se espera { items: [...] }', 'VALIDACION');
    }

    try {
      $saved = $this->artBarrasRepository->replaceForArticulo($codigo, $items);
      return $this->json($response, 200, ['items' => $saved]);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  /**
   * Comprueba si un EAN está libre o ya asignado a un artículo.
   * GET /api/mantenimiento/articulos/ean-lookup?ean=...
   */
  public function eanLookup(Request $request, Response $response): Response
  {
    $ean = trim((string) ($request->getQueryParams()['ean'] ?? ''));
    if ($ean === '') {
      return ErrorResponse::json($response, 400, 'Parámetro ean obligatorio', 'VALIDACION');
    }

    $digits = $ean;
    if (preg_match('/^\d+\.0+$/', $ean)) {
      $digits = explode('.', $ean, 2)[0];
    }
    if (!preg_match('/^\d{4,18}$/', $digits)) {
      return ErrorResponse::json($response, 400, "EAN inválido: {$ean} (solo dígitos, 4–18)", 'VALIDACION');
    }

    $codigo = $this->artBarrasRepository->codigoPorEan($digits);
    return $this->json($response, 200, [
      'ean' => $digits,
      'disponible' => $codigo === null,
      'codigoArticulo' => $codigo,
    ]);
  }

  public function listEscandallo(Request $request, Response $response, array $args): Response
  {
    $codigo = trim((string) ($args['codigo'] ?? ''));
    if ($codigo === '') {
      return ErrorResponse::json($response, 400, 'Codigo de articulo obligatorio', 'VALIDACION');
    }
    if (!$this->escandallosRepository->articuloExiste($codigo)) {
      return ErrorResponse::json($response, 404, 'Articulo no encontrado', 'NO_ENCONTRADO');
    }

    return $this->json($response, 200, ['items' => $this->escandallosRepository->findByArticulo($codigo)]);
  }

  public function putEscandallo(Request $request, Response $response, array $args): Response
  {
    $codigo = trim((string) ($args['codigo'] ?? ''));
    if ($codigo === '') {
      return ErrorResponse::json($response, 400, 'Codigo de articulo obligatorio', 'VALIDACION');
    }
    if (!$this->escandallosRepository->articuloExiste($codigo)) {
      return ErrorResponse::json($response, 404, 'Articulo no encontrado', 'NO_ENCONTRADO');
    }

    $body = (array) ($request->getParsedBody() ?? []);
    $items = $body['items'] ?? null;
    if (!is_array($items)) {
      return ErrorResponse::json($response, 400, 'Se espera { items: [...] }', 'VALIDACION');
    }

    try {
      $saved = $this->escandallosRepository->replaceForArticulo($codigo, $items);
      return $this->json($response, 200, ['items' => $saved]);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  /** Ficha botanica vinculada al articulo (GardenDocumental.Plantas via Articulos.Ficha). */
  public function getFichaBotanica(Request $request, Response $response, array $args): Response
  {
    $codigo = trim((string) ($args['codigo'] ?? ''));
    if ($codigo === '' || !$this->artBarrasRepository->articuloExiste($codigo)) {
      return ErrorResponse::json($response, 404, 'Articulo no encontrado', 'NO_ENCONTRADO');
    }

    $fichaRef = $this->leerFichaArticulo($codigo);
    $planta = null;
    if ($fichaRef !== null && $fichaRef > 0) {
      $planta = $this->plantasRepository->findByCodigo($fichaRef);
    }

    return $this->json($response, 200, [
      'articuloCodigo' => $codigo,
      'fichaVinculo' => $fichaRef,
      'ficha' => $planta,
      'grupos' => $this->plantasRepository->listGrupos(),
    ]);
  }

  public function putFichaBotanica(Request $request, Response $response, array $args): Response
  {
    $codigo = trim((string) ($args['codigo'] ?? ''));
    if ($codigo === '' || !$this->artBarrasRepository->articuloExiste($codigo)) {
      return ErrorResponse::json($response, 404, 'Articulo no encontrado', 'NO_ENCONTRADO');
    }

    $body = (array) ($request->getParsedBody() ?? []);
    $ficha = $body['ficha'] ?? $body;
    if (!is_array($ficha)) {
      return ErrorResponse::json($response, 400, 'Payload de ficha invalido', 'VALIDACION');
    }

    try {
      $fichaCodigo = isset($ficha['codigo']) ? (int) $ficha['codigo'] : 0;
      if ($fichaCodigo > 0 && $this->plantasRepository->findByCodigo($fichaCodigo) !== null) {
        $saved = $this->plantasRepository->update($fichaCodigo, $ficha);
      } else {
        unset($ficha['codigo']);
        $saved = $this->plantasRepository->create($ficha);
        $fichaCodigo = (int) ($saved['codigo'] ?? 0);
      }

      if ($fichaCodigo > 0) {
        $this->escribirFichaArticulo($codigo, (string) $fichaCodigo);
      }

      return $this->json($response, 200, [
        'articuloCodigo' => $codigo,
        'fichaVinculo' => $fichaCodigo,
        'ficha' => $saved,
      ]);
    } catch (\InvalidArgumentException $e) {
      return ErrorResponse::json($response, 400, $e->getMessage(), 'VALIDACION');
    } catch (\Throwable $e) {
      return ErrorResponse::json($response, 500, $e->getMessage(), 'ERROR');
    }
  }

  public function searchFichasBotanicas(Request $request, Response $response): Response
  {
    $q = trim((string) ($request->getQueryParams()['q'] ?? ''));
    $items = $this->plantasRepository->search($q, 80);
    return $this->json($response, 200, ['items' => $items]);
  }

  public function listGruposFicha(Request $request, Response $response): Response
  {
    $tipo = trim((string) ($request->getQueryParams()['tipo'] ?? ''));
    $items = $this->plantasRepository->listGrupos($tipo === '' ? null : $tipo);
    return $this->json($response, 200, ['items' => $items]);
  }

  public function getPlanta(Request $request, Response $response, array $args): Response
  {
    $codigo = (int) ($args['codigo'] ?? 0);
    $planta = $this->plantasRepository->findByCodigo($codigo);
    if ($planta === null) {
      return ErrorResponse::json($response, 404, 'Ficha no encontrada', 'NO_ENCONTRADO');
    }
    return $this->json($response, 200, $planta);
  }

  public function deleteFichaBotanica(Request $request, Response $response, array $args): Response
  {
    $codigoArt = trim((string) ($args['codigo'] ?? ''));
    if ($codigoArt === '' || !$this->artBarrasRepository->articuloExiste($codigoArt)) {
      return ErrorResponse::json($response, 404, 'Articulo no encontrado', 'NO_ENCONTRADO');
    }
    $fichaRef = $this->leerFichaArticulo($codigoArt);
    if ($fichaRef === null || $fichaRef <= 0) {
      return ErrorResponse::json($response, 404, 'El articulo no tiene ficha vinculada', 'NO_ENCONTRADO');
    }
    $this->plantasRepository->delete($fichaRef);
    $this->escribirFichaArticulo($codigoArt, '0');
    return new SlimResponse(204);
  }

  private function leerFichaArticulo(string $codigo): ?int
  {
    $stmt = $this->pdo->prepare('SELECT RTRIM([Ficha]) FROM [Articulos] WHERE RTRIM([Codigo]) = :c');
    $stmt->execute(['c' => $codigo]);
    $raw = trim((string) ($stmt->fetchColumn() ?: ''));
    if ($raw === '' || $raw === '0') {
      return null;
    }
    return ctype_digit($raw) ? (int) $raw : null;
  }

  private function escribirFichaArticulo(string $codigo, string $ficha): void
  {
    $stmt = $this->pdo->prepare(
      'UPDATE [Articulos] SET [Ficha] = :ficha WHERE RTRIM([Codigo]) = :c'
    );
    $stmt->execute(['ficha' => $ficha, 'c' => $codigo]);
  }

  private function json(Response $response, int $status, array $payload): Response
  {
    $out = new SlimResponse($status);
    $out->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
    return $out->withHeader('Content-Type', 'application/json');
  }
}
