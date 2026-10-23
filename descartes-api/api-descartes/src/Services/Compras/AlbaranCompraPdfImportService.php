<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Compras;

/**
 * Extrae texto de PDF digital y aplica plantilla por proveedor (Fase 2).
 */
final class AlbaranCompraPdfImportService
{
  private AlbaranCompraImportService $import;

  public function __construct(AlbaranCompraImportService $import)
  {
    $this->import = $import;
  }

  /**
   * @param array<string, mixed> $body proveedor, pdfBase64, almacen, empresa
   * @return array<string, mixed>
   */
  public function previewPdf(array $body): array
  {
    @set_time_limit(120);
    @ini_set('memory_limit', '256M');

    $proveedor = trim((string) ($body['proveedor'] ?? ''));
    if ($proveedor === '') {
      throw new \InvalidArgumentException('Indique el proveedor');
    }
    $b64 = preg_replace('/\s+/', '', (string) ($body['pdfBase64'] ?? '')) ?? '';
    if ($b64 === '') {
      throw new \InvalidArgumentException('Falta el contenido del PDF');
    }
    $raw = base64_decode($b64, true);
    if ($raw === false || $raw === '') {
      throw new \InvalidArgumentException('PDF no válido (base64)');
    }

    $texto = $this->normalizarTextoPdf($this->extraerTextoPdf($raw));
    if (trim($texto) === '') {
      throw new \InvalidArgumentException(
        'No se pudo leer texto del PDF. Si es un escaneo (solo imagen), use CSV o entrada manual.'
      );
    }
    if (!$this->textoPareceLegible($texto)) {
      throw new \InvalidArgumentException(
        'No se pudo extraer texto legible del PDF. Compruebe que en el servidor API está instalado smalot/pdfparser (composer install) o use CSV.'
      );
    }

    $plantilla = $this->cargarPlantilla($proveedor);
    $suAlbaran = $this->extraerSuAlbaran($texto, $plantilla);
    $filas = $this->extraerFilas($texto, $plantilla);

    if ($filas === []) {
      $muestra = trim(preg_replace('/\s+/u', ' ', $texto) ?? $texto);
      if (mb_strlen($muestra) > 160) {
        $muestra = mb_substr($muestra, 0, 160) . '…';
      }
      throw new \InvalidArgumentException(
        'No se detectaron líneas en el PDF (' . mb_strlen($texto) . ' caracteres de texto). '
        . 'Abra el PDF y compruebe que puede seleccionar texto (no escaneo). Si usa el EXE/hosting, pruebe con npm run dev en local o use CSV.'
        . ($muestra !== '' ? ' Inicio del texto: «' . $muestra . '»' : '')
      );
    }

    $preview = $this->import->previewFilas([
      'proveedor' => $proveedor,
      'almacen' => (int) ($body['almacen'] ?? 0),
      'empresa' => (string) ($body['empresa'] ?? ''),
      'filas' => $filas,
    ]);

    return array_merge($preview, [
      'suAlbaran' => $suAlbaran,
      'textoDetectado' => mb_strlen($texto) > 4000 ? mb_substr($texto, 0, 4000) . '…' : $texto,
      'lineasDetectadas' => count($filas),
    ]);
  }

  private function extraerTextoPdf(string $bytes): string
  {
    if (class_exists(\Smalot\PdfParser\Parser::class)) {
      try {
        $parser = new \Smalot\PdfParser\Parser();
        $pdf = $parser->parseContent($bytes);
        return trim($pdf->getText());
      } catch (\Throwable $e) {
        throw new \InvalidArgumentException(
          'Error al analizar el PDF: ' . $e->getMessage()
        );
      }
    }
    $basico = $this->extraerTextoPdfBasico($bytes);
    if (!$this->textoPareceLegible($basico)) {
      throw new \InvalidArgumentException(
        'Falta la librería smalot/pdfparser en el servidor API. Ejecute composer install o use CSV.'
      );
    }
    return $basico;
  }

