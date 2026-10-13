<?php
class TransfersController extends Controller {
    protected ?string $module = 'inventory';
    private function tr(int $id): array {
        $t = DB::one('SELECT t.*, CONCAT(b1.name," / ",w1.name) AS from_name, CONCAT(b2.name," / ",w2.name) AS to_name FROM stock_transfers t JOIN warehouses w1 ON w1.id=t.from_warehouse_id JOIN branches b1 ON b1.id=w1.branch_id JOIN warehouses w2 ON w2.id=t.to_warehouse_id JOIN branches b2 ON b2.id=w2.branch_id WHERE t.id=?', [$id]);
        if (!$t) $this->abort(404, 'Transfer not found.'); return $t;
    }
    private function warehouses(): array { return DB::all('SELECT w.id, CONCAT(b.name," / ",w.name) name FROM warehouses w JOIN branches b ON b.id=w.branch_id WHERE w.is_active=1 ORDER BY b.name, w.name'); }
    function action_index(): void {
        $this->need('inventory.transfers.view');
        $rows = DB::all('SELECT t.*, CONCAT(b1.name," / ",w1.name) AS from_name, CONCAT(b2.name," / ",w2.name) AS to_name FROM stock_transfers t JOIN warehouses w1 ON w1.id=t.from_warehouse_id JOIN branches b1 ON b1.id=w1.branch_id JOIN warehouses w2 ON w2.id=t.to_warehouse_id JOIN branches b2 ON b2.id=w2.branch_id ORDER BY t.id DESC LIMIT 200');
        $this->render('transfers/index', ['title' => 'Stock Transfers', 'rows' => $rows]);
    }
    function action_form(): void {
        $this->need('inventory.transfers.create');
        if (is_post()) {
            $from = (int)input('from_warehouse_id'); $to = (int)input('to_warehouse_id'); $err = [];
            if (!DB::val('SELECT 1 FROM warehouses WHERE id=? AND is_active=1', [$from]) || !DB::val('SELECT 1 FROM warehouses WHERE id=? AND is_active=1', [$to])) $err[] = 'Choose valid source and destination.';
            if ($from === $to) $err[] = 'Source and destination must be different.';
            if (mb_strlen((string)input('notes', '')) > 255) $err[] = 'Notes too long.';
            [$lines, $lerr] = Lines::parse(false); if ($lerr) $err[] = $lerr;
            if (!$err) foreach ($lines as $l) { $av = Stock::available($from, $l['product_id']); if ($l['qty'] > $av) { $err[] = 'Quantity exceeds available stock at source for ' . DB::val('SELECT name FROM products WHERE id=?', [$l['product_id']]) . " ($av)."; break; } }
            if ($err) { flash('danger', implode(' ', $err)); $this->withOld($_POST); redirect('transfers/form'); }
            $id = DB::tx(function () use ($from, $to, $lines) {
                $id = DB::insert('stock_transfers', ['tr_no' => next_number('transfer', 'TR-', 6), 'from_warehouse_id' => $from, 'to_warehouse_id' => $to, 'notes' => input('notes') ?: null, 'created_by' => Auth::id()]);
                foreach ($lines as $l) DB::insert('stock_transfer_items', ['transfer_id' => $id, 'product_id' => $l['product_id'], 'qty' => $l['qty']]); return $id;
            });
            audit('create', 'stock_transfers', $id); flash('success', 'Transfer created.'); redirect('transfers/view', ['id' => $id]);
        }
        $this->render('transfers/form', ['title' => 'New Stock Transfer', 'warehouses' => $this->warehouses(), 'products' => Lines::products(), 'lines' => []]);
    }
    function action_view(): void {
        $this->need('inventory.transfers.view'); $t = $this->tr((int)input('id'));
        $items = DB::all('SELECT i.*, p.sku, p.name FROM stock_transfer_items i JOIN products p ON p.id=i.product_id WHERE i.transfer_id=?', [$t['id']]);
        $this->render('transfers/view', ['title' => $t['tr_no'], 't' => $t, 'items' => $items]);
    }
    function action_step(): void {
        $this->postOnly(); $id = (int)input('id'); $to = (string)input('to');
        $map = ['approved' => ['draft', 'inventory.transfers.approve'], 'rejected' => ['draft', 'inventory.transfers.approve'], 'cancelled' => ['draft,approved', 'inventory.transfers.create'], 'dispatched' => ['approved', 'inventory.transfers.dispatch'], 'received' => ['dispatched', 'inventory.transfers.receive']];
        if (!isset($map[$to])) $this->abort(400, 'Invalid step.'); [$from, $perm] = $map[$to]; $this->need($perm);
        try {
            DB::tx(function () use ($id, $to, $from) {
                $t = DB::one('SELECT * FROM stock_transfers WHERE id=? FOR UPDATE', [$id]); if (!$t) throw new RuntimeException('Not found.');
                if (!in_array($t['status'], explode(',', $from), true)) throw new RuntimeException("Cannot move from {$t['status']} to $to.");
                $items = DB::all('SELECT * FROM stock_transfer_items WHERE transfer_id=?', [$id]); $upd = ['status' => $to];
                if ($to === 'approved') { if ((int)$t['created_by'] === Auth::id() && !Auth::isSuper()) throw new RuntimeException('A different user must approve this transfer.'); $upd['approved_by'] = Auth::id(); }
                if ($to === 'dispatched') { foreach ($items as $i) Stock::change((int)$t['from_warehouse_id'], (int)$i['product_id'], -(float)$i['qty'], 'transfer_out', 'transfer', $id, $t['tr_no']); $upd['dispatched_by'] = Auth::id(); }
                if ($to === 'received') { foreach ($items as $i) { Stock::change((int)$t['to_warehouse_id'], (int)$i['product_id'], (float)$i['qty'], 'transfer_in', 'transfer', $id, $t['tr_no']); DB::update('stock_transfer_items', ['received_qty' => $i['qty']], 'id=?', [$i['id']]); } $upd['received_by'] = Auth::id(); }
                DB::update('stock_transfers', $upd, 'id=?', [$id]);
            });
            audit('transfer_' . $to, 'stock_transfers', $id); flash('success', 'Transfer ' . $to . '.');
        } catch (RuntimeException $e) { flash('danger', $e->getMessage()); }
        redirect('transfers/view', ['id' => $id]);
    }
}
