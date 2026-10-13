<?php
class ReceiptController extends Controller {
    protected array $public = ['view'];
    function action_view(): void {
        $t = (string)input('t', ''); if (!preg_match('/^[a-f0-9]{32}$/', $t)) $this->abort(404, 'Receipt not found.');
        $s = DB::one('SELECT s.*, u.full_name AS cashier, c.name AS customer, c.phone AS customer_phone, c.email AS customer_email, c.code AS customer_code, b.name AS branch, b.address AS branch_address, b.phone AS branch_phone, b.receipt_footer FROM sales s JOIN users u ON u.id=s.user_id JOIN branches b ON b.id=s.branch_id LEFT JOIN customers c ON c.id=s.customer_id WHERE s.receipt_token=?', [$t]);
        if (!$s) $this->abort(404, 'Receipt not found.');
        $s['items'] = DB::all('SELECT * FROM sale_items WHERE sale_id=?', [$s['id']]); $s['payments'] = DB::all('SELECT * FROM sale_payments WHERE sale_id=?', [$s['id']]);
        $this->render('pos/receipt', ['s' => $s, 'mode' => 'receipt', 'auto' => false, 'public' => true], 'none');
    }
}
