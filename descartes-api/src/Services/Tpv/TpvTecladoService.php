<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Tpv;

use PDO;

/**
 * Teclado táctil legacy DefPlus, tal como lo pinta frmVenta (Venta genérica / Garden).
 *
 * - Nivel `000`: 8 botones de grupo (columna izquierda). Pulsar el grupo i abre el nivel `00(i+1)`.
 * - Resto de niveles: 14 botones en 2 columnas x 7 filas.
 * - `H_TECLA` no es fila/columna: legacy usa dos tablas fijas (cal_posniv / cal_posplu).
 * - Un botón de grupo (M/F) abre el nivel = nivel actual + código de tecla (3 cifras).
 * - Las pantallas de complementos (`O###` obligatorias, `P###` opcionales) viven siempre en la plantilla 001.
 */
final class TpvTecladoService
{
  /** cal_posniv: posición 0..7 del grupo -> H_TECLA. */
  private const TECLAS_GRUPO = [275, 278, 315, 318, 355, 358, 395, 398];

  /** cal_posplu: posición 0..13 del botón -> H_TECLA. */
  private const TECLAS_BOTON = [301, 303, 305, 307, 309, 311, 313, 381, 383, 385, 387, 389, 391, 393];

  private const NIVEL_GRUPOS = '000';
  private const PLANTILLA_COMPLEMENTOS = '001';
  private const LONGITUD_NIVEL = 9;

  /** H_VALOR2 legacy: artículo / línea de texto. */
  private const VALOR2_ARTICULO = 32;
  private const VALOR2_TEXTO = 235;

  /** Color de sistema "cara de botón" que guarda el editor legacy cuando no se elige color. */
  private const COLOR_SISTEMA_BOTON = -2147483633;

  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  /**
   * Grupos (nivel 000) y botones del nivel pedido.
   *
   * @return array<string, mixed>|null
   */
  public function obtenerNivel(string $general, string $nivel): ?array
  {
    $general = trim($general);
    $nivel = trim($nivel);
    if ($general === '' || $nivel === '') {
      throw new \InvalidArgumentException('Teclado y nivel son obligatorios');
    }

    $stCab = $this->pdo->prepare('SELECT H_NOMBRE FROM DefPlusC WHERE RTRIM(H_GENERAL) = :g');
    $stCab->execute(['g' => $general]);
    $cab = $stCab->fetch(PDO::FETCH_ASSOC);
    if (!$cab) {
      return null;
    }

    $grupos = [];
    foreach ($this->filas($general, self::NIVEL_GRUPOS) as $row) {
      $posicion = array_search((int) $row['H_TECLA'], self::TECLAS_GRUPO, true);
      if ($posicion === false) {
        continue;
      }
      $grupos[] = $this->mapGrupo($row, (int) $posicion);
    }

    $generalBotones = $this->esComplemento($nivel) ? self::PLANTILLA_COMPLEMENTOS : $general;
    $botones = [];
    foreach ($this->filas($generalBotones, $nivel) as $row) {
      $posicion = array_search((int) $row['H_TECLA'], self::TECLAS_BOTON, true);
      if ($posicion === false) {
        continue;
      }
      $botones[] = $this->mapBoton($row, (int) $posicion, $nivel);
    }

    return [
      'general' => $general,
      'nivel' => $nivel,
      'nombre' => $this->trimOrNull($cab['H_NOMBRE'] ?? null),
      'grupos' => $grupos,
      'botones' => $botones,
      'puedeTenerGrupos' => strlen($nivel) + 3 <= self::LONGITUD_NIVEL && !$this->esComplemento($nivel),
    ];
  }

