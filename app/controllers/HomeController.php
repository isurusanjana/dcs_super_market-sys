<?php
class HomeController extends Controller {
    static function systems(): array {
        return [
            'admin' => ['Administration', 'bi-gear-wide-connected', 'Users, roles, branches, suppliers, customers, pricing, finance and reports.', 'primary'],
            'pos' => ['Point of Sale', 'bi-cart4', 'Cashier sessions, sales, payments, returns, receipts and history.', 'success'],
            'inventory' => ['Product & Inventory', 'bi-boxes', 'Products, books, stationery, stock, purchasing, transfers and stocktake.', 'warning'],
        ];
    }
    function action_index(): void {
        unset($_SESSION['system']);
        $tiles = [];
        foreach (self::systems() as $k => $t) if (Auth::canModule($k)) $tiles[$k] = $t;
        $this->render('home/index', ['title' => 'Select system', 'tiles' => $tiles], 'layout/bare');
    }
    /** Follow a link into another system (switches the active system if the user may use it). */
    function action_jump(): void {
        $s = (string)input('s', ''); $to = (string)input('to', '');
        if (!isset(self::systems()[$s]) || !Auth::canModule($s)) $this->abort(403, 'You do not have access to that system.');
        if (!preg_match('/^[a-z_]+\/[a-z_]+(&[A-Za-z0-9_.%+=\-]*)*$/', $to)) $this->abort(400, 'Invalid link.');
        $_SESSION['system'] = $s; header('Location: ' . base_url() . '/index.php?r=' . $to); exit;
    }
    function action_enter(): void {
        $this->postOnly();
        $s = (string)input('system', '');
        if (!isset(self::systems()[$s]) || !Auth::canModule($s)) $this->abort(403, 'You do not have access to that system.');
        $_SESSION['system'] = $s; audit('enter_system', 'system', $s);
        redirect('dashboard/' . $s);
    }
}
