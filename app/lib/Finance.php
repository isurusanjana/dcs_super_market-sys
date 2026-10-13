<?php
class Finance {
    static function customerBalance(int $id): float {
        $c = (float)DB::val("SELECT COALESCE(SUM(credit_amount),0) FROM sales WHERE customer_id=? AND status='completed'", [$id]);
        $p = (float)DB::val('SELECT COALESCE(SUM(amount),0) FROM customer_payments WHERE customer_id=?', [$id]);
        return round($c - $p, 2);
    }
    static function supplierBalance(int $id): float {
        $i = (float)DB::val('SELECT COALESCE(SUM(total),0) FROM purchase_invoices WHERE supplier_id=?', [$id]);
        $p = (float)DB::val('SELECT COALESCE(SUM(amount),0) FROM supplier_payments WHERE supplier_id=?', [$id]);
        $r = (float)DB::val("SELECT COALESCE(SUM(total),0) FROM purchase_returns WHERE supplier_id=? AND status='approved'", [$id]);
        return round($i - $p - $r, 2);
    }
    static function totalReceivable(): float {
        return round((float)DB::val("SELECT COALESCE(SUM(credit_amount),0) FROM sales WHERE status='completed'") - (float)DB::val('SELECT COALESCE(SUM(amount),0) FROM customer_payments'), 2);
    }
    static function totalPayable(): float {
        return round((float)DB::val('SELECT COALESCE(SUM(total),0) FROM purchase_invoices') - (float)DB::val('SELECT COALESCE(SUM(amount),0) FROM supplier_payments')
            - (float)DB::val("SELECT COALESCE(SUM(total),0) FROM purchase_returns WHERE status='approved'"), 2);
    }
    /** Refresh invoice paid/partial/unpaid status for supplier based on payments tied to invoices. */
    static function refreshInvoice(int $invoiceId): void {
        $inv = DB::one('SELECT total FROM purchase_invoices WHERE id=?', [$invoiceId]); if (!$inv) return;
        $paid = (float)DB::val('SELECT COALESCE(SUM(amount),0) FROM supplier_payments WHERE invoice_id=?', [$invoiceId]);
        $st = $paid <= 0 ? 'unpaid' : ($paid + 0.004 >= (float)$inv['total'] ? 'paid' : 'partial');
        DB::update('purchase_invoices', ['status' => $st], 'id=?', [$invoiceId]);
    }
}
