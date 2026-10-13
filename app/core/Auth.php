<?php
class Auth {
    private static ?array $user = null; private static ?array $perms = null; private static ?bool $super = null; private static bool $loaded = false;

    static function id(): ?int { $u = self::user(); return $u ? (int)$u['id'] : null; }
    static function user(): ?array {
        if (self::$loaded) return self::$user;
        self::$loaded = true;
        if (empty($_SESSION['uid'])) return null;
        if (time() - ($_SESSION['last_seen'] ?? 0) > cfg('session_timeout', 1800)) { self::forget(); return null; }
        $u = DB::one('SELECT id, username, full_name, email, branch_id, employee_id, is_active, is_locked, must_change_password FROM users WHERE id=?', [$_SESSION['uid']]);
        if (!$u || !$u['is_active'] || $u['is_locked']) { self::forget(); return null; }
        $_SESSION['last_seen'] = time();
        return self::$user = $u;
    }
    private static function forget(): void { self::$user = null; unset($_SESSION['uid'], $_SESSION['system'], $_SESSION['last_seen']); }
    static function check(): bool { return self::user() !== null; }

    /** Returns [bool ok, string message] */
    static function attempt(string $username, string $password): array {
        $ip = client_ip();
        $recent = (int)DB::val('SELECT COUNT(*) FROM login_attempts WHERE ip=? AND success=0 AND created_at > (NOW() - INTERVAL 15 MINUTE)', [$ip]);
        if ($recent >= 20) return [false, 'Too many failed attempts from this address. Try again later.'];
        $u = DB::one('SELECT * FROM users WHERE username=?', [$username]);
        $hash = $u['password_hash'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
        $okPass = password_verify($password, $hash);
        $fail = function () use ($username, $ip, $u) {
            DB::insert('login_attempts', ['username' => substr($username, 0, 50), 'ip' => $ip, 'success' => 0]);
            if ($u) {
                $n = $u['failed_attempts'] + 1;
                $upd = ['failed_attempts' => $n];
                if ($n >= cfg('max_failed_logins', 5)) { $upd['locked_until'] = date('Y-m-d H:i:s', time() + cfg('lockout_minutes', 15) * 60); $upd['failed_attempts'] = 0; }
                DB::update('users', $upd, 'id=?', [$u['id']]);
            }
            audit('login_failed', 'user', $u['id'] ?? null, ['username' => $username]);
            return [false, 'Invalid username or password.'];
        };
        if (!$u || !$okPass) return $fail();
        if (!$u['is_active']) return [false, 'This account is deactivated. Contact an administrator.'];
        if ($u['is_locked']) return [false, 'This account is locked. Contact an administrator.'];
        if ($u['locked_until'] && strtotime($u['locked_until']) > time()) return [false, 'Account temporarily locked due to repeated failures. Try again later.'];
        session_regenerate_id(true);
        $_SESSION = ['uid' => (int)$u['id'], 'last_seen' => time(), '_csrf' => bin2hex(random_bytes(32))];
        DB::update('users', ['failed_attempts' => 0, 'locked_until' => null, 'last_login_at' => date('Y-m-d H:i:s'), 'last_login_ip' => $ip], 'id=?', [$u['id']]);
        DB::insert('login_attempts', ['username' => $username, 'ip' => $ip, 'success' => 1]);
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) DB::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id=?', [$u['id']]);
        self::$loaded = false; self::$user = null; self::$perms = null; self::$super = null;
        audit('login', 'user', $u['id']);
        return [true, 'ok'];
    }
    static function logout(): void {
        audit('logout', 'user', self::id());
        $_SESSION = []; if (ini_get('session.use_cookies')) { $p = session_get_cookie_params(); setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']); }
        session_destroy(); self::$user = null;
    }
    static function hashPassword(string $p): string { return password_hash($p, PASSWORD_DEFAULT); }

    static function isSuper(): bool {
        if (self::$super !== null) return self::$super;
        $id = self::id(); if (!$id) return self::$super = false;
        return self::$super = (bool)DB::val('SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=? AND r.is_active=1 AND r.is_super=1 LIMIT 1', [$id]);
    }
    static function perms(): array {
        if (self::$perms !== null) return self::$perms;
        $id = self::id(); if (!$id) return self::$perms = [];
        $rows = DB::all('SELECT DISTINCT p.code FROM user_roles ur JOIN roles r ON r.id=ur.role_id AND r.is_active=1 JOIN role_permissions rp ON rp.role_id=r.id JOIN permissions p ON p.id=rp.permission_id WHERE ur.user_id=?', [$id]);
        return self::$perms = array_column($rows, 'code');
    }
    static function can(?string $perm): bool { if ($perm === null) return true; if (!self::check()) return false; return self::isSuper() || in_array($perm, self::perms(), true); }
    static function canModule(string $m): bool { if (!self::check()) return false; if (self::isSuper()) return true; foreach (self::perms() as $p) if (str_starts_with($p, $m . '.')) return true; return false; }
    /** Verify another user's credentials has a permission (manager approval). Returns approver user id or null. */
    static function verifyApprover(string $username, string $password, string $perm): ?int {
        $u = DB::one('SELECT * FROM users WHERE username=? AND is_active=1 AND is_locked=0', [$username]);
        if (!$u || !password_verify($password, $u['password_hash'])) { audit('approval_failed', 'user', $u['id'] ?? null, ['perm' => $perm]); return null; }
        $super = (bool)DB::val('SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=? AND r.is_active=1 AND r.is_super=1 LIMIT 1', [$u['id']]);
        $has = $super || (bool)DB::val('SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id AND r.is_active=1 JOIN role_permissions rp ON rp.role_id=r.id JOIN permissions p ON p.id=rp.permission_id WHERE ur.user_id=? AND p.code=? LIMIT 1', [$u['id'], $perm]);
        return $has ? (int)$u['id'] : null;
    }
    static function passwordError(string $p): ?string {
        if (strlen($p) < 8) return 'Password must be at least 8 characters.';
        if (!preg_match('/[a-z]/', $p) || !preg_match('/[A-Z]/', $p) || !preg_match('/\d/', $p)) return 'Password needs upper-case, lower-case and a digit.';
        return null;
    }
}
