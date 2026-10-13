<?php
class Seeder {
    static function permissions(): void {
        $defs = require APP_PATH . '/permissions.php';
        foreach ($defs as $mod => $m) foreach ($m['features'] as $feat => [$flabel, $actions]) foreach ($actions as $act => $alabel) {
            DB::q('INSERT INTO permissions (code, module, feature, action, label) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE label=VALUES(label)',
                ["$mod.$feat.$act", $mod, $feat, $act, "$flabel: $alabel"]);
        }
    }
    /** Role definitions: name => [description, is_super, include patterns, exclude patterns] */
    static function roleDefs(): array {
        return [
            'Administrator' => ['Full system access', 1, [], []],
            'Manager' => ['Store manager: approvals, operations and reporting', 0, ['*'], ['admin.users.*', 'admin.roles.*', 'admin.settings.edit']],
            'Cashier' => ['POS cashier', 0, ['pos.session.open', 'pos.sale.create', 'pos.discount.apply', 'pos.returns.create', 'pos.history.view', 'pos.history.reprint', 'pos.giftcard.view', 'pos.giftcard.sell', 'admin.customers.view', 'admin.customers.create', 'inventory.stock.view'], []],
            'Inventory Clerk' => ['Stock and purchasing staff', 0, ['inventory.*', 'admin.suppliers.view'], ['inventory.*.approve', 'inventory.purchasing.invoice']],
            'Accountant' => ['Finance and reports', 0, ['admin.finance.*', 'admin.reports.*', 'admin.suppliers.view', 'admin.suppliers.payment', 'admin.customers.view', 'admin.customers.payment', 'inventory.purchasing.view', 'inventory.purchasing.invoice', 'pos.giftcard.view'], []],
        ];
    }
    private static function matchCodes(array $all, array $include, array $exclude): array {
        $ids = [];
        foreach ($all as $code => $id) {
            $ok = false; foreach ($include as $p) if (fnmatch($p, $code)) { $ok = true; break; }
            if ($ok) foreach ($exclude as $p) if (fnmatch($p, $code)) { $ok = false; break; }
            if ($ok) $ids[] = $id;
        }
        return $ids;
    }
    static function roles(): void {
        $all = array_column(DB::all('SELECT id, code FROM permissions'), 'id', 'code');
        foreach (self::roleDefs() as $name => [$desc, $super, $inc, $exc]) {
            if (DB::val('SELECT 1 FROM roles WHERE name=?', [$name])) continue;
            $rid = DB::insert('roles', ['name' => $name, 'description' => $desc, 'is_super' => $super]);
            foreach (self::matchCodes($all, $inc, $exc) as $pid) DB::insert('role_permissions', ['role_id' => $rid, 'permission_id' => $pid]);
        }
    }
    /** On upgrade: give the standard roles the NEW supermarket permissions only (never removes anything). */
    static function upgradeRoles(): void {
        $all = array_column(DB::all("SELECT id, code FROM permissions WHERE code LIKE 'pos.cash.%' OR code LIKE 'pos.giftcard.%' OR code LIKE 'inventory.expiry.%'"), 'id', 'code');
        foreach (self::roleDefs() as $name => [$desc, $super, $inc, $exc]) {
            $rid = DB::val('SELECT id FROM roles WHERE name=?', [$name]); if (!$rid || $super) continue;
            foreach (self::matchCodes($all, $inc, $exc) as $pid) DB::q('INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?,?)', [$rid, $pid]);
        }
    }
    static function defaults(string $businessName): void {
        foreach (['business_name' => $businessName, 'business_address' => '', 'business_phone' => '', 'currency' => 'Rs.', 'tax_rate' => '0', 'return_days' => '14',
            'max_cashier_discount' => '10', 'loyalty_per_amount' => '100', 'allow_negative_stock' => '0', 'receipt_footer' => 'Thank you for shopping with us!'] as $k => $v)
            DB::q('INSERT IGNORE INTO settings (skey, svalue) VALUES (?,?)', [$k, $v]);
        if (!DB::val('SELECT COUNT(*) FROM branches')) {
            $b = DB::insert('branches', ['code' => 'MAIN', 'name' => 'Main Branch']);
            DB::insert('warehouses', ['branch_id' => $b, 'name' => 'Main Warehouse', 'is_default' => 1]);
            DB::insert('counters', ['branch_id' => $b, 'name' => 'Counter 1']);
        }
        self::supermarketDefaults();
        foreach ([['Retail', 'retail', 0], ['Wholesale', 'wholesale', 0], ['Member', 'member', 0]] as [$n, $t, $d]) DB::q('INSERT IGNORE INTO customer_groups (name, price_type, discount_percent) VALUES (?,?,?)', [$n, $t, $d]);
    }
    /** Settings, units and department/category tree used by the supermarket edition (idempotent). */
    static function supermarketDefaults(): void {
        foreach (['receipt_language' => 'si', 'prices_include_tax' => '0', 'expiry_alert_days' => '7', 'loyalty_redeem_value' => '1', 'scale_label_prefix' => '2'] as $k => $v) DB::q('INSERT IGNORE INTO settings (skey, svalue) VALUES (?,?)', [$k, $v]);
        foreach (['Piece' => 'pc', 'Pack' => 'pk', 'Box' => 'box', 'Ream' => 'rm', 'Set' => 'set', 'Dozen' => 'dz', 'Kilogram' => 'kg', 'Gram' => 'g', 'Litre' => 'l', 'Millilitre' => 'ml', 'Bottle' => 'btl', 'Carton' => 'ctn', 'Bag' => 'bag'] as $n => $sh) DB::q('INSERT IGNORE INTO units (name, short_name) VALUES (?,?)', [$n, $sh]);
        $tree = [
            'Fresh Produce' => ['Fruits', 'Vegetables', 'Herbs & Leafy Greens'],
            'Meat & Seafood' => ['Chicken', 'Beef & Mutton', 'Fish & Seafood', 'Eggs'],
            'Dairy & Chilled' => ['Milk', 'Yoghurt & Curd', 'Cheese & Butter'],
            'Bakery & Deli' => ['Bread', 'Cakes & Pastries', 'Ready Meals'],
            'Frozen Foods' => ['Frozen Meat & Fish', 'Frozen Vegetables', 'Ice Cream'],
            'Grocery Staples' => ['Rice & Grains', 'Flour & Baking', 'Pulses & Lentils', 'Spices & Condiments', 'Oil & Ghee', 'Sugar & Salt', 'Canned & Packaged', 'Noodles & Pasta'],
            'Beverages' => ['Water', 'Soft Drinks', 'Juices', 'Tea & Coffee', 'Liquor & Beer'],
            'Snacks & Confectionery' => ['Biscuits', 'Chocolates & Sweets', 'Crisps & Nuts'],
            'Household' => ['Cleaning', 'Laundry', 'Paper & Disposables', 'Kitchenware'],
            'Health & Beauty' => ['Personal Care', 'Baby Care', 'Pharmacy (OTC)'],
            'Pet Care' => [],
            'Books' => ['Fiction', 'Non-Fiction', 'Educational' => ['Mathematics', 'Science', 'Languages'], "Children's Books"],
            'Stationery' => ['Writing' => ['Pens', 'Pencils', 'Markers'], 'Paper Products', 'School Supplies', 'Office Supplies', 'Art Supplies'],
        ];
        $mk = function ($name, $parent) {
            $id = DB::val('SELECT id FROM categories WHERE name=? AND parent_id <=> ?', [$name, $parent]);
            return $id ? (int)$id : DB::insert('categories', ['name' => $name, 'parent_id' => $parent]);
        };
        foreach ($tree as $top => $subs) { $t = $mk($top, null); foreach ($subs as $k => $v) { if (is_array($v)) { $sid = $mk($k, $t); foreach ($v as $l) $mk($l, $sid); } else $mk($v, $t); } }
    }
    static function admin(string $username, string $fullName, string $password): int {
        $uid = DB::insert('users', ['username' => $username, 'full_name' => $fullName, 'password_hash' => Auth::hashPassword($password), 'branch_id' => DB::val('SELECT id FROM branches ORDER BY id LIMIT 1')]);
        DB::insert('user_roles', ['user_id' => $uid, 'role_id' => DB::val("SELECT id FROM roles WHERE name='Administrator'")]);
        return $uid;
    }
    static function demo(): void {
        $cat = fn($n) => (int)DB::val('SELECT id FROM categories WHERE name=? LIMIT 1', [$n]);
        $unit = fn($n) => (int)DB::val('SELECT id FROM units WHERE name=?', [$n]);
        $wh = (int)DB::val('SELECT id FROM warehouses ORDER BY id LIMIT 1'); $branch = (int)DB::val('SELECT id FROM branches ORDER BY id LIMIT 1');
        foreach (['Keells', 'Anchor', 'Elephant House', 'Munchee', 'Maliban', 'Sunlight', 'Nestle', 'Atlas'] as $b) DB::q('INSERT IGNORE INTO brands (name) VALUES (?)', [$b]);
        $brand = fn($n) => $n ? (int)DB::val('SELECT id FROM brands WHERE name=?', [$n]) : null;
        $seq = 479000000000; $n = 0;
        // [type, name, category, brand, unit, cost, price, sold_by, plu, expiry-tracked, shelf_life_days, age18, quick, shelf, stock, reorder, expiry-days-first-batch]
        $items = [
            ['fresh', 'Bananas (Embul) loose', 'Fruits', null, 'Kilogram', 150, 220, 'weight', '100001', 1, 5, 0, 1, 'F1', 40, 10, 3],
            ['fresh', 'Tomato loose', 'Vegetables', null, 'Kilogram', 180, 260, 'weight', '100002', 1, 5, 0, 1, 'F2', 35, 10, 4],
            ['fresh', 'Carrots loose', 'Vegetables', null, 'Kilogram', 200, 300, 'weight', '100003', 1, 7, 0, 1, 'F2', 30, 8, 6],
            ['fresh', 'Chicken (skinless) loose', 'Chicken', null, 'Kilogram', 780, 1050, 'weight', '100004', 1, 3, 0, 0, 'M1', 25, 8, 2],
            ['fresh', 'Red Rice loose', 'Rice & Grains', null, 'Kilogram', 190, 240, 'weight', '100005', 0, null, 0, 1, 'G1', 120, 30, null],
            ['fresh', 'Fresh Bread Loaf', 'Bread', null, 'Piece', 90, 140, 'unit', null, 1, 2, 0, 1, 'B1', 30, 10, 1],
            ['grocery', 'Samba Rice 5kg', 'Rice & Grains', 'Keells', 'Bag', 1050, 1290, 'unit', null, 0, null, 0, 0, 'G1', 60, 15, null],
            ['grocery', 'Anchor Milk Powder 400g', 'Milk', 'Anchor', 'Pack', 690, 820, 'unit', null, 1, 365, 0, 0, 'D2', 48, 12, 200],
            ['grocery', 'Fresh Milk 1L', 'Milk', 'Anchor', 'Bottle', 210, 260, 'unit', null, 1, 7, 0, 0, 'D1', 40, 12, 5],
            ['grocery', 'Elephant House Ginger Beer 1L', 'Soft Drinks', 'Elephant House', 'Bottle', 190, 250, 'unit', null, 1, 180, 0, 0, 'BV1', 72, 24, 120],
            ['grocery', 'Lion Lager 625ml', 'Liquor & Beer', null, 'Bottle', 400, 520, 'unit', null, 1, 270, 1, 0, 'BV9', 96, 24, 150],
            ['grocery', 'Munchee Cream Cracker 190g', 'Biscuits', 'Munchee', 'Pack', 120, 165, 'unit', null, 1, 240, 0, 0, 'S1', 80, 20, 150],
            ['grocery', 'Maliban Marie Biscuit 200g', 'Biscuits', 'Maliban', 'Pack', 110, 150, 'unit', null, 1, 240, 0, 0, 'S1', 80, 20, 150],
            ['grocery', 'Sunflower Oil 1L', 'Oil & Ghee', null, 'Bottle', 780, 940, 'unit', null, 0, null, 0, 0, 'G3', 50, 12, null],
            ['grocery', 'White Sugar 1kg', 'Sugar & Salt', null, 'Pack', 210, 260, 'unit', null, 0, null, 0, 0, 'G2', 90, 25, null],
            ['grocery', 'Instant Noodles 80g', 'Noodles & Pasta', 'Nestle', 'Pack', 75, 110, 'unit', null, 1, 270, 0, 0, 'G4', 150, 40, 200],
            ['grocery', 'Sunlight Dishwash Liquid 500ml', 'Cleaning', 'Sunlight', 'Bottle', 260, 340, 'unit', null, 0, null, 0, 0, 'H1', 40, 10, null],
            ['household', 'Kitchen Tissue Roll (2 pack)', 'Paper & Disposables', null, 'Pack', 190, 260, 'unit', null, 0, null, 0, 0, 'H2', 45, 12, null],
            ['grocery', 'Carrier Bag (large)', 'Paper & Disposables', null, 'Piece', 4, 10, 'unit', null, 0, null, 0, 1, 'POS', 500, 100, null],
            ['stationery', 'Atlas Exercise Book 80pg', 'School Supplies', 'Atlas', 'Piece', 95, 130, 'unit', null, 0, null, 0, 0, 'ST1', 100, 30, null],
            ['stationery', 'Blue Ball Pen', 'Pens', 'Atlas', 'Piece', 12, 20, 'unit', null, 0, null, 0, 0, 'ST1', 150, 40, null],
            ['book', 'Gamperaliya (Martin Wickramasinghe)', 'Fiction', null, 'Piece', 950, 1100, 'unit', null, 0, null, 0, 0, 'BK1', 20, 5, null],
        ];
        $ids = [];
        foreach ($items as [$type, $name, $c, $b, $u, $cost, $price, $sold, $plu, $exp, $life, $age, $quick, $loc, $stock, $reorder, $days]) {
            $n++; $barcode = $plu ? Barcode::generate() : Barcode::ean13Check((string)($seq + $n * 13));
            $pre = ['fresh' => 'FR-', 'grocery' => 'GR-', 'household' => 'HH-', 'stationery' => 'ST-', 'book' => 'BK-'][$type];
            $id = DB::insert('products', ['product_type' => $type, 'sku' => next_number('sku', $pre, 5), 'barcode' => $barcode, 'isbn' => $type === 'book' ? $barcode : null, 'name' => $name, 'category_id' => $cat($c), 'brand_id' => $brand($b), 'unit_id' => $unit($u),
                'cost_price' => $cost, 'selling_price' => $price, 'wholesale_price' => round($price * 0.93, 2), 'member_price' => round($price * 0.97, 2), 'sold_by' => $sold, 'plu' => $plu, 'track_expiry' => $exp, 'shelf_life_days' => $life, 'age_restricted' => $age, 'is_quick' => $quick,
                'shelf_location' => $loc, 'reorder_level' => $reorder, 'min_stock' => round($reorder / 3), 'max_stock' => $reorder * 8]);
            $ids[$name] = $id;
            if ($exp && $days) { Stock::change($wh, $id, round($stock * 0.4, 3), 'opening', null, null, 'Opening stock', false, ['batch_no' => 'B' . date('md') . 'A', 'expiry_date' => date('Y-m-d', strtotime("+" . max(1, (int)floor($days / 2)) . " day"))]); Stock::change($wh, $id, round($stock * 0.6, 3), 'opening', null, null, 'Opening stock', false, ['batch_no' => 'B' . date('md') . 'B', 'expiry_date' => date('Y-m-d', strtotime("+$days day"))]); }
            else Stock::change($wh, $id, $stock, 'opening', null, null, 'Opening stock');
        }
        DB::insert('product_barcodes', ['product_id' => $ids['Lion Lager 625ml'], 'barcode' => '4790000990024', 'qty_per_scan' => 24, 'label' => 'Carton of 24']);
        DB::insert('product_barcodes', ['product_id' => $ids['Munchee Cream Cracker 190g'], 'barcode' => '4790000990031', 'qty_per_scan' => 12, 'label' => 'Box of 12']);
        $y = date('Y-m-d'); $end = date('Y-m-d', strtotime('+60 day'));
        DB::insert('promotions', ['name' => 'Beer: buy 1 get 1 free (weekend)', 'type' => 'bogo', 'value' => 0, 'buy_qty' => 1, 'get_qty' => 1, 'scope' => 'product', 'product_id' => $ids['Lion Lager 625ml'], 'min_qty' => 1, 'start_date' => $y, 'end_date' => $end]);
        DB::insert('promotions', ['name' => 'Biscuits 3 for 450', 'type' => 'multibuy', 'value' => 0, 'bundle_price' => 450, 'scope' => 'product', 'product_id' => $ids['Munchee Cream Cracker 190g'], 'min_qty' => 3, 'start_date' => $y, 'end_date' => $end]);
        DB::insert('promotions', ['name' => 'Fresh bread evening markdown 30%', 'type' => 'percent', 'value' => 30, 'scope' => 'product', 'product_id' => $ids['Fresh Bread Loaf'], 'min_qty' => 1, 'start_date' => $y, 'end_date' => $end, 'start_time' => '18:00:00', 'end_time' => '21:00:00']);
        DB::insert('promotions', ['name' => 'Dairy week 10% off', 'type' => 'percent', 'value' => 10, 'scope' => 'category', 'category_id' => $cat('Dairy & Chilled'), 'min_qty' => 1, 'start_date' => $y, 'end_date' => $end]);
        DB::insert('suppliers', ['code' => next_number('supplier', 'SUP-', 4), 'name' => 'Island Fresh Produce Suppliers', 'contact_person' => 'Mr. Silva', 'phone' => '0112888777', 'payment_terms' => 'Cash on delivery']);
        DB::insert('suppliers', ['code' => next_number('supplier', 'SUP-', 4), 'name' => 'Lanka Grocery Wholesale', 'contact_person' => 'Ms. Fernando', 'phone' => '0112345678', 'payment_terms' => '30 days']);
        DB::insert('customers', ['code' => next_number('customer', 'CUS-', 5), 'name' => 'Sample Customer', 'phone' => '0771234567', 'card_no' => 'LC000001', 'group_id' => DB::val("SELECT id FROM customer_groups WHERE name='Member'"), 'credit_limit' => 20000, 'loyalty_points' => 120]);
        DB::insert('gift_cards', ['code' => 'GC-DEMO-0001-GIFT', 'initial_amount' => 2000, 'balance' => 2000, 'note' => 'Demo gift card']);
        DB::insert('gift_card_txns', ['gift_card_id' => (int)DB::pdo()->lastInsertId(), 'amount' => 2000, 'type' => 'issue', 'note' => 'Demo']);
        foreach ([['manager', 'Store Manager', 'Manager'], ['cashier', 'Demo Cashier', 'Cashier'], ['clerk', 'Inventory Clerk', 'Inventory Clerk']] as [$u, $nm, $role]) {
            $uid = DB::insert('users', ['username' => $u, 'full_name' => $nm, 'password_hash' => Auth::hashPassword('Demo@1234'), 'branch_id' => $branch]);
            DB::insert('user_roles', ['user_id' => $uid, 'role_id' => DB::val('SELECT id FROM roles WHERE name=?', [$role])]);
        }
    }
}
