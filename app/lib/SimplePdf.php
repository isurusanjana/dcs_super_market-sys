<?php
/** Minimal dependency-free PDF table writer (Helvetica, Latin-1). Unicode beyond Latin-1 (e.g. Sinhala) is not embedded - use CSV/print view for those. */
class SimplePdf {
    private array $pages = []; private string $cur = ''; private float $y; private int $pageNo = 0;
    private float $W; private float $H; private float $m = 36; private string $title; private string $sub;
    function __construct(string $title, string $subtitle = '', bool $landscape = true) {
        $this->title = $title; $this->sub = $subtitle;
        [$this->W, $this->H] = $landscape ? [842.0, 595.0] : [595.0, 842.0]; $this->newPage();
    }
    private function enc(string $s): string {
        $s = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s); if ($s === false) $s = '';
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $s);
    }
    private function w(string $s, float $fs): float {
        $t = 0; foreach (str_split($s) as $c) { $t += ctype_digit($c) ? .556 : (in_array($c, ['.', ',', ' ', ':', ';', 'i', 'l', 'I', '|', '\'']) ? .278 : (ctype_upper($c) ? .68 : .53)); } return $t * $fs;
    }
    private function text(float $x, float $y, string $s, float $fs = 8, bool $bold = false): void {
        $this->cur .= sprintf("BT /%s %.1f Tf %.1f %.1f Td (%s) Tj ET\n", $bold ? 'F2' : 'F1', $fs, $x, $y, $this->enc($s));
    }
    private function fit(string $s, float $max, float $fs): string {
        if ($this->w($s, $fs) <= $max) return $s;
        while ($s !== '' && $this->w($s . '..', $fs) > $max) $s = mb_substr($s, 0, mb_strlen($s) - 1);
        return $s . '..';
    }
    private function newPage(): void {
        if ($this->cur !== '') $this->pages[] = $this->cur;
        $this->cur = ''; $this->pageNo++; $this->y = $this->H - $this->m;
        $this->text($this->m, $this->y, $this->title, 14, true); $this->y -= 14;
        if ($this->sub !== '') { $this->text($this->m, $this->y, $this->sub, 8); $this->y -= 10; }
        $this->y -= 6;
    }
    function table(array $headers, array $rows, array $align = []): void {
        $n = count($headers); $usable = $this->W - 2 * $this->m; $fs = 7.5; $lh = 11;
        $max = array_map(fn($h) => $this->w((string)$h, $fs) + 8, $headers);
        foreach (array_slice($rows, 0, 400) as $r) foreach (array_values($r) as $i => $c) $max[$i] = max($max[$i] ?? 0, min($this->w((string)$c, $fs) + 8, $usable * .4));
        $sum = array_sum($max); $cw = array_map(fn($x) => $x * $usable / max($sum, 1), $max);
        $head = function () use ($headers, $cw, $fs, $lh, $align) {
            $this->cur .= sprintf("0.9 g %.1f %.1f %.1f %.1f re f 0 g\n", $this->m, $this->y - 3, $this->W - 2 * $this->m, $lh);
            $x = $this->m; foreach ($headers as $i => $h) { $s = $this->fit((string)$h, $cw[$i] - 4, $fs); $tx = ($align[$i] ?? 'l') === 'r' ? $x + $cw[$i] - 2 - $this->w($s, $fs) : $x + 2; $this->text($tx, $this->y, $s, $fs, true); $x += $cw[$i]; }
            $this->y -= $lh;
        };
        $head();
        foreach ($rows as $r) {
            if ($this->y < $this->m + 20) { $this->newPage(); $head(); }
            $x = $this->m; foreach (array_values($r) as $i => $c) {
                $s = $this->fit((string)$c, $cw[$i] - 4, $fs); $tx = ($align[$i] ?? 'l') === 'r' ? $x + $cw[$i] - 2 - $this->w($s, $fs) : $x + 2; $this->text($tx, $this->y, $s, $fs); $x += $cw[$i];
            }
            $this->cur .= sprintf("0.85 G %.1f %.1f m %.1f %.1f l S\n", $this->m, $this->y - 3, $this->W - $this->m, $this->y - 3);
            $this->y -= $lh;
        }
    }
    function line(string $s, bool $bold = false): void { if ($this->y < $this->m + 20) $this->newPage(); $this->text($this->m, $this->y, $s, 9, $bold); $this->y -= 12; }
    function render(): string {
        $this->pages[] = $this->cur; $this->cur = '';
        $objs = []; $n = count($this->pages);
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = []; for ($i = 0; $i < $n; $i++) $kids[] = (5 + $i * 2) . ' 0 R';
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . "] /Count $n >>";
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        foreach ($this->pages as $i => $c) {
            $c .= sprintf("BT /F1 7 Tf %.1f 20 Td (Page %d of %d - generated %s) Tj ET\n", $this->m, $i + 1, $n, date('Y-m-d H:i'));
            $objs[5 + $i * 2] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.0f %.0f] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', $this->W, $this->H, 6 + $i * 2);
            $objs[6 + $i * 2] = "<< /Length " . strlen($c) . " >>\nstream\n$c\nendstream";
        }
        $out = "%PDF-1.4\n"; $off = [];
        ksort($objs); foreach ($objs as $k => $o) { $off[$k] = strlen($out); $out .= "$k 0 obj\n$o\nendobj\n"; }
        $x = strlen($out); $cnt = max(array_keys($objs)) + 1;
        $out .= "xref\n0 $cnt\n0000000000 65535 f \n"; for ($i = 1; $i < $cnt; $i++) $out .= sprintf("%010d 00000 n \n", $off[$i]);
        return $out . "trailer\n<< /Size $cnt /Root 1 0 R >>\nstartxref\n$x\n%%EOF";
    }
    function output(string $file): never {
        $d = $this->render(); header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $file) . '"');
        header('Content-Length: ' . strlen($d)); echo $d; exit;
    }
}
