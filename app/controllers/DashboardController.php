<?php
class DashboardController extends Controller {
    private function systemGuard(string $s): void { $this->guardModule($s); }
    function action_admin(): void {
        $this->systemGuard('admin');
        $today = date('Y-m-d'); $month = date('Y-m-01');
        $k = [
            'Sales today' => money(DB::val("SELECT COALESCE(SUM(total),0) FROM sales WHERE status='completed' AND DATE(sale_date)=?", [$today])),
            'Sales this month' => money(DB::val("SELECT COALESCE(SUM(total),0) FROM sales WHERE status='completed' AND sale_date>=?", [$month])),
            'Transactions today' => (int)DB::val("SELECT COUNT(*) FROM sales WHERE status='completed' AND DATE(sale_date)=?", [$today]),
            'Expenses this month' => money(DB::val("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE status='approved' AND expense_date>=?", [$month])),
            'Customer receivables' => money(Finance::totalReceivable()), 'Supplier payables' => money(Finance::totalPayable()),
            'Active customers' => (int)DB::val('SELECT COUNT(*) FROM customers WHERE is_active=1'), 'Low / out of stock' => count(Alerts::all()['low']) . ' / ' . count(Alerts::all()['out']),
            'Average basket today' => money(DB::val("SELECT COALESCE(AVG(total),0) FROM sales WHERE status='completed' AND DATE(sale_date)=?", [$today])),
            'Gift card liability' => money(DB::val("SELECT COALESCE(SUM(balance),0) FROM gift_cards WHERE status='active'")),
            'Wastage this month (cost)' => money(DB::val("SELECT COALESCE(-SUM(m.qty_change * p.cost_price),0) FROM stock_movements m JOIN products p ON p.id=m.product_id WHERE m.type IN ('wastage_expired','wastage_spoilage','wastage_theft','damaged','lost') AND m.created_at >= ?", [$month])),
            'Expired batches' => (int)DB::val('SELECT COUNT(*) FROM stock_batches WHERE qty>0 AND expiry_date < CURDATE()'),
        ];
        $days = DB::all("SELECT DATE(sale_date) d, SUM(total) t FROM sales WHERE status='completed' AND sale_date >= CURDATE() - INTERVAL 13 DAY GROUP BY DATE(sale_date)");
        $map = array_column($days, 't', 'd'); $labels = []; $vals = [];
        for ($i = 13; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i day")); $labels[] = date('d M', strtotime($d)); $vals[] = (float)($map[$d] ?? 0); }
        $top = DB::all("SELECT si.name, SUM(si.qty) q FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE s.status='completed' AND s.sale_date>=CURDATE() - INTERVAL 30 DAY GROUP BY si.product_id, si.name ORDER BY q DESC LIMIT 5");
        $pay = DB::all("SELECT sp.method, SUM(sp.amount) a FROM sale_payments sp JOIN sales s ON s.id=sp.sale_id WHERE s.status='completed' AND s.sale_date>=? GROUP BY sp.method", [$month]);
        $this->render('dashboard/admin', ['title' => 'Administration Dashboard', 'k' => $k, 'labels' => $labels, 'vals' => $vals, 'top' => $top, 'pay' => $pay]);
    }
    function action_pos(): void {
        $this->systemGuard('pos');
        $uid = Auth::id();
        $sess = DB::one("SELECT * FROM pos_sessions WHERE user_id=? AND status IN ('open','suspended') ORDER BY id DESC LIMIT 1", [$uid]);
        $mine = DB::one("SELECT COUNT(*) n, COALESCE(SUM(total),0) t FROM sales WHERE user_id=? AND status='completed' AND DATE(sale_date)=CURDATE()", [$uid]);
        $recent = DB::all("SELECT id, invoice_no, sale_date, total, status FROM sales WHERE user_id=? ORDER BY id DESC LIMIT 8", [$uid]);
        $this->render('dashboard/pos', ['title' => 'POS Dashboard', 'sess' => $sess, 'mine' => $mine, 'recent' => $recent]);
    }
    function action_inventory(): void {
        $this->systemGuard('inventory');
        $al = Alerts::all();
        $k = [
            'Active products' => (int)DB::val('SELECT COUNT(*) FROM products WHERE is_active=1 AND is_archived=0'),
            'Stock value (cost)' => money(DB::val('SELECT COALESCE(SUM(s.qty * p.cost_price),0) FROM stock s JOIN products p ON p.id=s.product_id')),
            'Low stock items' => count($al['low']), 'Out of stock' => count($al['out']),
            'Pending adjustments' => (int)DB::val("SELECT COUNT(*) FROM stock_adjustments WHERE status='pending'"),
            'POs awaiting approval' => (int)DB::val("SELECT COUNT(*) FROM purchase_orders WHERE status='submitted'"),
            'Transfers in progress' => (int)DB::val("SELECT COUNT(*) FROM stock_transfers WHERE status IN ('draft','approved','dispatched')"),
            'Overstocked items' => count($al['over']),
            'Expired batches' => (int)DB::val('SELECT COUNT(*) FROM stock_batches WHERE qty>0 AND expiry_date < CURDATE()'),
            'Expiring in ' . (int)setting('expiry_alert_days', 7) . ' days' => (int)DB::val('SELECT COUNT(*) FROM stock_batches WHERE qty>0 AND expiry_date >= CURDATE() AND expiry_date <= CURDATE() + INTERVAL ? DAY', [(int)setting('expiry_alert_days', 7)]),
            'Weighed items' => (int)DB::val("SELECT COUNT(*) FROM products WHERE sold_by='weight' AND is_active=1 AND is_archived=0"),
            'Pending purchase requests' => (int)DB::val("SELECT COUNT(*) FROM purchase_requests WHERE status='open'"),
        ];
        $cats = DB::all("SELECT COALESCE(c.name,'Uncategorised') n, SUM(s.qty) q FROM stock s JOIN products p ON p.id=s.product_id LEFT JOIN categories c ON c.id=p.category_id GROUP BY c.id, c.name HAVING q>0 ORDER BY q DESC LIMIT 8");
        $this->render('dashboard/inventory', ['title' => 'Inventory Dashboard', 'k' => $k, 'low' => array_slice(array_merge($al['out'], $al['low']), 0, 10), 'cats' => $cats]);
    }
}
