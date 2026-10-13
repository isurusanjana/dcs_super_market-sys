<?php
class Pos {
    static function r2($v): float { return round((float)$v + 0.0000001, 2); }
    static function openSession(?int $uid = null): ?array { return DB::one("SELECT s.*, c.name AS counter_name FROM pos_sessions s JOIN counters c ON c.id=s.counter_id WHERE s.user_id=? AND s.status IN ('open','suspended') ORDER BY s.id DESC LIMIT 1", [$uid ?? Auth::id()]); }
    static function taxRate(array $p, int $branchId): float {
        if ($p['tax_rate'] !== null) return (float)$p['tax_rate'];
        $b = DB::val('SELECT tax_rate FROM branches WHERE id=?', [$branchId]); return $b !== null ? (float)$b : (float)setting('tax_rate', 0);
    }
    static function unitPrice(array $p, ?array $cust): float {
        if ($cust && $cust['group_id']) {
            $pl = DB::val('SELECT i.price FROM price_list_items i JOIN price_lists l ON l.id=i.price_list_id WHERE i.product_id=? AND l.is_active=1 AND l.customer_group_id=? AND (l.start_date IS NULL OR l.start_date<=CURDATE()) AND (l.end_date IS NULL OR l.end_date>=CURDATE()) ORDER BY i.id DESC LIMIT 1', [$p['id'], $cust['group_id']]);
            if ($pl !== null) return (float)$pl;
            $t = $cust['price_type'] ?? 'retail';
            if ($t === 'wholesale' && $p['wholesale_price'] !== null) return (float)$p['wholesale_price'];
            if ($t === 'member' && $p['member_price'] !== null) return (float)$p['member_price'];
        }
        return (float)$p['selling_price'];
    }
    private static function ancestors(?int $cat): array { $o = []; for ($i = 0; $cat && $i < 6; $i++) { $o[] = $cat; $cat = (int)DB::val('SELECT parent_id FROM categories WHERE id=?', [$cat]); } return $o; }