  /**
   * Crea o reemplaza el botón de una posición. En el nivel 000 la posición es un grupo
   * (0..7) y solo se guardan textos, colores e imagen.
   *
   * @param array<string, mixed> $data
   * @return string|null Nivel que abre el botón cuando es un grupo
   */
  public function guardarBoton(string $general, string $nivel, int $posicion, array $data): ?string
  {
    $general = trim($general);
    $nivel = trim($nivel);
    $esGrupoRaiz = $nivel === self::NIVEL_GRUPOS;
    $tecla = $this->teclaDePosicion($nivel, $posicion);
    if ($general === '' || $nivel === '' || $this->esComplemento($nivel)) {
      throw new \InvalidArgumentException('Teclado y nivel válidos son obligatorios');
    }

    $etiquetas = [];
    foreach ([1, 2, 3] as $i) {
      $etiquetas[$i] = mb_substr(trim((string) ($data['etiqueta' . $i] ?? '')), 0, 12);
    }
    $icono = trim((string) ($data['icono'] ?? ''));
    if (mb_strlen($icono) > 100) {
      throw new \InvalidArgumentException('La ruta de la imagen no puede superar 100 caracteres');
    }
    $colorFondo = $this->cssAColorOle($data['colorFondo'] ?? null);
    $colorTexto = $this->cssAColorOle($data['colorTexto'] ?? null);

    $actual = $this->fila($general, $nivel, $tecla);

    if ($esGrupoRaiz) {
      if ($etiquetas[1] === '' && $etiquetas[2] === '' && $etiquetas[3] === '' && $icono === '') {
        throw new \InvalidArgumentException('El grupo necesita un texto o una imagen');
      }
      $valores = [
        'clase' => 'M',
        'valor1' => '',
        'valor2' => 0,
        'precio' => ' ',
      ];
      $destino = str_pad((string) ($posicion + 1), 3, '0', STR_PAD_LEFT);
    } else {
      $tipo = strtolower(trim((string) ($data['tipo'] ?? '')));
      $destino = null;
      switch ($tipo) {
        case 'articulo':
          $articulo = trim((string) ($data['articulo'] ?? ''));
          if ($articulo === '' || mb_strlen($articulo) > 18) {
            throw new \InvalidArgumentException('Artículo obligatorio');
          }
          if ($etiquetas[1] === '' && $etiquetas[2] === '' && $etiquetas[3] === '') {
            $etiquetas[1] = mb_substr($articulo, 0, 12);
          }
          $valores = [
            'clase' => 'C',
            'valor1' => $articulo,
            'valor2' => self::VALOR2_ARTICULO,
            'precio' => !empty($data['pedirPrecio']) ? '0' : ' ',
          ];
          break;
        case 'texto':
          $texto = trim((string) ($data['texto'] ?? ''));
          if ($texto === '' || mb_strlen($texto) > 18) {
            throw new \InvalidArgumentException('El texto es obligatorio (máximo 18 caracteres)');
          }
          if ($etiquetas[1] === '' && $etiquetas[2] === '' && $etiquetas[3] === '') {
            $etiquetas[1] = mb_substr($texto, 0, 12);
          }
          $valores = [
            'clase' => 'C',
            'valor1' => $texto,
            'valor2' => self::VALOR2_TEXTO,
            'precio' => ' ',
          ];
          break;
        case 'grupo':
          if (strlen($nivel) + 3 > self::LONGITUD_NIVEL) {
            throw new \InvalidArgumentException('No se pueden crear más niveles de grupos dentro de este grupo');
          }
          if ($etiquetas[1] === '' && $etiquetas[2] === '' && $etiquetas[3] === '' && $icono === '') {
            throw new \InvalidArgumentException('El grupo necesita un texto o una imagen');
          }
          $claseActual = strtoupper(trim((string) ($actual['H_M_C_MF'] ?? '')));
          $valores = [
            'clase' => $claseActual === 'M' ? 'M' : 'F',
            'valor1' => '',
            'valor2' => 0,
            'precio' => ' ',
          ];
          $destino = $nivel . str_pad((string) $tecla, 3, '0', STR_PAD_LEFT);
          break;
        default:
          throw new \InvalidArgumentException('El tipo debe ser artículo, grupo o texto');
      }
    }

    $params = [
      'e1' => $etiquetas[1],
      'e2' => $etiquetas[2],
      'e3' => $etiquetas[3],
      'valor1' => $valores['valor1'],
      'valor2' => $valores['valor2'],
      'clase' => $valores['clase'],
      'precio' => $valores['precio'],
      'color' => $colorFondo ?? self::COLOR_SISTEMA_BOTON,
      'collit' => $colorTexto ?? 0,
      'icono' => $icono,
      'g' => $general,
      'n' => $nivel,
      't' => $tecla,
    ];

    if ($actual !== null) {
      $this->pdo->prepare(
        'UPDATE DefPlus
         SET H_ETIQUET1 = :e1, H_ETIQUET2 = :e2, H_ETIQUET3 = :e3,
             H_VALOR1 = :valor1, H_VALOR2 = :valor2, H_M_C_MF = :clase, H_PRECIO = :precio,
             H_COLOR = :color, H_COLLIT = :collit, H_ICON = :icono
         WHERE RTRIM(H_GENERAL) = :g AND RTRIM(H_NIVEL) = :n AND H_TECLA = :t'
      )->execute($params);
      return $destino;
    }

    $this->pdo->prepare(
      "INSERT INTO DefPlus
        (H_GENERAL, H_NIVEL, H_TECLA, H_ANCHO, H_ALTO,
         H_ETIQUET1, H_TAM1, H_ETIQUET2, H_TAM2, H_ETIQUET3, H_TAM3,
         H_VALOR1, H_VALOR2, H_COLOR, H_COLLIT, H_FILL, H_M_C_MF, H_ICON,
         H_NIVOP, H_NIVOB, H_PRECIO, H_PRECIO2, H_NIVOB2, H_NIVOB3, H_NIVOB4,
         H_NIVOP2, H_NIVOP3, H_NIVOP4, H_PLATO, H_COLHYPER)
       VALUES
        (:g, :n, :t, 3, 2,
         :e1, 0, :e2, 0, :e3, 0,
         :valor1, :valor2, :color, :collit, 0, :clase, :icono,
         '', '', :precio, ' ', '', '', '',
         '', '', '', 0, 0)"
    )->execute($params);

    return $destino;
  }

