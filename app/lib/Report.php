<?php
class Report {
    static function csv(string $file, array $headers, array $rows): never {
        header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $file) . '"');
        $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF"); fputcsv($o, $headers);
        foreach ($rows as $r) fputcsv($o, array_map(fn($v) => self::safe((string)$v), array_values($r)));
        fclose($o); exit;
    }
    /** Neutralise spreadsheet formula injection */
    static function safe(string $v): string { return ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false && !is_numeric($v)) ? "'" . $v : $v; }
    static function pdf(string $file, string $title, string $sub, array $headers, array $rows, array $align = []): never {
        $p = new SimplePdf($title, $sub, count($headers) > 6); $p->table($headers, array_map('array_values', $rows), $align); $p->output($file);
    }
}
