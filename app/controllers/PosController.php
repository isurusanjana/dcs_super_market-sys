<?php
class PosController extends Controller {
    protected ?string $module = 'pos';
    private function body(): array { $j = json_decode((string)file_get_contents('php://input'), true); return is_array($j) ? $j : []; }
    private function session(): ?array { return Pos::openSession(); }
    private function ctx(array $in, int $branchId): array {
        $c = Pos::customer(isset($in['customer_id']) && $in['customer_id'] ? (int)$in['customer_id'] : null);
        return ['branch_id' => $branchId, 'customer' => $c, 'can_override' => Auth::can('pos.sale.price_override'), 'can_discount' => Auth::can('pos.discount.apply') || Auth::can('pos.discount.approve')];
    }

    // ================= Register / session
    function action_session(): void {
        $this->need('pos.session.open'); $s = $this->session(); $summary = null;
        if ($s) { $summary = ['cash_sales' => Pos::expectedCash($s), 'count' => (int)DB::val("SELECT COUNT(*) FROM sales WHERE session_id=? AND status='completed'", [$s['id']]), 'total' => (float)DB::val("SELECT COALESCE(SUM(total),0) FROM sales WHERE session_id=? AND status='completed'", [$s['id']]),
            'methods' => DB::all("SELECT sp.method, SUM(sp.amount) amt FROM sale_payments sp JOIN sales sa ON sa.id=sp.sale_id WHERE sa.session_id=? AND sa.status='completed' GROUP BY sp.method", [$s['id']])]; }
        $user = Auth::user(); $bid = $user['branch_id'];
        $counters = DB::all('SELECT c.id, c.name, b.name AS branch, b.id AS branch_id, (SELECT COUNT(*) FROM pos_sessions ps WHERE ps.counter_id=c.id AND ps.status IN ("open","suspended")) AS busy FROM counters c JOIN branches b ON b.id=c.branch_id WHERE c.is_active=1 AND b.is_active=1' . ($bid ? ' AND c.branch_id=' . (int)$bid : '') . ' ORDER BY b.name, c.name');
        $mine = Auth::can('pos.session.view_all') ? '' : ' WHERE s.user_id=' . (int)Auth::id();
        $recent = DB::all("SELECT s.*, u.full_name FROM pos_sessions s JOIN users u ON u.id=s.user_id$mine ORDER BY s.id DESC LIMIT 10");
        $this->render('pos/session', ['title' => 'Register / Session', 's' => $s, 'summary' => $summary, 'counters' => $counters, 'recent' => $recent]);
    }
    function action_open(): void {
        $this->postOnly(); $this->need('pos.session.open');
        if ($this->session()) { flash('warning', 'You already have an active session.'); redirect('pos/session'); }
        $cid = (int)input('counter_id'); $cash = input('opening_cash', '0');
        $err = Validator::check(['c' => $cid, 'cash' => $cash], ['c' => ['label' => 'Counter', 'rules' => 'required|exists:counters,id'], 'cash' => ['label' => 'Opening cash', 'rules' => 'required|decimal']]);
        $c = DB::one('SELECT * FROM counters WHERE id=? AND is_active=1', [$cid]);
        if (!$c) $err[] = 'Choose a counter.'; elseif (Auth::user()['branch_id'] && (int)$c['branch_id'] !== (int)Auth::user()['branch_id']) $err[] = 'That counter belongs to another branch.';
        if ($c && DB::val("SELECT 1 FROM pos_sessions WHERE counter_id=? AND status IN ('open','suspended')", [$cid])) $err[] = 'That counter is already in use.';
        $wh = $c ? Stock::defaultWarehouse((int)$c['branch_id']) : null; if ($c && !$wh) $err[] = 'The branch has no active warehouse. Ask an administrator to create one.';
        if ($err) { flash('danger', implode(' ', $err)); redirect('pos/session'); }
        $id = DB::insert('pos_sessions', ['session_no' => next_number('session', 'SES-', 6), 'user_id' => Auth::id(), 'branch_id' => $c['branch_id'], 'counter_id' => $cid, 'warehouse_id' => $wh, 'opening_cash' => Pos::r2($cash), 'opened_at' => date('Y-m-d H:i:s')]);
        audit('session_open', 'pos_sessions', $id, ['opening_cash' => $cash]); flash('success', 'Register opened.'); redirect('pos/terminal');
    }
    function action_toggle(): void {
        $this->postOnly(); $this->need('pos.session.open'); $s = $this->session(); if (!$s) redirect('pos/session');
        $to = $s['status'] === 'open' ? 'suspended' : 'open'; DB::update('pos_sessions', ['status' => $to], 'id=?', [$s['id']]); audit('session_' . ($to === 'open' ? 'resume' : 'suspend'), 'pos_sessions', $s['id']); flash('success', 'Session ' . ($to === 'open' ? 'resumed.' : 'suspended.')); redirect('pos/session');
    }
    function action_close(): void {
        $this->postOnly(); $this->need('pos.session.open'); $s = $this->session(); if (!$s) redirect('pos/session');
        $c = input('counted_cash'); if (Validator::check(['c' => $c], ['c' => ['label' => 'Counted cash', 'rules' => 'required|decimal']])) { flash('danger', 'Enter the counted cash amount.'); redirect('pos/session'); }
        if (DB::val('SELECT COUNT(*) FROM held_carts WHERE user_id=?', [Auth::id()])) { flash('warning', 'You still have held carts. Resume or discard them first.'); redirect('pos/session'); }
        $exp = Pos::expectedCash($s); $counted = Pos::r2($c); $var = Pos::r2($counted - $exp);
        DB::update('pos_sessions', ['status' => 'closed', 'closed_at' => date('Y-m-d H:i:s'), 'expected_cash' => $exp, 'counted_cash' => $counted, 'variance' => $var, 'notes' => substr((string)input('notes', ''), 0, 255) ?: null], 'id=?', [$s['id']]);
        audit('session_close', 'pos_sessions', $s['id'], ['expected' => $exp, 'counted' => $counted, 'variance' => $var]);
        flash($var == 0 ? 'success' : 'warning', 'Register closed. Cash variance: ' . money($var)); redirect('pos/session');
    }
    // ================= Terminal + AJAX
    function action_terminal(): void {
        $this->need('pos.sale.create'); $s = $this->session();
        if (!$s || $s['status'] !== 'open') { flash('warning', $s ? 'Your session is suspended. Resume it first.' : 'Open a register session first.'); redirect('pos/session'); }
        $this->layout = 'layout/main';
        $holds = DB::all('SELECT id, label, created_at FROM held_carts WHERE user_id=? ORDER BY id DESC', [Auth::id()]);
        $quick = DB::all('SELECT id, sku, name, sold_by, selling_price FROM products WHERE is_quick=1 AND is_active=1 AND is_archived=0 ORDER BY name LIMIT 24');
        $this->render('pos/terminal', ['title' => 'POS Terminal', 's' => $s, 'holds' => $holds, 'quick' => $quick, 'cfg' => ['maxPct' => (float)setting('max_cashier_discount', 10), 'canDisc' => Auth::can('pos.discount.apply') || Auth::can('pos.discount.approve'), 'canPrice' => Auth::can('pos.sale.price_override'), 'canCredit' => Auth::can('pos.sale.credit'), 'isApprover' => Auth::can('pos.discount.approve')]]);
    }
    function action_search(): void {
        $this->need('pos.sale.create'); $s = $this->session(); if (!$s) json_out(['ok' => false, 'error' => 'No active session'], 409);
        $q = trim((string)input('q', '')); if ($q === '') json_out(['ok' => true, 'items' => []]);
        $wh = (int)$s['warehouse_id'];
        $sel = 'SELECT p.id, p.sku, p.barcode, p.name, p.selling_price, p.product_type, p.sold_by, p.age_restricted, p.image, COALESCE(st.qty - st.reserved_qty,0) AS avail FROM products p LEFT JOIN stock st ON st.product_id=p.id AND st.warehouse_id=? WHERE p.is_active=1 AND p.is_archived=0 AND ';
        $exact = DB::all($sel . '(p.barcode=? OR p.sku=? OR p.isbn=?) LIMIT 5', [$wh, $q, $q, str_replace('-', '', $q)]);
        if ($exact) json_out(['ok' => true, 'exact' => true, 'items' => $exact]);
        $pb = DB::one('SELECT product_id, qty_per_scan FROM product_barcodes WHERE barcode=?', [$q]);   // carton / second barcode
        if ($pb && ($it = DB::all($sel . 'p.id=?', [$wh, $pb['product_id']]))) { $it[0]['scan_qty'] = (float)$pb['qty_per_scan']; json_out(['ok' => true, 'exact' => true, 'items' => $it]); }
        if (preg_match('/^\d{13}$/', $q) && Barcode::valid13($q) && $q[0] === (string)setting('scale_label_prefix', '2')) {   // scale label: 2 + PLU(6) + grams(5) + check
            $plu = (int)substr($q, 1, 6); $grams = (int)substr($q, 7, 5);
            $it = DB::all($sel . "p.sold_by='weight' AND p.plu IS NOT NULL AND CAST(p.plu AS UNSIGNED)=? LIMIT 1", [$wh, $plu]);
            if ($it && $grams > 0) { $it[0]['scan_qty'] = round($grams / 1000, 3); json_out(['ok' => true, 'exact' => true, 'items' => $it]); }
        }
        $l = '%' . addcslashes($q, '%_\\') . '%'; $cat = (int)input('category', 0); $extra = $cat ? ' AND p.category_id=' . $cat : '';
        json_out(['ok' => true, 'exact' => false, 'items' => DB::all($sel . '(p.name LIKE ? OR p.sku LIKE ? OR p.isbn LIKE ? OR p.barcode LIKE ? OR p.plu = ?)' . $extra . ' ORDER BY p.name LIMIT 20', [$wh, $l, $l, $l, $l, $q])]);
    }
    function action_customers(): void {
        $this->need('pos.sale.create'); $q = trim((string)input('q', '')); if (mb_strlen($q) < 2) json_out(['ok' => true, 'items' => []]); $l = '%' . addcslashes($q, '%_\\') . '%';
        $rows = DB::all('SELECT c.id, c.code, c.name, c.phone, c.credit_limit, c.loyalty_points, g.name AS group_name FROM customers c LEFT JOIN customer_groups g ON g.id=c.group_id WHERE c.is_active=1 AND (c.name LIKE ? OR c.phone LIKE ? OR c.code LIKE ? OR c.card_no = ?) ORDER BY c.name LIMIT 8', [$l, $l, $l, $q]);
        foreach ($rows as &$r) { $r['balance'] = Finance::customerBalance((int)$r['id']); } json_out(['ok' => true, 'items' => $rows]);
    }
    function action_quick_customer(): void {
        $this->postOnly(); $this->need('pos.sale.create'); if (!Auth::can('admin.customers.create')) json_out(['ok' => false, 'error' => 'You are not allowed to create customers.'], 403); $b = $this->body();
        $d = ['name' => trim((string)($b['name'] ?? '')), 'phone' => trim((string)($b['phone'] ?? '')) ?: null];
        $err = Validator::check($d, ['name' => ['label' => 'Name', 'rules' => 'required|max:150'], 'phone' => ['label' => 'Phone', 'rules' => 'phone']]); if ($err) json_out(['ok' => false, 'error' => implode(' ', $err)], 422);
        $id = DB::insert('customers', $d + ['code' => next_number('customer', 'CUS-', 5)]); audit('create', 'customers', $id, ['via' => 'pos']); json_out(['ok' => true, 'customer' => ['id' => $id, 'name' => $d['name'], 'phone' => $d['phone'], 'credit_limit' => 0, 'loyalty_points' => 0, 'balance' => 0, 'group_name' => null]]);
    }
    function action_quote(): void {
        $this->postOnly(); $this->need('pos.sale.create'); $s = $this->session(); if (!$s) json_out(['ok' => false, 'error' => 'No active session'], 409); $b = $this->body();
        $r = Pos::compute($b, $this->ctx($b, (int)$s['branch_id'])); $r['approved'] = Pos::approvalValid() || Auth::can('pos.discount.approve');
        foreach ($r['lines'] as &$l) $l['avail'] = Stock::available((int)$s['warehouse_id'], $l['product_id']); json_out(['ok' => true] + $r);
    }
    function action_approve(): void {
        $this->postOnly(); $this->need('pos.sale.create'); $b = $this->body();
        $id = Auth::verifyApprover((string)($b['username'] ?? ''), (string)($b['password'] ?? ''), 'pos.discount.approve');
        if (!$id) json_out(['ok' => false, 'error' => 'Invalid credentials or the user cannot approve discounts.'], 403);
        $_SESSION['pos_approval'] = ['uid' => Auth::id(), 'exp' => time() + 600, 'by' => $id]; audit('discount_approved', 'pos', null, ['approver' => $id]); json_out(['ok' => true]);
    }
    function action_hold(): void {
        $this->postOnly(); $this->need('pos.sale.create'); $b = $this->body(); if (empty($b['items'])) json_out(['ok' => false, 'error' => 'Cart is empty.'], 422);
        if (DB::val('SELECT COUNT(*) FROM held_carts WHERE user_id=?', [Auth::id()]) >= 20) json_out(['ok' => false, 'error' => 'Too many held carts.'], 422);
        $data = json_encode(['items' => $b['items'], 'customer' => $b['customer'] ?? null, 'cart_disc' => $b['cart_disc'] ?? null, 'coupon' => $b['coupon'] ?? '']);
        if (strlen($data) > 200000) json_out(['ok' => false, 'error' => 'Cart too large.'], 422);
        $id = DB::insert('held_carts', ['user_id' => Auth::id(), 'label' => mb_substr(trim((string)($b['label'] ?? '')) ?: 'Cart ' . date('H:i'), 0, 80), 'data' => $data]); json_out(['ok' => true, 'id' => $id]);
    }
    function action_resume(): void {
        $this->postOnly(); $this->need('pos.sale.create'); $b = $this->body(); $h = DB::one('SELECT * FROM held_carts WHERE id=? AND user_id=?', [(int)($b['id'] ?? 0), Auth::id()]);
        if (!$h) json_out(['ok' => false, 'error' => 'Held cart not found.'], 404);
        if (empty($b['keep'])) DB::q('DELETE FROM held_carts WHERE id=?', [$h['id']]); json_out(['ok' => true, 'cart' => json_decode($h['data'], true)]);
    }
    function action_discard(): void { $this->postOnly(); $this->need('pos.sale.create'); $b = $this->body(); DB::q('DELETE FROM held_carts WHERE id=? AND user_id=?', [(int)($b['id'] ?? 0), Auth::id()]); json_out(['ok' => true]); }

