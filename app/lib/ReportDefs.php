<?php
/** Report catalogue. Each report: title, group, period(bool), run(from,to,params) => [headers, rows, align(r cols), totals(cols to sum)] */
class ReportDefs {
    static function all(): array {
        $c = fn($t) => $t;
        return [
            'sales_daily' => ['Sales by day', 'Sales', true, function ($f, $t) {
                $r = DB::all("SELECT DATE(sale_date) d, COUNT(*) n, SUM(subtotal) sub, SUM(discount_total) disc, SUM(tax_total) tax, SUM(total) tot FROM sales WHERE status='completed' AND sale_date BETWEEN ? AND ? GROUP BY DATE(sale_date) ORDER BY d", ["$f 00:00:00", "$t 23:59:59"]);
                return [['Date', 'Invoices', 'Subtotal', 'Discount', 'Tax', 'Total'], array_map(fn($x) => [$x['d'], $x['n'], $x['sub'], $x['disc'], $x['tax'], $x['tot']], $r), [1, 2, 3, 4, 5], [1, 2, 3, 4, 5]]; }],
            'sales_product' => ['Sales by product', 'Sales', true, function ($f, $t) {
                $r = DB::all("SELECT si.name, p.sku, SUM(si.qty - si.returned_qty) q, SUM(si.line_total) amt, SUM((si.unit_price - si.cost_price) * (si.qty - si.returned_qty)) - SUM(si.discount) AS margin FROM sale_items si JOIN sales s ON s.id=si.sale_id LEFT JOIN products p ON p.id=si.product_id WHERE s.status='completed' AND s.sale_date BETWEEN ? AND ? GROUP BY si.product_id, si.name, p.sku ORDER BY amt DESC", ["$f 00:00:00", "$t 23:59:59"]);
                return [['Product', 'SKU', 'Net qty', 'Sales', 'Est. margin'], array_map(fn($x) => [$x['name'], $x['sku'], $x['q'], $x['amt'], $x['margin']], $r), [2, 3, 4], [2, 3, 4]]; }],
            'sales_cashier' => ['Sales by cashier', 'Sales', true, function ($f, $t) {
                $r = DB::all("SELECT u.full_name, COUNT(*) n, SUM(s.total) tot, SUM(s.discount_total) d FROM sales s JOIN users u ON u.id=s.user_id WHERE s.status='completed' AND s.sale_date BETWEEN ? AND ? GROUP BY u.id ORDER BY tot DESC", ["$f 00:00:00", "$t 23:59:59"]);
                return [['Cashier', 'Invoices', 'Total', 'Discounts given'], array_map(fn($x) => [$x['full_name'], $x['n'], $x['tot'], $x['d']], $r), [1, 2, 3], [1, 2, 3]]; }],
            'sales_payment' => ['Sales by payment method', 'Sales', true, function ($f, $t) {
                $r = DB::all("SELECT sp.method, COUNT(*) n, SUM(sp.amount) a FROM sale_payments sp JOIN sales s ON s.id=sp.sale_id WHERE s.status='completed' AND s.sale_date BETWEEN ? AND ? GROUP BY sp.method", ["$f 00:00:00", "$t 23:59:59"]);
                return [['Method', 'Payments', 'Amount'], array_map(fn($x) => [ucwords(str_replace('_', ' ', $x['method'])), $x['n'], $x['a']], $r), [1, 2], [1, 2]]; }],
            'returns' => ['Returns & refunds', 'Sales', true, function ($f, $t) {
                $r = DB::all("SELECT r.return_no, DATE(r.return_date) d, s.invoice_no, r.refund_method, r.reason, r.total_refund FROM sales_returns r JOIN sales s ON s.id=r.sale_id WHERE r.return_date BETWEEN ? AND ? ORDER BY r.id", ["$f 00:00:00", "$t 23:59:59"]);
                return [['Return', 'Date', 'Invoice', 'Method', 'Reason', 'Refund'], array_map(fn($x) => array_values($x), $r), [5], [5]]; }],
            'voids' => ['Voided transactions', 'Sales', true, function ($f, $t) {
                $r = DB::all("SELECT s.invoice_no, s.sale_date, u.full_name, v.full_name vb, s.total, s.void_reason FROM sales s JOIN users u ON u.id=s.user_id LEFT JOIN users v ON v.id=s.voided_by WHERE s.status='void' AND s.voided_at BETWEEN ? AND ? ORDER BY s.id", ["$f 00:00:00", "$t 23:59:59"]);
                return [['Invoice', 'Sold', 'Cashier', 'Voided by', 'Total', 'Reason'], array_map(fn($x) => array_values($x), $r), [4], [4]]; }],
            'inventory_value' => ['Inventory valuation', 'Inventory', false, function () {
                $r = DB::all('SELECT p.sku, p.name, COALESCE(SUM(s.qty),0) q, p.cost_price, COALESCE(SUM(s.qty),0)*p.cost_price v, COALESCE(SUM(s.qty),0)*p.selling_price rv FROM products p LEFT JOIN stock s ON s.product_id=p.id WHERE p.is_archived=0 GROUP BY p.id ORDER BY v DESC');
                return [['SKU', 'Product', 'Qty', 'Cost', 'Cost value', 'Retail value'], array_map(fn($x) => array_values($x), $r), [2, 3, 4, 5], [2, 4, 5]]; }],
            'low_stock' => ['Low & out of stock', 'Inventory', false, function () {
                $a = Alerts::all(); $rows = [];
                foreach (['out' => 'Out of stock', 'low' => 'Low stock'] as $k => $l) foreach ($a[$k] as $x) $rows[] = [$l, $x['sku'], $x['name'], $x['qty'], $x['reorder_level']];
                return [['Status', 'SKU', 'Product', 'On hand', 'Reorder level'], $rows, [3, 4], []]; }],
            'purchases' => ['Purchases (invoices)', 'Purchasing', true, function ($f, $t) {
                $r = DB::all('SELECT i.inv_no, i.invoice_date, s.name, i.supplier_invoice_no, i.total, i.status FROM purchase_invoices i JOIN suppliers s ON s.id=i.supplier_id WHERE i.invoice_date BETWEEN ? AND ? ORDER BY i.invoice_date', [$f, $t]);
                return [['Invoice', 'Date', 'Supplier', 'Supplier inv', 'Total', 'Status'], array_map(fn($x) => array_values($x), $r), [4], [4]]; }],
            'supplier_outstanding' => ['Supplier outstanding', 'Suppliers', false, function () {
                $rows = []; foreach (DB::all('SELECT id, code, name, phone FROM suppliers ORDER BY name') as $s) { $b = Finance::supplierBalance((int)$s['id']); if (abs($b) > 0.004) $rows[] = [$s['code'], $s['name'], $s['phone'], $b]; }
                return [['Code', 'Supplier', 'Phone', 'Outstanding'], $rows, [3], [3]]; }],
            'customer_outstanding' => ['Customer outstanding', 'Customers', false, function () {
                $rows = []; foreach (DB::all('SELECT id, code, name, phone, credit_limit FROM customers ORDER BY name') as $c) { $b = Finance::customerBalance((int)$c['id']); if (abs($b) > 0.004) $rows[] = [$c['code'], $c['name'], $c['phone'], $c['credit_limit'], $b]; }
                return [['Code', 'Customer', 'Phone', 'Credit limit', 'Outstanding'], $rows, [3, 4], [4]]; }],
            'top_customers' => ['Top customers', 'Customers', true, function ($f, $t) {
                $r = DB::all("SELECT c.code, c.name, COUNT(*) n, SUM(s.total) tot FROM sales s JOIN customers c ON c.id=s.customer_id WHERE s.status='completed' AND s.sale_date BETWEEN ? AND ? GROUP BY c.id ORDER BY tot DESC LIMIT 100", ["$f 00:00:00", "$t 23:59:59"]);
                return [['Code', 'Customer', 'Invoices', 'Total'], array_map(fn($x) => array_values($x), $r), [2, 3], [2, 3]]; }],
            'expenses' => ['Expenses', 'Finance', true, function ($f, $t) {
                $r = DB::all("SELECT expense_date, category, description, method, amount FROM expenses WHERE status='approved' AND expense_date BETWEEN ? AND ? ORDER BY expense_date", [$f, $t]);
                return [['Date', 'Category', 'Description', 'Method', 'Amount'], array_map(fn($x) => array_values($x), $r), [4], [4]]; }],
            'profit_loss' => ['Profit & loss', 'Finance', true, function ($f, $t) { return self::pl($f, $t); }],
            'cash_variance' => ['Cash drawer variances', 'Finance', true, function ($f, $t) {
                $r = DB::all("SELECT s.session_no, u.full_name, s.opened_at, s.opening_cash, s.expected_cash, s.counted_cash, s.variance FROM pos_sessions s JOIN users u ON u.id=s.user_id WHERE s.status IN ('closed','reconciled') AND s.closed_at BETWEEN ? AND ? ORDER BY s.id", ["$f 00:00:00", "$t 23:59:59"]);
                return [['Session', 'Cashier', 'Opened', 'Opening', 'Expected', 'Counted', 'Variance'], array_map(fn($x) => array_values($x), $r), [3, 4, 5, 6], [6]]; }],
            'attendance' => ['Employee attendance summary', 'Employees', true, function ($f, $t) {
                $r = DB::all("SELECT e.emp_no, e.full_name, SUM(a.status='present') pr, SUM(a.status='late') la, SUM(a.status='half_day') hd, SUM(a.status='leave') lv, SUM(a.status='absent') ab FROM employees e LEFT JOIN attendance a ON a.employee_id=e.id AND a.att_date BETWEEN ? AND ? WHERE e.status='active' GROUP BY e.id ORDER BY e.full_name", [$f, $t]);
                return [['Emp no', 'Employee', 'Present', 'Late', 'Half day', 'Leave', 'Absent'], array_map(fn($x) => array_map(fn($v) => $v ?? 0, array_values($x)), $r), [2, 3, 4, 5, 6], [2, 3, 4, 5, 6]]; }],
            'employee_sales' => ['Employee sales performance', 'Employees', true, function ($f, $t) {
                $r = DB::all("SELECT COALESCE(e.emp_no,'-') en, u.full_name, COUNT(*) n, SUM(s.total) tot FROM sales s JOIN users u ON u.id=s.user_id LEFT JOIN employees e ON e.id=u.employee_id WHERE s.status='completed' AND s.sale_date BETWEEN ? AND ? GROUP BY u.id ORDER BY tot DESC", ["$f 00:00:00", "$t 23:59:59"]);
                return [['Emp no', 'User', 'Invoices', 'Sales'], array_map(fn($x) => array_values($x), $r), [2, 3], [2, 3]]; }],
            'price_changes' => ['Price change log', 'Audit', true, function ($f, $t) {
                $r = DB::all('SELECT h.changed_at, p.sku, p.name, h.field_name, h.old_value, h.new_value, u.username FROM product_price_history h JOIN products p ON p.id=h.product_id LEFT JOIN users u ON u.id=h.user_id WHERE h.changed_at BETWEEN ? AND ? ORDER BY h.id DESC', ["$f 00:00:00", "$t 23:59:59"]);
                return [['When', 'SKU', 'Product', 'Field', 'Old', 'New', 'By'], array_map(fn($x) => array_values($x), $r), [4, 5], []]; }],
            // ---- supermarket reports
            'sales_department' => ['Sales by department', 'Supermarket', true, function ($f, $t) {
                $r = DB::all("SELECT COALESCE(g.name, pc.name, c.name, 'Uncategorised') dept, COUNT(DISTINCT s.id) n, SUM(si.qty - si.returned_qty) q, SUM(si.line_total) amt FROM sale_items si JOIN sales s ON s.id=si.sale_id LEFT JOIN products p ON p.id=si.product_id LEFT JOIN categories c ON c.id=p.category_id LEFT JOIN categories pc ON pc.id=c.parent_id LEFT JOIN categories g ON g.id=pc.parent_id WHERE s.status='completed' AND s.sale_date BETWEEN ? AND ? GROUP BY dept ORDER BY amt DESC", ["$f 00:00:00", "$t 23:59:59"]);
                return [['Department', 'Invoices', 'Units / kg sold', 'Sales'], array_map(fn($x) => [$x['dept'], $x['n'], fq($x['q']), $x['amt']], $r), [1, 2, 3], [3]]; }],
            'sales_hourly' => ['Sales by hour of day', 'Supermarket', true, function ($f, $t) {
                $r = DB::all("SELECT HOUR(sale_date) h, COUNT(*) n, SUM(total) tot, AVG(total) av FROM sales WHERE status='completed' AND sale_date BETWEEN ? AND ? GROUP BY HOUR(sale_date) ORDER BY h", ["$f 00:00:00", "$t 23:59:59"]);
                return [['Hour', 'Invoices', 'Sales', 'Average basket'], array_map(fn($x) => [sprintf('%02d:00 - %02d:59', $x['h'], $x['h']), $x['n'], $x['tot'], round($x['av'], 2)], $r), [1, 2, 3], [1, 2]]; }],
            'basket' => ['Basket analysis (average basket by day)', 'Supermarket', true, function ($f, $t) {
                $r = DB::all("SELECT DATE(s.sale_date) d, COUNT(*) n, SUM(s.total) tot, AVG(s.total) av, (SELECT COUNT(*) FROM sale_items si JOIN sales s2 ON s2.id=si.sale_id WHERE s2.status='completed' AND DATE(s2.sale_date)=DATE(s.sale_date)) nlines FROM sales s WHERE s.status='completed' AND s.sale_date BETWEEN ? AND ? GROUP BY DATE(s.sale_date) ORDER BY d", ["$f 00:00:00", "$t 23:59:59"]);
                return [['Date', 'Baskets', 'Sales', 'Avg basket value', 'Avg items per basket'], array_map(fn($x) => [$x['d'], $x['n'], $x['tot'], round($x['av'], 2), round($x["nlines"] / max(1, $x["n"]), 1)], $r), [1, 2, 3, 4], [1, 2]]; }],
            'margin_product' => ['Gross margin by product', 'Supermarket', true, function ($f, $t) {
                $r = DB::all("SELECT si.name, SUM(si.qty - si.returned_qty) q, SUM(si.line_total - si.tax_amount) rev, SUM(si.cost_price * (si.qty - si.returned_qty)) cost FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE s.status='completed' AND s.sale_date BETWEEN ? AND ? GROUP BY si.product_id, si.name ORDER BY (SUM(si.line_total - si.tax_amount) - SUM(si.cost_price * (si.qty - si.returned_qty))) DESC", ["$f 00:00:00", "$t 23:59:59"]);
                return [['Product', 'Qty', 'Revenue (ex tax)', 'Cost', 'Margin', 'Margin %'], array_map(fn($x) => [$x['name'], fq($x['q']), $x['rev'], $x['cost'], round($x['rev'] - $x['cost'], 2), $x['rev'] > 0 ? round(($x['rev'] - $x['cost']) / $x['rev'] * 100, 1) : 0], $r), [1, 2, 3, 4, 5], [2, 3, 4]]; }],
            'wastage' => ['Wastage & shrinkage (expired, spoiled, theft, damaged, lost)', 'Supermarket', true, function ($f, $t) {
                $r = DB::all("SELECT m.type, p.sku, p.name, -SUM(m.qty_change) q, -SUM(m.qty_change) * p.cost_price val FROM stock_movements m JOIN products p ON p.id=m.product_id WHERE m.type IN ('wastage_expired','wastage_spoilage','wastage_theft','damaged','lost') AND m.created_at BETWEEN ? AND ? GROUP BY m.type, p.id ORDER BY val DESC", ["$f 00:00:00", "$t 23:59:59"]);
                return [['Reason', 'SKU', 'Product', 'Qty', 'Cost value'], array_map(fn($x) => [ucwords(str_replace(['wastage_', '_'], ['', ' '], $x['type'])), $x['sku'], $x['name'], fq($x['q']), $x['val']], $r), [3, 4], [4]]; }],
            'expiry' => ['Batches & expiry (current stock)', 'Supermarket', false, function () {
                $r = DB::all("SELECT p.sku, p.name, b.batch_no, b.expiry_date, DATEDIFF(b.expiry_date, CURDATE()) d, b.qty, b.qty * p.cost_price v FROM stock_batches b JOIN products p ON p.id=b.product_id WHERE b.qty>0 AND b.expiry_date IS NOT NULL ORDER BY b.expiry_date");
                return [['SKU', 'Product', 'Batch', 'Expiry', 'Days left', 'Qty', 'Cost value'], array_map(fn($x) => [$x['sku'], $x['name'], $x['batch_no'], $x['expiry_date'], $x['d'], fq($x['qty']), $x['v']], $r), [4, 5, 6], [6]]; }],
            'dead_stock' => ['Slow / dead stock (no sales since the "From" date)', 'Supermarket', true, function ($f, $t) {
                $r = DB::all("SELECT p.sku, p.name, SUM(s.qty) q, SUM(s.qty * p.cost_price) v FROM stock s JOIN products p ON p.id=s.product_id WHERE p.is_archived=0 AND s.qty>0 AND NOT EXISTS (SELECT 1 FROM sale_items si JOIN sales sa ON sa.id=si.sale_id WHERE si.product_id=p.id AND sa.status='completed' AND sa.sale_date >= ?) GROUP BY p.id ORDER BY v DESC", ["$f 00:00:00"]);
                return [['SKU', 'Product', 'On hand', 'Cost value'], array_map(fn($x) => [$x['sku'], $x['name'], fq($x['q']), $x['v']], $r), [2, 3], [3]]; }],
            'abc' => ['ABC analysis of products (by sales)', 'Supermarket', true, function ($f, $t) {
                $r = DB::all("SELECT si.name, SUM(si.line_total) amt FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE s.status='completed' AND s.sale_date BETWEEN ? AND ? GROUP BY si.product_id, si.name ORDER BY amt DESC", ["$f 00:00:00", "$t 23:59:59"]);
                $tot = array_sum(array_column($r, 'amt')); $cum = 0; $rows = [];
                foreach ($r as $x) { $cum += $x['amt']; $pct = $tot > 0 ? $cum / $tot * 100 : 0; $rows[] = [$x['name'], $x['amt'], round($x['amt'] / max($tot, 0.01) * 100, 1), round($pct, 1), $pct - ($x['amt'] / max($tot, 0.01) * 100) < 80 ? 'A' : ($pct - ($x['amt'] / max($tot, 0.01) * 100) < 95 ? 'B' : 'C')]; }
                return [['Product', 'Sales', '% of sales', 'Cumulative %', 'Class'], $rows, [1, 2, 3], [1]]; }],
            'promo_effect' => ['Promotion performance', 'Supermarket', true, function ($f, $t) {
                $r = DB::all("SELECT si.promo_name, COUNT(*) nlines, SUM(si.qty) q, SUM(si.discount) disc, SUM(si.line_total) amt FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE s.status='completed' AND si.promo_name IS NOT NULL AND s.sale_date BETWEEN ? AND ? GROUP BY si.promo_name ORDER BY disc DESC", ["$f 00:00:00", "$t 23:59:59"]);
                return [['Promotion', 'Lines', 'Qty', 'Discount given', 'Sales'], array_map(fn($x) => [$x["promo_name"], $x["nlines"], fq($x['q']), $x['disc'], $x['amt']], $r), [1, 2, 3, 4], [1, 3, 4]]; }],
            'tax_summary' => ['Tax summary by rate', 'Finance', true, function ($f, $t) {
                $r = DB::all("SELECT si.tax_rate, SUM(si.line_total - si.tax_amount) base, SUM(si.tax_amount) tax, SUM(si.line_total) tot FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE s.status='completed' AND s.sale_date BETWEEN ? AND ? GROUP BY si.tax_rate ORDER BY si.tax_rate", ["$f 00:00:00", "$t 23:59:59"]);
                return [['Tax rate %', 'Taxable amount', 'Tax', 'Total'], array_map(fn($x) => [$x['tax_rate'], $x['base'], $x['tax'], $x['tot']], $r), [0, 1, 2, 3], [1, 2, 3]]; }],
            'giftcards' => ['Gift card balances (liability)', 'Finance', false, function () {
                $r = DB::all("SELECT g.code, g.issued_at, g.initial_amount, g.balance, g.expires_on, g.status FROM gift_cards g WHERE g.balance > 0 ORDER BY g.issued_at");
                return [['Code', 'Issued', 'Initial', 'Balance', 'Expires', 'Status'], array_map(fn($x) => [$x['code'], fmt_date($x['issued_at']), $x['initial_amount'], $x['balance'], $x['expires_on'], $x['status']], $r), [2, 3], [2, 3]]; }],
            'cash_events' => ['Cash pickups, paid-outs & float', 'Finance', true, function ($f, $t) {
                $r = DB::all("SELECT t.txn_date, t.type, u.username, t.direction, t.amount, t.note FROM fin_transactions t LEFT JOIN users u ON u.id=t.user_id WHERE t.type IN ('cash_pickup','paid_out','float_in') AND t.txn_date BETWEEN ? AND ? ORDER BY t.id", ["$f 00:00:00", "$t 23:59:59"]);
                return [['When', 'Type', 'User', 'In/Out', 'Amount', 'Reason'], array_map(fn($x) => array_values($x), $r), [4], [4]]; }],
        ];
    }
    /** Profit & loss: sales net of returns, COGS, expenses. Returns report tuple. */
    static function pl(string $f, string $t): array {
        $a = ["$f 00:00:00", "$t 23:59:59"];
        $gross = (float)DB::val("SELECT COALESCE(SUM(total),0) FROM sales WHERE status='completed' AND sale_date BETWEEN ? AND ?", $a);
        $ret = (float)DB::val('SELECT COALESCE(SUM(total_refund),0) FROM sales_returns WHERE return_date BETWEEN ? AND ?', $a);
        $cogs = (float)DB::val("SELECT COALESCE(SUM(si.cost_price * (si.qty - si.returned_qty)),0) FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE s.status='completed' AND s.sale_date BETWEEN ? AND ?", $a);
        $tax = (float)DB::val("SELECT COALESCE(SUM(tax_total),0) FROM sales WHERE status='completed' AND sale_date BETWEEN ? AND ?", $a);
        $exp = (float)DB::val("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE status='approved' AND expense_date BETWEEN ? AND ?", [$f, $t]);
        $net = $gross - $ret; $gp = $net - $tax - $cogs;
        $rows = [['Gross sales (incl. tax)', $gross], ['Less: returns & refunds', -$ret], ['Net sales', $net], ['Less: tax collected', -$tax], ['Less: cost of goods sold', -$cogs], ['Gross profit', $gp], ['Less: operating expenses', -$exp], ['NET PROFIT', $gp - $exp]];
        return [['Item', 'Amount'], $rows, [1], []];
    }
}
