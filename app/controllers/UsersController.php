<?php
class UsersController extends Controller {
    protected ?string $module = 'admin';
    private function load(int $id): array { $u = DB::one('SELECT * FROM users WHERE id=?', [$id]); if (!$u) $this->abort(404, 'User not found.'); return $u; }
    private function isSuperUser(int $id): bool { return (bool)DB::val('SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=? AND r.is_super=1', [$id]); }
    private function guardTarget(int $id): void { if ($this->isSuperUser($id) && !Auth::isSuper()) $this->abort(403, 'Only an administrator can modify an administrator account.'); }

    function action_index(): void {
        $this->need('admin.users.view'); $q = (string)input('q', ''); $p = []; $w = '1=1';
        if ($q !== '') { $w = '(u.username LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)'; $p = array_fill(0, 3, '%' . addcslashes($q, '%_\\') . '%'); }
        $users = DB::all("SELECT u.*, b.name AS branch_name, (SELECT GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ', ') FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=u.id) AS roles
            FROM users u LEFT JOIN branches b ON b.id=u.branch_id WHERE $w ORDER BY u.username", $p);
        $this->render('users/index', ['title' => 'Users', 'users' => $users, 'q' => $q]);
    }
    function action_form(): void {
        $id = (int)input('id', 0);
        if ($id) { $this->need('admin.users.edit'); $this->guardTarget($id); $row = $this->load($id); } else { $this->need('admin.users.create'); $row = null; }
        $roles = DB::all('SELECT id, name, is_super FROM roles WHERE is_active=1 ORDER BY name');
        if (!Auth::isSuper()) $roles = array_values(array_filter($roles, fn($r) => !$r['is_super']));
        $mine = $id ? array_column(DB::all('SELECT role_id FROM user_roles WHERE user_id=?', [$id]), 'role_id') : [];
        $emps = DB::all('SELECT e.id, e.emp_no, e.full_name FROM employees e WHERE e.status="active" AND (NOT EXISTS (SELECT 1 FROM users u WHERE u.employee_id=e.id' . ($id ? " AND u.id<>$id" : '') . ')) ORDER BY e.full_name');
        $this->render('users/form', ['title' => $id ? 'Edit user' : 'New user', 'row' => $row, 'roles' => $roles, 'mine' => $mine, 'emps' => $emps,
            'branches' => DB::all('SELECT id, name FROM branches WHERE is_active=1 ORDER BY name'), 'errors' => $_SESSION['_errors'] ?? []]);
        unset($_SESSION['_errors']);
    }
    function action_save(): void {
        $this->postOnly(); $id = (int)input('id', 0);
        if ($id) { $this->need('admin.users.edit'); $this->guardTarget($id); } else $this->need('admin.users.create');
        $d = ['username' => input('username', ''), 'full_name' => input('full_name', ''), 'email' => input('email', '') ?: null, 'phone' => input('phone', '') ?: null,
            'employee_id' => input('employee_id') ?: null, 'branch_id' => input('branch_id') ?: null];
        $rules = ['username' => ['label' => 'Username', 'rules' => 'required|username|unique:users,username'], 'full_name' => ['label' => 'Full name', 'rules' => 'required|max:120'],
            'email' => ['label' => 'Email', 'rules' => 'email|max:120'], 'phone' => ['label' => 'Phone', 'rules' => 'phone'], 'employee_id' => ['label' => 'Employee', 'rules' => 'exists:employees,id'], 'branch_id' => ['label' => 'Branch', 'rules' => 'exists:branches,id']];
        $pw = (string)($_POST['password'] ?? '');
        if (!$id) { $d['password'] = $pw; $rules['password'] = ['label' => 'Password', 'rules' => 'required|password']; }
        $errors = Validator::check($d, $rules, $id ?: null);
        if (!empty($d['employee_id']) && DB::val('SELECT 1 FROM users WHERE employee_id=? AND id<>?', [$d['employee_id'], $id])) $errors['employee_id'] = 'That employee already has a user account.';
        $roleIds = array_map('intval', (array)($_POST['roles'] ?? []));
        $allowed = array_column(DB::all('SELECT id FROM roles WHERE is_active=1' . (Auth::isSuper() ? '' : ' AND is_super=0')), 'id');
        $roleIds = array_values(array_intersect($roleIds, array_map('intval', $allowed)));
        if (!Auth::can('admin.users.assign_role') && !Auth::isSuper()) { $roleIds = null; }
        if ($errors) { $_SESSION['_errors'] = $errors; $this->withOld($_POST); redirect('users/form', $id ? ['id' => $id] : []); }
        unset($d['password']);
        DB::tx(function () use ($id, &$d, $pw, $roleIds, &$uid) {
            if ($id) { DB::update('users', $d, 'id=?', [$id]); $uid = $id; }
            else { $d['password_hash'] = Auth::hashPassword($pw); $d['must_change_password'] = 1; $uid = DB::insert('users', $d); }
            if ($roleIds !== null) {
                $before = array_column(DB::all('SELECT role_id FROM user_roles WHERE user_id=?', [$uid]), 'role_id');
                // never strip super roles when a non-super edits (already guarded) ; protect last super admin
                if ($id && $this->isSuperUser($uid) && !array_intersect($roleIds, array_column(DB::all('SELECT id FROM roles WHERE is_super=1'), 'id')) && $this->superCount($uid) === 0) throw new RuntimeException('At least one active administrator must remain.');
                DB::q('DELETE FROM user_roles WHERE user_id=?', [$uid]); foreach ($roleIds as $r) DB::insert('user_roles', ['user_id' => $uid, 'role_id' => $r]);
                if (array_map('intval', $before) != $roleIds) audit('role_change', 'users', $uid, ['from' => $before, 'to' => $roleIds]);
            }
        });
        audit($id ? 'update' : 'create', 'users', $uid, ['username' => $d['username']]);
        flash('success', $id ? 'User updated.' : 'User created. They must change the password at first login.'); redirect('users/index');
    }
    /** number of OTHER active unlocked super admins */
    private function superCount(int $except): int {
        return (int)DB::val('SELECT COUNT(DISTINCT u.id) FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id AND r.is_super=1 AND r.is_active=1 WHERE u.is_active=1 AND u.is_locked=0 AND u.id<>?', [$except]);
    }
    function action_toggle(): void {
        $this->postOnly(); $this->need('admin.users.activate'); $id = (int)input('id'); $u = $this->load($id); $this->guardTarget($id);
        if ($id === Auth::id()) { flash('danger', 'You cannot deactivate your own account.'); redirect('users/index'); }
        if ($u['is_active'] && $this->isSuperUser($id) && $this->superCount($id) === 0) { flash('danger', 'At least one active administrator must remain.'); redirect('users/index'); }
        DB::update('users', ['is_active' => $u['is_active'] ? 0 : 1], 'id=?', [$id]); audit($u['is_active'] ? 'deactivate' : 'activate', 'users', $id);
        flash('success', 'User ' . ($u['is_active'] ? 'deactivated.' : 'activated.')); redirect('users/index');
    }
    function action_lock(): void {
        $this->postOnly(); $this->need('admin.users.lock'); $id = (int)input('id'); $u = $this->load($id); $this->guardTarget($id);
        if ($id === Auth::id()) { flash('danger', 'You cannot lock your own account.'); redirect('users/index'); }
        if (!$u['is_locked'] && $this->isSuperUser($id) && $this->superCount($id) === 0) { flash('danger', 'At least one active administrator must remain.'); redirect('users/index'); }
        DB::update('users', ['is_locked' => $u['is_locked'] ? 0 : 1, 'failed_attempts' => 0, 'locked_until' => null], 'id=?', [$id]); audit($u['is_locked'] ? 'unlock' : 'lock', 'users', $id);
        flash('success', $u['is_locked'] ? 'Account unlocked.' : 'Account locked.'); redirect('users/index');
    }
    function action_reset(): void {
        $id = (int)input('id'); $this->need('admin.users.reset_password'); $u = $this->load($id); $this->guardTarget($id);
        $errors = [];
        if (is_post()) {
            $p = (string)($_POST['password'] ?? '');
            if ($err = Auth::passwordError($p)) $errors['password'] = $err; elseif ($p !== (string)($_POST['confirm'] ?? '')) $errors['confirm'] = 'Confirmation does not match.';
            if (!$errors) { DB::update('users', ['password_hash' => Auth::hashPassword($p), 'must_change_password' => 1, 'failed_attempts' => 0, 'locked_until' => null], 'id=?', [$id]); audit('password_reset', 'users', $id); flash('success', 'Password reset. The user must change it at next login.'); redirect('users/index'); }
        }
        $this->render('users/reset', ['title' => 'Reset password', 'u' => $u, 'errors' => $errors]);
    }
}
