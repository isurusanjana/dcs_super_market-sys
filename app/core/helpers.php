<?php
function e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function base_url(): string { $d = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/'); return $d; }
function url(string $route = '', array $params = []): string {
    $q = $route !== '' ? ['r' => $route] + $params : $params;
    return base_url() . '/index.php' . ($q ? '?' . http_build_query($q) : '');
}
function asset(string $p): string { return base_url() . '/assets/' . ltrim($p, '/'); }
function redirect(string $route, array $params = []): never { header('Location: ' . url($route, $params)); exit; }
function is_post(): bool { return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; }
function input(string $k, $d = null) { $v = $_POST[$k] ?? $_GET[$k] ?? $d; return is_string($v) ? trim($v) : $v; }
function flash(string $type, string $msg): void { $_SESSION['_flash'][] = [$type, $msg]; }
function flashes(): array { $f = $_SESSION['_flash'] ?? []; unset($_SESSION['_flash']); return $f; }
function old(string $k, $d = '') { return $_SESSION['_old'][$k] ?? $d; }
function money($v): string { return setting('currency', 'Rs.') . ' ' . number_format((float)$v, 2); }
function fmt_date($v, string $f = 'Y-m-d'): string { return $v ? date($f, strtotime((string)$v)) : ''; }
function csrf_token(): string { if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32)); return $_SESSION['_csrf']; }
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">'; }
function json_out($d, int $code = 200): never { http_response_code($code); header('Content-Type: application/json'); echo json_encode($d); exit; }
function client_ip(): string { return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45); }

function setting(string $k, $d = null) {
    static $all = null;
    if ($all === null) { try { $all = array_column(DB::all('SELECT skey, svalue FROM settings'), 'svalue', 'skey'); } catch (Throwable $e) { $all = []; } }
    return $all[$k] ?? $d;
}
function set_setting(string $k, string $v): void { DB::q('INSERT INTO settings (skey, svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)', [$k, $v]); }

