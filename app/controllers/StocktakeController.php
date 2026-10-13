<?php
class StocktakeController extends Controller {
    protected ?string $module = 'inventory';
    private function st(int $id): array { $s = DB::one('SELECT s.*, CONCAT(b.name," / ",w.name) AS wname, c.name AS cname FROM stocktakes s JOIN warehouses w ON w.id=s.warehouse_id JOIN branches b ON b.id=w.branch_id LEFT JOIN categories c ON c.id=s.category_id WHERE s.id=?', [$id]); if (!$s) $this->abort(404, 'Stocktake not found.'); return $s; }
    function action_index(): void {
        $this->need('inventory.stocktake.view');
        $rows = DB::all('SELECT s.*, CONCAT(b.name," / ",w.name) AS wname, (SELECT COUNT(*) FROM stocktake_items i WHERE i.stocktake_id=s.id) items, (SELECT COUNT(*) FROM stocktake_items i WHERE i.stocktake_id=s.id AND i.variance IS NOT NULL AND i.variance<>0) variances FROM stocktakes s JOIN warehouses w ON w.id=s.warehouse_id JOIN branches b ON b.id=w.branch_id ORDER BY s.id DESC LIMIT 100');
        $this->render('stocktake/index', ['title' => 'Stocktake', 'rows' => $rows, 'warehouses' => DB::all('SELECT w.id, CONCAT(b.name," / ",w.name) name FROM warehouses w JOIN branches b ON b.id=w.branch_id WHERE w.is_active=1 ORDER BY b.name'), 'categories' => DB::all('SELECT id, name FROM categories WHERE is_active=1 ORDER BY name')]);
    }
    function action_create(): void {
        $this->postOnly(); $this->need('inventory.stocktake.create'); $wh = (int)input('warehouse_id'); $cat = (int)input('category_id') ?: null;
        if (!DB::val('SELECT 1 FROM warehouses WHERE id=? AND is_active=1', [$wh])) { flash('danger', 'Choose a warehouse.'); redirect('stocktake/index'); }
        if (DB::val("SELECT 1 FROM stocktakes WHERE warehouse_id=? AND status IN ('counting','pending_approval')", [$wh])) { flash('danger', 'An open stocktake already exists for this warehouse.'); redirect('stocktake/index'); }
        $ids = $cat ? array_merge([$cat], array_column(DB::all('SELECT id FROM categories WHERE parent_id=?', [$cat]), 'id'), array_column(DB::all('SELECT c2.id FROM categories c2 JOIN categories c1 ON c1.id=c2.parent_id WHERE c1.parent_id=?', [$cat]), 'id')) : [];
        $where = 'p.is_archived=0 AND p.is_active=1' . ($ids ? ' AND p.category_id IN (' . implode(',', array_map('intval', $ids)) . ')' : '');
        $id = DB::tx(function () use ($wh, $cat, $where) {
            $id = DB::insert('stocktakes', ['st_no' => next_number('stocktake', 'ST-', 5), 'warehouse_id' => $wh, 'category_id' => $cat, 'notes' => input('notes') ?: null, 'created_by' => Auth::id()]);
            DB::q("INSERT INTO stocktake_items (stocktake_id, product_id, system_qty) SELECT ?, p.id, COALESCE(s.qty,0) FROM products p LEFT JOIN stock s ON s.product_id=p.id AND s.warehouse_id=? WHERE $where", [$id, $wh]); return $id;
        });
        audit('create', 'stocktakes', $id); flash('success', 'Stocktake created. Enter counted quantities.'); redirect('stocktake/count', ['id' => $id]);
    }
    function action_count(): void {
        $this->need('inventory.stocktake.view'); $s = $this->st((int)input('id'));
        if (is_post()) {
            $this->need('inventory.stocktake.create'); if ($s['status'] !== 'counting') { flash('danger', 'Counting is closed.'); redirect('stocktake/count', ['id' => $s['id']]); }
            $n = 0; foreach ((array)($_POST['counted'] ?? []) as $iid => $v) { $v = trim((string)$v); if ($v === '') continue; if (!preg_match('/^\d{1,7}(\.\d{1,3})?$/', $v)) { flash('danger', 'Counts must be numbers (up to 3 decimals for weighed items).'); redirect('stocktake/count', ['id' => $s['id']]); }
                DB::q('UPDATE stocktake_items SET counted_qty=?, variance=? - system_qty WHERE id=? AND stocktake_id=?', [(float)$v, (float)$v, (int)$iid, $s['id']]); $n++; }
            if (input('submit_for_approval')) {
                $missing = (int)DB::val('SELECT COUNT(*) FROM stocktake_items WHERE stocktake_id=? AND counted_qty IS NULL', [$s['id']]);
                if ($missing) { flash('warning', "$missing item(s) not counted yet. Saved; count them before submitting."); }
                else { DB::update('stocktakes', ['status' => 'pending_approval'], 'id=?', [$s['id']]); audit('stocktake_submit', 'stocktakes', $s['id']); flash('success', 'Submitted for approval.'); redirect('stocktake/count', ['id' => $s['id']]); }
            } else flash('success', "$n count(s) saved.");
            redirect('stocktake/count', ['id' => $s['id']]);
        }
        $only = input('only') === 'variance' ? ' AND i.variance IS NOT NULL AND i.variance<>0' : '';
        $items = DB::all("SELECT i.*, p.sku, p.name, p.cost_price FROM stocktake_items i JOIN products p ON p.id=i.product_id WHERE i.stocktake_id=?$only ORDER BY p.name", [$s['id']]);
        $this->render('stocktake/count', ['title' => $s['st_no'], 's' => $s, 'items' => $items]);
    }
    function action_decide(): void {
        $this->postOnly(); $this->need('inventory.stocktake.approve'); $id = (int)input('id'); $dec = (string)input('decision');
        try { DB::tx(function () use ($id, $dec) {
            $s = DB::one('SELECT * FROM stocktakes WHERE id=? FOR UPDATE', [$id]); if (!$s) throw new RuntimeException('Not found.');
            if ($dec === 'cancel') { if (!in_array($s['status'], ['counting', 'pending_approval'], true)) throw new RuntimeException('Cannot cancel.'); DB::update('stocktakes', ['status' => 'cancelled'], 'id=?', [$id]); return; }
            if ($s['status'] !== 'pending_approval') throw new RuntimeException('Stocktake is not awaiting approval.');
            if ($dec === 'reopen') { DB::update('stocktakes', ['status' => 'counting'], 'id=?', [$id]); return; }
            if ((int)$s['created_by'] === Auth::id() && !Auth::isSuper()) throw new RuntimeException('A different user must approve the variances.');
            foreach (DB::all('SELECT * FROM stocktake_items WHERE stocktake_id=? AND variance IS NOT NULL AND variance<>0', [$id]) as $i) {
                $cur = (float)DB::val('SELECT qty FROM stock WHERE warehouse_id=? AND product_id=?', [$s['warehouse_id'], $i['product_id']]);
                Stock::change((int)$s['warehouse_id'], (int)$i['product_id'], (float)$i['counted_qty'] - $cur, 'stocktake', 'stocktake', $id, $s['st_no'] . ' count adjustment');   // set to counted (accounts for sales since snapshot)
            }
            DB::update('stocktakes', ['status' => 'approved', 'approved_by' => Auth::id()], 'id=?', [$id]);
        }); audit('stocktake_' . $dec, 'stocktakes', $id); flash('success', 'Done.'); } catch (RuntimeException $e) { flash('danger', $e->getMessage()); }
        redirect('stocktake/count', ['id' => $id]);
    }
    function action_report(): void {
        $this->need('inventory.stocktake.view'); $s = $this->st((int)input('id'));
        $rows = DB::all('SELECT p.sku, p.name, i.system_qty, i.counted_qty, i.variance, i.variance * p.cost_price AS value FROM stocktake_items i JOIN products p ON p.id=i.product_id WHERE i.stocktake_id=? ORDER BY ABS(COALESCE(i.variance,0)) DESC, p.name', [$s['id']]);
        $data = array_map(fn($r) => [$r['sku'], $r['name'], $r['system_qty'], $r['counted_qty'] ?? '', $r['variance'] ?? '', number_format((float)$r['value'], 2, '.', '')], $rows);
        if (input('export') === 'csv') Report::csv($s['st_no'] . '.csv', ['SKU', 'Product', 'System', 'Counted', 'Variance', 'Variance value'], $data);
        if (input('export') === 'pdf') Report::pdf($s['st_no'] . '.pdf', 'Stocktake report ' . $s['st_no'], $s['wname'] . ' - ' . $s['status'], ['SKU', 'Product', 'System', 'Counted', 'Variance', 'Value'], $data, [2 => 'r', 3 => 'r', 4 => 'r', 5 => 'r']);
        $this->render('stocktake/report', ['title' => 'Stocktake report', 's' => $s, 'rows' => $rows]);
    }
}
