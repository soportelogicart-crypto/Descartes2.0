<?php

declare(strict_types=1);

namespace Descartes\Api\Services\Tpv;

use PDO;

/**
 * Teclado táctil legacy DefPlus (006 US2).
 *
 * H_TECLA codifica la posición en una rejilla de 20 columnas: fila = tecla / 20, columna = tecla % 20.
 * H_M_C_MF indica el tipo de botón: 'C' código de artículo, 'F' familia, 'M' macrofamilia.
 * Los botones de familia del teclado de pruebas no traen H_NIVOP; el nivel destino se deduce por
 * el orden del botón dentro del nivel (research R-002 regla 3) y solo si ese nivel existe en BD.
 */
final class TpvTecladoService
{
  private const COLUMNAS_LEGACY = 20;

  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  /**
   * @return array<string, mixed>|null
   */
  public function obtenerNivel(string $general, string $nivel): ?array
  {
    $general = trim($general);
    $nivel = trim($nivel);
    if ($general === '' || $nivel === '') {
      throw new \InvalidArgumentException('Teclado y nivel son obligatorios');
    }

    $stCab = $this->pdo->prepare('SELECT H_NOMBRE FROM DefPlusC WHERE H_GENERAL = :g');
    $stCab->execute(['g' => $general]);
    $cab = $stCab->fetch(PDO::FETCH_ASSOC);
    if (!$cab) {
      return null;
    }

    $nivelesExistentes = $this->nivelesConBotones($general);

    $st = $this->pdo->prepare(
      'SELECT H_TECLA, H_NIVEL, H_ETIQUET1, H_ETIQUET2, H_ETIQUET3,
              H_ANCHO, H_ALTO, H_VALOR1, H_NIVOP, H_NIVOB, H_COLOR, H_COLLIT,
              H_ICON, H_TARIFA, H_M_C_MF
       FROM DefPlus
       WHERE H_GENERAL = :g AND H_NIVEL = :n
       ORDER BY H_TECLA ASC'
    );
    $st->execute(['g' => $general, 'n' => $nivel]);

    $filas = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $filas[] = $row;
    }

    $botones = [];
    foreach ($filas as $indice => $row) {
      $botones[] = $this->mapBoton($row, $indice + 1, $nivel, $nivelesExistentes);
    }

