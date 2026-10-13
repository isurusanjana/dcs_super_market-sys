<?php
class Barcode {
    static function ean13Check(string $d12): string { $s = 0; for ($i = 0; $i < 12; $i++) $s += (int)$d12[$i] * ($i % 2 ? 3 : 1); return $d12 . ((10 - $s % 10) % 10); }
    static function valid13(string $c): bool { return preg_match('/^\d{13}$/', $c) && self::ean13Check(substr($c, 0, 12)) === $c; }
    /** Internal (in-store) EAN-13 starting with 200 */
    static function generate(): string { return self::ean13Check('200' . str_pad((string)random_int(0, 999999999), 9, '0', STR_PAD_LEFT)); }
    static function svg(string $code, int $h = 50, float $w = 1.6): string {
        $mods = self::valid13($code) ? self::ean13Modules($code) : self::code39Modules($code);
        $x = 10; $rects = ''; $i = 0; $n = strlen($mods);
        while ($i < $n) { if ($mods[$i] === '1') { $j = $i; while ($j < $n && $mods[$j] === '1') $j++; $rects .= '<rect x="' . round($x + $i * $w, 2) . '" y="0" width="' . round(($j - $i) * $w, 2) . '" height="' . $h . '"/>'; $i = $j; } else $i++; }
        $tw = $n * $w + 20;
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $tw . ' ' . ($h + 14) . '" width="' . $tw . '" height="' . ($h + 14) . '"><rect width="100%" height="100%" fill="#fff"/><g fill="#000">' . $rects . '</g><text x="' . ($tw / 2) . '" y="' . ($h + 11) . '" font-family="monospace" font-size="11" text-anchor="middle">' . htmlspecialchars($code) . '</text></svg>';
    }
    private static function ean13Modules(string $c): string {
        $L = ['0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011'];
        $G = ['0100111', '0110011', '0011011', '0100001', '0011101', '0111001', '0000101', '0010001', '0001001', '0010111'];
        $R = ['1110010', '1100110', '1101100', '1000010', '1011100', '1001110', '1010000', '1000100', '1001000', '1110100'];
        $P = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'];
        $par = $P[(int)$c[0]]; $m = '101';
        for ($i = 1; $i <= 6; $i++) $m .= ($par[$i - 1] === 'L' ? $L : $G)[(int)$c[$i]];
        $m .= '01010';
        for ($i = 7; $i <= 12; $i++) $m .= $R[(int)$c[$i]];
        return $m . '101';
    }
    private static function code39Modules(string $s): string {
        $t = ['0' => 'nnnwwnwnn', '1' => 'wnnwnnnnw', '2' => 'nnwwnnnnw', '3' => 'wnwwnnnnn', '4' => 'nnnwwnnnw', '5' => 'wnnwwnnnn', '6' => 'nnwwwnnnn', '7' => 'nnnwnnwnw', '8' => 'wnnwnnwnn', '9' => 'nnwwnnwnn',
            'A' => 'wnnnnwnnw', 'B' => 'nnwnnwnnw', 'C' => 'wnwnnwnnn', 'D' => 'nnnnwwnnw', 'E' => 'wnnnwwnnn', 'F' => 'nnwnwwnnn', 'G' => 'nnnnnwwnw', 'H' => 'wnnnnwwnn', 'I' => 'nnwnnwwnn', 'J' => 'nnnnwwwnn',
            'K' => 'wnnnnnnww', 'L' => 'nnwnnnnww', 'M' => 'wnwnnnnwn', 'N' => 'nnnnwnnww', 'O' => 'wnnnwnnwn', 'P' => 'nnwnwnnwn', 'Q' => 'nnnnnnwww', 'R' => 'wnnnnnwwn', 'S' => 'nnwnnnwwn', 'T' => 'nnnnwnwwn',
            'U' => 'wwnnnnnnw', 'V' => 'nwwnnnnnw', 'W' => 'wwwnnnnnn', 'X' => 'nwnnwnnnw', 'Y' => 'wwnnwnnnn', 'Z' => 'nwwnwnnnn', '-' => 'nwnnnnwnw', '.' => 'wwnnnnwnn', ' ' => 'nwwnnnwnn', '*' => 'nwnnwnwnn'];
        $s = '*' . preg_replace('/[^0-9A-Z\-. ]/', '', strtoupper($s)) . '*'; $m = '';
        foreach (str_split($s) as $ci => $ch) {
            $bar = true;
            foreach (str_split($t[$ch]) as $e) { $m .= str_repeat($bar ? '1' : '0', $e === 'w' ? 3 : 1); $bar = !$bar; }
            $m .= '0';
        }
        return $m;
    }
}
