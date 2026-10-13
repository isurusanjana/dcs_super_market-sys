<?php
class FinanceController extends Controller {
    protected ?string $module = 'admin';
    function action_expense_decide(): void {
        $this->postOnly(); $this->need('admin.finance.approve'); $id = (int)input('id'); $dec = (string)input('decision');
        if (!in_array($dec, ['approved', 'rejected'], true)) $this->abort(400, 'Invalid decision.');
        DB::tx(function () use ($id, $dec) {
            $x = DB::one('SELECT * FROM expenses WHERE id=? FOR UPDATE', [$id]);
            if (!$x || $x['status'] !== 'pending') throw new RuntimeException('Expense is not pending.');
            if ((int)$x['created_by'] === Auth::id() && !Auth::isSuper()) throw new RuntimeException('You cannot approve your own expense.');
            DB::update('expenses', ['status' => $dec, 'approved_by' => Auth::id()], 'id=?', [$id]);
            if ($dec === 'approved') fin_txn('expense', 'out', $x['method'], (float)$x['amount'], $x['branch_id'] ? (int)$x['branch_id'] : null, null, 'expense', $id, $x['category']);
            audit('expense_' . $dec, 'expenses', $id, ['amount' => $x['amount']]);
        });
        flash('success', 'Expense ' . $dec . '.'); redirect('crud/list', ['e' => 'expenses']);
    }
    function action_transactions(): void {
        $this->need('admin.finance.view'); $w = ['1=1']; $p = [];
        foreach (['type' => 't.type', 'method' => 't.method', 'branch' => 't.branch_id', 'direction' => 't.direction'] as $f => $c) if (($v = (string)input($f, '')) !== '') { $w[] = "$c=?"; $p[] = $v; }
        $from = (string)input('from', date('Y-m-01')); $to = (string)input('to', date('Y-m-d')); $w[] = 't.txn_date BETWEEN ? AND ?'; array_push($p, "$from 00:00:00", "$to 23:59:59");
        $ws = implode(' AND ', $w);
        $rows = DB::all("SELECT t.*, b.name AS branch_name, u.username FROM fin_transactions t LEFT JOIN branches b ON b.id=t.branch_id LEFT JOIN users u ON u.id=t.user_id WHERE $ws ORDER BY t.id DESC LIMIT 1000", $p);
        $sum = DB::one("SELECT COALESCE(SUM(CASE WHEN direction='in' THEN amount END),0) i, COALESCE(SUM(CASE WHEN direction='out' THEN amount END),0) o FROM fin_transactions t WHERE $ws", $p);
        if (input('export') === 'csv') Report::csv('financial_transactions.csv', ['Date', 'Type', 'Direction', 'Method', 'Amount', 'Branch', 'Reference', 'Note', 'User'], array_map(fn($r) => [$r['txn_date'], $r['type'], $r['direction'], $r['method'], $r['amount'], $r['branch_name'], $r['ref_type'] . '#' . $r['ref_id'], $r['note'], $r['username']], $rows));
        $this->render('finance/transactions', ['title' => 'Financial Transactions', 'rows' => $rows, 'sum' => $sum, 'from' => $from, 'to' => $to, 'branches' => DB::all('SELECT id, name FROM branches ORDER BY name'),
            'types' => array_column(DB::all('SELECT DISTINCT type FROM fin_transactions ORDER BY type'), 'type')]);
    }
    function action_cash(): void {
        $this->need('admin.finance.view');
        $sessions = DB::all("SELECT s.*, u.full_name, c.name AS counter, b.name AS branch FROM pos_sessions s JOIN users u ON u.id=s.user_id JOIN counters c ON c.id=s.counter_id JOIN branches b ON b.id=s.branch_id ORDER BY s.id DESC LIMIT 60");
        $adj = DB::all('SELECT a.*, b.name AS branch, u.username FROM cash_adjustments a JOIN branches b ON b.id=a.branch_id LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 20');
        $this->render('finance/cash', ['title' => 'Cash Drawer', 'sessions' => $sessions, 'adj' => $adj, 'branches' => DB::all('SELECT id, name FROM branches WHERE is_active=1 ORDER BY name')]);
    }
    function action_reconcile(): void {
        $this->postOnly(); $this->need('admin.finance.reconcile'); $id = (int)input('id');
        $n = DB::update('pos_sessions', ['status' => 'reconciled', 'reconciled_by' => Auth::id()], "id=? AND status='closed'", [$id]);
        if ($n) audit('cash_reconcile', 'pos_sessions', $id); flash($n ? 'success' : 'warning', $n ? 'Session reconciled.' : 'Only closed sessions can be reconciled.'); redirect('finance/cash');
    }
    function action_adjust(): void {
        $this->postOnly(); $this->need('admin.finance.cash');
        $d = ['branch_id' => input('branch_id'), 'type' => input('type'), 'amount' => input('amount'), 'reason' => input('reason')];
        $err = Validator::check($d, ['branch_id' => ['label' => 'Branch', 'rules' => 'required|exists:branches,id'], 'type' => ['label' => 'Type', 'rules' => 'required|in:in,out'], 'amount' => ['label' => 'Amount', 'rules' => 'required|pos'], 'reason' => ['label' => 'Reason', 'rules' => 'required|max:255']]);
        if ($err) { flash('danger', implode(' ', $err)); redirect('finance/cash'); }
        DB::tx(function () use ($d) { $id = DB::insert('cash_adjustments', ['branch_id' => $d['branch_id'], 'type' => $d['type'], 'amount' => round((float)$d['amount'], 2), 'reason' => $d['reason'], 'user_id' => Auth::id()]);
            fin_txn('cash_adjustment', $d['type'], 'cash', (float)$d['amount'], (int)$d['branch_id'], null, 'cash_adjustment', $id, $d['reason']); });
        audit('cash_adjustment', 'cash_adjustments', null, $d); flash('success', 'Cash adjustment recorded.'); redirect('finance/cash');
    }
}