  /**
   * Intercambia dos posiciones de un nivel. Si alguna es un grupo, sus subniveles cambian de
   * prefijo con ella para que el grupo siga abriendo los mismos botones.
   */
  public function intercambiar(string $general, string $nivel, int $origen, int $destino): void
  {
    $general = trim($general);
    $nivel = trim($nivel);
    if ($origen === $destino) {
      return;
    }
    if ($this->esComplemento($nivel)) {
      throw new \InvalidArgumentException('No se pueden mover botones de complementos');
    }
    $teclaA = $this->teclaDePosicion($nivel, $origen);
    $teclaB = $this->teclaDePosicion($nivel, $destino);
    [$prefijoA, $prefijoB] = $nivel === self::NIVEL_GRUPOS
      ? [str_pad((string) ($origen + 1), 3, '0', STR_PAD_LEFT), str_pad((string) ($destino + 1), 3, '0', STR_PAD_LEFT)]
      : [$nivel . str_pad((string) $teclaA, 3, '0', STR_PAD_LEFT), $nivel . str_pad((string) $teclaB, 3, '0', STR_PAD_LEFT)];

    $this->pdo->beginTransaction();
    try {
      $mover = $this->pdo->prepare(
        'UPDATE DefPlus SET H_TECLA = :nueva
         WHERE RTRIM(H_GENERAL) = :g AND RTRIM(H_NIVEL) = :n AND H_TECLA = :vieja'
      );
      $mover->execute(['nueva' => -1, 'g' => $general, 'n' => $nivel, 'vieja' => $teclaA]);
      $mover->execute(['nueva' => $teclaA, 'g' => $general, 'n' => $nivel, 'vieja' => $teclaB]);
      $mover->execute(['nueva' => $teclaB, 'g' => $general, 'n' => $nivel, 'vieja' => -1]);

      $temporal = 'T' . substr($prefijoA, 1);
      $this->renombrarPrefijo($general, $prefijoA, $temporal);
      $this->renombrarPrefijo($general, $prefijoB, $prefijoA);
      $this->renombrarPrefijo($general, $temporal, $prefijoB);

      $this->pdo->commit();
    } catch (\Throwable $e) {
      if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      throw $e;
    }
  }

  /**
   * Borra el botón. Si es un grupo, borra también los botones que abría.
   */
  public function borrarBoton(string $general, string $nivel, int $posicion): void
  {
    $general = trim($general);
    $nivel = trim($nivel);
    if ($this->esComplemento($nivel)) {
      throw new \InvalidArgumentException('No se pueden borrar botones de complementos');
    }
    $tecla = $this->teclaDePosicion($nivel, $posicion);
    $actual = $this->fila($general, $nivel, $tecla);
    if ($actual === null) {
      return;
    }

    $prefijo = null;
    if ($nivel === self::NIVEL_GRUPOS) {
      $prefijo = str_pad((string) ($posicion + 1), 3, '0', STR_PAD_LEFT);
    } elseif (in_array(strtoupper(trim((string) ($actual['H_M_C_MF'] ?? ''))), ['M', 'F'], true)) {
      $prefijo = $nivel . str_pad((string) $tecla, 3, '0', STR_PAD_LEFT);
    }

    $this->pdo->beginTransaction();
    try {
      $this->pdo->prepare(
        'DELETE FROM DefPlus WHERE RTRIM(H_GENERAL) = :g AND RTRIM(H_NIVEL) = :n AND H_TECLA = :t'
      )->execute(['g' => $general, 'n' => $nivel, 't' => $tecla]);
      if ($prefijo !== null) {
        $largo = strlen($prefijo);
        $this->pdo->prepare(
          "DELETE FROM DefPlus WHERE RTRIM(H_GENERAL) = :g AND LEFT(RTRIM(H_NIVEL), {$largo}) = :p"
        )->execute(['g' => $general, 'p' => $prefijo]);
      }
      $this->pdo->commit();
    } catch (\Throwable $e) {
      if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      throw $e;
    }
  }