function next_number(string $seq, string $prefix, int $pad = 5): string {
    DB::q('INSERT INTO sequences (name, val) VALUES (?, LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE val = LAST_INSERT_ID(val + 1)', [$seq]);
    return $prefix . str_pad((string)DB::pdo()->lastInsertId(), $pad, '0', STR_PAD_LEFT);
}
function audit(string $action, ?string $entity = null, $id = null, $details = null): void {
    $u = Auth::user();
    try {
        DB::insert('audit_logs', ['user_id' => $u['id'] ?? null, 'username' => $u['username'] ?? null, 'action' => $action, 'entity' => $entity,
            'entity_id' => $id === null ? null : (string)$id, 'details' => is_array($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : $details, 'ip' => client_ip()]);
    } catch (Throwable $e) { /* never break a request because logging failed */ }
}
function fin_txn(string $type, string $dir, string $method, float $amt, ?int $branch, ?int $session, ?string $refType, ?int $refId, ?string $note = null): void {
    DB::insert('fin_transactions', ['txn_date' => date('Y-m-d H:i:s'), 'type' => $type, 'direction' => $dir, 'method' => $method, 'amount' => round($amt, 2),
        'branch_id' => $branch, 'session_id' => $session, 'ref_type' => $refType, 'ref_id' => $refId, 'note' => $note, 'user_id' => Auth::id()]);
}
/** Quick-jump links between related records. Links appear only when the user may open the target; they switch system automatically if needed. */
function jump_url(string $module, string $route): string {
    if (($_SESSION['system'] ?? null) === $module) return base_url() . '/index.php?r=' . $route;
    return url('home/jump', ['s' => $module, 'to' => $route]);
}
function jump_link(string $module, ?string $perm, string $route, string $label, string $class = ''): string {
    if ($label === '' || !Auth::can($perm)) return e($label);
    return '<a class="jump-link ' . e($class) . '" href="' . e(jump_url($module, $route)) . '" title="Open">' . e($label) . '</a>';
}
function ref_url(string $entity, $id, string $label = ''): ?string {
    static $all = null; if ($all === null) $all = require APP_PATH . '/entities.php';
    if (!isset($all[$entity]) || !$id) return null; $perm = $all[$entity]['perm']; $mod = explode('.', $perm)[0];
    if (Auth::can($perm . '.edit')) $route = 'crud/form&e=' . $entity . '&id=' . (int)$id;
    elseif (Auth::can($perm . '.view')) $route = 'crud/list&e=' . $entity . '&q=' . rawurlencode($label);
    else return null;
    return jump_url($mod, $route);
}
function ref_link(string $entity, $id, ?string $label, string $class = ''): string {
    $label = (string)$label; if ($label === '') return ''; $u = ref_url($entity, $id, $label);
    return $u ? '<a class="jump-link ' . e($class) . '" href="' . e($u) . '" title="Open">' . e($label) . '</a>' : e($label);
}
function link_product($id, ?string $label): string {
    static $types = []; $label = (string)$label; if (!$id) return e($label);
    if (!isset($types[$id])) $types[$id] = DB::val('SELECT product_type FROM products WHERE id=?', [$id]);
    $ent = ['book' => 'books', 'stationery' => 'stationery', 'fresh' => 'fresh', 'grocery' => 'groceries', 'household' => 'groceries'][$types[$id]] ?? 'products';
    return ref_link($ent, $id, $label);
}
function link_supplier($id, ?string $label): string { return jump_link('admin', 'admin.suppliers.view', 'supplier/view&id=' . (int)$id, (string)$label); }
function link_customer($id, ?string $label): string { return $id ? jump_link('admin', 'admin.customers.view', 'customer/view&id=' . (int)$id, (string)$label) : e((string)$label); }
function link_sale($id, ?string $label): string { return jump_link('pos', 'pos.history.view', 'pos/sale&id=' . (int)$id, (string)$label); }
function link_po($id, ?string $label): string { return jump_link('inventory', 'inventory.purchasing.view', 'purchasing/view&id=' . (int)$id, (string)$label); }
function link_wh($id, ?string $label): string { return ref_link('warehouses', $id, $label); }
/** Receipt wording in the shop's chosen language (setting receipt_language: si | en | both). */
function rl(string $key): string {
    static $d = null; if ($d === null) $d = require APP_PATH . '/lang/receipt.php';
    $lang = (string)setting('receipt_language', 'si'); $si = $d['si'][$key] ?? $key; $en = $d['en'][$key] ?? $key;
    return $lang === 'en' ? $en : ($lang === 'both' && $si !== $en ? $si . ' / ' . $en : $si);
}
function rl_method(string $m): string { $k = 'm_' . $m; $d = require APP_PATH . '/lang/receipt.php'; return isset($d['en'][$k]) ? rl($k) : ucwords(str_replace('_', ' ', $m)); }
function receipt_footer(?string $branchFooter): string {
    $f = trim((string)($branchFooter ?: setting('receipt_footer', ''))); $d = require APP_PATH . '/lang/receipt.php';
    return ($f === '' || $f === $d['en']['thanks']) ? rl('thanks') : $f;
}
function paginate(int $total, int $per, int $page): array { $pages = max(1, (int)ceil($total / $per)); $page = min(max(1, $page), $pages); return [$page, $pages, ($page - 1) * $per]; }
function payment_methods(): array { return ['cash' => 'Cash', 'card' => 'Card', 'bank_transfer' => 'Bank Transfer', 'digital' => 'Digital Payment']; }
function badge(string $s): string {
    $m = ['active' => 'success', 'approved' => 'success', 'completed' => 'success', 'received' => 'success', 'paid' => 'success', 'open' => 'success', 'present' => 'success', 'reconciled' => 'success',
        'pending' => 'warning', 'draft' => 'secondary', 'partial' => 'info', 'submitted' => 'info', 'dispatched' => 'info', 'counting' => 'info', 'pending_approval' => 'warning', 'suspended' => 'warning', 'unpaid' => 'danger',
        'void' => 'danger', 'cancelled' => 'danger', 'rejected' => 'danger', 'inactive' => 'secondary', 'closed' => 'secondary', 'absent' => 'danger', 'late' => 'warning', 'leave' => 'info', 'half_day' => 'warning', 'ordered' => 'info'];
    return '<span class="badge text-bg-' . ($m[$s] ?? 'secondary') . '">' . e(ucwords(str_replace('_', ' ', $s))) . '</span>';
}

/** Format a quantity: whole numbers without decimals, weights with up to 3 decimals (e.g. 2, 0.35, 1.25). */
function fq($v): string { if ($v === null || $v === '') return ''; return rtrim(rtrim(number_format((float)$v, 3, '.', ''), '0'), '.'); }
/** Parse a user quantity. Items sold by unit must be whole numbers; weighed items allow 3 decimals. Returns null if invalid. */
function parse_qty($v, bool $weighable = false, float $max = 1000000): ?float {
    $v = is_string($v) ? trim($v) : $v;
    if (is_int($v) || is_float($v)) $v = (string)$v;
    if (!is_string($v) || !preg_match($weighable ? '/^\d+(\.\d{1,3})?$/' : '/^\d+$/', $v)) return null;
    $f = round((float)$v, 3); return ($f > 0 && $f <= $max) ? $f : null;
}
