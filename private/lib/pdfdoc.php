<?php
declare(strict_types=1);
// מחולל PDF קטן בעברית (ללא ספריות חיצוניות): פונט TrueType מוטבע, סדר תצוגה ימין-לשמאל, תמונת רקע וחתימה.

/** פונט TrueType: קריאת cmap ורוחבי תווים. */
final class PdfFont {
  public string $data; public int $upm = 1000; public int $ascent = 900; public int $descent = -200; public array $bbox = [0, 0, 1000, 1000];
  private array $cmap = []; private array $adv = []; public array $used = [];
  public function __construct(string $path) {
    $this->data = (string)file_get_contents($path); $d = $this->data; if ($d === '') throw new RuntimeException('פונט חסר');
    $n = $this->u16(4); $t = [];
    for ($i = 0; $i < $n; $i++) { $o = 12 + $i * 16; $t[substr($d, $o, 4)] = [$this->u32($o + 8), $this->u32($o + 12)]; }
    $h = $t['head'][0]; $this->upm = $this->u16($h + 18); $this->bbox = [$this->s16($h + 36), $this->s16($h + 38), $this->s16($h + 40), $this->s16($h + 42)];
    $hh = $t['hhea'][0]; $this->ascent = $this->s16($hh + 4); $this->descent = $this->s16($hh + 6); $nh = $this->u16($hh + 34);
    $hm = $t['hmtx'][0]; $ng = $this->u16($t['maxp'][0] + 4); $last = 0;
    for ($g = 0; $g < $ng; $g++) { if ($g < $nh) $last = $this->u16($hm + $g * 4); $this->adv[$g] = $last; }
    $cm = $t['cmap'][0]; $nt = $this->u16($cm + 2);
    for ($i = 0; $i < $nt; $i++) {
      $pid = $this->u16($cm + 4 + $i * 8); $eid = $this->u16($cm + 6 + $i * 8); $off = $cm + $this->u32($cm + 8 + $i * 8);
      if (($pid === 3 && $eid === 1) || $pid === 0) { if ($this->u16($off) === 4) { $this->fmt4($off); break; } }
    }
  }
  private function u16(int $o): int { return (ord($this->data[$o]) << 8) | ord($this->data[$o + 1]); }
  private function s16(int $o): int { $v = $this->u16($o); return $v >= 32768 ? $v - 65536 : $v; }
  private function u32(int $o): int { return ($this->u16($o) << 16) | $this->u16($o + 2); }
  private function fmt4(int $o): void {
    $sc = $this->u16($o + 6) / 2; $e = $o + 14; $s = $e + $sc * 2 + 2; $dl = $s + $sc * 2; $ro = $dl + $sc * 2;
    for ($i = 0; $i < $sc; $i++) {
      $end = $this->u16($e + $i * 2); $start = $this->u16($s + $i * 2); $delta = $this->u16($dl + $i * 2); $rof = $this->u16($ro + $i * 2);
      if ($start === 0xFFFF) continue;
      for ($c = $start; $c <= $end; $c++) {
        if ($rof === 0) $g = ($c + $delta) & 0xFFFF;
        else { $p = $ro + $i * 2 + $rof + ($c - $start) * 2; $g = $this->u16($p); if ($g !== 0) $g = ($g + $delta) & 0xFFFF; }
        if ($g) $this->cmap[$c] = $g;
      }
    }
  }
  public function gid(int $cp): int { return $this->cmap[$cp] ?? ($this->cmap[32] ?? 0); }
  public function w(int $gid): int { return $this->adv[$gid] ?? 0; }
  /** רוחב מחרוזת (כבר בסדר תצוגה) בנקודות. */
  public function width(string $visual, float $size): float {
    $w = 0; foreach (mb_str_split($visual) as $ch) $w += $this->w($this->gid(mb_ord($ch)));
    return $w * $size / $this->upm;
  }
  public function hex(string $visual): string {
    $o = ''; foreach (mb_str_split($visual) as $ch) { $g = $this->gid(mb_ord($ch)); $this->used[$g] = 1; $o .= sprintf('%04X', $g); } return $o;
  }
}