  /** Cuántos botones hay dentro de un grupo (para avisar antes de borrarlo). */
  public function contarSubniveles(string $general, string $nivel, int $posicion): int
  {
    $general = trim($general);
    $nivel = trim($nivel);
    $tecla = $this->teclaDePosicion($nivel, $posicion);
    $prefijo = $nivel === self::NIVEL_GRUPOS
      ? str_pad((string) ($posicion + 1), 3, '0', STR_PAD_LEFT)
      : $nivel . str_pad((string) $tecla, 3, '0', STR_PAD_LEFT);
    $largo = strlen($prefijo);
    $st = $this->pdo->prepare(
      "SELECT COUNT(*) FROM DefPlus WHERE RTRIM(H_GENERAL) = :g AND LEFT(RTRIM(H_NIVEL), {$largo}) = :p"
    );
    $st->execute(['g' => $general, 'p' => $prefijo]);
    return (int) $st->fetchColumn();
  }

  private function renombrarPrefijo(string $general, string $desde, string $hasta): void
  {
    $largo = strlen($desde);
    $inicio = $largo + 1;
    $this->pdo->prepare(
      "UPDATE DefPlus
       SET H_NIVEL = :hasta + SUBSTRING(RTRIM(H_NIVEL), {$inicio}, 9)
       WHERE RTRIM(H_GENERAL) = :g AND LEFT(RTRIM(H_NIVEL), {$largo}) = :desde"
    )->execute([
      'hasta' => $hasta,
      'g' => $general,
      'desde' => $desde,
    ]);
  }

  private function teclaDePosicion(string $nivel, int $posicion): int
  {
    $tabla = $nivel === self::NIVEL_GRUPOS ? self::TECLAS_GRUPO : self::TECLAS_BOTON;
    if (!isset($tabla[$posicion])) {
      throw new \InvalidArgumentException('Posición de botón no válida');
    }
    return $tabla[$posicion];
  }

  private function esComplemento(string $nivel): bool
  {
    $primera = strtoupper(substr($nivel, 0, 1));
    return $primera === 'O' || $primera === 'P';
  }

