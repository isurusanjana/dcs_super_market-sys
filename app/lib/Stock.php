<?php
class Stock {
    private static array $tracked = [];
    static function isTracked(int $pid): bool {
        if (!isset(self::$tracked[$pid])) self::$tracked[$pid] = (bool)DB::val('SELECT track_expiry FROM products WHERE id=?', [$pid]);
        return self::$tracked[$pid];
    }
    /** Change on-hand qty (call inside a DB transaction). Positive = in, negative = out. Throws on negative result unless allowed.
     *  For products with expiry tracking, batches are maintained: stock IN adds to (or creates) a batch; stock OUT is taken first-expiry-first-out
     *  unless a specific batch id is given in $batch['id']. */
    static function change(int $wh, int $pid, float $delta, string $type, ?string $refType = null, ?int $refId = null, ?string $note = null, bool $allowNeg = false, ?array $batch = null): void {
        $delta = round($delta, 3);
        DB::q('INSERT IGNORE INTO stock (warehouse_id, product_id, qty) VALUES (?,?,0)', [$wh, $pid]);
        $row = DB::one('SELECT qty FROM stock WHERE warehouse_id=? AND product_id=? FOR UPDATE', [$wh, $pid]);
        if (!$allowNeg && (float)$row['qty'] + $delta < -0.0005) {
            $n = DB::val('SELECT name FROM products WHERE id=?', [$pid]);
            throw new RuntimeException("Insufficient stock for \"$n\" (on hand " . fq($row['qty']) . ', needed ' . fq(abs($delta)) . ').');
        }
        DB::q('UPDATE stock SET qty = qty + ? WHERE warehouse_id=? AND product_id=?', [$delta, $wh, $pid]);
        DB::insert('stock_movements', ['product_id' => $pid, 'warehouse_id' => $wh, 'qty_change' => $delta, 'type' => $type, 'ref_type' => $refType, 'ref_id' => $refId, 'note' => $note, 'user_id' => Auth::id()]);
        if (self::isTracked($pid) && abs($delta) > 0.0005) { if ($delta > 0) self::batchIn($wh, $pid, $delta, $batch); else self::batchOut($wh, $pid, -$delta, $batch['id'] ?? null); }
    }
    static function batchIn(int $wh, int $pid, float $qty, ?array $batch): void {
        $no = trim((string)($batch['batch_no'] ?? '')); $exp = !empty($batch['expiry_date']) ? $batch['expiry_date'] : null;
        $b = DB::one('SELECT id FROM stock_batches WHERE warehouse_id=? AND product_id=? AND batch_no=? AND expiry_date <=> ? FOR UPDATE', [$wh, $pid, $no, $exp]);
        if ($b) DB::q('UPDATE stock_batches SET qty = qty + ? WHERE id=?', [$qty, $b['id']]);
        else DB::insert('stock_batches', ['warehouse_id' => $wh, 'product_id' => $pid, 'batch_no' => $no, 'expiry_date' => $exp, 'qty' => $qty]);
    }
    /** Take qty out of batches (specific batch, or earliest expiry first). Quantity not covered by batches (legacy stock) is simply ignored. */
    static function batchOut(int $wh, int $pid, float $qty, ?int $batchId = null): void {
        $rows = $batchId ? DB::all('SELECT * FROM stock_batches WHERE id=? AND warehouse_id=? AND product_id=? FOR UPDATE', [$batchId, $wh, $pid])
            : DB::all('SELECT * FROM stock_batches WHERE warehouse_id=? AND product_id=? AND qty>0 ORDER BY expiry_date IS NULL, expiry_date, id FOR UPDATE', [$wh, $pid]);
        foreach ($rows as $r) {
            if ($qty <= 0.0005) break; $take = min($qty, (float)$r['qty']);
            DB::q('UPDATE stock_batches SET qty = qty - ? WHERE id=?', [$take, $r['id']]); $qty = round($qty - $take, 3);
        }
        DB::q('DELETE FROM stock_batches WHERE warehouse_id=? AND product_id=? AND qty <= 0.0005', [$wh, $pid]);
    }
    static function available(int $wh, int $pid): float {
        return round((float)DB::val('SELECT qty - reserved_qty FROM stock WHERE warehouse_id=? AND product_id=?', [$wh, $pid]), 3);
    }
    static function defaultWarehouse(?int $branchId): ?int {
        $w = DB::val('SELECT id FROM warehouses WHERE branch_id=? AND is_active=1 ORDER BY is_default DESC, id LIMIT 1', [$branchId]);
        return $w ? (int)$w : null;
    }
}
