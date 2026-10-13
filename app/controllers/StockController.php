<?php
class StockController extends Controller {
    protected ?string $module = 'inventory';
    private function warehouses(): array { return DB::all('SELECT w.id, CONCAT(b.name, " / ", w.name) AS name FROM warehouses w JOIN branches b ON b.id=w.branch_id WHERE w.is_active=1 ORDER BY b.name, w.name'); }

    function action_index(): void {
        $this->need('inventory.stock.view'); $wh = (int)input('warehouse', 0); $q = (string)input('q', ''); $flt = (string)input('filter', '');
        $w = ['p.is_archived=0']; $par = [];
        if ($q !== '') { $w[] = '(p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ? OR p.isbn LIKE ?)'; $l = '%' . addcslashes($q, '%_\\') . '%'; array_push($par, $l, $l, $l, $l); }
        $join = $wh ? 'LEFT JOIN stock s ON s.product_id=p.id AND s.warehouse_id=' . $wh : 'LEFT JOIN stock s ON s.product_id=p.id';
        $having = ['low' => 'HAVING qty <= p.reorder_level AND qty > 0', 'out' => 'HAVING qty <= 0', 'damaged' => 'HAVING damaged > 0'][$flt] ?? '';
        $base = "FROM products p $join WHERE " . implode(' AND ', $w) . " GROUP BY p.id $having";
        $sel = "SELECT p.id, p.sku, p.name, p.reorder_level, p.min_stock, p.cost_price, COALESCE(SUM(s.qty),0) AS qty, COALESCE(SUM(s.reserved_qty),0) AS reserved, COALESCE(SUM(s.damaged_qty),0) AS damaged";
        $total = (int)DB::val("SELECT COUNT(*) FROM ($sel $base) x", $par); [$page, $pages, $off] = paginate($total, 25, (int)input('page', 1));
        $rows = DB::all("$sel $base ORDER BY p.name LIMIT 25 OFFSET $off", $par);
        if (input('export') === 'csv') { $all = DB::all("$sel $base ORDER BY p.name", $par); Report::csv('stock_levels.csv', ['SKU', 'Product', 'On hand', 'Reserved', 'Available', 'Damaged', 'Reorder level', 'Cost', 'Value'], array_map(fn($r) => [$r['sku'], $r['name'], $r['qty'], $r['reserved'], $r['qty'] - $r['reserved'], $r['damaged'], $r['reorder_level'], $r['cost_price'], $r['qty'] * $r['cost_price']], $all)); }
        $this->render('stock/index', ['title' => 'Stock Levels', 'rows' => $rows, 'wh' => $wh, 'q' => $q, 'flt' => $flt, 'page' => $page, 'pages' => $pages, 'total' => $total, 'warehouses' => $this->warehouses()]);
    }
    function action_adjustments(): void {
        $this->need('inventory.stock.view'); $st = (string)input('status', '');
        $w = '1=1'; $p = []; if (in_array($st, ['pending', 'approved', 'rejected'], true)) { $w = 'a.status=?'; $p[] = $st; }
        $rows = DB::all("SELECT a.*, p.sku, p.name, wh.name AS warehouse, u.username AS creator, ap.username AS approver FROM stock_adjustments a JOIN products p ON p.id=a.product_id JOIN warehouses wh ON wh.id=a.warehouse_id LEFT JOIN users u ON u.id=a.created_by LEFT JOIN users ap ON ap.id=a.approved_by WHERE $w ORDER BY a.id DESC LIMIT 200", $p);
        $this->render('stock/adjustments', ['title' => 'Stock Adjustments', 'rows' => $rows, 'st' => $st]);
    }
    function action_adjust(): void {
        $this->need('inventory.stock.adjust');
        if (is_post()) {
            $d = ['warehouse_id' => input('warehouse_id'), 'product_id' => input('product_id'), 'type' => input('type'), 'qty' => input('qty'), 'reason' => input('reason')];
            $err = Validator::check($d, ['warehouse_id' => ['label' => 'Warehouse', 'rules' => 'required|exists:warehouses,id'], 'product_id' => ['label' => 'Product', 'rules' => 'required|exists:products,id'],
                'type' => ['label' => 'Type', 'rules' => 'required|in:increase,decrease,damaged,lost,reserve,release,expired,spoilage,theft'], 'qty' => ['label' => 'Quantity', 'rules' => 'required|numeric'], 'reason' => ['label' => 'Reason', 'rules' => 'required|min:3|max:255']]);
            if (!$err) { $w = DB::val('SELECT sold_by FROM products WHERE id=?', [$d['product_id']]) === 'weight'; $qv = parse_qty($d['qty'], $w); if ($qv === null) $err['qty'] = 'Enter a quantity above zero (a whole number for items sold by unit, up to 3 decimals for items sold by weight).'; else $d['qty'] = $qv; }
            if (!$err && in_array($d['type'], ['decrease', 'damaged', 'lost', 'reserve', 'expired', 'spoilage', 'theft'], true)) {
                $s = DB::one('SELECT qty, reserved_qty FROM stock WHERE warehouse_id=? AND product_id=?', [$d['warehouse_id'], $d['product_id']]) ?: ['qty' => 0, 'reserved_qty' => 0];
                $limit = $d['type'] === 'reserve' ? $s['qty'] - $s['reserved_qty'] : $s['qty'];
                if ($d['qty'] > $limit + 0.0005) $err['qty'] = 'Quantity exceeds available stock (' . fq($limit) . ').';
            }
            if (!$err && $d['type'] === 'release') { $r = (float)DB::val('SELECT reserved_qty FROM stock WHERE warehouse_id=? AND product_id=?', [$d['warehouse_id'], $d['product_id']]); if ($d['qty'] > $r + 0.0005) $err['qty'] = 'Only ' . fq($r) . ' reserved.'; }
            if ($err) { flash('danger', implode(' ', $err)); $this->withOld($_POST); redirect('stock/adjust'); }
            $auto = Auth::can('inventory.stock.approve');
            $id = DB::tx(function () use ($d, $auto) {
                $id = DB::insert('stock_adjustments', ['adj_no' => next_number('adjust', 'ADJ-', 6), 'warehouse_id' => $d['warehouse_id'], 'product_id' => $d['product_id'], 'type' => $d['type'], 'qty' => (float)$d['qty'], 'reason' => $d['reason'], 'created_by' => Auth::id()]);
                if ($auto) $this->apply($id, Auth::id()); return $id;
            });
            audit('stock_adjustment', 'stock_adjustments', $id, $d + ['auto_approved' => $auto]);
            flash('success', $auto ? 'Adjustment applied.' : 'Adjustment submitted for approval.'); redirect('stock/adjustments');
        }
        $this->render('stock/adjust', ['title' => 'Stock Adjustment', 'warehouses' => $this->warehouses(), 'products' => Lines::products()]);
    }
    private function apply(int $id, int $by): void {
        $a = DB::one('SELECT * FROM stock_adjustments WHERE id=? FOR UPDATE', [$id]); if (!$a || $a['status'] !== 'pending') throw new RuntimeException('Adjustment is not pending.');
        $wh = (int)$a['warehouse_id']; $pid = (int)$a['product_id']; $q = (float)$a['qty']; $note = $a['adj_no'] . ': ' . $a['reason'];
        switch ($a['type']) {
            case 'increase': Stock::change($wh, $pid, $q, 'adjust_in', 'adjustment', $id, $note); break;
            case 'decrease': Stock::change($wh, $pid, -$q, 'adjust_out', 'adjustment', $id, $note); break;
            case 'lost': Stock::change($wh, $pid, -$q, 'lost', 'adjustment', $id, $note); break;
            case 'expired': case 'spoilage': case 'theft': Stock::change($wh, $pid, -$q, 'wastage_' . $a['type'], 'adjustment', $id, $note, false, $a['batch_id'] ? ['id' => (int)$a['batch_id']] : null); break;
            case 'damaged': Stock::change($wh, $pid, -$q, 'damaged', 'adjustment', $id, $note); DB::q('UPDATE stock SET damaged_qty = damaged_qty + ? WHERE warehouse_id=? AND product_id=?', [$q, $wh, $pid]); break;
            case 'reserve': DB::q('INSERT IGNORE INTO stock (warehouse_id, product_id, qty) VALUES (?,?,0)', [$wh, $pid]); $s = DB::one('SELECT qty, reserved_qty FROM stock WHERE warehouse_id=? AND product_id=? FOR UPDATE', [$wh, $pid]);
                if ($s['qty'] - $s['reserved_qty'] < $q - 0.0005) throw new RuntimeException('Not enough available stock to reserve.'); DB::q('UPDATE stock SET reserved_qty = reserved_qty + ? WHERE warehouse_id=? AND product_id=?', [$q, $wh, $pid]); break;
            case 'release': $s = DB::one('SELECT reserved_qty FROM stock WHERE warehouse_id=? AND product_id=? FOR UPDATE', [$wh, $pid]); if (!$s || $s['reserved_qty'] < $q - 0.0005) throw new RuntimeException('Not enough reserved stock to release.'); DB::q('UPDATE stock SET reserved_qty = reserved_qty - ? WHERE warehouse_id=? AND product_id=?', [$q, $wh, $pid]); break;
        }
        DB::update('stock_adjustments', ['status' => 'approved', 'approved_by' => $by, 'approved_at' => date('Y-m-d H:i:s')], 'id=?', [$id]);
    }
    function action_decide(): void {
        $this->postOnly(); $this->need('inventory.stock.approve'); $id = (int)input('id'); $dec = (string)input('decision');
        if (!in_array($dec, ['approved', 'rejected'], true)) $this->abort(400, 'Invalid decision.');
        try {
            DB::tx(function () use ($id, $dec) {
                $a = DB::one('SELECT * FROM stock_adjustments WHERE id=? FOR UPDATE', [$id]); if (!$a || $a['status'] !== 'pending') throw new RuntimeException('Adjustment is not pending.');
                if ((int)$a['created_by'] === Auth::id() && !Auth::isSuper()) throw new RuntimeException('You cannot approve your own adjustment.');
                if ($dec === 'approved') $this->apply($id, Auth::id()); else DB::update('stock_adjustments', ['status' => 'rejected', 'approved_by' => Auth::id(), 'approved_at' => date('Y-m-d H:i:s')], 'id=?', [$id]);
            });
            audit('stock_adjustment_' . $dec, 'stock_adjustments', $id); flash('success', 'Adjustment ' . $dec . '.');
        } catch (RuntimeException $e) { flash('danger', $e->getMessage()); }
        redirect('stock/adjustments');
    }
    function action_movements(): void {
        $this->need('inventory.stock.view'); $w = ['1=1']; $p = [];
        if (($v = (int)input('product', 0))) { $w[] = 'm.product_id=?'; $p[] = $v; } if (($v = (int)input('warehouse', 0))) { $w[] = 'm.warehouse_id=?'; $p[] = $v; } if (($v = (string)input('type', '')) !== '') { $w[] = 'm.type=?'; $p[] = $v; }
        $from = (string)input('from', date('Y-m-01')); $to = (string)input('to', date('Y-m-d')); $w[] = 'm.created_at BETWEEN ? AND ?'; array_push($p, "$from 00:00:00", "$to 23:59:59");
        $rows = DB::all('SELECT m.*, p.sku, p.name, wh.name AS warehouse, u.username FROM stock_movements m JOIN products p ON p.id=m.product_id JOIN warehouses wh ON wh.id=m.warehouse_id LEFT JOIN users u ON u.id=m.user_id WHERE ' . implode(' AND ', $w) . ' ORDER BY m.id DESC LIMIT 500', $p);
        $this->render('stock/movements', ['title' => 'Stock Movements', 'rows' => $rows, 'from' => $from, 'to' => $to, 'warehouses' => $this->warehouses(), 'types' => array_column(DB::all('SELECT DISTINCT type FROM stock_movements ORDER BY type'), 'type')]);
    }
}