  /** @return list<array<string, mixed>> */
  private function filas(string $general, string $nivel): array
  {
    $st = $this->pdo->prepare(
      'SELECT H_TECLA, H_NIVEL, H_ETIQUET1, H_ETIQUET2, H_ETIQUET3, H_VALOR1, H_VALOR2,
              H_COLOR, H_COLLIT, H_ICON, H_TARIFA, H_M_C_MF, H_PRECIO,
              H_NIVOB, H_NIVOB2, H_NIVOB3, H_NIVOB4, H_NIVOP, H_NIVOP2, H_NIVOP3, H_NIVOP4
       FROM DefPlus
       WHERE RTRIM(H_GENERAL) = :g AND RTRIM(H_NIVEL) = :n
       ORDER BY H_TECLA ASC'
    );
    $st->execute(['g' => $general, 'n' => $nivel]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }

  /** @return array<string, mixed>|null */
  private function fila(string $general, string $nivel, int $tecla): ?array
  {
    $st = $this->pdo->prepare(
      'SELECT H_M_C_MF FROM DefPlus
       WHERE RTRIM(H_GENERAL) = :g AND RTRIM(H_NIVEL) = :n AND H_TECLA = :t'
    );
    $st->execute(['g' => $general, 'n' => $nivel, 't' => $tecla]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  }

  /**
   * @param array<string, mixed> $row
   * @return array<string, mixed>
   */
  private function mapGrupo(array $row, int $posicion): array
  {
    $base = $this->camposComunes($row, $posicion);
    $base['tipo'] = 'grupo';
    $base['nivelDestino'] = str_pad((string) ($posicion + 1), 3, '0', STR_PAD_LEFT);
    // Legacy: un grupo sin texto ni imagen no se muestra.
    $base['visible'] = $base['etiqueta1'] !== null || $base['etiqueta2'] !== null
      || $base['etiqueta3'] !== null || $base['icono'] !== null;
    return $base;
  }

  /**
   * @param array<string, mixed> $row
   * @return array<string, mixed>
   */
  private function mapBoton(array $row, int $posicion, string $nivel): array
  {
    $base = $this->camposComunes($row, $posicion);
    $clase = strtoupper((string) $this->trimOrNull($row['H_M_C_MF'] ?? null));
    $valor1 = $this->trimOrNull($row['H_VALOR1'] ?? null);
    $valor2 = (int) round((float) ($row['H_VALOR2'] ?? 0));

    $tipo = 'vacio';
    $nivelDestino = null;
    if ($clase === 'C' && $valor2 === self::VALOR2_TEXTO) {
      $tipo = 'texto';
    } elseif ($clase === 'C' && $valor1 !== null) {
      $tipo = 'articulo';
    } elseif ($clase === 'M' || $clase === 'F') {
      $tipo = $clase === 'F' ? 'grupoVuelta' : 'grupo';
      $nivelDestino = $nivel . str_pad((string) $base['tecla'], 3, '0', STR_PAD_LEFT);
    }

    $base['tipo'] = $tipo;
    $base['articulo'] = $tipo === 'articulo' ? $valor1 : null;
    $base['texto'] = $tipo === 'texto' ? $valor1 : null;
    $base['nivelDestino'] = $nivelDestino;
    $base['pedirPrecio'] = trim((string) ($row['H_PRECIO'] ?? '')) === '0';
    $base['obligatorios'] = $this->listaNiveles($row, 'H_NIVOB');
    $base['opcionales'] = $this->listaNiveles($row, 'H_NIVOP');
    $base['tarifa'] = isset($row['H_TARIFA']) && (float) $row['H_TARIFA'] > 0 ? (float) $row['H_TARIFA'] : null;
    $base['visible'] = true;
    return $base;
  }

  /**
   * @param array<string, mixed> $row
   * @return array<string, mixed>
   */
  private function camposComunes(array $row, int $posicion): array
  {
    $fondo = $this->colorOleACss($row['H_COLOR'] ?? null);
    return [
      'posicion' => $posicion,
      'tecla' => (int) $row['H_TECLA'],
      'etiqueta1' => $this->trimOrNull($row['H_ETIQUET1'] ?? null),
      'etiqueta2' => $this->trimOrNull($row['H_ETIQUET2'] ?? null),
      'etiqueta3' => $this->trimOrNull($row['H_ETIQUET3'] ?? null),
      'colorFondo' => $fondo,
      // Con el color de sistema, el texto también es el de sistema.
      'colorTexto' => $fondo === null ? null : $this->colorOleACss($row['H_COLLIT'] ?? null, true),
      'icono' => $this->trimOrNull($row['H_ICON'] ?? null),
    ];
  }

  /**
   * Legacy concatena H_NIVOx..H_NIVOx4, cada uno de 3 caracteres.
   *
   * @param array<string, mixed> $row
   * @return list<string>
   */
  private function listaNiveles(array $row, string $prefijo): array
  {
    $out = [];
    foreach (['', '2', '3', '4'] as $sufijo) {
      $v = trim((string) ($row[$prefijo . $sufijo] ?? ''));
      if ($v !== '') {
        $out[] = $v;
      }
    }
    return $out;
  }

  /**
   * Color OLE de VB (&H00BBGGRR) a hex CSS. Los colores de sistema (valor negativo) y 0
   * en el fondo no tienen equivalente: se devuelven como null.
   */
  private function colorOleACss($valor, bool $permitirNegro = false): ?string
  {
    if ($valor === null || trim((string) $valor) === '') {
      return null;
    }
    $entero = (int) round((float) $valor);
    if ($entero < 0 || ($entero === 0 && !$permitirNegro) || $entero > 0xFFFFFF) {
      return null;
    }
    $r = $entero & 0xFF;
    $g = ($entero >> 8) & 0xFF;
    $b = ($entero >> 16) & 0xFF;
    return sprintf('#%02x%02x%02x', $r, $g, $b);
  }

  /** Hex CSS (#rrggbb) a color OLE de VB. */
  private function cssAColorOle($valor): ?int
  {
    $s = strtolower(trim((string) ($valor ?? '')));
    if (!preg_match('/^#([0-9a-f]{6})$/', $s, $m)) {
      return null;
    }
    $r = hexdec(substr($m[1], 0, 2));
    $g = hexdec(substr($m[1], 2, 2));
    $b = hexdec(substr($m[1], 4, 2));
    return (int) ($r + ($g << 8) + ($b << 16));
  }

  private function trimOrNull($value): ?string
  {
    if ($value === null) {
      return null;
    }
    $s = trim((string) $value);
    return $s === '' ? null : $s;
  }
}
