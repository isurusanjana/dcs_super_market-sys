<?php
class ExpiryController extends Controller {
    protected ?string $module = 'inventory';
    private function warehouses(): array { return DB::all('SELECT w.id, CONCAT(b.name," / ",w.name) name FROM warehouses w JOIN branches b ON b.id=w.branch_id WHERE w.is_active=1 ORDER BY b.name, w.name'); }
    function action_index(): void {
        $this->need('inventory.expiry.view'); $days = max(1, (int)setting('expiry_alert_days', 7)); $f = (string)input('filter', 'soon'); $wh = (int)input('warehouse', 0);
        $w = ['b.qty > 0', 'p.is_archived=0']; $p = [];
        if ($f === 'expired') $w[] = 'b.expiry_date < CURDATE()'; elseif ($f === 'soon') { $w[] = 'b.expiry_date IS NOT NULL AND b.expiry_date >= CURDATE() AND b.expiry_date <= CURDATE() + INTERVAL ? DAY'; $p[] = $days; } elseif ($f === 'dated') $w[] = 'b.expiry_date IS NOT NULL';
        if ($wh) { $w[] = 'b.warehouse_id=?'; $p[] = $wh; }
        $rows = DB::all('SELECT b.*, p.sku, p.name, p.cost_price, p.selling_price, wh.name AS wname, DATEDIFF(b.expiry_date, CURDATE()) AS days_left FROM stock_batches b JOIN products p ON p.id=b.product_id JOIN warehouses wh ON wh.id=b.warehouse_id WHERE ' . implode(' AND ', $w) . ' ORDER BY b.expiry_date IS NULL, b.expiry_date, p.name LIMIT 500', $p);
        $sum = DB::one("SELECT COALESCE(SUM(CASE WHEN b.expiry_date < CURDATE() THEN b.qty*p.cost_price END),0) expired_val, COALESCE(SUM(CASE WHEN b.expiry_date >= CURDATE() AND b.expiry_date <= CURDATE() + INTERVAL ? DAY THEN b.qty*p.cost_price END),0) soon_val FROM stock_batches b JOIN products p ON p.id=b.product_id WHERE b.qty>0", [$days]);
        $this->render('expiry/index', ['title' => 'Expiry & Batches', 'rows' => $rows, 'f' => $f, 'wh' => $wh, 'days' => $days, 'sum' => $sum, 'warehouses' => $this->warehouses()]);
    }
    function action_writeoff(): void {
        $this->postOnly(); $this->need('inventory.expiry.writeoff'); $bid = (int)input('batch_id'); $b = DB::one('SELECT b.*, p.sold_by FROM stock_batches b JOIN products p ON p.id=b.product_id WHERE b.id=?', [$bid]);
        if (!$b) { flash('danger', 'Batch not found.'); redirect('expiry/index'); }
        $type = in_array(input('type'), ['expired', 'spoilage', 'theft'], true) ? input('type') : 'expired'; $reason = trim((string)input('reason', ''));
        $qtyIn = trim((string)input('qty', '')); $qty = $qtyIn === '' ? (float)$b['qty'] : parse_qty($qtyIn, $b['sold_by'] === 'weight');
        if ($qty === null || $qty > (float)$b['qty'] + 0.0005) { flash('danger', 'Quantity must be above zero and not more than the batch quantity (' . fq($b['qty']) . ').'); redirect('expiry/index'); }
        if ($reason === '') $reason = ucfirst($type) . ' - batch ' . ($b['batch_no'] ?: '#' . $b['id']) . ($b['expiry_date'] ? ' (exp ' . $b['expiry_date'] . ')' : '');
        $auto = Auth::can('inventory.stock.approve'); $err = null;
        try {
            DB::tx(function () use ($b, $type, $qty, $reason, $auto, &$aid) {
                $aid = DB::insert('stock_adjustments', ['adj_no' => next_number('adjust', 'ADJ-', 6), 'warehouse_id' => $b['warehouse_id'], 'product_id' => $b['product_id'], 'type' => $type, 'qty' => $qty, 'reason' => mb_substr($reason, 0, 255), 'batch_id' => $b['id'], 'created_by' => Auth::id()]);
                if ($auto) { Stock::change((int)$b['warehouse_id'], (int)$b['product_id'], -$qty, 'wastage_' . $type, 'adjustment', $aid, $reason, false, ['id' => (int)$b['id']]); DB::update('stock_adjustments', ['status' => 'approved', 'approved_by' => Auth::id(), 'approved_at' => date('Y-m-d H:i:s')], 'id=?', [$aid]); }
            });
        } catch (RuntimeException $e) { $err = $e->getMessage(); }
        if ($err) flash('danger', $err); else { audit('wastage', 'stock_adjustments', $aid, ['type' => $type, 'qty' => $qty, 'auto' => $auto]); flash('success', $auto ? 'Written off and removed from stock.' : 'Write-off submitted for approval.'); }
        redirect('expiry/index', ['filter' => input('filter', 'soon')]);
    }
    function action_markdown(): void {
        $this->postOnly(); $this->need('inventory.expiry.markdown'); $b = DB::one('SELECT b.*, p.name FROM stock_batches b JOIN products p ON p.id=b.product_id WHERE b.id=?', [(int)input('batch_id')]);
        $pct = input('percent'); if (!$b || !is_numeric($pct) || $pct <= 0 || $pct > 90) { flash('danger', 'Enter a markdown percentage between 1 and 90.'); redirect('expiry/index'); }
        $end = $b['expiry_date'] && $b['expiry_date'] >= date('Y-m-d') ? $b['expiry_date'] : date('Y-m-d');
        $id = DB::insert('promotions', ['name' => 'Near-expiry markdown: ' . mb_substr($b['name'], 0, 70), 'type' => 'percent', 'value' => round((float)$pct, 2), 'scope' => 'product', 'product_id' => $b['product_id'], 'min_qty' => 1, 'start_date' => date('Y-m-d'), 'end_date' => $end, 'is_active' => 1]);
        audit('markdown', 'promotions', $id, ['product' => $b['product_id'], 'percent' => $pct, 'until' => $end]); flash('success', "Markdown of $pct% will apply at the POS to this product until $end."); redirect('expiry/index', ['filter' => input('filter', 'soon')]);
    }
}
