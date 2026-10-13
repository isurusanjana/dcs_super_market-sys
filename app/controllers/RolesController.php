<?php
class RolesController extends Controller {
    protected ?string $module = 'admin';
    private function load(int $id): array { $r = DB::one('SELECT * FROM roles WHERE id=?', [$id]); if (!$r) $this->abort(404, 'Role not found.'); return $r; }
    function action_index(): void {
        $this->need('admin.roles.view');
        $roles = DB::all('SELECT r.*, (SELECT COUNT(*) FROM user_roles ur WHERE ur.role_id=r.id) AS users, (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id=r.id) AS perms FROM roles r ORDER BY r.name');
        $this->render('roles/index', ['title' => 'Roles & Permissions', 'roles' => $roles]);
    }
    function action_form(): void {
        $id = (int)input('id', 0);
        if ($id) { $this->need('admin.roles.edit'); $role = $this->load($id); } else { $this->need('admin.roles.create'); $role = null; }
        if ($role && $role['is_super'] && !Auth::isSuper()) $this->abort(403, 'Only an administrator can modify this role.');
        $granted = $id ? array_column(DB::all('SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=?', [$id]), 'code') : [];
        $this->render('roles/form', ['title' => $id ? 'Edit role' : 'New role', 'role' => $role, 'defs' => require APP_PATH . '/permissions.php', 'granted' => isset($_SESSION['_old']['perm']) ? array_keys($_SESSION['_old']['perm']) : $granted, 'errors' => $_SESSION['_errors'] ?? []]);
        unset($_SESSION['_errors']);
    }
    function action_save(): void {
        $this->postOnly(); $id = (int)input('id', 0);
        $role = $id ? $this->load($id) : null;
        $this->need($id ? 'admin.roles.edit' : 'admin.roles.create');
        if ($role && $role['is_super'] && !Auth::isSuper()) $this->abort(403, 'Only an administrator can modify this role.');
        $d = ['name' => input('name', ''), 'description' => input('description', '') ?: null];
        $errors = Validator::check($d, ['name' => ['label' => 'Role name', 'rules' => 'required|min:2|max:60|unique:roles,name'], 'description' => ['label' => 'Description', 'rules' => 'max:255']], $id ?: null);
        if ($errors) { $_SESSION['_errors'] = $errors; $this->withOld($_POST); redirect('roles/form', $id ? ['id' => $id] : []); }
        $d['is_active'] = isset($_POST['is_active']) ? 1 : 0;
        if ($role && $role['is_super']) $d['is_active'] = 1;
        $canPerm = Auth::can('admin.roles.assign_permission');
        $codes = array_keys((array)($_POST['perm'] ?? []));
        DB::tx(function () use ($id, $d, $role, $canPerm, $codes, &$rid) {
            if ($id) { DB::update('roles', $d, 'id=?', [$id]); $rid = $id; } else $rid = DB::insert('roles', $d);
            if ($canPerm && !($role && $role['is_super'])) {
                $valid = array_column(DB::all('SELECT id, code FROM permissions'), 'id', 'code');
                $before = array_column(DB::all('SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=?', [$rid]), 'code');
                $new = array_values(array_intersect($codes, array_keys($valid)));
                DB::q('DELETE FROM role_permissions WHERE role_id=?', [$rid]);
                foreach ($new as $c) DB::insert('role_permissions', ['role_id' => $rid, 'permission_id' => $valid[$c]]);
                $added = array_values(array_diff($new, $before)); $removed = array_values(array_diff($before, $new));
                if ($added || $removed) audit('permission_change', 'roles', $rid, ['added' => $added, 'removed' => $removed]);
            }
        });
        audit($id ? 'update' : 'create', 'roles', $rid, ['name' => $d['name']]);
        flash('success', 'Role saved.'); redirect('roles/index');
    }
    function action_delete(): void {
        $this->postOnly(); $this->need('admin.roles.delete'); $id = (int)input('id'); $r = $this->load($id);
        if ($r['is_super']) { flash('danger', 'The administrator role cannot be removed.'); redirect('roles/index'); }
        if (DB::val('SELECT COUNT(*) FROM user_roles WHERE role_id=?', [$id])) {
            DB::update('roles', ['is_active' => 0], 'id=?', [$id]); audit('deactivate', 'roles', $id); flash('warning', 'Role is assigned to users, so it was deactivated instead of deleted.');
        } else { DB::q('DELETE FROM roles WHERE id=?', [$id]); audit('delete', 'roles', $id, ['name' => $r['name']]); flash('success', 'Role deleted.'); }
        redirect('roles/index');
    }
    function action_view(): void {
        $this->need('admin.roles.view'); $id = (int)input('id'); $role = $this->load($id);
        $granted = array_column(DB::all('SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=?', [$id]), 'code');
        $this->render('roles/view', ['title' => 'Role: ' . $role['name'], 'role' => $role, 'defs' => require APP_PATH . '/permissions.php', 'granted' => $granted]);
    }
}
