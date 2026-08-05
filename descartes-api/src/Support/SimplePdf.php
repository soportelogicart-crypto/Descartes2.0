<?php

declare(strict_types=1);

namespace Descartes\Api\Support;

/**
 * Generador minimo de PDF (texto + tabla) sin dependencias externas.
 * Codificacion WinAnsi (Latin-1) para acentos basicos.
 */
final class SimplePdf
{
  /** @var list<string> */
  private array $lines = [];
  private float $y = 800.0;
  private float $left = 40.0;
  private float $pageWidth = 595.0;
  private float $pageHeight = 842.0;
  private int $page = 1;

  /** @var list<string> content streams per page */
  private array $pages = [];

  public function title(string $text): void
  {
    $this->text($text, 16, true);
    $this->y -= 6;
  }

  public function text(string $text, float $size = 10, bool $bold = false): void
  {
    $this->ensureSpace(18);
    $font = $bold ? 'F2' : 'F1';
    $this->lines[] = sprintf(
      'BT /%s %.1f Tf %.1f %.1f Td (%s) Tj ET',
      $font,
      $size,
      $this->left,
      $this->y,
      $this->esc($text)
    );
    $this->y -= $size + 4;
  }

  public function spacer(float $dy = 8): void
  {
    $this->y -= $dy;
  }

  /** Fuerza salto de pagina (p. ej. una factura por pagina). */
  public function pageBreak(): void
  {
    if ($this->lines !== []) {
      $this->flushPage();
      $this->y = 800.0;
      $this->page++;
    }
  }

  /**
   * @param list<string> $headers
   * @param list<list<string>> $rows
   * @param list<float> $colWidths
   */
  public function table(array $headers, array $rows, array $colWidths): void
  {
    $rowH = 16.0;
    $this->ensureSpace($rowH * 2);
    $this->drawTableRow($headers, $colWidths, $rowH, true);
    foreach ($rows as $row) {
      $this->ensureSpace($rowH + 2);
      $this->drawTableRow($row, $colWidths, $rowH, false);
    }
  }

  /** @param list<string> $cells @param list<float> $colWidths */
  private function drawTableRow(array $cells, array $colWidths, float $rowH, bool $header): void
  {
    $x = $this->left;
    $y = $this->y;
    $totalW = array_sum($colWidths);
    // Fondo cabecera
    if ($header) {
      $this->lines[] = sprintf(
        '%.1f %.1f %.1f %.1f re 0.94 0.96 0.98 rg f 0 g',
        $x,
        $y - $rowH + 4,
        $totalW,
        $rowH
      );
    }
    // Linea inferior
    $this->lines[] = sprintf(
      '%.1f %.1f m %.1f %.1f l 0.7 0.7 0.7 RG S 0 g',
      $x,
      $y - $rowH + 4,
      $x + $totalW,
      $y - $rowH + 4
    );

    $cx = $x + 2;
    $font = $header ? 'F2' : 'F1';
    foreach ($cells as $i => $cell) {
      $w = $colWidths[$i] ?? 80.0;
      $alignRight = $i >= 2;
      $txt = $this->trunc($cell, (int) ($w / 5.2));
      if ($alignRight) {
        $tw = $this->approxWidth($txt, 9);
        $tx = $cx + $w - 4 - $tw;
      } else {
        $tx = $cx;
      }
      $this->lines[] = sprintf(
        'BT /%s 9 Tf %.1f %.1f Td (%s) Tj ET',
        $font,
        $tx,
        $y - 8,
        $this->esc($txt)
      );
      $cx += $w;
    }
    $this->y -= $rowH;
  }

  private function ensureSpace(float $needed): void
  {
    if ($this->y - $needed < 50) {
      $this->flushPage();
      $this->y = 800.0;
      $this->page++;
    }
  }

  private function flushPage(): void
  {
    $this->pages[] = implode("\n", $this->lines);
    $this->lines = [];
  }

  public function build(): string
  {
    if ($this->lines !== []) {
      $this->flushPage();
    }
    if ($this->pages === []) {
      $this->pages[] = '';
    }
    return $this->assemble();
  }

  private function assemble(): string
  {
    $n = count($this->pages);
    $objects = [];
    // 1 Catalog, 2 Pages, then pairs (Page, Content) per page, then 2 fonts
    $font1Id = 3 + $n * 2;
    $font2Id = $font1Id + 1;

    $pageKids = [];
    for ($i = 0; $i < $n; $i++) {
      $pageId = 3 + $i * 2;
      $contentId = $pageId + 1;
      $pageKids[] = $pageId . ' 0 R';
      $stream = $this->pages[$i];
      $objects[$contentId] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
      $objects[$pageId] = sprintf(
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.0f %.0f] /Contents %d 0 R /Resources << /Font << /F1 %d 0 R /F2 %d 0 R >> >> >>',
        $this->pageWidth,
        $this->pageHeight,
        $contentId,
        $font1Id,
        $font2Id
      );
    }

    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[2] = sprintf(
      '<< /Type /Pages /Kids [%s] /Count %d >>',
      implode(' ', $pageKids),
      $n
    );
    $objects[$font1Id] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
    $objects[$font2Id] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

    ksort($objects, SORT_NUMERIC);
    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $id => $body) {
      $offsets[$id] = strlen($pdf);
      $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
    }
    $xrefPos = strlen($pdf);
    $maxId = max(array_keys($objects));
    $pdf .= "xref\n0 " . ($maxId + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= $maxId; $i++) {
      $off = $offsets[$i] ?? 0;
      $pdf .= sprintf("%010d 00000 n \n", $off);
    }
    $pdf .= "trailer\n<< /Size " . ($maxId + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n" . $xrefPos . "\n%%EOF\n";
    return $pdf;
  }

  private function esc(string $text): string
  {
    $latin = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
    if ($latin === false) {
      $latin = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? $text;
    }
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $latin);
  }

  private function trunc(string $text, int $maxChars): string
  {
    if ($maxChars < 1) {
      return '';
    }
    if (mb_strlen($text, 'UTF-8') <= $maxChars) {
      return $text;
    }
    return mb_substr($text, 0, max(1, $maxChars - 1), 'UTF-8') . '...';
  }

  private function approxWidth(string $text, float $size): float
  {
    return mb_strlen($text, 'UTF-8') * $size * 0.5;
  }
}
