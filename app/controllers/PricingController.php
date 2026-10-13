<?php
class PricingController extends Controller {
    protected ?string $module = 'admin';
    private function load(): array { $l = DB::one('SELECT l.*, g.name AS group_name FROM price_lists l LEFT JOIN customer_groups g ON g.id=l.customer_group_id WHERE l.id=?', [(int)input('id')]); if (!$l) $this->abort(404, 'Price list not found.'); return $l; }
    function action_items(): void {
        $this->need('admin.pricing.view'); $l = $this->load();
        $items = DB::all('SELECT i.*, p.sku, p.name, p.selling_price FROM price_list_items i JOIN products p ON p.id=i.product_id WHERE i.price_list_id=? ORDER BY p.name', [$l['id']]);
        $this->render('pricing/items', ['title' => 'Price list: ' . $l['name'], 'l' => $l, 'items' => $items]);
    }
    function action_add(): void {
        $this->postOnly(); $this->need('admin.pricing.edit'); $l = $this->load(); $code = trim((string)input('code', ''));
        $p = DB::one('SELECT id FROM products WHERE (sku=? OR barcode=? OR isbn=?) AND is_archived=0', [$code, $code, $code]);
        $err = Validator::check(['price' => input('price')], ['price' => ['label' => 'Price', 'rules' => 'required|decimal']]);
        if (!$p) flash('danger', 'No product matches that SKU / barcode / ISBN.'); elseif ($err) flash('danger', implode(' ', $err));
        else { DB::q('INSERT INTO price_list_items (price_list_id, product_id, price) VALUES (?,?,?) ON DUPLICATE KEY UPDATE price=VALUES(price)', [$l['id'], $p['id'], round((float)input('price'), 2)]); audit('price_change', 'price_lists', $l['id'], ['product_id' => $p['id'], 'price' => input('price')]); flash('success', 'Price saved.'); }
        redirect('pricing/items', ['id' => $l['id']]);
    }
    function action_remove(): void {
        $this->postOnly(); $this->need('admin.pricing.edit'); $l = $this->load(); DB::q('DELETE FROM price_list_items WHERE id=? AND price_list_id=?', [(int)input('item'), $l['id']]); audit('price_change', 'price_lists', $l['id'], ['removed_item' => (int)input('item')]);
        flash('success', 'Item removed.'); redirect('pricing/items', ['id' => $l['id']]);
    }
}
