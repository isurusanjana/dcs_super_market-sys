<?php
class Alerts {
    /** @return array{low:array,out:array,over:array} aggregated across warehouses (or a single one) */
    static function all(?int $wh = null): array {
        $join = $wh ? 'LEFT JOIN stock s ON s.product_id=p.id AND s.warehouse_id=' . (int)$wh : 'LEFT JOIN stock s ON s.product_id=p.id';
        $rows = DB::all("SELECT p.id, p.sku, p.name, p.reorder_level, p.min_stock, p.max_stock, p.cost_price, COALESCE(SUM(s.qty),0) AS qty, COALESCE(SUM(s.reserved_qty),0) AS reserved
            FROM products p $join WHERE p.is_active=1 AND p.is_archived=0 GROUP BY p.id");
        $o = ['low' => [], 'out' => [], 'over' => []];
        foreach ($rows as $r) {
            if ($r['qty'] <= 0) { if ($r['reorder_level'] > 0 || $r['min_stock'] > 0 || true) $o['out'][] = $r; }
            elseif ($r['reorder_level'] > 0 && $r['qty'] <= $r['reorder_level']) $o['low'][] = $r;
            if ($r['max_stock'] > 0 && $r['qty'] > $r['max_stock']) $o['over'][] = $r;
        }
        return $o;
    }
}
