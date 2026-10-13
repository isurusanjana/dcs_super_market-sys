<?php
// Per-entity business validation and side effects. Method naming: validate_<slug>, before_<slug>, after_<slug>.
class EntityHooks {
    /** Non-column form values (e.g. opening stock) passed from CrudController to the after_ hooks. */
    public static array $extra = [];
    static function call(string $phase, string $slug, ...$args) { $m = $phase . '_' . $slug; return method_exists(__CLASS__, $m) ? self::$m(...$args) : ($phase === 'validate' ? [] : null); }
    static function before(string $slug, array &$d, ?int $id): void { $m = 'before_' . $slug; if (method_exists(__CLASS__, $m)) self::$m($d, $id); }

    static function validate_promotions(array $d, ?int $id): array {
        $e = [];
        if (($d['scope'] ?? '') === 'category' && empty($d['category_id'])) $e['category_id'] = 'Choose a category for a category promotion.';
        if (($d['scope'] ?? '') === 'product' && empty($d['product_id'])) $e['product_id'] = 'Choose a product for a product promotion.';
        $t = $d['type'] ?? '';
        if ($t === 'percent' && ((float)($d['value'] ?? 0) <= 0 || (float)$d['value'] > 100)) $e['value'] = 'Enter a percentage between 0 and 100.';
        if ($t === 'fixed' && (float)($d['value'] ?? 0) <= 0) $e['value'] = 'Enter the amount off per unit.';
        if ($t === 'bogo' && (empty($d['buy_qty']) || empty($d['get_qty']))) $e['buy_qty'] = 'Enter both Buy X and Get Y free.';
        if ($t === 'multibuy' && ((int)($d['min_qty'] ?? 1) < 2 || (float)($d['bundle_price'] ?? 0) <= 0)) $e['bundle_price'] = 'Multi-buy needs a minimum quantity of 2 or more and a bundle price.';
        if ((!empty($d['start_time'])) xor (!empty($d['end_time']))) $e['end_time'] = 'Enter both the daily start and end time, or neither.';
        return $e + self::dateRange($d);
    }
    static function validate_price_lists(array $d, ?int $id): array { return self::dateRange($d); }
    private static function dateRange(array $d): array {
        return (!empty($d['start_date']) && !empty($d['end_date']) && $d['end_date'] < $d['start_date']) ? ['end_date' => 'End date must not be before the start date.'] : [];
    }
    static function after_warehouses(int $id, ?array $old, array $d): void {
        if ($old === null) DB::q('INSERT IGNORE INTO stock (warehouse_id, product_id, qty) SELECT ?, id, 0 FROM products', [$id]);
        if (!empty($d['is_default'])) DB::q('UPDATE warehouses SET is_default=0 WHERE branch_id=? AND id<>?', [$d['branch_id'], $id]);
        elseif (!DB::val('SELECT 1 FROM warehouses WHERE branch_id=? AND is_default=1', [$d['branch_id']])) DB::q('UPDATE warehouses SET is_default=1 WHERE id=?', [$id]);
    }
    static function validate_categories(array $d, ?int $id): array {
        if ($id && !empty($d['parent_id'])) {
            $p = (int)$d['parent_id'];
            for ($i = 0; $p && $i < 20; $i++) { if ($p === $id) return ['parent_id' => 'A category cannot be placed under itself or its own sub-category.']; $p = (int)DB::val('SELECT parent_id FROM categories WHERE id=?', [$p]); }
        }
        return [];
    }
    // ---- products / books / stationery
    static function validate_products(array $d, ?int $id): array {
        $e = [];
        if (isset($d['max_stock']) && $d['max_stock'] !== null && (float)$d['max_stock'] > 0 && (float)$d['max_stock'] < (float)($d['reorder_level'] ?? 0)) $e['max_stock'] = 'Maximum stock must be at least the reorder level.';
        if (!empty($d['barcode']) && preg_match('/^\d{13}$/', $d['barcode']) && !Barcode::valid13($d['barcode'])) $e['barcode'] = 'EAN-13 barcode has an invalid check digit.';
        if ($id && !empty($d['parent_id']) && (int)$d['parent_id'] === $id) $e['parent_id'] = 'A product cannot be its own parent.';
        $oq = $d['opening_qty'] ?? '';
        if ($oq !== '' && $oq !== null) {
            if (($d['sold_by'] ?? 'unit') !== 'weight' && floor((float)$oq) != (float)$oq) $e['opening_qty'] = 'Items sold by unit need a whole-number quantity.';
            elseif ((float)$oq > 0 && !empty($d['track_expiry']) && empty($d['opening_expiry']) && empty($d['shelf_life_days'])) $e['opening_expiry'] = 'Enter the expiry date of the opening stock (or set a shelf life in days).';
        }
        if (!empty($d['plu']) && ($d['sold_by'] ?? 'unit') !== 'weight') $e['plu'] = 'A PLU code is only used for items sold by weight.';
        if (!empty($d['shelf_life_days']) && empty($d['track_expiry'])) $e['shelf_life_days'] = 'Tick "Track batches and expiry dates" to use a shelf life.';
        return $e;
    }
    static function validate_books(array $d, ?int $id): array { return self::validate_products($d, $id); }
    static function validate_groceries(array $d, ?int $id): array { return self::validate_products($d, $id); }
    static function validate_fresh(array $d, ?int $id): array { return self::validate_products($d, $id); }
    static function before_groceries(array &$d, ?int $id): void { self::before_products($d, $id); }
    static function before_fresh(array &$d, ?int $id): void { self::before_products($d, $id); }
    static function after_groceries(int $id, ?array $old, array $d): void { self::after_products($id, $old, $d); }
    static function after_fresh(int $id, ?array $old, array $d): void { self::after_products($id, $old, $d); }
    static function validate_stationery(array $d, ?int $id): array { return self::validate_products($d, $id); }
    static function before_products(array &$d, ?int $id): void {
        $type = $d['product_type'] ?? 'other';
        if (empty($d['sku'])) $d['sku'] = next_number('sku', ['book' => 'BK-', 'stationery' => 'ST-', 'grocery' => 'GR-', 'fresh' => 'FR-', 'household' => 'HH-'][$type] ?? 'PR-', 5);
        if (!empty($d['isbn'])) { $d['isbn'] = str_replace(['-', ' '], '', strtoupper($d['isbn'])); if (empty($d['barcode']) && strlen($d['isbn']) === 13) $d['barcode'] = $d['isbn']; }
        if (empty($d['barcode'])) { do { $d['barcode'] = Barcode::generate(); } while (DB::val('SELECT 1 FROM products WHERE barcode=?', [$d['barcode']])); }
    }
    static function before_books(array &$d, ?int $id): void { self::before_products($d, $id); }
    static function before_stationery(array &$d, ?int $id): void { self::before_products($d, $id); }
    /** New product: create zero-stock rows in every warehouse (so it shows in stock lists / POS) and post any opening stock. */
    static function openingStock(int $pid, array $d): void {
        DB::q('INSERT IGNORE INTO stock (warehouse_id, product_id, qty) SELECT id, ?, 0 FROM warehouses', [$pid]);
        $x = self::$extra; $q = isset($x['opening_qty']) && $x['opening_qty'] !== '' ? round((float)$x['opening_qty'], 3) : 0.0; if ($q <= 0) return;
        $wh = !empty($x['opening_warehouse']) ? (int)$x['opening_warehouse'] : (int)Stock::defaultWarehouse(Auth::user()['branch_id'] ?? null);
        if (!$wh) $wh = (int)DB::val('SELECT id FROM warehouses WHERE is_active=1 ORDER BY is_default DESC, id LIMIT 1');
        $batch = null;
        if (!empty($d['track_expiry'])) { $ex = $x['opening_expiry'] ?? ''; if ($ex === '' && !empty($d['shelf_life_days'])) $ex = date('Y-m-d', strtotime('+' . (int)$d['shelf_life_days'] . ' day')); $batch = ['batch_no' => (string)($x['opening_batch'] ?? ''), 'expiry_date' => $ex ?: null]; }
        Stock::change($wh, $pid, $q, 'opening', 'product', $pid, 'Opening stock', false, $batch);
        audit('opening_stock', 'products', $pid, ['qty' => $q, 'warehouse' => $wh]);
    }
    static function after_products(int $id, ?array $old, array $d): void {
        if ($old === null) self::openingStock($id, $d);
        foreach (['cost_price', 'selling_price', 'wholesale_price', 'member_price'] as $f) {
            $o = $old[$f] ?? null; $n = $d[$f] ?? null;
            if ($old === null) { if ($n === null) continue; } elseif ((string)(float)$o === (string)(float)$n && ($o === null) === ($n === null)) continue;
            DB::insert('product_price_history', ['product_id' => $id, 'field_name' => $f, 'old_value' => $o, 'new_value' => $n, 'user_id' => Auth::id()]);
            if ($old !== null) audit('price_change', 'products', $id, [$f => [$o, $n]]);
        }
    }
    static function after_books(int $id, ?array $old, array $d): void { self::after_products($id, $old, $d); }
    static function after_stationery(int $id, ?array $old, array $d): void { self::after_products($id, $old, $d); }
}
