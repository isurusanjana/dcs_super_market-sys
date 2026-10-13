<?php
class CustomerController extends Controller {
    protected ?string $module = 'admin';
    private function load(): array { $c = DB::one('SELECT c.*, g.name AS group_name FROM customers c LEFT JOIN customer_groups g ON g.id=c.group_id WHERE c.id=?', [(int)input('id')]); if (!$c) $this->abort(404, 'Customer not found.'); return $c; }
    function action_view(): void {
        $this->need('admin.customers.view'); $c = $this->load(); $id = (int)$c['id']; $bal = Finance::customerBalance($id);
        $this->render('customer/view', ['title' => 'Customer: ' . $c['name'], 'c' => $c, 'balance' => $bal,
            'sales' => DB::all('SELECT id, invoice_no, sale_date, total, credit_amount, status FROM sales WHERE customer_id=? ORDER BY id DESC LIMIT 30', [$id]),
            'payments' => DB::all('SELECT * FROM customer_payments WHERE customer_id=? ORDER BY id DESC LIMIT 30', [$id]),
            'loyalty' => DB::all('SELECT * FROM loyalty_transactions WHERE customer_id=? ORDER BY id DESC LIMIT 30', [$id])]);
    }
    function action_payment(): void {
        $this->postOnly(); $this->need('admin.customers.payment'); $c = $this->load(); $id = (int)$c['id'];
        $d = ['amount' => input('amount'), 'method' => input('method'), 'reference' => input('reference') ?: null];
        $err = Validator::check($d, ['amount' => ['label' => 'Amount', 'rules' => 'required|pos'], 'method' => ['label' => 'Method', 'rules' => 'required|in:cash,card,bank_transfer,digital'], 'reference' => ['label' => 'Reference', 'rules' => 'max:80']]);
        $amt = round((float)$d['amount'], 2); $bal = Finance::customerBalance($id);
        if (!$err && $amt > $bal + 0.004) $err['amount'] = 'Amount exceeds the outstanding balance (' . money($bal) . ').';
        if ($err) { flash('danger', implode(' ', $err)); redirect('customer/view', ['id' => $id]); }
        DB::tx(function () use ($id, $d, $amt) {
            $pid = DB::insert('customer_payments', ['customer_id' => $id, 'amount' => $amt, 'method' => $d['method'], 'reference' => $d['reference'], 'user_id' => Auth::id(), 'branch_id' => $this->branchId()]);
            fin_txn('customer_payment', 'in', $d['method'], $amt, $this->branchId(), null, 'customer_payment', $pid, 'Customer payment');
        });
        audit('customer_payment', 'customers', $id, ['amount' => $amt]); flash('success', 'Payment recorded.'); redirect('customer/view', ['id' => $id]);
    }
    function action_loyalty(): void {
        $this->postOnly(); $this->need('admin.customers.loyalty'); $c = $this->load(); $id = (int)$c['id']; $pts = (int)input('points'); $reason = trim((string)input('reason', ''));
        if ($pts === 0 || abs($pts) > 100000) { flash('danger', 'Enter a non-zero number of points.'); redirect('customer/view', ['id' => $id]); }
        if ($reason === '' || mb_strlen($reason) > 160) { flash('danger', 'A reason (max 160 chars) is required.'); redirect('customer/view', ['id' => $id]); }
        if ($c['loyalty_points'] + $pts < 0) { flash('danger', 'Points cannot go below zero.'); redirect('customer/view', ['id' => $id]); }
        DB::tx(function () use ($id, $pts, $reason) { DB::q('UPDATE customers SET loyalty_points = loyalty_points + ? WHERE id=?', [$pts, $id]); DB::insert('loyalty_transactions', ['customer_id' => $id, 'points' => $pts, 'reason' => $reason, 'ref' => 'adjust', 'user_id' => Auth::id()]); });
        audit('loyalty_adjust', 'customers', $id, ['points' => $pts, 'reason' => $reason]); flash('success', 'Loyalty points adjusted.'); redirect('customer/view', ['id' => $id]);
    }
}