    return [
      'general' => $general,
      'nivel' => $nivel,
      'nombre' => $this->trimOrNull($cab['H_NOMBRE'] ?? null),
      'columnas' => self::COLUMNAS_LEGACY,
      'botones' => $botones,
    ];
  }

  /** @return list<array{nivel: string, etiqueta: string, botones: int}> */
  public function listarNiveles(string $general): array
  {
    $general = trim($general);
    if ($general === '') {
      throw new \InvalidArgumentException('Teclado obligatorio');
    }

    $st = $this->pdo->prepare(
      "SELECT d.H_NIVEL, COUNT(*) AS Botones,
              COALESCE(
                (SELECT TOP 1 NULLIF(LTRIM(RTRIM(p.H_ETIQUET1)), '')
                 FROM DefPlus p
                 WHERE p.H_GENERAL = d.H_GENERAL AND p.H_NIVOP = d.H_NIVEL
                 ORDER BY p.H_TECLA),
                'Grupo ' + d.H_NIVEL
              ) AS Etiqueta
       FROM DefPlus d
       WHERE d.H_GENERAL = :g
       GROUP BY d.H_GENERAL, d.H_NIVEL
       ORDER BY d.H_NIVEL"
    );
    $st->execute(['g' => $general]);

    $niveles = [];
    $vistos = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $nivel = trim((string) $row['H_NIVEL']);
      $vistos[] = $nivel;
      $niveles[] = [
        'nivel' => $nivel,
        'etiqueta' => trim((string) $row['Etiqueta']),
        'botones' => (int) $row['Botones'],
      ];
    }

    // Un grupo recién creado todavía no tiene teclas: solo existe como destino.
    $stVacios = $this->pdo->prepare(
      "SELECT DISTINCT LTRIM(RTRIM(H_NIVOP)) AS Nivel,
              NULLIF(LTRIM(RTRIM(H_ETIQUET1)), '') AS Etiqueta
       FROM DefPlus
       WHERE H_GENERAL = :g AND H_NIVOP IS NOT NULL AND LTRIM(RTRIM(H_NIVOP)) <> ''"
    );
    $stVacios->execute(['g' => $general]);
    while ($row = $stVacios->fetch(PDO::FETCH_ASSOC)) {
      $nivel = trim((string) $row['Nivel']);
      if ($nivel === '' || in_array($nivel, $vistos, true)) {
        continue;
      }
      $vistos[] = $nivel;
      $niveles[] = [
        'nivel' => $nivel,
        'etiqueta' => trim((string) ($row['Etiqueta'] ?? '')) ?: 'Grupo ' . $nivel,
        'botones' => 0,
      ];
    }

    usort($niveles, static fn(array $a, array $b): int => strcmp($a['nivel'], $b['nivel']));

    return $niveles;
  }

  /**
   * Primer nivel libre para un grupo nuevo. Se numeran desde 900 para no chocar
   * con la heurística de nivel por ordinal de los botones legacy sin H_NIVOP.
   */
  private function siguienteNivelLibre(string $general): string
  {
    $usados = $this->nivelesUsados($general);
    foreach ([range(900, 999), range(2, 899)] as $rango) {
      foreach ($rango as $numero) {
        $candidato = str_pad((string) $numero, 3, '0', STR_PAD_LEFT);
        if (!in_array($candidato, $usados, true)) {
          return $candidato;
        }
      }
    }
    throw new \RuntimeException('No quedan niveles libres en el teclado');
  }

  /** Niveles con teclas propias más los referenciados como destino. @return list<string> */
  private function nivelesUsados(string $general): array
  {
    $st = $this->pdo->prepare(
      "SELECT DISTINCT LTRIM(RTRIM(H_NIVEL)) AS Nivel FROM DefPlus WHERE H_GENERAL = :g1
       UNION
       SELECT DISTINCT LTRIM(RTRIM(H_NIVOP)) FROM DefPlus
       WHERE H_GENERAL = :g2 AND H_NIVOP IS NOT NULL AND LTRIM(RTRIM(H_NIVOP)) <> ''"
    );
    $st->execute(['g1' => $general, 'g2' => $general]);

    $niveles = [];
    while ($row = $st->fetch(PDO::FETCH_NUM)) {
      $nivel = trim((string) $row[0]);
      if ($nivel !== '') {
        $niveles[] = $nivel;
      }
    }
    return $niveles;
  }

  /**
   * Crea o reemplaza una tecla de venta rápida.
   *
   * @param array<string, mixed> $data
   * @return string|null Nivel destino cuando la tecla abre un grupo
   */
  public function guardarBoton(
    string $general,
    string $nivel,
    int $tecla,
    array $data
  ): ?string {
    $general = trim($general);
    $nivel = trim($nivel);
    $tipo = strtolower(trim((string) ($data['tipo'] ?? '')));
    $etiqueta = trim((string) ($data['etiqueta'] ?? ''));
    if ($general === '' || $nivel === '' || $tecla < 0 || $tecla > 32767) {
      throw new \InvalidArgumentException('Teclado, nivel y tecla válidos son obligatorios');
    }
    if (!in_array($tipo, ['articulo', 'nivel'], true)) {
      throw new \InvalidArgumentException('El tipo debe ser artículo o grupo');
    }

    $articulo = null;
    $nivelDestino = null;
    $clase = 'C';
    if ($tipo === 'articulo') {
      $articulo = trim((string) ($data['articulo'] ?? ''));
      if ($articulo === '') {
        throw new \InvalidArgumentException('Artículo obligatorio');
      }
      if ($etiqueta === '') {
        $etiqueta = $articulo;
      }
    } elseif (!empty($data['crearGrupo'])) {
      // Grupo nuevo: nace vacío, sin filas en DefPlus, y se rellena al configurarlo.
      $nivelDestino = $this->siguienteNivelLibre($general);
      $clase = 'F';
      if ($etiqueta === '') {
        $etiqueta = 'Grupo ' . $nivelDestino;
      }
    } else {
      $nivelDestino = trim((string) ($data['nivelDestino'] ?? ''));
      if ($nivelDestino === '' || $nivelDestino === $nivel) {
        throw new \InvalidArgumentException('Seleccione un grupo de destino distinto del actual');
      }
      if (!in_array($nivelDestino, $this->nivelesUsados($general), true)) {
        throw new \InvalidArgumentException('El grupo de destino no existe');
      }
      $clase = 'F';
      if ($etiqueta === '') {
        $etiqueta = 'Grupo ' . $nivelDestino;
      }
    }

    $params = [
      'g' => $general,
      'n' => $nivel,
      't' => $tecla,
      'ancho' => max(1, min(self::COLUMNAS_LEGACY, (int) ($data['ancho'] ?? 4))),
      'alto' => max(1, min(10, (int) ($data['alto'] ?? 1))),
      // DefPlus legacy limita cada línea de etiqueta a 12 caracteres.
      'etiqueta' => mb_substr($etiqueta, 0, 12),
      'articulo' => $articulo,
      'nivop' => $nivelDestino,
      'clase' => $clase,
    ];

    $existe = $this->pdo->prepare(
      'SELECT COUNT(*) FROM DefPlus WHERE H_GENERAL = :g AND H_NIVEL = :n AND H_TECLA = :t'
    );
    $existe->execute(['g' => $general, 'n' => $nivel, 't' => $tecla]);

    if ((int) $existe->fetchColumn() > 0) {
      $st = $this->pdo->prepare(
        'UPDATE DefPlus
         SET H_ANCHO = :ancho, H_ALTO = :alto, H_ETIQUET1 = :etiqueta,
             H_VALOR1 = :articulo, H_NIVOP = :nivop, H_M_C_MF = :clase
         WHERE H_GENERAL = :g AND H_NIVEL = :n AND H_TECLA = :t'
      );
      $st->execute($params);
      return $nivelDestino;
    }

    $st = $this->pdo->prepare(
      'INSERT INTO DefPlus
        (H_GENERAL, H_NIVEL, H_TECLA, H_ANCHO, H_ALTO, H_ETIQUET1,
         H_VALOR1, H_NIVOP, H_M_C_MF)
       VALUES
        (:g, :n, :t, :ancho, :alto, :etiqueta, :articulo, :nivop, :clase)'
    );
    $st->execute($params);

    return $nivelDestino;
  }

  public function borrarBoton(string $general, string $nivel, int $tecla): void
  {
    $st = $this->pdo->prepare(
      'DELETE FROM DefPlus WHERE H_GENERAL = :g AND H_NIVEL = :n AND H_TECLA = :t'
    );
    $st->execute([
      'g' => trim($general),
      'n' => trim($nivel),
      't' => $tecla,
    ]);
  }

  /** @return list<string> */
  private function nivelesConBotones(string $general): array
  {
    $st = $this->pdo->prepare('SELECT DISTINCT H_NIVEL FROM DefPlus WHERE H_GENERAL = :g');
    $st->execute(['g' => $general]);

    $niveles = [];
    while ($valor = $st->fetchColumn()) {
      $niveles[] = trim((string) $valor);
    }
    return $niveles;
  }

  /**
   * @param array<string, mixed> $row
   * @param list<string> $nivelesExistentes
   * @return array<string, mixed>
   */
  private function mapBoton(array $row, int $ordinal, string $nivelActual, array $nivelesExistentes): array
  {
    $articulo = $this->trimOrNull($row['H_VALOR1'] ?? null);
    $nivop = $this->trimOrNull($row['H_NIVOP'] ?? null);
    $clase = strtoupper((string) $this->trimOrNull($row['H_M_C_MF'] ?? null));
    $etiqueta1 = $this->trimOrNull($row['H_ETIQUET1'] ?? null);
    $tecla = (int) ($row['H_TECLA'] ?? 0);

    $nivelDestino = $nivop;
    if ($nivelDestino === null && $etiqueta1 !== null && $articulo === null && ($clase === 'F' || $clase === 'M')) {
      $candidato = str_pad((string) $ordinal, 3, '0', STR_PAD_LEFT);
      if ($candidato !== $nivelActual && in_array($candidato, $nivelesExistentes, true)) {
        $nivelDestino = $candidato;
      }
    }

    $tipo = 'vacio';
    if ($articulo !== null) {
      $tipo = 'articulo';
    } elseif ($nivelDestino !== null) {
      $tipo = 'nivel';
    }

    return [
      'tecla' => $tecla,
      'nivel' => $this->trimOrNull($row['H_NIVEL'] ?? null),
      'fila' => intdiv($tecla, self::COLUMNAS_LEGACY),
      'columna' => $tecla % self::COLUMNAS_LEGACY,
      'etiqueta1' => $etiqueta1,
      'etiqueta2' => $this->trimOrNull($row['H_ETIQUET2'] ?? null),
      'etiqueta3' => $this->trimOrNull($row['H_ETIQUET3'] ?? null),
      'ancho' => max(1, (int) ($row['H_ANCHO'] ?? 1)),
      'alto' => max(1, (int) ($row['H_ALTO'] ?? 1)),
      'articulo' => $articulo,
      'nivelDestino' => $nivelDestino,
      'nivelVolver' => $this->trimOrNull($row['H_NIVOB'] ?? null),
      'colorFondo' => $this->colorOleACss($row['H_COLOR'] ?? null),
      'colorTexto' => $this->colorOleACss($row['H_COLLIT'] ?? null),
      'icono' => $this->trimOrNull($row['H_ICON'] ?? null),
      'tarifa' => isset($row['H_TARIFA']) ? (float) $row['H_TARIFA'] : null,
      'clase' => $clase !== '' ? $clase : null,
      'tipo' => $tipo,
    ];
  }

  /**
   * Color OLE de VB (&H00BBGGRR) a hex CSS. Los colores de sistema (bit alto activo,
   * valor negativo) no tienen equivalente y se devuelven como null.
   */
  private function colorOleACss($valor): ?string
  {
    if ($valor === null || trim((string) $valor) === '') {
      return null;
    }
    $entero = (int) round((float) $valor);
    if ($entero < 0) {
      return null;
    }
    $r = $entero & 0xFF;
    $g = ($entero >> 8) & 0xFF;
    $b = ($entero >> 16) & 0xFF;
    return sprintf('#%02x%02x%02x', $r, $g, $b);
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