    // ================= Checkout
    function action_checkout(): void {
        $this->postOnly(); $this->need('pos.sale.create'); $s = $this->session();
        if (!$s || $s['status'] !== 'open') json_out(['ok' => false, 'error' => 'No open register session.'], 409);
        $b = $this->body(); $key = substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($b['key'] ?? '')), 0, 64);
        if ($key === '') json_out(['ok' => false, 'error' => 'Missing request key.'], 422);
        if ($dup = DB::one('SELECT id FROM sales WHERE idem_key=?', [$key])) json_out(['ok' => true, 'sale_id' => (int)$dup['id'], 'duplicate' => true]);
        $branch = (int)$s['branch_id']; $wh = (int)$s['warehouse_id']; $ctx = $this->ctx($b, $branch); $cust = $ctx['customer'];
        if (!empty($b['customer_id']) && !$cust) json_out(['ok' => false, 'error' => 'Customer not found or inactive.'], 422);
        $c = Pos::compute($b, $ctx); $errors = $c['errors'];
        if ($c['needs_approval'] && !Pos::approvalValid() && !Auth::can('pos.discount.approve')) $errors[] = 'Discount exceeds your limit - manager approval required.';
        $total = $c['total']; if ($total < 0.005 && !$errors) $errors[] = 'Sale total must be greater than zero.';
        foreach ($c['lines'] as $l) if (!empty($l['age_restricted']) && empty($b['age_verified'])) { $errors[] = 'An age-restricted item is in the cart - confirm that the customer\'s ID was checked.'; break; }
        // payments
        $pays = []; $paid = 0.0; $cashTendered = 0.0; $nonCash = 0.0; $valid = array_keys(payment_methods()); $exchangeRet = []; $giftUse = []; $pointsUse = 0;
        foreach ((array)($b['payments'] ?? []) as $p) {
            $m = (string)($p['method'] ?? ''); $a = $p['amount'] ?? 0; if (!is_numeric($a) || $a <= 0) continue; $a = Pos::r2($a);
            if ($m === 'exchange_credit') {
                $ref = trim((string)($p['reference'] ?? '')); $r = DB::one("SELECT * FROM sales_returns WHERE return_no=? AND refund_method='exchange' AND exchange_used=0", [$ref]);
                if (!$r || in_array($r['id'], array_column($exchangeRet, 0))) { $errors[] = 'Exchange credit note is invalid or already used.'; continue; }
                $room = Pos::r2($total - $paid); $a = Pos::r2(min($a, (float)$r['total_refund'] - (float)$r['credit_used'], max(0, $room)));
                if ($a < 0.005) { $errors[] = 'Exchange credit cannot be applied (nothing due or note fully used).'; continue; }
                $exchangeRet[] = [$r['id'], $a]; $pays[] = ['method' => $m, 'amount' => $a, 'reference' => $ref]; $paid += $a; $nonCash += $a; continue;
            }
            if ($m === 'gift_card') {
                $code = strtoupper(trim((string)($p['reference'] ?? ''))); $g = DB::one("SELECT * FROM gift_cards WHERE code=? AND status='active' AND (expires_on IS NULL OR expires_on >= CURDATE())", [$code]);
                if (!$g || in_array($g['id'], array_column($giftUse, 0))) { $errors[] = 'Gift card is invalid, expired, blocked or empty.'; continue; }
                $room = Pos::r2($total - $paid); $a = Pos::r2(min($a, (float)$g['balance'], max(0, $room)));
                if ($a < 0.005) { $errors[] = 'Gift card cannot be applied (nothing due or no balance).'; continue; }
                $giftUse[] = [$g['id'], $a]; $pays[] = ['method' => $m, 'amount' => $a, 'reference' => $code]; $paid += $a; $nonCash += $a; continue;
            }
            if ($m === 'loyalty') {
                $val = (float)setting('loyalty_redeem_value', 1);
                if (!$cust || $val <= 0) { $errors[] = 'Choose a customer to redeem loyalty points.'; continue; }
                $room = Pos::r2($total - $paid); $maxAmt = floor((int)$cust['loyalty_points'] * $val * 100) / 100; $a = Pos::r2(min($a, $maxAmt, max(0, $room)));
                if ($a < 0.005) { $errors[] = 'Not enough loyalty points to redeem.'; continue; }
                $pts = (int)ceil($a / $val - 0.0000001); $pointsUse += $pts; $pays[] = ['method' => $m, 'amount' => $a, 'reference' => (string)$pts]; $paid += $a; $nonCash += $a; continue;
            }
            if (!in_array($m, $valid, true)) { $errors[] = 'Invalid payment method.'; continue; }
            if (($m === 'card' || $m === 'bank_transfer' || $m === 'digital') && strlen((string)($p['reference'] ?? '')) > 80) { $errors[] = 'Payment reference too long.'; continue; }
            $pays[] = ['method' => $m, 'amount' => $a, 'reference' => substr(trim((string)($p['reference'] ?? '')), 0, 80) ?: null]; $paid += $a; if ($m === 'cash') $cashTendered += $a; else $nonCash += $a;
        }
        $credit = 0.0; $change = 0.0;
        if (!$errors) {
            if ($nonCash > $total + 0.004) $errors[] = 'Non-cash payments cannot exceed the sale total.';
            elseif ($paid + 0.004 >= $total) { $change = Pos::r2($paid - $total); if ($change > $cashTendered + 0.004) $errors[] = 'Change cannot exceed the cash tendered.'; }
            else {
                $credit = Pos::r2($total - $paid);
                if (!$cust) $errors[] = 'Select a customer to sell on credit (or collect full payment).';
                elseif (!Auth::can('pos.sale.credit')) $errors[] = 'You are not allowed to make credit sales.';
                elseif ($cust['credit_limit'] <= 0) $errors[] = 'This customer has no credit limit.';
                elseif (Finance::customerBalance((int)$cust['id']) + $credit > $cust['credit_limit'] + 0.004) $errors[] = 'Credit limit exceeded (available ' . money(max(0, $cust['credit_limit'] - Finance::customerBalance((int)$cust['id']))) . ').';
            }
        }
        if ($errors) json_out(['ok' => false, 'error' => implode(' ', array_unique($errors))], 422);
        try {
            $saleId = DB::tx(function () use ($c, $pays, $credit, $change, $total, $cust, $s, $branch, $wh, $key, $b, $exchangeRet, $paid, $giftUse, $pointsUse) {
                $sid = DB::insert('sales', ['invoice_no' => next_number('invoice', 'INV-' . date('ymd') . '-', 5), 'branch_id' => $branch, 'session_id' => $s['id'], 'user_id' => Auth::id(), 'customer_id' => $cust['id'] ?? null, 'sale_date' => date('Y-m-d H:i:s'),
                    'subtotal' => $c['subtotal'], 'discount_total' => $c['discount_total'], 'tax_total' => $c['tax_total'], 'total' => $total, 'paid_total' => Pos::r2($paid - $change), 'credit_amount' => $credit, 'change_amount' => $change, 'receipt_token' => bin2hex(random_bytes(16)), 'idem_key' => $key, 'notes' => substr(trim((string)($b['notes'] ?? '')), 0, 255) ?: null]);
                $lines = $c['lines']; usort($lines, fn($x, $y) => $x['product_id'] <=> $y['product_id']);
                foreach ($lines as $l) {
                    DB::insert('sale_items', ['sale_id' => $sid, 'product_id' => $l['product_id'], 'name' => $l['name'], 'qty' => $l['qty'], 'unit_label' => $l['unit_label'], 'unit_price' => $l['unit_price'], 'cost_price' => $l['cost_price'], 'discount' => $l['discount'], 'tax_rate' => $l['tax_rate'], 'tax_amount' => $l['tax_amount'], 'line_total' => $l['line_total'], 'promo_name' => $l['promo_name']]);
                    DB::q('INSERT IGNORE INTO stock (warehouse_id, product_id, qty) VALUES (?,?,0)', [$wh, $l['product_id']]);
                    $st = DB::one('SELECT qty, reserved_qty FROM stock WHERE warehouse_id=? AND product_id=? FOR UPDATE', [$wh, $l['product_id']]);
                    if (setting('allow_negative_stock', '0') !== '1' && $st['qty'] - $st['reserved_qty'] < $l['qty']) throw new RuntimeException("Insufficient stock for \"{$l['name']}\" (available " . fq(max(0, $st['qty'] - $st['reserved_qty'])) . '). Receive the stock (Purchasing) or ask a manager to add it with a stock adjustment first.');
                    Stock::change($wh, $l['product_id'], -$l['qty'], 'sale', 'sale', $sid, null, true);
                }
                $cashLeft = $change;
                foreach ($pays as $p) {
                    DB::insert('sale_payments', ['sale_id' => $sid, 'method' => $p['method'], 'amount' => $p['amount'], 'reference' => $p['reference']]);
                    $net = $p['amount']; if ($p['method'] === 'cash' && $cashLeft > 0) { $take = min($cashLeft, $net); $net -= $take; $cashLeft -= $take; }
                    if (!in_array($p['method'], ['exchange_credit', 'gift_card', 'loyalty'], true) && $net > 0) fin_txn('sale', 'in', $p['method'], $net, $branch, (int)$s['id'], 'sale', $sid, 'Sale');
                }
                foreach ($giftUse as [$gid, $gamt]) {
                    $g = DB::one('SELECT balance, status FROM gift_cards WHERE id=? FOR UPDATE', [$gid]);
                    if (!$g || $g['status'] !== 'active' || (float)$g['balance'] + 0.004 < $gamt) throw new RuntimeException('Gift card balance changed - please check the card and try again.');
                    DB::q("UPDATE gift_cards SET balance = balance - ?, status = IF(balance - ? <= 0.004, 'used', status) WHERE id=?", [$gamt, $gamt, $gid]);
                    DB::insert('gift_card_txns', ['gift_card_id' => $gid, 'amount' => -$gamt, 'type' => 'redeem', 'sale_id' => $sid, 'user_id' => Auth::id(), 'note' => 'Sale']);
                }
                if ($pointsUse > 0 && $cust) {
                    $n = DB::update('customers', ['loyalty_points' => (int)$cust['loyalty_points'] - $pointsUse], 'id=? AND loyalty_points >= ?', [$cust['id'], $pointsUse]);
                    if (!$n) throw new RuntimeException('Loyalty points changed - please try again.');
                    DB::insert('loyalty_transactions', ['customer_id' => $cust['id'], 'points' => -$pointsUse, 'reason' => 'Redeemed at POS', 'ref' => 'sale#' . $sid, 'user_id' => Auth::id()]);
                }
                foreach ($exchangeRet as [$rid, $amtUsed]) DB::q('UPDATE sales_returns SET credit_used = credit_used + ?, exchange_used = (credit_used + ? >= total_refund - 0.004) WHERE id=?', [$amtUsed, $amtUsed, $rid]);
                if ($cust) {
                    $per = (float)setting('loyalty_per_amount', 0);
                    if ($per > 0) { $pts = (int)floor($total / $per); if ($pts > 0) { DB::q('UPDATE customers SET loyalty_points = loyalty_points + ? WHERE id=?', [$pts, $cust['id']]); DB::insert('loyalty_transactions', ['customer_id' => $cust['id'], 'points' => $pts, 'reason' => 'Purchase', 'ref' => 'sale#' . $sid, 'user_id' => Auth::id()]); } }
                }
                if (!empty($b['held_id'])) DB::q('DELETE FROM held_carts WHERE id=? AND user_id=?', [(int)$b['held_id'], Auth::id()]);
                return $sid;
            });
        } catch (RuntimeException $e) { json_out(['ok' => false, 'error' => $e->getMessage()], 409); }
        audit('sale', 'sales', $saleId, ['total' => $total, 'credit' => $credit]); unset($_SESSION['pos_approval']);
        json_out(['ok' => true, 'sale_id' => $saleId, 'change' => $change, 'receipt' => url('pos/receipt', ['id' => $saleId])]);
    }

    // ================= Cash pickups (safe drops), paid-outs, float
    function action_cash(): void {
        $this->need('pos.session.open'); $s = $this->session();
        if (!$s) { flash('warning', 'Open a register session first.'); redirect('pos/session'); }
        $types = ['pickup' => ['cash_pickup', 'out', 'pos.cash.pickup', 'Cash pickup (safe drop)'], 'paid_out' => ['paid_out', 'out', 'pos.cash.paid_out', 'Paid out (small expense)'], 'float_in' => ['float_in', 'in', 'pos.cash.float_in', 'Float / change money in']];
        if (is_post()) {
            $t = (string)input('type'); $amt = input('amount'); $reason = trim((string)input('reason', ''));
            if (!isset($types[$t])) { flash('danger', 'Choose a type.'); redirect('pos/cash'); }
            [$ftype, $dir, $perm] = $types[$t]; $err = [];
            if (!is_numeric($amt) || (float)$amt <= 0 || (float)$amt > 10000000) $err[] = 'Enter an amount above zero.';
            if (mb_strlen($reason) < 3 || mb_strlen($reason) > 200) $err[] = 'A reason is required.';
            $approver = Auth::id();
            if (!Auth::can($perm)) { $approver = Auth::verifyApprover((string)input('approver', ''), (string)($_POST['approver_pass'] ?? ''), $perm); if (!$approver) $err[] = 'A supervisor must approve: enter valid supervisor credentials.'; }
            if (!$err && $dir === 'out' && (float)$amt > Pos::expectedCash($s) + 0.004) $err[] = 'That is more than the cash expected in the drawer (' . money(Pos::expectedCash($s)) . ').';
            if ($err) { flash('danger', implode(' ', $err)); redirect('pos/cash'); }
            fin_txn($ftype, $dir, 'cash', Pos::r2($amt), (int)$s['branch_id'], (int)$s['id'], 'pos_session', (int)$s['id'], mb_substr($reason, 0, 200) . ($approver !== Auth::id() ? ' (approved by user #' . $approver . ')' : ''));
            audit($ftype, 'pos_sessions', $s['id'], ['amount' => $amt, 'reason' => $reason, 'approver' => $approver]); flash('success', $types[$t][3] . ' recorded.'); redirect('pos/cash');
        }
        $rows = DB::all("SELECT * FROM fin_transactions WHERE session_id=? AND type IN ('cash_pickup','paid_out','float_in') ORDER BY id DESC", [$s['id']]);
        $this->render('pos/cash', ['title' => 'Cash Pickup / Paid out', 's' => $s, 'types' => $types, 'rows' => $rows, 'expected' => Pos::expectedCash($s)]);
    }

    // ================= Receipts / invoices / history
    private function saleFull(int $id): array {
        $s = DB::one('SELECT s.*, u.full_name AS cashier, c.name AS customer, c.phone AS customer_phone, c.email AS customer_email, c.code AS customer_code, b.name AS branch, b.address AS branch_address, b.phone AS branch_phone, b.receipt_footer FROM sales s JOIN users u ON u.id=s.user_id JOIN branches b ON b.id=s.branch_id LEFT JOIN customers c ON c.id=s.customer_id WHERE s.id=?', [$id]);
        if (!$s) $this->abort(404, 'Sale not found.');
        $s['items'] = DB::all('SELECT * FROM sale_items WHERE sale_id=? ORDER BY id', [$id]); $s['payments'] = DB::all('SELECT * FROM sale_payments WHERE sale_id=?', [$id]); return $s;
    }
    private function guardSale(array $s): void { if (!Auth::can('pos.history.view_all') && (int)$s['user_id'] !== Auth::id() && !Auth::can('admin.reports.view')) $this->abort(403, 'You can only view your own transactions.'); }
    function action_sale(): void {
        $this->need('pos.history.view'); $s = $this->saleFull((int)input('id')); $this->guardSale($s);
        $returns = DB::all('SELECT * FROM sales_returns WHERE sale_id=? ORDER BY id', [$s['id']]);
        $this->render('pos/sale', ['title' => $s['invoice_no'], 's' => $s, 'returns' => $returns]);
    }
    function action_receipt(): void {
        $this->need('pos.history.view'); $s = $this->saleFull((int)input('id')); $this->guardSale($s);
        if (input('reprint')) { $this->need('pos.history.reprint'); audit('reprint', 'sales', $s['id']); }
        $this->render('pos/receipt', ['s' => $s, 'mode' => 'receipt', 'auto' => !input('noprint')], 'none');
    }
    function action_invoice(): void {
        $this->need('pos.history.view'); $s = $this->saleFull((int)input('id')); $this->guardSale($s); $this->render('pos/receipt', ['s' => $s, 'mode' => 'invoice', 'auto' => false], 'none');
    }
    function action_email(): void {
        $this->postOnly(); $this->need('pos.history.reprint'); $s = $this->saleFull((int)input('id')); $this->guardSale($s);
        $to = trim((string)input('email', $s['customer_email'] ?? '')); if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { flash('danger', 'Enter a valid email address.'); redirect('pos/sale', ['id' => $s['id']]); }
        $link = (!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . url('receipt/view', ['t' => $s['receipt_token']]);
        $body = "Thank you for shopping at " . setting('business_name', cfg('app_name')) . ".\nInvoice: {$s['invoice_no']}\nTotal: " . money($s['total']) . "\nView your digital receipt: $link\n";
        $ok = @mail($to, 'Your receipt ' . $s['invoice_no'], $body, 'From: no-reply@' . preg_replace('/[^a-z0-9.\-]/i', '', $_SERVER['SERVER_NAME'] ?? 'localhost'));
        audit('receipt_email', 'sales', $s['id'], ['to' => $to, 'sent' => $ok]); flash($ok ? 'success' : 'warning', $ok ? 'Receipt emailed.' : 'The server could not send mail (check the PHP mail configuration). Share the digital receipt link instead.'); redirect('pos/sale', ['id' => $s['id']]);
    }
    function action_history(): void {
        $this->need('pos.history.view'); $w = ['1=1']; $p = []; $all = Auth::can('pos.history.view_all');
        if (!$all) { $w[] = 's.user_id=?'; $p[] = Auth::id(); }
        $from = (string)input('from', date('Y-m-d')); $to = (string)input('to', date('Y-m-d')); $w[] = 's.sale_date BETWEEN ? AND ?'; array_push($p, "$from 00:00:00", "$to 23:59:59");
        if (($v = trim((string)input('q', ''))) !== '') { $w[] = '(s.invoice_no LIKE ? OR c.name LIKE ? OR c.phone LIKE ?)'; $l = '%' . addcslashes($v, '%_\\') . '%'; array_push($p, $l, $l, $l); }
        if (($v = (string)input('status', '')) !== '' && in_array($v, ['completed', 'void'], true)) { $w[] = 's.status=?'; $p[] = $v; }
        if ($all && ($v = (int)input('cashier', 0))) { $w[] = 's.user_id=?'; $p[] = $v; }
        $ws = implode(' AND ', $w); $base = "FROM sales s JOIN users u ON u.id=s.user_id LEFT JOIN customers c ON c.id=s.customer_id WHERE $ws";
        if (input('export') === 'csv') { $rows = DB::all("SELECT s.invoice_no, s.sale_date, u.full_name, c.name cust, s.subtotal, s.discount_total, s.tax_total, s.total, s.credit_amount, s.status $base ORDER BY s.id DESC LIMIT 20000", $p); Report::csv('transactions.csv', ['Invoice', 'Date', 'Cashier', 'Customer', 'Subtotal', 'Discount', 'Tax', 'Total', 'Credit', 'Status'], $rows); }
        $sum = DB::one("SELECT COUNT(*) n, COALESCE(SUM(CASE WHEN s.status='completed' THEN s.total END),0) t $base", $p);
        [$page, $pages, $off] = paginate((int)$sum['n'], 30, (int)input('page', 1));
        $rows = DB::all("SELECT s.*, u.full_name AS cashier, c.name AS customer, (SELECT COUNT(*) FROM sales_returns r WHERE r.sale_id=s.id) AS returns_n $base ORDER BY s.id DESC LIMIT 30 OFFSET $off", $p);
        $this->render('pos/history', ['title' => 'Transactions', 'rows' => $rows, 'sum' => $sum, 'from' => $from, 'to' => $to, 'page' => $page, 'pages' => $pages, 'all' => $all, 'cashiers' => $all ? DB::all('SELECT id, full_name FROM users ORDER BY full_name') : []]);
    }
    function action_void(): void {
        $this->postOnly(); $this->need('pos.history.void'); $id = (int)input('id'); $reason = trim((string)input('reason', ''));
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 255) { flash('danger', 'Provide a reason (3-255 characters).'); redirect('pos/sale', ['id' => $id]); }
        try {
            DB::tx(function () use ($id, $reason) {
                $s = DB::one('SELECT * FROM sales WHERE id=? FOR UPDATE', [$id]); if (!$s || $s['status'] !== 'completed') throw new RuntimeException('Only completed sales can be voided.');
                if (DB::val('SELECT 1 FROM sales_returns WHERE sale_id=?', [$id])) throw new RuntimeException('This sale has returns and cannot be voided.');
                if (!Auth::can('pos.history.view_all') && (int)$s['user_id'] !== Auth::id()) throw new RuntimeException('You can only void your own sales.');
                $wh = (int)DB::val('SELECT warehouse_id FROM pos_sessions WHERE id=?', [$s['session_id']]) ?: (int)Stock::defaultWarehouse((int)$s['branch_id']);
                foreach (DB::all('SELECT product_id, qty FROM sale_items WHERE sale_id=? ORDER BY product_id', [$id]) as $i) Stock::change($wh, (int)$i['product_id'], (int)$i['qty'], 'void', 'sale', $id, 'Void ' . $s['invoice_no'], true);
                $cashBack = (float)$s['change_amount'];
                foreach (DB::all('SELECT * FROM sale_payments WHERE sale_id=?', [$id]) as $p) {
                    if ($p['method'] === 'gift_card') { $g = DB::one('SELECT id FROM gift_cards WHERE code=?', [$p['reference']]); if ($g) { DB::q("UPDATE gift_cards SET balance = balance + ?, status = IF(status='used','active',status) WHERE id=?", [$p['amount'], $g['id']]); DB::insert('gift_card_txns', ['gift_card_id' => $g['id'], 'amount' => $p['amount'], 'type' => 'refund', 'sale_id' => $id, 'user_id' => Auth::id(), 'note' => 'Sale voided']); } continue; }
                    if ($p['method'] === 'loyalty') { $pts = (int)$p['reference']; if ($s['customer_id'] && $pts > 0) { DB::q('UPDATE customers SET loyalty_points = loyalty_points + ? WHERE id=?', [$pts, $s['customer_id']]); DB::insert('loyalty_transactions', ['customer_id' => $s['customer_id'], 'points' => $pts, 'reason' => 'Sale voided - points returned', 'ref' => 'void#' . $id, 'user_id' => Auth::id()]); } continue; }
                    if ($p['method'] === 'exchange_credit') { DB::q('UPDATE sales_returns SET credit_used = GREATEST(0, credit_used - ?), exchange_used=0 WHERE return_no=?', [$p['amount'], $p['reference']]); continue; }
                    $net = (float)$p['amount']; if ($p['method'] === 'cash' && $cashBack > 0) { $t = min($cashBack, $net); $net -= $t; $cashBack -= $t; }
                    if ($net > 0) fin_txn('void', 'out', $p['method'], $net, (int)$s['branch_id'], ($open = Pos::openSession()) ? (int)$open['id'] : null, 'sale', $id, 'Void ' . $s['invoice_no']);
                }
                if ($s['customer_id']) { $pts = (int)DB::val("SELECT COALESCE(SUM(points),0) FROM loyalty_transactions WHERE ref=?", ['sale#' . $id]); if ($pts > 0) { $cur = (int)DB::val('SELECT loyalty_points FROM customers WHERE id=?', [$s['customer_id']]); $d = min($pts, $cur); DB::q('UPDATE customers SET loyalty_points = loyalty_points - ? WHERE id=?', [$d, $s['customer_id']]); DB::insert('loyalty_transactions', ['customer_id' => $s['customer_id'], 'points' => -$d, 'reason' => 'Sale voided', 'ref' => 'void#' . $id, 'user_id' => Auth::id()]); } }
                DB::update('sales', ['status' => 'void', 'void_reason' => $reason, 'voided_by' => Auth::id(), 'voided_at' => date('Y-m-d H:i:s')], 'id=?', [$id]);
            });
            audit('void', 'sales', $id, ['reason' => $reason]); flash('success', 'Transaction voided; stock and payments reversed.');
        } catch (RuntimeException $e) { flash('danger', $e->getMessage()); }
        redirect('pos/sale', ['id' => $id]);
    }

    // ================= Returns & exchanges
    function action_returns(): void {
        $this->need('pos.returns.create'); $q = trim((string)input('q', '')); $found = [];
        if ($q !== '') { $l = '%' . addcslashes($q, '%_\\') . '%'; $found = DB::all("SELECT s.id, s.invoice_no, s.sale_date, s.total, s.status, c.name AS customer FROM sales s LEFT JOIN customers c ON c.id=s.customer_id WHERE (s.invoice_no LIKE ? OR c.phone LIKE ? OR c.name LIKE ?) ORDER BY s.id DESC LIMIT 20", [$l, $l, $l]); }
        $recent = DB::all("SELECT r.*, s.invoice_no FROM sales_returns r JOIN sales s ON s.id=r.sale_id ORDER BY r.id DESC LIMIT 15");
        $this->render('pos/returns', ['title' => 'Returns & Exchanges', 'q' => $q, 'found' => $found, 'recent' => $recent]);
    }
    function action_return_form(): void {
        $this->need('pos.returns.create'); $s = $this->saleFull((int)input('sale_id')); 
        $days = (int)setting('return_days', 14); $age = (int)floor((time() - strtotime($s['sale_date'])) / 86400);
        $items = DB::all('SELECT si.*, COALESCE((SELECT SUM(amount) FROM sales_return_items ri WHERE ri.sale_item_id=si.id),0) AS refunded FROM sale_items si WHERE si.sale_id=?', [$s['id']]);
        $this->render('pos/return_form', ['title' => 'Return - ' . $s['invoice_no'], 's' => $s, 'items' => $items, 'age' => $age, 'days' => $days, 'canApprove' => Auth::can('pos.returns.approve')]);
    }
    function action_return_save(): void {
        $this->postOnly(); $this->need('pos.returns.create'); $sid = (int)input('sale_id'); $s = DB::one('SELECT * FROM sales WHERE id=?', [$sid]); if (!$s) $this->abort(404, 'Sale not found.');
        $back = fn() => redirect('pos/return_form', ['sale_id' => $sid]);
        if ($s['status'] !== 'completed') { flash('danger', 'Voided sales cannot be returned.'); $back(); }
        $reason = trim((string)input('reason', '')); $method = (string)input('refund_method');
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 255) { flash('danger', 'A return reason is required.'); $back(); }
        if (!in_array($method, ['cash', 'card', 'account', 'exchange'], true)) { flash('danger', 'Choose a refund method.'); $back(); }
        if (in_array($method, ['account'], true) && !$s['customer_id']) { flash('danger', 'Refund to account needs a customer on the sale.'); $back(); }
        $approver = null; $age = (int)floor((time() - strtotime($s['sale_date'])) / 86400);
        if (!Auth::can('pos.returns.approve')) {
            $approver = Auth::verifyApprover((string)input('approver', ''), (string)($_POST['approver_pass'] ?? ''), 'pos.returns.approve');
            if (!$approver) { flash('danger', 'Manager approval is required: enter valid manager credentials.'); $back(); }
        } else $approver = Auth::id();
        if ($age > (int)setting('return_days', 14) && !Auth::can('pos.returns.approve') && !$approver) { flash('danger', 'Outside the return window.'); $back(); }
        $qtys = (array)($_POST['qty'] ?? []); $conds = (array)($_POST['cond'] ?? []);
        try {
            $rid = DB::tx(function () use ($s, $sid, $qtys, $conds, $reason, $method, $approver) {
                DB::one('SELECT id FROM sales WHERE id=? FOR UPDATE', [$sid]); $rows = []; $total = 0.0;
                foreach (DB::all('SELECT * FROM sale_items WHERE sale_id=? ORDER BY id', [$sid]) as $it) {
                    $q = (string)($qtys[$it['id']] ?? '0'); if (!preg_match('/^\d+(\.\d{1,3})?$/', $q) || (float)$q == 0) continue; $q = round((float)$q, 3); $rem = round($it['qty'] - $it['returned_qty'], 3);
                    if ($it['unit_label'] === null && floor($q) != $q) throw new RuntimeException("Return quantity for {$it['name']} must be a whole number.");
                    if ($q > $rem + 0.0005) throw new RuntimeException("Return quantity for {$it['name']} exceeds the returnable quantity (" . fq($rem) . ').');
                    $refunded = (float)DB::val('SELECT COALESCE(SUM(amount),0) FROM sales_return_items WHERE sale_item_id=?', [$it['id']]);
                    $amt = abs($q - $rem) < 0.0005 ? Pos::r2($it['line_total'] - $refunded) : Pos::r2($it['line_total'] / $it['qty'] * $q);
                    $rows[] = [$it, $q, max(0, $amt), ($conds[$it['id']] ?? 'good') === 'damaged' ? 'damaged' : 'good']; $total += max(0, $amt);
                }
                if (!$rows) throw new RuntimeException('Select at least one item and quantity to return.');
                $wh = (int)DB::val('SELECT warehouse_id FROM pos_sessions WHERE id=?', [$s['session_id']]) ?: (int)Stock::defaultWarehouse((int)$s['branch_id']); $open = Pos::openSession();
                $rid = DB::insert('sales_returns', ['return_no' => next_number('return', 'RET-', 6), 'sale_id' => $sid, 'branch_id' => $s['branch_id'], 'user_id' => Auth::id(), 'approved_by' => $approver, 'return_date' => date('Y-m-d H:i:s'), 'total_refund' => Pos::r2($total), 'refund_method' => $method, 'reason' => $reason, 'session_id' => $open['id'] ?? null]);
                foreach ($rows as [$it, $q, $amt, $cond]) {
                    DB::insert('sales_return_items', ['return_id' => $rid, 'sale_item_id' => $it['id'], 'product_id' => $it['product_id'], 'qty' => $q, 'amount' => $amt, 'item_condition' => $cond]);
                    DB::q('UPDATE sale_items SET returned_qty = returned_qty + ? WHERE id=?', [$q, $it['id']]);
                    if ($cond === 'good') Stock::change($wh, (int)$it['product_id'], $q, 'sale_return', 'return', $rid, 'Return', true);
                    else { DB::q('INSERT IGNORE INTO stock (warehouse_id, product_id, qty) VALUES (?,?,0)', [$wh, $it['product_id']]); DB::q('UPDATE stock SET damaged_qty = damaged_qty + ? WHERE warehouse_id=? AND product_id=?', [$q, $wh, $it['product_id']]); DB::insert('stock_movements', ['product_id' => $it['product_id'], 'warehouse_id' => $wh, 'qty_change' => 0, 'type' => 'return_damaged', 'ref_type' => 'return', 'ref_id' => $rid, 'note' => "Damaged return x$q", 'user_id' => Auth::id()]); }
                }
                $total = Pos::r2($total);
                if ($method === 'cash' || $method === 'card') fin_txn('refund', 'out', $method, $total, (int)$s['branch_id'], $open['id'] ?? null, 'return', $rid, 'Refund');
                if ($method === 'account') DB::insert('customer_payments', ['customer_id' => $s['customer_id'], 'amount' => $total, 'method' => 'return_credit', 'reference' => 'RET#' . $rid, 'note' => 'Return credit', 'user_id' => Auth::id(), 'branch_id' => $s['branch_id']]);
                if ($s['customer_id']) { $per = (float)setting('loyalty_per_amount', 0); if ($per > 0) { $pts = (int)floor($total / $per); $cur = (int)DB::val('SELECT loyalty_points FROM customers WHERE id=?', [$s['customer_id']]); $d = min($pts, $cur); if ($d > 0) { DB::q('UPDATE customers SET loyalty_points = loyalty_points - ? WHERE id=?', [$d, $s['customer_id']]); DB::insert('loyalty_transactions', ['customer_id' => $s['customer_id'], 'points' => -$d, 'reason' => 'Return', 'ref' => 'return#' . $rid, 'user_id' => Auth::id()]); } } }
                return $rid;
            });
        } catch (RuntimeException $e) { flash('danger', $e->getMessage()); $back(); }
        audit('sales_return', 'sales_returns', $rid, ['sale' => $sid, 'method' => $method, 'approver' => $approver]);
        $r = DB::one('SELECT return_no, total_refund FROM sales_returns WHERE id=?', [$rid]);
        flash('success', "Return {$r['return_no']} recorded. " . ($method === 'exchange' ? 'Credit note value ' . money($r['total_refund']) . ' - use payment method "Exchange credit" with this number on the new sale.' : 'Refund ' . money($r['total_refund']) . " via $method."));
        redirect('pos/sale', ['id' => $sid]);
    }
}