  private function normalizarTextoPdf(string $texto): string
  {
    $t = preg_replace("/\r\n|\r/", "\n", $texto) ?? $texto;
    $t = str_replace(["\xc2\xa0", "\xe2\x80\xaf", "\xe2\x80\x89"], ' ', $t);
    // Algunos PDF parten código / precio / cantidad en líneas distintas sin tabulador.
    $t = preg_replace(
      '/(\d{3,14})\s*\n\s*(\d+[.,]\d+)\s*\n\s*(\d+[.,]\d+)/u',
      "$1\t$2\t$3",
      $t
    ) ?? $t;
    // Códigos artículo partidos: "0 0 1 0 0 3 1 9 6 1" → 0010031961
    $t = preg_replace_callback(
      '/(?<![\d])(\d(?:\s\d){8,13})(?![\d])/u',
      static fn (array $m): string => str_replace(' ', '', $m[1]),
      $t
    ) ?? $t;
    return $t;
  }

  private function textoPareceLegible(string $texto): bool
  {
    $t = trim($texto);
    if ($t === '' || mb_strlen($t) < 24) {
      return false;
    }
    $sample = mb_substr($t, 0, 2500);
    $ok = 0;
    if (preg_match_all('/[\p{L}\p{N}\s.,;:%\-@\/\(\)\t\r\n]/u', $sample, $m)) {
      $ok = strlen(implode('', $m[0]));
    }
    $len = strlen($sample);
    return $len > 0 && ($ok / $len) >= 0.82;
  }

  /** Fallback sin dependencias: extrae literales entre paréntesis en flujos PDF. */
  private function extraerTextoPdfBasico(string $bytes): string
  {
    $parts = [];
    if (preg_match_all('/\(([^\\\\()]*(?:\\\\.[^\\\\()]*)*)\)/s', $bytes, $m)) {
      foreach ($m[1] as $chunk) {
        $t = stripcslashes($chunk);
        $t = preg_replace('/\s+/', ' ', $t) ?? $t;
        if (strlen(trim($t)) >= 2) {
          $parts[] = trim($t);
        }
      }
    }
    return implode("\n", $parts);
  }

  /**
   * @return array<string, mixed>
   */
  private function cargarPlantilla(string $proveedor): array
  {
    $root = dirname(__DIR__, 3);
    $dirs = [$root . '/config/import-proveedores'];
    $nested = $root . '/api-descartes/config/import-proveedores';
    if (is_dir($nested)) {
      $dirs[] = $nested;
    }
    $codigo = preg_replace('/[^a-zA-Z0-9_-]/', '', $proveedor) ?? $proveedor;
    $paths = [];
    foreach ($dirs as $dir) {
      $paths[] = $dir . '/' . $codigo . '.json';
      $paths[] = $dir . '/_default.json';
    }
    foreach ($paths as $path) {
      if (!is_file($path)) {
        continue;
      }
      $json = file_get_contents($path);
      if ($json === false) {
        continue;
      }
      $data = json_decode($json, true);
      if (is_array($data)) {
        return $data;
      }
    }
    return [
      'useHeuristics' => true,
      'suAlbaranPattern' => '/(?:Albar[aá]n|ALBARAN|Documento)\s*[:#]?\s*([A-Z0-9][A-Z0-9./-]{2,18})/iu',
      'lineaPattern' => '',
    ];
  }

  /**
   * @param array<string, mixed> $plantilla
   */
  private function extraerSuAlbaran(string $texto, array $plantilla): ?string
  {
    $pat = (string) ($plantilla['suAlbaranPattern'] ?? '');
    if ($pat !== '' && @preg_match($pat, $texto, $m) && !empty($m[1])) {
      return mb_substr(trim((string) $m[1]), 0, 20);
    }
    if (preg_match('/Albar[aá]n[\s\t]+(\d{5,12})/iu', $texto, $m)) {
      return mb_substr(trim((string) $m[1]), 0, 20);
    }
    return null;
  }

