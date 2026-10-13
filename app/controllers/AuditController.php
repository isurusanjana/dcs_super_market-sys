<?php
class AuditController extends Controller {
    protected ?string $module = 'admin';
    function action_index(): void {
        $this->need('admin.audit.view');
        $w = ['1=1']; $p = [];
        foreach (['action' => 'action', 'username' => 'username'] as $f => $col) if (($v = (string)input($f, '')) !== '') { $w[] = "$col=?"; $p[] = $v; }
        if (($v = (string)input('from', '')) !== '') { $w[] = 'created_at >= ?'; $p[] = $v . ' 00:00:00'; }
        if (($v = (string)input('to', '')) !== '') { $w[] = 'created_at <= ?'; $p[] = $v . ' 23:59:59'; }
        if (($v = (string)input('q', '')) !== '') { $w[] = '(entity LIKE ? OR details LIKE ? OR entity_id LIKE ?)'; $l = '%' . addcslashes($v, '%_\\') . '%'; array_push($p, $l, $l, $l); }
        $ws = implode(' AND ', $w);
        if (input('export') === 'csv') { $rows = DB::all("SELECT created_at, username, action, entity, entity_id, details, ip FROM audit_logs WHERE $ws ORDER BY id DESC LIMIT 20000", $p); audit('export', 'audit_logs'); Report::csv('audit_log.csv', ['Time', 'User', 'Action', 'Entity', 'Entity ID', 'Details', 'IP'], $rows); }
        $per = 50; $total = (int)DB::val("SELECT COUNT(*) FROM audit_logs WHERE $ws", $p); [$page, $pages, $off] = paginate($total, $per, (int)input('page', 1));
        $rows = DB::all("SELECT * FROM audit_logs WHERE $ws ORDER BY id DESC LIMIT $per OFFSET $off", $p);
        $this->render('audit/index', ['title' => 'Audit Logs', 'rows' => $rows, 'page' => $page, 'pages' => $pages, 'total' => $total,
            'actions' => array_column(DB::all('SELECT DISTINCT action FROM audit_logs ORDER BY action'), 'action'), 'users' => array_column(DB::all('SELECT DISTINCT username FROM audit_logs WHERE username IS NOT NULL ORDER BY username'), 'username')]);
    }
}
