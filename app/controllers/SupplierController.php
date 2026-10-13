<?php
class SupplierController extends Controller {
    protected ?string $module = 'admin';
    private function load(): array { $s = DB::one('SELECT * FROM suppliers WHERE id=?', [(int)input('id')]); if (!$s) $this->abort(404, 'Supplier not found.'); return $s; }
    function action_view(): void {
        $this->need('admin.suppliers.view'); $s = $this->load(); $id = (int)$s['id'];
        $this->render('supplier/view', ['title' => 'Supplier: ' . $s['name'], 's' => $s, 'balance' => Finance::supplierBalance($id),
            'pos' => DB::all('SELECT * FROM purchase_orders WHERE supplier_id=? ORDER BY id DESC LIMIT 20', [$id]),
            'invoices' => DB::all('SELECT i.*, i.total - COALESCE((SELECT SUM(amount) FROM supplier_payments p WHERE p.invoice_id=i.id),0) AS due FROM purchase_invoices i WHERE supplier_id=? ORDER BY id DESC LIMIT 30', [$id]),
            'payments' => DB::all('SELECT p.*, i.inv_no FROM supplier_payments p LEFT JOIN purchase_invoices i ON i.id=p.invoice_id WHERE p.supplier_id=? ORDER BY p.id DESC LIMIT 30', [$id]),
            'products' => DB::all('SELECT sp.*, p.name, p.sku FROM supplier_products sp JOIN products p ON p.id=sp.product_id WHERE sp.supplier_id=? ORDER BY p.name', [$id]),
            'allProducts' => DB::all('SELECT id, sku, name FROM products WHERE is_archived=0 AND is_active=1 ORDER BY name LIMIT 2000')]);
    }
    function action_payment(): void {
        $this->postOnly(); $this->need('admin.suppliers.payment'); $s = $this->load(); $id = (int)$s['id'];
        $d = ['amount' => input('amount'), 'method' => input('method'), 'paid_date' => input('paid_date', date('Y-m-d')), 'reference' => input('reference') ?: null, 'note' => input('note') ?: null, 'invoice_id' => input('invoice_id') ?: null];
        $err = Validator::check($d, ['amount' => ['label' => 'Amount', 'rules' => 'required|pos'], 'method' => ['label' => 'Method', 'rules' => 'required|in:cash,card,bank_transfer,digital'], 'paid_date' => ['label' => 'Date', 'rules' => 'required|date'],
            'reference' => ['label' => 'Reference', 'rules' => 'max:80'], 'note' => ['label' => 'Note', 'rules' => 'max:255']]);
        $amt = round((float)$d['amount'], 2);
        if (!$err) {
            if ($d['invoice_id']) {
                $inv = DB::one('SELECT total FROM purchase_invoices WHERE id=? AND supplier_id=?', [$d['invoice_id'], $id]);
                if (!$inv) $err['invoice_id'] = 'Invoice does not belong to this supplier.';
                else { $due = (float)$inv['total'] - (float)DB::val('SELECT COALESCE(SUM(amount),0) FROM supplier_payments WHERE invoice_id=?', [$d['invoice_id']]); if ($amt > $due + 0.004) $err['amount'] = 'Amount exceeds the invoice balance (' . money($due) . ').'; }
            } elseif ($amt > max(0, Finance::supplierBalance($id)) + 0.004) $err['amount'] = 'Amount exceeds the outstanding balance (' . money(Finance::supplierBalance($id)) . ').';
        }
        if ($err) { flash('danger', implode(' ', $err)); redirect('supplier/view', ['id' => $id]); }
        DB::tx(function () use ($d, $amt, $id) {
            $pid = DB::insert('supplier_payments', ['pay_no' => next_number('supplier_pay', 'SP-', 6), 'supplier_id' => $id, 'invoice_id' => $d['invoice_id'], 'amount' => $amt, 'method' => $d['method'], 'reference' => $d['reference'], 'note' => $d['note'], 'paid_date' => $d['paid_date'], 'user_id' => Auth::id(), 'branch_id' => $this->branchId()]);
            fin_txn('supplier_payment', 'out', $d['method'], $amt, $this->branchId(), null, 'supplier_payment', $pid, 'Supplier payment');
            if ($d['invoice_id']) Finance::refreshInvoice((int)$d['invoice_id']);
            audit('supplier_payment', 'suppliers', $id, ['amount' => $amt, 'method' => $d['method']]);
        });
        flash('success', 'Payment recorded.'); redirect('supplier/view', ['id' => $id]);
    }
    function action_assign(): void {
        $this->postOnly(); $this->need('admin.suppliers.products'); $s = $this->load(); $pid = (int)input('product_id');
        if (!DB::val('SELECT 1 FROM products WHERE id=?', [$pid])) { flash('danger', 'Choose a product.'); redirect('supplier/view', ['id' => $s['id']]); }
        $price = input('last_price') !== '' && input('last_price') !== null ? round((float)input('last_price'), 2) : null; if ($price !== null && $price < 0) $price = null;
        DB::q('INSERT INTO supplier_products (supplier_id, product_id, supplier_sku, last_price) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE supplier_sku=VALUES(supplier_sku), last_price=VALUES(last_price)', [$s['id'], $pid, substr((string)input('supplier_sku', ''), 0, 60) ?: null, $price]);
        audit('assign_product', 'suppliers', $s['id'], ['product_id' => $pid]); flash('success', 'Product assigned.'); redirect('supplier/view', ['id' => $s['id']]);
    }
    function action_unassign(): void {
        $this->postOnly(); $this->need('admin.suppliers.products'); $s = $this->load(); DB::q('DELETE FROM supplier_products WHERE supplier_id=? AND product_id=?', [$s['id'], (int)input('product_id')]);
        audit('unassign_product', 'suppliers', $s['id'], ['product_id' => (int)input('product_id')]); flash('success', 'Removed.'); redirect('supplier/view', ['id' => $s['id']]);
    }
    function action_statement(): void {
        $this->need('admin.suppliers.view'); $s = $this->load(); $id = (int)$s['id'];
        $from = (string)input('from', date('Y-m-01', strtotime('-2 months'))); $to = (string)input('to', date('Y-m-d'));
        $ev = DB::all("SELECT invoice_date d, CONCAT('Invoice ', inv_no) ref, total debit, 0 credit FROM purchase_invoices WHERE supplier_id=?
            UNION ALL SELECT paid_date, CONCAT('Payment ', pay_no), 0, amount FROM supplier_payments WHERE supplier_id=?
            UNION ALL SELECT DATE(created_at), CONCAT('Return ', pr_no), 0, total FROM purchase_returns WHERE supplier_id=? AND status='approved' ORDER BY d, ref", [$id, $id, $id]);
        $bal = 0; $rows = [];
        foreach ($ev as $r) { $bal += $r['debit'] - $r['credit']; if ($r['d'] >= $from && $r['d'] <= $to) $rows[] = [$r['d'], $r['ref'], (float)$r['debit'], (float)$r['credit'], round($bal, 2)]; }
        if (input('export') === 'csv') Report::csv("statement_{$s['code']}.csv", ['Date', 'Reference', 'Debit', 'Credit', 'Balance'], $rows);
        if (input('export') === 'pdf') Report::pdf("statement_{$s['code']}.pdf", 'Supplier Statement - ' . $s['name'], "$from to $to", ['Date', 'Reference', 'Invoiced', 'Paid/Credit', 'Balance'], $rows, [2 => 'r', 3 => 'r', 4 => 'r']);
        $this->render('supplier/statement', ['title' => 'Supplier statement', 's' => $s, 'rows' => $rows, 'from' => $from, 'to' => $to, 'balance' => Finance::supplierBalance($id)]);
    }
}
