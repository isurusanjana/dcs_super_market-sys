<?php
class ReportsController extends Controller {
    protected ?string $module = 'admin';
    function action_index(): void {
        $this->need('admin.reports.view'); $groups = [];
        foreach (ReportDefs::all() as $k => [$t, $g]) $groups[$g][$k] = $t;
        $sched = Auth::can('admin.reports.export') ? DB::all('SELECT * FROM report_schedules ORDER BY id DESC') : [];
        $this->render('reports/index', ['title' => 'Reports', 'groups' => $groups, 'sched' => $sched, 'defs' => ReportDefs::all()]);
    }
    private function runReport(string $key, string $from, string $to): array { $d = ReportDefs::all(); if (!isset($d[$key])) $this->abort(404, 'Unknown report.'); [$title, , $period, $fn] = $d[$key]; return [$title, $period, $fn($from, $to)]; }
    function action_view(): void {
        $this->need('admin.reports.view'); $key = (string)input('report'); $from = (string)input('from', date('Y-m-01')); $to = (string)input('to', date('Y-m-d'));
        if (Validator::check(['f' => $from, 't' => $to], ['f' => ['label' => 'From', 'rules' => 'date'], 't' => ['label' => 'To', 'rules' => 'date']]) || $from > $to) { flash('danger', 'Invalid date range.'); redirect('reports/index'); }
        [$title, $period, [$headers, $rows, $numCols, $sumCols]] = $this->runReport($key, $from, $to);
        $search = trim((string)input('q', '')); if ($search !== '') $rows = array_values(array_filter($rows, fn($r) => stripos(implode(' ', array_map('strval', $r)), $search) !== false));
        $totals = null; if ($sumCols) { $totals = array_fill(0, count($headers), ''); $totals[0] = 'TOTAL'; foreach ($sumCols as $c) $totals[$c] = array_sum(array_map(fn($r) => (float)$r[$c], $rows)); }
        $cmp = null;
        if (input('compare') && $period) { $days = (int)((strtotime($to) - strtotime($from)) / 86400) + 1; $pf = date('Y-m-d', strtotime("$from -$days day")); $pt = date('Y-m-d', strtotime("$from -1 day")); [, , [$h2, $r2, , $s2]] = $this->runReport($key, $pf, $pt);
            $t2 = null; if ($s2) { $t2 = array_fill(0, count($h2), ''); foreach ($s2 as $c) $t2[$c] = array_sum(array_map(fn($r) => (float)$r[$c], $r2)); } $cmp = ['from' => $pf, 'to' => $pt, 'totals' => $t2]; }
        $exp = (string)input('export', '');
        if ($exp !== '') {
            $this->need('admin.reports.export'); $out = $rows; if ($totals) $out[] = $totals; audit('report_export', 'reports', null, ['report' => $key, 'format' => $exp, 'from' => $from, 'to' => $to]);
            $align = []; foreach ($numCols as $c) $align[$c] = 'r'; $fmt = fn($r) => array_map(fn($v, $i) => is_float($v) || (in_array($i, $numCols, true) && is_numeric($v) && str_contains((string)$v, '.')) ? number_format((float)$v, 2, '.', '') : $v, $r, array_keys($r));
            if ($exp === 'csv') Report::csv($key . '_' . $from . '_' . $to . '.csv', $headers, array_map($fmt, $out));
            if ($exp === 'pdf') Report::pdf($key . '_' . $from . '_' . $to . '.pdf', $title, ($period ? "$from to $to" : 'As at ' . date('Y-m-d')) . ' - ' . setting('business_name', ''), $headers, array_map($fmt, $out), $align);
        }
        $this->render('reports/view', ['title' => $title, 'key' => $key, 'period' => $period, 'from' => $from, 'to' => $to, 'headers' => $headers, 'rows' => $rows, 'numCols' => $numCols, 'totals' => $totals, 'q' => $search, 'cmp' => $cmp]);
    }
    function action_schedule(): void {
        $this->postOnly(); $this->need('admin.reports.export'); $key = (string)input('report'); $email = trim((string)input('email')); $freq = (string)input('frequency');
        if (!isset(ReportDefs::all()[$key]) || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($freq, ['daily', 'weekly', 'monthly'], true)) { flash('danger', 'Choose a report, a valid email and a frequency.'); redirect('reports/index'); }
        $id = DB::insert('report_schedules', ['report' => $key, 'frequency' => $freq, 'email' => $email, 'period_days' => ['daily' => 1, 'weekly' => 7, 'monthly' => 30][$freq], 'created_by' => Auth::id()]);
        audit('report_schedule', 'report_schedules', $id, ['report' => $key, 'freq' => $freq]); flash('success', 'Schedule saved. Reports are sent when cron/run_scheduled_reports.php runs (see README).'); redirect('reports/index');
    }
    function action_unschedule(): void { $this->postOnly(); $this->need('admin.reports.export'); DB::q('DELETE FROM report_schedules WHERE id=?', [(int)input('id')]); audit('report_unschedule', 'report_schedules', (int)input('id')); redirect('reports/index'); }
}
