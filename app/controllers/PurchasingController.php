<?php
class PurchasingController extends Controller {
    protected ?string $module = 'inventory';
    private function po(int $id): array { $p = DB::one('SELECT po.*, s.name AS supplier, s.code AS supplier_code, w.name AS warehouse FROM purchase_orders po JOIN suppliers s ON s.id=po.supplier_id JOIN warehouses w ON w.id=po.warehouse_id WHERE po.id=?', [$id]); if (!$p) $this->abort(404, 'Purchase order not found.'); return $p; }
    private function suppliers(): array { return DB::all('SELECT id, name FROM suppliers WHERE is_active=1 ORDER BY name'); }
    private function warehouses(): array { return DB::all('SELECT w.id, CONCAT(b.name," / ",w.name) name FROM warehouses w JOIN branches b ON b.id=w.branch_id WHERE w.is_active=1 ORDER BY b.name, w.name'); }

    // ---------- Purchase orders
    function action_index(): void {
        $this->need('inventory.purchasing.view'); $st = (string)input('status', ''); $w = '1=1'; $p = [];
        if (in_array($st, ['draft', 'submitted', 'approved', 'partial', 'received', 'cancelled'], true)) { $w = 'po.status=?'; $p[] = $st; }
        $rows = DB::all("SELECT po.*, s.name AS supplier FROM purchase_orders po JOIN suppliers s ON s.id=po.supplier_id WHERE $w ORDER BY po.id DESC LIMIT 200", $p);
        $this->render('purchasing/index', ['title' => 'Purchase Orders', 'rows' => $rows, 'st' => $st]);
    }
    function action_form(): void {
        $id = (int)input('id', 0); $po = null; $lines = [];
        if ($id) { $this->need('inventory.purchasing.create'); $po = $this->po($id); if ($po['status'] !== 'draft') { flash('warning', 'Only draft orders can be edited.'); redirect('purchasing/view', ['id' => $id]); } $lines = DB::all('SELECT product_id, qty, price FROM purchase_order_items WHERE po_id=?', [$id]); }
        else $this->need('inventory.purchasing.create');
        $this->render('purchasing/form', ['title' => $id ? 'Edit PO ' . $po['po_no'] : 'New Purchase Order', 'po' => $po, 'lines' => $lines, 'suppliers' => $this->suppliers(), 'warehouses' => $this->warehouses(), 'products' => Lines::products()]);
    }
    function action_save(): void {
        $this->postOnly(); $this->need('inventory.purchasing.create'); $id = (int)input('id', 0);
        if ($id) { $po = $this->po($id); if ($po['status'] !== 'draft') $this->abort(409, 'Only draft orders can be edited.'); }
        $d = ['supplier_id' => input('supplier_id'), 'warehouse_id' => input('warehouse_id'), 'order_date' => input('order_date'), 'expected_date' => input('expected_date') ?: null, 'notes' => input('notes') ?: null];
        $err = Validator::check($d, ['supplier_id' => ['label' => 'Supplier', 'rules' => 'required|exists:suppliers,id'], 'warehouse_id' => ['label' => 'Warehouse', 'rules' => 'required|exists:warehouses,id'], 'order_date' => ['label' => 'Order date', 'rules' => 'required|date'], 'expected_date' => ['label' => 'Expected date', 'rules' => 'date'], 'notes' => ['label' => 'Notes', 'rules' => 'max:255']]);
        if (!$err && $d['expected_date'] && $d['expected_date'] < $d['order_date']) $err[] = 'Expected date cannot be before the order date.';
        [$lines, $lerr] = Lines::parse(true); if ($lerr) $err[] = $lerr;
        if ($err) { flash('danger', implode(' ', $err)); $this->withOld($_POST); redirect('purchasing/form', $id ? ['id' => $id] : []); }
        $total = array_sum(array_map(fn($l) => $l['qty'] * $l['price'], $lines));
        $poId = DB::tx(function () use ($id, $d, $lines, $total) {
            $d['total'] = round($total, 2);
            if ($id) { DB::update('purchase_orders', $d, 'id=?', [$id]); DB::q('DELETE FROM purchase_order_items WHERE po_id=?', [$id]); $pid = $id; }
            else { $d['po_no'] = next_number('po', 'PO-', 6); $d['created_by'] = Auth::id(); $pid = DB::insert('purchase_orders', $d); }
            foreach ($lines as $l) DB::insert('purchase_order_items', ['po_id' => $pid, 'product_id' => $l['product_id'], 'qty' => $l['qty'], 'price' => $l['price']]);
            return $pid;
        });
        audit($id ? 'update' : 'create', 'purchase_orders', $poId, ['total' => $total]); flash('success', 'Purchase order saved as draft.'); redirect('purchasing/view', ['id' => $poId]);
    }
    function action_view(): void {
        $this->need('inventory.purchasing.view'); $po = $this->po((int)input('id'));
        $items = DB::all('SELECT i.*, p.sku, p.name FROM purchase_order_items i JOIN products p ON p.id=i.product_id WHERE i.po_id=?', [$po['id']]);
        $grns = DB::all('SELECT g.*, (SELECT id FROM purchase_invoices pi WHERE pi.grn_id=g.id LIMIT 1) AS invoice_id FROM grns g WHERE po_id=? ORDER BY id', [$po['id']]);
        $this->render('purchasing/view', ['title' => 'PO ' . $po['po_no'], 'po' => $po, 'items' => $items, 'grns' => $grns]);
    }
    function action_status(): void {
        $this->postOnly(); $id = (int)input('id'); $to = (string)input('to'); $po = $this->po($id);
        $rules = ['submitted' => ['draft', 'inventory.purchasing.create'], 'approved' => ['submitted', 'inventory.purchasing.approve'], 'cancelled' => ['draft,submitted,approved', 'inventory.purchasing.approve'], 'draft' => ['submitted', 'inventory.purchasing.create']];
        if (!isset($rules[$to])) $this->abort(400, 'Invalid status.'); [$from, $perm] = $rules[$to]; $this->need($perm);
        if (!in_array($po['status'], explode(',', $from), true)) { flash('danger', "Cannot move from {$po['status']} to $to."); redirect('purchasing/view', ['id' => $id]); }
        if ($to === 'approved' && (int)$po['created_by'] === Auth::id() && !Auth::isSuper()) { flash('danger', 'A different user must approve this purchase order.'); redirect('purchasing/view', ['id' => $id]); }
        if ($to === 'cancelled' && DB::val('SELECT COUNT(*) FROM grns WHERE po_id=?', [$id])) { flash('danger', 'Goods were already received against this order.'); redirect('purchasing/view', ['id' => $id]); }
        $upd = ['status' => $to]; if ($to === 'approved') $upd['approved_by'] = Auth::id();
        DB::update('purchase_orders', $upd, 'id=?', [$id]); audit('po_' . $to, 'purchase_orders', $id); flash('success', 'Order ' . $to . '.'); redirect('purchasing/view', ['id' => $id]);
    }
    // ---------- Goods receiving
    function action_receive(): void {
        $this->need('inventory.purchasing.receive'); $po = $this->po((int)input('id'));
        if (!in_array($po['status'], ['approved', 'partial'], true)) { flash('warning', 'Only approved orders can receive goods.'); redirect('purchasing/view', ['id' => $po['id']]); }
        $items = DB::all('SELECT i.*, p.sku, p.name, p.track_expiry, p.sold_by, p.shelf_life_days FROM purchase_order_items i JOIN products p ON p.id=i.product_id WHERE i.po_id=? AND i.received_qty < i.qty', [$po['id']]);
        if (is_post()) {
            $recv = (array)($_POST['recv'] ?? []); $dmg = (array)($_POST['damaged'] ?? []); $rej = (array)($_POST['rejected'] ?? []); $bno = (array)($_POST['batch_no'] ?? []); $bex = (array)($_POST['expiry_date'] ?? []); $err = []; $rows = [];
            $date0 = (string)input('received_date', date('Y-m-d'));
            foreach ($items as $it) {
                $k = (int)$it['id']; $w = $it['sold_by'] === 'weight';
                $pq = function ($v) use ($w) { $v = trim((string)$v); if ($v === '' || (is_numeric($v) && (float)$v == 0)) return 0.0; return parse_qty($v, $w); };
                $r = $pq($recv[$k] ?? '0'); $d = $pq($dmg[$k] ?? '0'); $x = $pq($rej[$k] ?? '0');
                if ($r === null || $d === null || $x === null) { $err[] = $it['name'] . ': quantities must be whole numbers (decimals only for items sold by weight).'; continue; }
                $open = round($it['qty'] - $it['received_qty'], 3);
                if ($r > $open + 0.0005) $err[] = "{$it['name']}: received (" . fq($r) . ") exceeds outstanding (" . fq($open) . ').';
                if ($d + $x > $r + 0.0005) $err[] = "{$it['name']}: damaged + rejected cannot exceed received.";
                $acc = round($r - $d - $x, 3); $batch = null;
                if ($r > 0 && $it['track_expiry'] && $acc > 0) {
                    $ex = trim((string)($bex[$k] ?? '')); $no = substr(trim((string)($bno[$k] ?? '')), 0, 40);
                    if ($ex === '' && $it['shelf_life_days']) $ex = date('Y-m-d', strtotime($date0 . ' +' . (int)$it['shelf_life_days'] . ' day'));
                    if ($ex === '') $err[] = $it['name'] . ': enter the expiry date (this product is expiry-tracked).';
                    elseif (Validator::check(['e' => $ex], ['e' => ['label' => 'Expiry', 'rules' => 'date']])) $err[] = $it['name'] . ': invalid expiry date.';
                    else $batch = ['batch_no' => $no, 'expiry_date' => $ex];
                }
                if ($r > 0) $rows[] = ['it' => $it, 'recv' => $r, 'dmg' => $d, 'rej' => $x, 'acc' => $acc, 'batch' => $batch];
            }
            $date = (string)input('received_date', date('Y-m-d')); if (Validator::check(['d' => $date], ['d' => ['label' => 'Date', 'rules' => 'required|date']])) $err[] = 'Invalid received date.';
            if (!$rows && !$err) $err[] = 'Enter at least one received quantity.';
            if ($err) { flash('danger', implode(' ', array_unique($err))); redirect('purchasing/receive', ['id' => $po['id']]); }
            $grnId = DB::tx(function () use ($po, $rows, $date) {
                $lock = DB::one('SELECT status FROM purchase_orders WHERE id=? FOR UPDATE', [$po['id']]); if (!in_array($lock['status'], ['approved', 'partial'], true)) throw new RuntimeException('Order status changed.');
                $gid = DB::insert('grns', ['grn_no' => next_number('grn', 'GRN-', 6), 'po_id' => $po['id'], 'supplier_id' => $po['supplier_id'], 'warehouse_id' => $po['warehouse_id'], 'received_date' => $date, 'notes' => input('notes') ?: null, 'created_by' => Auth::id()]);
                foreach ($rows as $r) {
                    $it = $r['it']; $short = max(0, round($it['qty'] - $it['received_qty'] - $r['recv'], 3));
                    DB::insert('grn_items', ['grn_id' => $gid, 'po_item_id' => $it['id'], 'product_id' => $it['product_id'], 'received_qty' => $r['recv'], 'accepted_qty' => $r['acc'], 'damaged_qty' => $r['dmg'], 'rejected_qty' => $r['rej'], 'batch_no' => $r['batch']['batch_no'] ?? null, 'expiry_date' => $r['batch']['expiry_date'] ?? null, 'shortage_qty' => $short, 'price' => $it['price']]);
                    DB::q('UPDATE purchase_order_items SET received_qty = received_qty + ? WHERE id=?', [$r['recv'], $it['id']]);
                    if ($r['acc'] > 0) { Stock::change((int)$po['warehouse_id'], (int)$it['product_id'], $r['acc'], 'purchase', 'grn', $gid, 'GRN for ' . $po['po_no'], false, $r['batch']); }
                    if ($r['dmg'] > 0) { DB::q('INSERT IGNORE INTO stock (warehouse_id, product_id, qty) VALUES (?,?,0)', [$po['warehouse_id'], $it['product_id']]); DB::q('UPDATE stock SET damaged_qty = damaged_qty + ? WHERE warehouse_id=? AND product_id=?', [$r['dmg'], $po['warehouse_id'], $it['product_id']]); }
                    $old = (float)DB::val('SELECT cost_price FROM products WHERE id=?', [$it['product_id']]);
                    if ($r['acc'] > 0 && abs($old - (float)$it['price']) > 0.004) { DB::update('products', ['cost_price' => $it['price']], 'id=?', [$it['product_id']]); DB::insert('product_price_history', ['product_id' => $it['product_id'], 'field_name' => 'cost_price', 'old_value' => $old, 'new_value' => $it['price'], 'user_id' => Auth::id()]); audit('price_change', 'products', $it['product_id'], ['cost_price' => [$old, $it['price']], 'via' => $po['po_no']]); }
                    DB::q('INSERT INTO supplier_products (supplier_id, product_id, last_price) VALUES (?,?,?) ON DUPLICATE KEY UPDATE last_price=VALUES(last_price)', [$po['supplier_id'], $it['product_id'], $it['price']]);
                }
                $open = (int)DB::val('SELECT COUNT(*) FROM purchase_order_items WHERE po_id=? AND received_qty < qty', [$po['id']]);
                DB::update('purchase_orders', ['status' => $open ? 'partial' : 'received'], 'id=?', [$po['id']]); return $gid;
            });
            audit('goods_received', 'grns', $grnId, ['po' => $po['po_no']]); flash('success', 'Goods received and stock updated.'); redirect('purchasing/grn', ['id' => $grnId]);
        }
        $this->render('purchasing/receive', ['title' => 'Receive goods - ' . $po['po_no'], 'po' => $po, 'items' => $items]);
    }
    function action_grns(): void {
        $this->need('inventory.purchasing.view');
        $rows = DB::all('SELECT g.*, s.name AS supplier, po.po_no, (SELECT inv_no FROM purchase_invoices pi WHERE pi.grn_id=g.id LIMIT 1) AS inv_no FROM grns g JOIN suppliers s ON s.id=g.supplier_id JOIN purchase_orders po ON po.id=g.po_id ORDER BY g.id DESC LIMIT 200');
        $this->render('purchasing/grns', ['title' => 'Goods Received', 'rows' => $rows]);
    }
    function action_grn(): void {
        $this->need('inventory.purchasing.view'); $g = DB::one('SELECT g.*, s.name AS supplier, po.po_no FROM grns g JOIN suppliers s ON s.id=g.supplier_id JOIN purchase_orders po ON po.id=g.po_id WHERE g.id=?', [(int)input('id')]); if (!$g) $this->abort(404, 'GRN not found.');
        $items = DB::all('SELECT gi.*, p.sku, p.name FROM grn_items gi JOIN products p ON p.id=gi.product_id WHERE gi.grn_id=?', [$g['id']]);
        $inv = DB::one('SELECT * FROM purchase_invoices WHERE grn_id=?', [$g['id']]);
        $this->render('purchasing/grn', ['title' => $g['grn_no'], 'g' => $g, 'items' => $items, 'inv' => $inv]);
    }
    // ---------- Purchase invoices
    function action_invoice_create(): void {
        $this->postOnly(); $this->need('inventory.purchasing.invoice'); $gid = (int)input('grn_id'); $g = DB::one('SELECT * FROM grns WHERE id=?', [$gid]); if (!$g) $this->abort(404, 'GRN not found.');
        if (DB::val('SELECT 1 FROM purchase_invoices WHERE grn_id=?', [$gid])) { flash('warning', 'An invoice already exists for this GRN.'); redirect('purchasing/grn', ['id' => $gid]); }
        $d = ['supplier_invoice_no' => input('supplier_invoice_no') ?: null, 'invoice_date' => input('invoice_date', date('Y-m-d')), 'due_date' => input('due_date') ?: null];
        $err = Validator::check($d, ['supplier_invoice_no' => ['label' => 'Supplier invoice no', 'rules' => 'max:60'], 'invoice_date' => ['label' => 'Invoice date', 'rules' => 'required|date'], 'due_date' => ['label' => 'Due date', 'rules' => 'date']]);
        if (!$err && $d['due_date'] && $d['due_date'] < $d['invoice_date']) $err[] = 'Due date cannot be before the invoice date.';
        if ($err) { flash('danger', implode(' ', $err)); redirect('purchasing/grn', ['id' => $gid]); }
        $total = (float)DB::val('SELECT COALESCE(SUM(accepted_qty * price + damaged_qty * price),0) FROM grn_items WHERE grn_id=?', [$gid]);
        $total = (float)DB::val('SELECT COALESCE(SUM(accepted_qty * price),0) FROM grn_items WHERE grn_id=?', [$gid]);
        $id = DB::insert('purchase_invoices', $d + ['inv_no' => next_number('pinv', 'PI-', 6), 'supplier_id' => $g['supplier_id'], 'grn_id' => $gid, 'po_id' => $g['po_id'], 'total' => round($total, 2), 'created_by' => Auth::id()]);
        audit('purchase_invoice', 'purchase_invoices', $id, ['total' => $total]); flash('success', 'Purchase invoice created and added to supplier payables.'); redirect('purchasing/invoices');
    }
    function action_invoices(): void {
        $this->need('inventory.purchasing.view');
        $rows = DB::all('SELECT i.*, s.name AS supplier, i.total - COALESCE((SELECT SUM(amount) FROM supplier_payments p WHERE p.invoice_id=i.id),0) AS due FROM purchase_invoices i JOIN suppliers s ON s.id=i.supplier_id ORDER BY i.id DESC LIMIT 200');
        $this->render('purchasing/invoices', ['title' => 'Purchase Invoices', 'rows' => $rows]);
    }
    // ---------- Purchase returns
    function action_returns(): void {
        $this->need('inventory.purchasing.view');
        $rows = DB::all('SELECT r.*, s.name AS supplier FROM purchase_returns r JOIN suppliers s ON s.id=r.supplier_id ORDER BY r.id DESC LIMIT 200');
        $this->render('purchasing/returns', ['title' => 'Purchase Returns', 'rows' => $rows]);
    }
    function action_return_form(): void {
        $this->need('inventory.purchasing.create');
        if (is_post()) {
            $d = ['supplier_id' => input('supplier_id'), 'warehouse_id' => input('warehouse_id'), 'reason' => input('reason')];
            $err = Validator::check($d, ['supplier_id' => ['label' => 'Supplier', 'rules' => 'required|exists:suppliers,id'], 'warehouse_id' => ['label' => 'Warehouse', 'rules' => 'required|exists:warehouses,id'], 'reason' => ['label' => 'Reason', 'rules' => 'required|min:3|max:255']]);
            [$lines, $lerr] = Lines::parse(true); if ($lerr) $err[] = $lerr;
            if (!$err) foreach ($lines as $l) { $av = Stock::available((int)$d['warehouse_id'], $l['product_id']); if ($l['qty'] > $av) { $err[] = 'Return quantity exceeds available stock for product #' . $l['product_id'] . " ($av)."; break; } }
            if ($err) { flash('danger', implode(' ', $err)); $this->withOld($_POST); redirect('purchasing/return_form'); }
            $id = DB::tx(function () use ($d, $lines) {
                $id = DB::insert('purchase_returns', ['pr_no' => next_number('pret', 'PR-', 6), 'supplier_id' => $d['supplier_id'], 'warehouse_id' => $d['warehouse_id'], 'reason' => $d['reason'], 'total' => round(array_sum(array_map(fn($l) => $l['qty'] * $l['price'], $lines)), 2), 'created_by' => Auth::id()]);
                foreach ($lines as $l) DB::insert('purchase_return_items', ['pr_id' => $id, 'product_id' => $l['product_id'], 'qty' => $l['qty'], 'price' => $l['price']]); return $id;
            });
            audit('create', 'purchase_returns', $id); flash('success', 'Return created; awaiting approval.'); redirect('purchasing/returns');
        }
        $this->render('purchasing/return_form', ['title' => 'New Purchase Return', 'suppliers' => $this->suppliers(), 'warehouses' => $this->warehouses(), 'products' => Lines::products(), 'lines' => []]);
    }
    function action_return_decide(): void {
        $this->postOnly(); $this->need('inventory.purchasing.approve'); $id = (int)input('id'); $dec = (string)input('decision'); if (!in_array($dec, ['approved', 'rejected'], true)) $this->abort(400, 'Invalid.');
        try { DB::tx(function () use ($id, $dec) {
            $r = DB::one('SELECT * FROM purchase_returns WHERE id=? FOR UPDATE', [$id]); if (!$r || $r['status'] !== 'pending') throw new RuntimeException('Return is not pending.');
            if ((int)$r['created_by'] === Auth::id() && !Auth::isSuper()) throw new RuntimeException('A different user must approve this return.');
            if ($dec === 'approved') foreach (DB::all('SELECT * FROM purchase_return_items WHERE pr_id=?', [$id]) as $i) Stock::change((int)$r['warehouse_id'], (int)$i['product_id'], -(float)$i['qty'], 'purchase_return', 'purchase_return', $id, $r['pr_no']);
            DB::update('purchase_returns', ['status' => $dec, 'approved_by' => Auth::id()], 'id=?', [$id]);
        }); audit('purchase_return_' . $dec, 'purchase_returns', $id); flash('success', 'Return ' . $dec . '; supplier credit recorded.'); } catch (RuntimeException $e) { flash('danger', $e->getMessage()); }
        redirect('purchasing/returns');
    }
}