  /**
   * @param array<string, mixed> $plantilla
   * @return list<array{codigo: string, cantidad: float, precio: float, descripcion: string, dto: float}>
   */
  private function extraerFilas(string $texto, array $plantilla): array
  {
    $filas = [];
    $pat = (string) ($plantilla['lineaPattern'] ?? '');
    if ($pat !== '') {
      foreach (preg_split('/\R/u', $texto) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || !preg_match($pat, $line, $m)) {
          continue;
        }
        $fila = $this->filaDesdeMatch(
          trim((string) ($m[1] ?? '')),
          (string) ($m[2] ?? '1'),
          (string) ($m[3] ?? '0'),
          ''
        );
        if ($fila !== null) {
          $filas[] = $fila;
        }
      }
    }

    if ($filas !== []) {
      $filtradas = $this->filtrarFilasPlausibles($this->deduplicarFilas($filas));
      if ($filtradas !== []) {
        return $filtradas;
      }
      $filas = [];
    }

    $useHeuristics = !isset($plantilla['useHeuristics']) || $plantilla['useHeuristics'] !== false;
    if ($useHeuristics) {
      $filas = $this->extraerFilasHeuristica($texto);
    }

    return $this->filtrarFilasPlausibles($this->deduplicarFilas($filas));
  }

  /**
   * Albaranes ERP: código numérico + columnas separadas por tabulador.
   *
   * @return list<array{codigo: string, cantidad: float, precio: float, descripcion: string, dto: float}>
   */
  private function extraerFilasHeuristica(string $texto): array
  {
    $filas = [];
    foreach (preg_split('/\R/u', $texto) ?: [] as $line) {
      $line = trim($line);
      if ($line === '' || $this->esLineaCabecera($line)) {
        continue;
      }

      if (str_contains($line, "\t")) {
        $fila = $this->parseLineaTabulada($line);
        if ($fila !== null) {
          $filas[] = $fila;
          continue;
        }
      }

      $fila = $this->parseLineaLarasaInline($line);
      if ($fila !== null) {
        $filas[] = $fila;
        continue;
      }

      $fila = $this->parseLineaLarasaEspacios($line);
      if ($fila !== null) {
        $filas[] = $fila;
        continue;
      }

      if (preg_match(
        '/^(\d{3,14})\s+(\d+[.,]\d+)\s+(\d+[.,]\d+)/u',
        $line,
        $m
      )) {
        $fila = $this->filaDesdeMatch($m[1], $m[3], $m[2], $this->extraerDescripcionCola($line));
        if ($fila !== null) {
          $filas[] = $fila;
          continue;
        }
      }

      if (preg_match(
        '/^(\d{3,14})\s+(\d+[.,]\d+)\s+(\d+[.,]\d+)\s+(\d+[.,]\d+)/u',
        $line,
        $m
      )) {
        $fila = $this->filaDesdeMatch($m[1], $m[2], $m[3], $this->extraerDescripcionCola($line));
        if ($fila !== null) {
          $filas[] = $fila;
        }
      }
    }

    $filas = $this->deduplicarFilas(array_merge($filas, $this->extraerFilasPatronGlobal($texto)));

    return $filas;
  }

  /**
   * Busca líneas tipo Larasa en todo el texto (si el PDF no conserva saltos de línea).
   *
   * @return list<array{codigo: string, cantidad: float, precio: float, descripcion: string, dto: float}>
   */
  private function extraerFilasPatronGlobal(string $texto): array
  {
    $filas = [];
    $patterns = [
      '/(\d{3,14})[\t]+(\d+[.,]\d+)[\t]+(\d+[.,]\d+)/u',
      '/(?<![\d])(\d{3,14})[\t ]+(\d+[.,]\d+)[\t ]+(\d+[.,]\d+)/u',
    ];
    foreach ($patterns as $pat) {
      if (!preg_match_all($pat, $texto, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        continue;
      }
      foreach ($matches as $m) {
        $codigo = (string) ($m[1][0] ?? '');
        $precioRaw = (string) ($m[2][0] ?? '');
        $cantRaw = (string) ($m[3][0] ?? '');
        $matchText = (string) ($m[0][0] ?? '');
        $pos = (int) ($m[0][1] ?? 0);
        $desc = $this->descripcionTrasCoincidencia($texto, $pos, $matchText);
        $fila = $this->filaDesdeMatch($codigo, $cantRaw, $precioRaw, $desc);
        if ($fila !== null) {
          $filas[] = $fila;
        }
      }
    }

    if (!preg_match_all(
      '/(?<![\d])(\d{3,14})\s+(.+?)\s+(-?\d+[.,]\d+)\s+(-?\d+[.,]\d+)\s+(-?\d+[.,]\d+)(?:\s+-?\d+[.,]\d+)?/u',
      $texto,
      $inline,
      PREG_SET_ORDER
    )) {
      return $filas;
    }
    foreach ($inline as $m) {
      $desc = trim((string) ($m[2] ?? ''));
      if ($desc === '' || !preg_match('/[\p{L}]{2,}/u', $desc) || preg_match('/^Albar[aá]n\s/i', $desc)) {
        continue;
      }
      $fila = $this->filaDesdeMatch((string) $m[1], (string) $m[4], (string) $m[3], $desc);
      if ($fila !== null) {
        $filas[] = $fila;
      }
    }

    return $filas;
  }

  private function descripcionTrasCoincidencia(string $texto, int $pos, string $matchText): string
  {
    $cola = mb_substr($texto, $pos + mb_strlen($matchText), 100);
    $cola = trim($cola);
    if ($cola === '') {
      return '';
    }
    if (preg_match('/[\t ]*([\p{L}][\p{L}\p{N}\s.\-]{2,48})/u', $cola, $dm)) {
      $d = trim($dm[1]);
      $d = preg_replace('/\d+[.,]\d+.*$/u', '', $d) ?? $d;
      return trim($d);
    }
    return $this->extraerDescripcionCola($cola);
  }

  /**
   * Factura Larasa en una línea: código + descripción + P.U. + tab + unidades + importe + IVA.
   *
   * @return array{codigo: string, cantidad: float, precio: float, descripcion: string, dto: float}|null
   */
  /**
   * Larasa sin tabuladores (solo espacios): factura y albarán columnar.
   *
   * @return array{codigo: string, cantidad: float, precio: float, descripcion: string, dto: float}|null
   */
  private function parseLineaLarasaEspacios(string $line): ?array
  {
    if (preg_match(
      '/^(\d{3,14})\s+(.+?)\s+(-?\d+[.,]\d+)\s+(-?\d+[.,]\d+)\s+(-?\d+[.,]\d+)(?:\s+-?\d+[.,]\d+)?\s*$/u',
      $line,
      $m
    )) {
      $desc = trim((string) $m[2]);
      if ($desc !== '' && preg_match('/[\p{L}]{2,}/u', $desc) && !preg_match('/^Albar[aá]n\s/i', $desc)) {
        return $this->filaDesdeMatch((string) $m[1], (string) $m[4], (string) $m[3], $desc);
      }
    }

    if (preg_match(
      '/^(\d{3,14})\s+(\d+[.,]\d+)\s+(\d+[.,]\d+)\s+(\d+[.,]\d+)\s+(\d+[.,]\d+)\s+(\d+[.,]\d+)\s+(.+)$/u',
      $line,
      $m
    )) {
      return $this->filaDesdeMatch($m[1], $m[3], $m[2], trim($m[7]));
    }

    if (preg_match(
      '/^(\d{3,14})\s+(\d+[.,]\d+)\s+(\d+[.,]\d+)(?:\s+\d+[.,]\d+){1,3}\s+(.+)$/u',
      $line,
      $m
    )) {
      return $this->filaDesdeMatch($m[1], $m[3], $m[2], trim($m[4]));
    }

    return null;
  }

  private function parseLineaLarasaInline(string $line): ?array
  {
    if (!preg_match(
      '/^(\d{3,14})\s+(.+?)\s+(-?\d+[.,]\d+)\t(-?\d+[.,]\d+)/u',
      $line,
      $m
    )) {
      return null;
    }
    $desc = trim((string) $m[2]);
    if ($desc === '' || preg_match('/^Albar[aá]n\s/i', $desc)) {
      return null;
    }
    if (!preg_match('/[A-Za-zÁÉÍÓÚÜÑáéíóúüñ]{2,}/u', $desc)) {
      return null;
    }
    return $this->filaDesdeMatch(
      (string) $m[1],
      (string) $m[4],
      (string) $m[3],
      $desc
    );
  }

  /**
   * @return array{codigo: string, cantidad: float, precio: float, descripcion: string, dto: float}|null
   */
  private function parseLineaTabulada(string $line): ?array
  {
    $parts = array_values(array_filter(array_map('trim', preg_split('/\t+/u', $line) ?: []), static fn ($p) => $p !== ''));
    if ($parts === []) {
      return null;
    }

    $codigo = null;
    $codigoIdx = null;
    foreach ($parts as $i => $part) {
      if (preg_match('/^\d{3,14}$/u', $part)) {
        $codigo = $part;
        $codigoIdx = $i;
        break;
      }
    }
    if ($codigo === null) {
      return null;
    }

    $descripcion = '';
    $idx = $codigoIdx + 1;
    if (isset($parts[$idx]) && preg_match('/[A-Za-zÁÉÍÓÚÜÑáéíóúüñ]{2,}/u', $parts[$idx])) {
      $descripcion = trim($parts[$idx]);
      $idx++;
    }

    $precio = 0.0;
    $cantidad = 1.0;
    for (; $idx < count($parts); $idx++) {
      $part = $parts[$idx];
      if ($precio <= 0 && preg_match('/^(-?\d+[.,]\d+)$/u', $part, $mp)) {
        $precio = $this->numeroEs($mp[1]);
        continue;
      }
      if (preg_match('/^(-?\d+[.,]\d+)/u', $part, $mq)) {
        $q = $this->numeroEs($mq[1]);
        if ($q !== 0.0 && abs($q) <= 9999) {
          $cantidad = $q;
        }
        break;
      }
    }

    if ($descripcion === '') {
      for ($i = count($parts) - 1; $i > $codigoIdx; $i--) {
        $p = $parts[$i];
        if (preg_match('/[A-Za-zÁÉÍÓÚÜÑáéíóúüñ]{3,}/u', $p)) {
          $descripcion = preg_replace('/\d+[.,]\d+.*$/u', '', $p) ?? $p;
          $descripcion = trim($descripcion);
          if ($descripcion !== '') {
            break;
          }
        }
      }
    }

    return $this->filaDesdeMatch($codigo, (string) $cantidad, (string) $precio, $descripcion);
  }

  private function esLineaCabecera(string $line): bool
  {
    $lower = mb_strtolower($line);
    if (preg_match('/^(unidades|descripci[oó]n|art[ií]culo|precio|importe|cif|p[aá]gina|base imponible|albar[aá]n\s)/iu', $lower)) {
      return true;
    }
    if (preg_match('/^albar[aá]n\s+\d/i', $lower)) {
      return true;
    }
    if (str_contains($lower, 'descripción') && str_contains($lower, 'precio')) {
      return true;
    }
    return false;
  }

  private function extraerDescripcionCola(string $line): string
  {
    if (preg_match('/([A-Za-zÁÉÍÓÚÜÑáéíóúüñ][A-Za-zÁÉÍÓÚÜÑáéíóúüñ0-9\s\-.,]{2,})$/u', $line, $m)) {
      return trim($m[1]);
    }
    return '';
  }

  /**
   * @return array{codigo: string, cantidad: float, precio: float, descripcion: string, dto: float}|null
   */
  private function filaDesdeMatch(string $codigo, string $cantidadRaw, string $precioRaw, string $descripcion): ?array
  {
    $codigo = trim($codigo);
    if ($codigo === '' || !preg_match('/^\d{3,14}$/u', $codigo)) {
      if (!preg_match('/^[A-Z0-9][A-Z0-9.-]{2,22}$/iu', $codigo)) {
        return null;
      }
    }
    $cantidad = $this->numeroEs($cantidadRaw);
    $precio = $this->numeroEs($precioRaw);
    if ($cantidad <= 0) {
      $cantidad = 1;
    }
    return [
      'codigo' => $codigo,
      'cantidad' => $cantidad,
      'precio' => $precio < 0 ? $precio : max(0, $precio),
      'descripcion' => trim($descripcion),
      'dto' => 0,
    ];
  }

  /**
   * @param list<array{codigo: string, cantidad: float, precio: float, descripcion: string, dto: float}> $filas
   * @return list<array{codigo: string, cantidad: float, precio: float, descripcion: string, dto: float}>
   */
  private function deduplicarFilas(array $filas): array
  {
    $out = [];
    $seen = [];
    foreach ($filas as $f) {
      $key = $f['codigo'] . '|' . $f['cantidad'] . '|' . $f['precio'];
      if (isset($seen[$key])) {
        continue;
      }
      $seen[$key] = true;
      $out[] = $f;
    }
    return $out;
  }

  /**
   * @param list<array{codigo: string, cantidad: float, precio: float, descripcion: string, dto: float}> $filas
   * @return list<array{codigo: string, cantidad: float, precio: float, descripcion: string, dto: float}>
   */
  private function filtrarFilasPlausibles(array $filas): array
  {
    $out = [];
    foreach ($filas as $f) {
      if ($this->filaEsPlausibleProducto($f)) {
        $out[] = $f;
      }
    }
    return $out;
  }

  /**
   * @param array{codigo: string, cantidad: float, precio: float, descripcion: string, dto: float} $f
   */
  private function filaEsPlausibleProducto(array $f): bool
  {
    $codigo = trim($f['codigo']);
    $desc = mb_strtolower(trim($f['descripcion']));
    $precio = (float) ($f['precio'] ?? 0);

    if (strlen($codigo) < 3) {
      return false;
    }
    if (strlen($codigo) < 6 && trim($f['descripcion']) === '') {
      return false;
    }
    if ($precio <= 0.0001 && $precio >= -0.0001) {
      return false;
    }
    $basura = ['albaran', 'albarán', 'tel', 'fax', 'cif', 'n.i.f', 'página', 'cliente', 'fecha'];
    foreach ($basura as $b) {
      if ($desc === $b || str_starts_with($desc, $b . ' ')) {
        return false;
      }
    }
    if ($desc === '' && strlen($codigo) < 10) {
      return false;
    }
    return true;
  }

  private function numeroEs(string $s): float
  {
    $t = trim($s);
    if ($t === '') {
      return 0.0;
    }
    if (preg_match('/^\d{1,3}(\.\d{3})+,\d+$/', $t)) {
      $t = str_replace('.', '', $t);
      $t = str_replace(',', '.', $t);
    } else {
      $t = str_replace(' ', '', $t);
      if (substr_count($t, ',') === 1 && substr_count($t, '.') === 0) {
        $t = str_replace(',', '.', $t);
      } elseif (substr_count($t, '.') === 1 && substr_count($t, ',') === 0) {
        // decimal anglosajón
      } else {
        $t = str_replace('.', '', $t);
        $t = str_replace(',', '.', $t);
      }
    }
    $n = (float) $t;
    return is_finite($n) ? $n : 0.0;
  }
}
