<?php
// Run from cron:  php /path/to/cron/run_scheduled_reports.php   (e.g. daily at 06:00)
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require __DIR__ . '/../app/bootstrap.php';
$defs = ReportDefs::all(); $now = time();
foreach (DB::all('SELECT * FROM report_schedules WHERE is_active=1') as $s) {
    $due = !$s['last_run'] || $now - strtotime($s['last_run']) >= ['daily' => 86000, 'weekly' => 7 * 86400 - 600, 'monthly' => 28 * 86400][$s['frequency']];
    if (!$due || !isset($defs[$s['report']])) continue;
    $to = date('Y-m-d', strtotime('-1 day')); $from = date('Y-m-d', strtotime("-{$s['period_days']} day"));
    [$title, , $period, $fn] = $defs[$s['report']]; [$h, $rows] = $fn($from, $to);
    $csv = fopen('php://temp', 'w+'); fputcsv($csv, $h); foreach ($rows as $r) fputcsv($csv, array_map(fn($v) => Report::safe((string)$v), $r)); rewind($csv); $body = stream_get_contents($csv);
    $b = 'bsrms' . md5((string)$now); $name = $s['report'] . "_$to.csv";
    $msg = "--$b\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n$title ($from to $to) attached.\r\n--$b\r\nContent-Type: text/csv; name=\"$name\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$name\"\r\n\r\n" . chunk_split(base64_encode($body)) . "--$b--";
    $ok = mail($s['email'], "[Report] $title", $msg, "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"$b\"");
    DB::update('report_schedules', ['last_run' => date('Y-m-d H:i:s')], 'id=?', [$s['id']]); echo date('c') . " {$s['report']} -> {$s['email']} " . ($ok ? 'sent' : 'FAILED') . "\n";
}
