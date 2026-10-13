<?php
class GiftcardsController extends Controller {
    protected ?string $module = 'pos';
    private function load(): array { $g = DB::one('SELECT g.*, c.name AS customer FROM gift_cards g LEFT JOIN customers c ON c.id=g.customer_id WHERE g.id=?', [(int)input('id')]); if (!$g) $this->abort(404, 'Gift card not found.'); return $g; }
    private function newCode(): string {
        $al = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do { $c = 'GC'; for ($g = 0; $g < 3; $g++) { $c .= '-'; for ($i = 0; $i < 4; $i++) $c .= $al[random_int(0, strlen($al) - 1)]; } } while (DB::val('SELECT 1 FROM gift_cards WHERE code=?', [$c]));
        return $c;
    }
    function action_index(): void {
        $this->need('pos.giftcard.view'); $q = trim((string)input('q', '')); $st = (string)input('status', ''); $w = ['1=1']; $p = [];
        if ($q !== '') { $w[] = '(g.code LIKE ? OR c.name LIKE ? OR c.phone LIKE ?)'; $l = '%' . addcslashes($q, '%_\\') . '%'; array_push($p, $l, $l, $l); }
        if (in_array($st, ['active', 'used', 'blocked'], true)) { $w[] = 'g.status=?'; $p[] = $st; }
        $rows = DB::all('SELECT g.*, c.name AS customer FROM gift_cards g LEFT JOIN customers c ON c.id=g.customer_id WHERE ' . implode(' AND ', $w) . ' ORDER BY g.id DESC LIMIT 200', $p);
        $liab = (float)DB::val("SELECT COALESCE(SUM(balance),0) FROM gift_cards WHERE status='active' AND (expires_on IS NULL OR expires_on >= CURDATE())");
        $this->render('giftcards/index', ['title' => 'Gift Cards', 'rows' => $rows, 'q' => $q, 'st' => $st, 'liab' => $liab]);
    }
    function action_issue(): void {
        $this->need('pos.giftcard.sell'); $s = Pos::openSession();
        if (is_post()) {
            $d = ['amount' => input('amount'), 'method' => input('method'), 'expires_on' => input('expires_on') ?: null, 'note' => input('note') ?: null, 'customer_id' => input('customer_id') ?: null];
            $err = Validator::check($d, ['amount' => ['label' => 'Amount', 'rules' => 'required|pos|maxval:1000000'], 'method' => ['label' => 'Payment method', 'rules' => 'required|in:cash,card,bank_transfer,digital'], 'expires_on' => ['label' => 'Expiry date', 'rules' => 'date'], 'note' => ['label' => 'Note', 'rules' => 'max:160'], 'customer_id' => ['label' => 'Customer', 'rules' => 'exists:customers,id']]);
            if (!$err && $d['expires_on'] && $d['expires_on'] < date('Y-m-d')) $err[] = 'Expiry date cannot be in the past.';
            if (!$err && $d['method'] === 'cash' && !$s) $err[] = 'Open your register first - cash for a gift card goes into your drawer.';
            if ($err) { flash('danger', implode(' ', $err)); redirect('giftcards/issue'); }
            $amt = round((float)$d['amount'], 2); $branch = $this->branchId();
            $id = DB::tx(function () use ($d, $amt, $s, $branch) {
                $id = DB::insert('gift_cards', ['code' => $this->newCode(), 'initial_amount' => $amt, 'balance' => $amt, 'customer_id' => $d['customer_id'], 'expires_on' => $d['expires_on'], 'note' => $d['note'], 'issued_by' => Auth::id(), 'branch_id' => $branch]);
                DB::insert('gift_card_txns', ['gift_card_id' => $id, 'amount' => $amt, 'type' => 'issue', 'user_id' => Auth::id(), 'note' => 'Sold for ' . $d['method']]);
                fin_txn('giftcard_sale', 'in', $d['method'], $amt, $branch ?? ($s ? (int)$s['branch_id'] : null), $s ? (int)$s['id'] : null, 'gift_card', $id, 'Gift card sold');
                return $id;
            });
            audit('giftcard_issue', 'gift_cards', $id, ['amount' => $amt]); flash('success', 'Gift card issued. Print the slip for the customer.'); redirect('giftcards/view', ['id' => $id]);
        }
        $this->render('giftcards/issue', ['title' => 'Sell a Gift Card', 'hasSession' => (bool)$s, 'customers' => DB::all('SELECT id, name, phone FROM customers WHERE is_active=1 ORDER BY name LIMIT 500')]);
    }
    function action_view(): void {
        $this->need('pos.giftcard.view'); $g = $this->load();
        $this->render('giftcards/view', ['title' => 'Gift card ' . $g['code'], 'g' => $g, 'txns' => DB::all('SELECT t.*, u.username, s.invoice_no FROM gift_card_txns t LEFT JOIN users u ON u.id=t.user_id LEFT JOIN sales s ON s.id=t.sale_id WHERE t.gift_card_id=? ORDER BY t.id DESC', [$g['id']])]);
    }
    function action_slip(): void { $this->need('pos.giftcard.view'); $g = $this->load(); $this->render('giftcards/slip', ['g' => $g], 'none'); }
    function action_check(): void {
        $this->need('pos.giftcard.view'); $c = strtoupper(trim((string)input('code', ''))); $g = DB::one('SELECT code, balance, status, expires_on FROM gift_cards WHERE code=?', [$c]);
        if (!$g) json_out(['ok' => false, 'error' => 'Gift card not found.'], 404);
        $valid = $g['status'] === 'active' && (!$g['expires_on'] || $g['expires_on'] >= date('Y-m-d')); json_out(['ok' => true, 'balance' => (float)$g['balance'], 'status' => $g['status'], 'expires_on' => $g['expires_on'], 'usable' => $valid]);
    }
    function action_block(): void {
        $this->postOnly(); $this->need('pos.giftcard.block'); $g = $this->load(); $to = $g['status'] === 'blocked' ? ((float)$g['balance'] > 0 ? 'active' : 'used') : 'blocked';
        DB::update('gift_cards', ['status' => $to], 'id=?', [$g['id']]); audit('giftcard_' . ($to === 'blocked' ? 'block' : 'unblock'), 'gift_cards', $g['id']); flash('success', 'Card ' . ($to === 'blocked' ? 'blocked.' : 'unblocked.')); redirect('giftcards/view', ['id' => $g['id']]);
    }
    function action_adjust(): void {
        $this->postOnly(); $this->need('pos.giftcard.block'); $g = $this->load(); $amt = input('amount'); $reason = trim((string)input('reason', ''));
        if (!is_numeric($amt) || (float)$amt == 0 || abs((float)$amt) > 1000000 || mb_strlen($reason) < 3) { flash('danger', 'Enter a non-zero amount (negative to reduce) and a reason.'); redirect('giftcards/view', ['id' => $g['id']]); }
        $amt = round((float)$amt, 2); if ((float)$g['balance'] + $amt < 0) { flash('danger', 'The balance cannot go below zero.'); redirect('giftcards/view', ['id' => $g['id']]); }
        DB::tx(function () use ($g, $amt, $reason) { DB::q("UPDATE gift_cards SET balance = balance + ?, status = IF(status='blocked','blocked',IF(balance + ? <= 0.004,'used','active')) WHERE id=?", [$amt, $amt, $g['id']]); DB::insert('gift_card_txns', ['gift_card_id' => $g['id'], 'amount' => $amt, 'type' => 'adjust', 'user_id' => Auth::id(), 'note' => mb_substr($reason, 0, 160)]); });
        audit('giftcard_adjust', 'gift_cards', $g['id'], ['amount' => $amt, 'reason' => $reason]); flash('success', 'Balance adjusted.'); redirect('giftcards/view', ['id' => $g['id']]);
    }
}