    /** Server-side pricing engine. $ctx: branch_id, customer(row|null), perms flags. Returns calculation + errors. */
    static function compute(array $in, array $ctx): array {
        $errors = []; $lines = []; $cust = $ctx['customer'];
        $promos = DB::all('SELECT * FROM promotions WHERE is_active=1 AND (start_date IS NULL OR start_date<=CURDATE()) AND (end_date IS NULL OR end_date>=CURDATE()) AND (customer_group_id IS NULL OR customer_group_id <=> ?)', [$cust['group_id'] ?? null]);
        $coupon = strtoupper(trim((string)($in['coupon'] ?? ''))); $couponUsed = false; $maxPct = (float)setting('max_cashier_discount', 10); $needsApproval = false; $seen = [];
        foreach ((array)($in['items'] ?? []) as $it) {
            $pid = (int)($it['product_id'] ?? 0);
            $p = DB::one('SELECT * FROM products WHERE id=? AND is_archived=0 AND is_active=1', [$pid]);
            if (!$p) { $errors[] = 'A product in the cart is unavailable.'; continue; }
            $qty = parse_qty($it['qty'] ?? 0, $p['sold_by'] === 'weight', 100000);
            if ($qty === null) { $errors[] = "Invalid quantity for {$p['name']}" . ($p['sold_by'] === 'weight' ? ' (enter the weight, up to 3 decimals).' : ' (whole numbers only).'); continue; }
            if (isset($seen[$pid])) { $errors[] = "Duplicate line for {$p['name']}."; continue; } $seen[$pid] = 1;
            $unit = self::unitPrice($p, $cust); $over = $it['price'] ?? null;
            if ($over !== null && $over !== '' && abs((float)$over - $unit) > 0.004) {
                if (!$ctx['can_override']) $errors[] = "You are not allowed to change the price of {$p['name']}."; elseif (!is_numeric($over) || $over < 0) $errors[] = 'Invalid price.'; else { $unit = self::r2($over); }
            }
            $gross = self::r2($unit * $qty); $best = 0.0; $bestName = null;
            $cats = self::ancestors($p['category_id'] ? (int)$p['category_id'] : null);
            foreach ($promos as $pr) {
                if ($pr['coupon_code'] !== null && $pr['coupon_code'] !== '' && strtoupper($pr['coupon_code']) !== $coupon) continue;
                if ($pr['min_qty'] > $qty) continue;
                if ($pr['scope'] === 'product' && (int)$pr['product_id'] !== $pid) continue; if ($pr['scope'] === 'category' && !in_array((int)$pr['category_id'], $cats, true)) continue;
                if ($pr['start_time'] && $pr['end_time']) { $now = date('H:i:s'); if ($now < $pr['start_time'] || $now > $pr['end_time']) continue; }   // happy hour window
                if ($pr['type'] === 'percent') $d = $gross * $pr['value'] / 100;
                elseif ($pr['type'] === 'fixed') $d = min($gross, $pr['value'] * $qty);
                elseif ($pr['type'] === 'bogo') { $set = (int)$pr['buy_qty'] + (int)$pr['get_qty']; $d = $set > 0 && $p['sold_by'] !== 'weight' ? floor($qty / $set) * (int)$pr['get_qty'] * $unit : 0; }
                elseif ($pr['type'] === 'multibuy') { $n = max(2, (int)$pr['min_qty']); $d = $p['sold_by'] !== 'weight' ? max(0, floor($qty / $n) * ($unit * $n - (float)$pr['bundle_price'])) : 0; }
                else $d = 0;
                if ($d > $best) { $best = $d; $bestName = $pr['name']; if ($pr['coupon_code']) $couponUsed = true; }
            }
            if ($cust && (float)$cust['discount_percent'] > 0 && $gross * $cust['discount_percent'] / 100 > $best) { $best = $gross * $cust['discount_percent'] / 100; $bestName = $cust['group_name'] . ' discount'; }
            $best = min($gross, self::r2($best)); $manual = 0.0; $mt = $it['disc_type'] ?? ''; $mv = $it['disc_value'] ?? 0;
            if ($mt !== '' && is_numeric($mv) && $mv > 0) {
                if (!$ctx['can_discount']) $errors[] = 'You are not allowed to apply manual discounts.';
                else { $manual = $mt === 'percent' ? $gross * min(100, $mv) / 100 : min($gross, $mv); $manual = min($gross - $best, self::r2($manual)); if ($gross > 0 && $manual / $gross * 100 > $maxPct + 0.0001) $needsApproval = true; }
            }
            $lines[] = ['product_id' => $pid, 'sku' => $p['sku'], 'name' => $p['name'], 'qty' => $qty, 'unit_price' => $unit, 'cost_price' => (float)$p['cost_price'], 'gross' => $gross, 'promo_discount' => $best, 'promo_name' => $bestName, 'manual_discount' => $manual,
                'line_disc' => self::r2($best + $manual), 'unit_label' => $p['sold_by'] === 'weight' ? 'kg' : null, 'age_restricted' => (bool)$p['age_restricted'], 'tax_rate' => self::taxRate($p, (int)$ctx['branch_id'])];
        }
        if ($coupon !== '' && !$couponUsed) $errors[] = 'Coupon code is invalid or does not apply to these items.';
        if (!$lines && !$errors) $errors[] = 'The cart is empty.';
        $subtotal = array_sum(array_column($lines, 'gross')); $net = array_sum(array_map(fn($l) => $l['gross'] - $l['line_disc'], $lines)); $cartDisc = 0.0;
        $ct = $in['cart_disc']['type'] ?? ''; $cv = $in['cart_disc']['value'] ?? 0;
        if ($ct !== '' && is_numeric($cv) && $cv > 0) {
            if (!$ctx['can_discount']) $errors[] = 'You are not allowed to apply manual discounts.';
            else { $cartDisc = $ct === 'percent' ? $net * min(100, $cv) / 100 : min($net, $cv); $cartDisc = self::r2(min($net, $cartDisc)); if ($net > 0 && $cartDisc / $net * 100 > $maxPct + 0.0001) $needsApproval = true; }
        }
        $incl = setting('prices_include_tax', '0') === '1'; $left = $cartDisc; $n = count($lines); $tax = 0.0; $disc = 0.0; $total = 0.0;
        foreach ($lines as $i => &$l) {
            $ln = $l['gross'] - $l['line_disc']; $alloc = ($i === $n - 1) ? $left : self::r2($net > 0 ? $cartDisc * $ln / $net : 0); $alloc = min($alloc, $left); $left = self::r2($left - $alloc);
            $l['discount'] = self::r2($l['line_disc'] + $alloc); $base = self::r2($l['gross'] - $l['discount']);
            if ($incl) { $l['line_total'] = $base; $l['tax_amount'] = self::r2($base * $l['tax_rate'] / (100 + $l['tax_rate'])); }   // prices already include tax
            else { $l['tax_amount'] = self::r2($base * $l['tax_rate'] / 100); $l['line_total'] = self::r2($base + $l['tax_amount']); }
            $tax += $l['tax_amount']; $disc += $l['discount']; $total += $l['line_total'];
        } unset($l);
        return ['lines' => $lines, 'subtotal' => self::r2($subtotal), 'discount_total' => self::r2($disc), 'tax_total' => self::r2($tax), 'total' => self::r2($total), 'needs_approval' => $needsApproval, 'errors' => array_values(array_unique($errors))];
    }
    static function customer(?int $id): ?array {
        if (!$id) return null;
        return DB::one('SELECT c.*, g.name AS group_name, g.price_type, COALESCE(g.discount_percent,0) AS discount_percent FROM customers c LEFT JOIN customer_groups g ON g.id=c.group_id WHERE c.id=? AND c.is_active=1', [$id]);
    }
    static function expectedCash(array $s): float {
        $in = (float)DB::val("SELECT COALESCE(SUM(amount),0) FROM fin_transactions WHERE session_id=? AND method='cash' AND direction='in'", [$s['id']]);
        $out = (float)DB::val("SELECT COALESCE(SUM(amount),0) FROM fin_transactions WHERE session_id=? AND method='cash' AND direction='out'", [$s['id']]);
        return self::r2($s['opening_cash'] + $in - $out);
    }
    static function approvalValid(): bool { $a = $_SESSION['pos_approval'] ?? null; return $a && $a['uid'] === Auth::id() && $a['exp'] > time(); }
}
