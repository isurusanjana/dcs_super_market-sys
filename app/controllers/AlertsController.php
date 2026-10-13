<?php
class AlertsController extends Controller {
    protected ?string $module = 'inventory';
    function action_index(): void {
        $this->need('inventory.alerts.view'); $wh = (int)input('warehouse', 0) ?: null; $al = Alerts::all($wh);
        $open = array_column(DB::all("SELECT product_id, SUM(qty) q FROM purchase_requests WHERE status IN ('open','ordered') GROUP BY product_id"), 'q', 'product_id');
        $this->render('alerts/index', ['title' => 'Inventory Alerts', 'al' => $al, 'open' => $open, 'wh' => $wh, 'warehouses' => DB::all('SELECT w.id, CONCAT(b.name," / ",w.name) name FROM warehouses w JOIN branches b ON b.id=w.branch_id WHERE w.is_active=1 ORDER BY b.name'),
            'requests' => DB::all("SELECT r.*, p.sku, p.name, w.name AS wname FROM purchase_requests r JOIN products p ON p.id=r.product_id JOIN warehouses w ON w.id=r.warehouse_id WHERE r.status<>'closed' ORDER BY r.id DESC LIMIT 50"), 'suppliers' => DB::all('SELECT id, name FROM suppliers WHERE is_active=1 ORDER BY name')]);
    }
    function action_request(): void {
        $this->postOnly(); $this->need('inventory.alerts.request'); $wh = (int)input('warehouse_id');
        if (!DB::val('SELECT 1 FROM warehouses WHERE id=? AND is_active=1', [$wh])) { flash('danger', 'Choose the warehouse to replenish.'); redirect('alerts/index'); }
        $sel = array_map('intval', array_keys((array)($_POST['sel'] ?? []))); $qty = (array)($_POST['qty'] ?? []); $made = []; $supplier = (int)input('supplier_id');
        if (!$sel) { flash('warning', 'Select at least one product.'); redirect('alerts/index'); }
        DB::tx(function () use ($sel, $qty, $wh, $supplier, &$made) {
            $poId = null;
            if ($supplier) { if (!Auth::can('inventory.purchasing.create') || !DB::val('SELECT 1 FROM suppliers WHERE id=? AND is_active=1', [$supplier])) throw new RuntimeException('Not allowed / invalid supplier.'); $poId = DB::insert('purchase_orders', ['po_no' => next_number('po', 'PO-', 6), 'supplier_id' => $supplier, 'warehouse_id' => $wh, 'order_date' => date('Y-m-d'), 'notes' => 'Created from low-stock alerts', 'created_by' => Auth::id()]); }
            $total = 0;
            foreach ($sel as $pid) {
                $q = round((float)($qty[$pid] ?? 0), 3); if ($q <= 0 || $q > 1000000) continue; $p = DB::one('SELECT id, cost_price FROM products WHERE id=? AND is_archived=0', [$pid]); if (!$p) continue;
                DB::insert('purchase_requests', ['product_id' => $pid, 'warehouse_id' => $wh, 'qty' => $q, 'status' => $poId ? 'ordered' : 'open', 'po_id' => $poId, 'created_by' => Auth::id()]); $made[] = $pid;
                if ($poId) { DB::insert('purchase_order_items', ['po_id' => $poId, 'product_id' => $pid, 'qty' => $q, 'price' => $p['cost_price']]); $total += $q * $p['cost_price']; }
            }
            if ($poId) { if (!$made) throw new RuntimeException('No valid quantities entered.'); DB::update('purchase_orders', ['total' => round($total, 2)], 'id=?', [$poId]); $made['po'] = $poId; }
        });
        audit('purchase_request', 'purchase_requests', null, ['products' => array_values(array_filter($made, 'is_int'))]);
        if (isset($made['po'])) { flash('success', 'Draft purchase order created from alerts.'); redirect('purchasing/view', ['id' => $made['po']]); }
        flash($made ? 'success' : 'warning', $made ? count($made) . ' purchase request(s) created.' : 'Enter a quantity for the selected products.'); redirect('alerts/index');
    }
    function action_resolve(): void {
        $this->postOnly(); $this->need('inventory.alerts.request'); $n = DB::update('purchase_requests', ['status' => 'closed'], "id=? AND status<>'closed'", [(int)input('id')]);
        if ($n) audit('alert_resolved', 'purchase_requests', (int)input('id')); flash('success', 'Marked resolved.'); redirect('alerts/index');
    }
}
