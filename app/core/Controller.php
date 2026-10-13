<?php
abstract class Controller {
    protected ?string $module = null;          // admin|pos|inventory (null = any logged-in)
    protected array $public = [];              // actions that need no login
    protected string $layout = 'layout/main';

    function run(string $action): void {
        $m = 'action_' . $action;
        if (!method_exists($this, $m)) $this->abort(404, 'Page not found');
        if (!in_array($action, $this->public, true)) {
            if (!Auth::check()) { if ($this->isAjax()) json_out(['ok' => false, 'error' => 'Session expired. Please log in again.'], 401); flash('warning', 'Please log in.'); redirect('auth/login'); }
            $u = Auth::user();
            if ($u['must_change_password'] && !($this instanceof AuthController)) { flash('warning', 'You must change your password before continuing.'); redirect('auth/password'); }
        }
        if (is_post()) $this->verifyCsrf();
        $this->guardModule();
        $this->$m();
    }
    protected function guardModule(?string $mod = null): void {
        $mod = $mod ?? $this->module; if (!$mod) return;
        if (!Auth::canModule($mod)) $this->abort(403, 'You do not have access to this module.');
        if (($_SESSION['system'] ?? null) !== $mod) {
            if ($this->isAjax()) json_out(['ok' => false, 'error' => 'Wrong system selected.'], 403);
            flash('info', 'Select a system first.'); redirect('home/index');
        }
    }
    protected function verifyCsrf(): void {
        $t = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($t) || !hash_equals($_SESSION['_csrf'] ?? '', $t)) { $this->abort(419, 'Security token expired. Go back, refresh the page and try again.'); }
    }
    protected function isAjax(): bool { return !empty($_SERVER['HTTP_X_REQUESTED_WITH']); }
    protected function need(string $perm): void { if (!Auth::can($perm)) $this->abort(403, 'You do not have permission to do that.'); }
    protected function postOnly(): void { if (!is_post()) $this->abort(405, 'Method not allowed'); }
    protected function abort(int $code, string $msg): never {
        http_response_code($code);
        if ($this->isAjax()) json_out(['ok' => false, 'error' => $msg], $code);
        $this->render('errors/error', ['code' => $code, 'msg' => $msg], Auth::check() ? $this->layout : 'layout/bare'); exit;
    }
    protected function render(string $view, array $data = [], ?string $layout = null): void {
        extract($data, EXTR_SKIP);
        ob_start(); require APP_PATH . '/views/' . $view . '.php'; $content = ob_get_clean();
        $layout = $layout ?? $this->layout;
        if ($layout === 'none') { echo $content; return; }
        $title = $title ?? cfg('app_name');
        require APP_PATH . '/views/' . $layout . '.php';
        unset($_SESSION['_old']);
    }
    protected function back(string $default, array $p = []): never { redirect($default, $p); }
    protected function withOld(array $d): void { $_SESSION['_old'] = $d; }
    protected function branchId(): ?int { $b = Auth::user()['branch_id'] ?? null; return $b ? (int)$b : null; }
}