/** סדר תצוגה (שמאל→ימין) לשורה בפסקה ימין-לשמאל. */
function pdf_bidi(string $s): string {
  $ch = mb_str_split($s); $n = count($ch); if ($n === 0) return '';
  $type = [];
  foreach ($ch as $i => $c) {
    $o = mb_ord($c);
    if ($c >= '0' && $c <= '9') $type[$i] = 'D';
    elseif (($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z')) $type[$i] = 'L';
    elseif (($o >= 0x590 && $o <= 0x5FF) || $o === 0x200F) $type[$i] = 'R';
    else $type[$i] = 'N';
  }
  $isL = fn($t) => $t === 'D' || $t === 'L';
  for ($i = 0; $i < $n; $i++) {
    if ($type[$i] !== 'N') continue;
    $p = $i > 0 ? $type[$i - 1] : ''; $q = $i < $n - 1 ? $type[$i + 1] : '';
    if (!$isL($p) || !$isL($q)) continue;
    if (str_contains('.,:/-%@_+&#', $ch[$i]) || ($ch[$i] === ' ' && $p === 'L' && $q === 'L')) $type[$i] = 'L';
  }
  for ($i = 0; $i < $n; $i++) if ($type[$i] === 'D') $type[$i] = 'L'; else if ($type[$i] === 'N') $type[$i] = 'R';
  $mir = ['(' => ')', ')' => '(', '[' => ']', ']' => '[', '{' => '}', '}' => '{', '<' => '>', '>' => '<'];
  $runs = []; $cur = ''; $dir = $type[0];
  for ($i = 0; $i < $n; $i++) {
    if ($type[$i] !== $dir) { $runs[] = [$dir, $cur]; $cur = ''; $dir = $type[$i]; }
    $cur .= $ch[$i];
  }
  $runs[] = [$dir, $cur]; $out = '';
  foreach (array_reverse($runs) as [$d, $t]) {
    if ($d === 'L') { $out .= $t; continue; }
    $r = array_reverse(mb_str_split($t)); foreach ($r as &$c) if (isset($mir[$c])) $c = $mir[$c]; unset($c);
    $out .= implode('', $r);
  }
  return $out;
}

final class PdfDoc {
  const W = 595.28, H = 841.89;
  private array $pages = []; private int $cur = -1; private array $fonts = []; private string $bgJpg = ''; private ?array $sig = null;
  public function __construct(string $fontDir, string $bgJpg = '', string $sigPng = '') {
    $this->fonts = ['r' => new PdfFont($fontDir . '/LiberationSans-Regular.ttf'), 'b' => new PdfFont($fontDir . '/LiberationSans-Bold.ttf')];
    if ($bgJpg !== '' && is_file($bgJpg)) $this->bgJpg = (string)file_get_contents($bgJpg);
    if ($sigPng !== '' && is_file($sigPng)) $this->sig = self::loadPng($sigPng);
  }
  private static function loadPng(string $path): ?array {
    static $cache = []; if (isset($cache[$path])) return $cache[$path];
    $im = @imagecreatefrompng($path); if (!$im) return null; $w = imagesx($im); $h = imagesy($im); $rgb = ''; $al = '';
    for ($y = 0; $y < $h; $y++) for ($x = 0; $x < $w; $x++) { $c = imagecolorat($im, $x, $y); $rgb .= chr(($c >> 16) & 255) . chr(($c >> 8) & 255) . chr($c & 255); $al .= chr(255 - (int)round((($c >> 24) & 127) * 255 / 127)); }
    imagedestroy($im); return $cache[$path] = ['w' => $w, 'h' => $h, 'rgb' => gzcompress($rgb, 6), 'a' => gzcompress($al, 6)];
  }
  public function addPage(bool $sig = false): void { $this->pages[] = ['c' => '', 'sig' => $sig]; $this->cur = count($this->pages) - 1; if ($this->bgJpg !== '') $this->pages[$this->cur]['c'] .= sprintf("q %.2F 0 0 %.2F 0 0 cm /Bg Do Q\n", self::W, self::H); }
  public function width(string $text, float $size, string $f = 'r'): float { return $this->fonts[$f]->width(pdf_bidi($text), $size); }
  /** שבירת טקסט לשורות לפי רוחב. */
  public function wrap(string $text, float $size, float $maxW, string $f = 'r'): array {
    $lines = []; $line = '';
    foreach (preg_split('/\s+/u', trim($text)) ?: [] as $w) {
      if ($w === '') continue; $try = $line === '' ? $w : $line . ' ' . $w;
      if ($line !== '' && $this->width($try, $size, $f) > $maxW) { $lines[] = $line; $line = $w; } else $line = $try;
    }
    if ($line !== '') $lines[] = $line; return $lines ?: [''];
  }
  /** כתיבת שורה אחת. $align: c מרכז סביב $x, r יישור ימין ל-$x, l יישור שמאל ל-$x. $y = קו בסיס מהקצה העליון. */
  public function text(string $text, float $x, float $y, float $size, string $f = 'r', string $align = 'c', bool $underline = false): void {
    $vis = pdf_bidi($text); $font = $this->fonts[$f]; $w = $font->width($vis, $size);
    $x0 = $align === 'c' ? $x - $w / 2 : ($align === 'r' ? $x - $w : $x); $py = self::H - $y;
    $this->pages[$this->cur]['c'] .= sprintf("BT /%s %.2F Tf 1 0 0 1 %.2F %.2F Tm <%s> Tj ET\n", $f === 'b' ? 'F2' : 'F1', $size, $x0, $py, $font->hex($vis));
    if ($underline) $this->pages[$this->cur]['c'] .= sprintf("q %.2F w %.2F %.2F m %.2F %.2F l S Q\n", max(0.6, $size / 20), $x0, $py - $size * 0.12, $x0 + $w, $py - $size * 0.12);
  }
  /** חתימה/חותמת: $x שמאל, $y קצה עליון, $w רוחב (הגובה לפי היחס). מחזירה את הגובה. */
  public function signature(float $x, float $yTop, float $w): float {
    if (!$this->sig) return 0.0; $h = $w * $this->sig['h'] / $this->sig['w']; $this->pages[$this->cur]['sig'] = true;
    $this->pages[$this->cur]['c'] .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /Sig Do Q\n", $w, $h, $x, self::H - $yTop - $h); return $h;
  }
  public function pageCount(): int { return count($this->pages); }
  public function output(): string {
    $objs = []; $add = function (string $s) use (&$objs): int { $objs[] = $s; return count($objs); };
    $stream = fn(string $dict, string $data) => "<< $dict /Length " . strlen($data) . " >>\nstream\n" . $data . "\nendstream";
    $catalog = $add(''); $pagesId = $add('');
    $fontIds = [];
    foreach (['r' => 'F1', 'b' => 'F2'] as $k => $name) {
      $f = $this->fonts[$k]; $ff = $add($stream('/Filter /FlateDecode /Length1 ' . strlen($f->data), gzcompress($f->data, 6)));
      $sc = 1000 / $f->upm; $fd = $add(sprintf('<< /Type /FontDescriptor /FontName /AlmaSans%s /Flags 32 /FontBBox [%d %d %d %d] /ItalicAngle 0 /Ascent %d /Descent %d /CapHeight %d /StemV 80 /FontFile2 %d 0 R >>', strtoupper($k), $f->bbox[0] * $sc, $f->bbox[1] * $sc, $f->bbox[2] * $sc, $f->bbox[3] * $sc, $f->ascent * $sc, $f->descent * $sc, 700, $ff));
      $w = ''; $g = array_keys($f->used); sort($g); foreach ($g as $gid) $w .= $gid . ' [' . (int)round($f->w($gid) * $sc) . '] ';
      $cid = $add("<< /Type /Font /Subtype /CIDFontType2 /BaseFont /AlmaSans" . strtoupper($k) . " /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor $fd 0 R /CIDToGIDMap /Identity /DW 600 /W [ $w] >>");
      $fontIds[$name] = $add("<< /Type /Font /Subtype /Type0 /BaseFont /AlmaSans" . strtoupper($k) . " /Encoding /Identity-H /DescendantFonts [$cid 0 R] >>");
    }
    $bg = 0; if ($this->bgJpg !== '') { $sz = getimagesizefromstring($this->bgJpg); $bg = $add($stream(sprintf('/Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode', $sz[0], $sz[1]), $this->bgJpg)); }
    $sg = 0; if ($this->sig) {
      $sm = $add($stream(sprintf('/Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode', $this->sig['w'], $this->sig['h']), $this->sig['a']));
      $sg = $add($stream(sprintf('/Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode /SMask %d 0 R', $this->sig['w'], $this->sig['h'], $sm), $this->sig['rgb']));
    }
    $kids = [];
    foreach ($this->pages as $p) {
      $cs = $add($stream('/Filter /FlateDecode', gzcompress($p['c'], 6)));
      $xo = ''; if ($bg) $xo .= "/Bg $bg 0 R "; if ($sg && $p['sig']) $xo .= "/Sig $sg 0 R ";
      $kids[] = $add(sprintf('<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 %d 0 R /F2 %d 0 R >> /XObject << %s>> >> /Contents %d 0 R >>', $pagesId, self::W, self::H, $fontIds['F1'], $fontIds['F2'], $xo, $cs));
    }
    $objs[$catalog - 1] = "<< /Type /Catalog /Pages $pagesId 0 R /Lang (he) >>";
    $objs[$pagesId - 1] = '<< /Type /Pages /Kids [' . implode(' ', array_map(fn($k) => "$k 0 R", $kids)) . '] /Count ' . count($kids) . ' >>';
    $out = "%PDF-1.5\n%\xE2\xE3\xCF\xD3\n"; $off = [];
    foreach ($objs as $i => $o) { $off[$i] = strlen($out); $out .= ($i + 1) . " 0 obj\n$o\nendobj\n"; }
    $x = strlen($out); $out .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
    foreach ($off as $o) $out .= sprintf("%010d 00000 n \n", $o);
    return $out . "trailer\n<< /Size " . (count($objs) + 1) . " /Root $catalog 0 R /Info << /Producer (Alma) >> >>\nstartxref\n$x\n%%EOF\n";
  }
}
