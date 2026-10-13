<?php
class CrudController extends Controller {
    private array $ent = [];
    function run(string $action): void {
        $slug = (string)($_GET['e'] ?? $_POST['e'] ?? '');
        $all = require APP_PATH . '/entities.php';
        if (!isset($all[$slug])) { if (!Auth::check()) redirect('auth/login'); $this->abort(404, 'Unknown entity'); }
        $this->ent = $all[$slug] + ['slug' => $slug];
        $this->module = explode('.', $this->ent['perm'])[0];
        parent::run($action);
    }
    private function from(): string { return $this->ent['from'] ?? "`{$this->ent['table']}` t"; }
    private function sourceOptions(array $src): array {
        [$t, $idc, $lc, $w] = $src + [null, 'id', 'name', '1=1'];
        return array_column(DB::all("SELECT `$idc` AS i, `$lc` AS l FROM `$t` WHERE $w ORDER BY `$lc`"), 'l', 'i');
    }
    private function fieldOptions(array $f): array { return $f['options'] ?? (isset($f['source']) ? $this->sourceOptions($f['source']) : []); }
    private function isLocked(array $row): bool {
        foreach ($this->ent['lock_if'] ?? [] as $col => $vals) if (in_array($row[$col] ?? null, $vals, true)) return true;
        return false;
    }
    private function find(int $id): array {
        $r = DB::one("SELECT " . ($this->ent['cols'] ?? 't.*') . " FROM " . $this->from() . " WHERE t.id=?", [$id]);
        if (!$r) $this->abort(404, 'Record not found.');
        return $r;
    }

    function action_list(): void {
        $e = $this->ent; $this->need($e['perm'] . '.view');
        $where = [$e['where'] ?? '1=1']; $p = [];
        $q = (string)input('q', '');
        if ($q !== '' && !empty($e['search'])) {
            $w = []; foreach ($e['search'] as $c) { $w[] = "$c LIKE ?"; $p[] = '%' . addcslashes($q, '%_\\') . '%'; } $where[] = '(' . implode(' OR ', $w) . ')';
        }
        $filters = [];
        foreach ($e['filters'] ?? [] as $f) {
            $v = (string)input('f_' . $f['name'], ''); $f['options'] = $this->sourceOptions($f['source']); $f['value'] = $v; $filters[] = $f;
            if ($v !== '') { $where[] = 't.`' . $f['name'] . '` = ?'; $p[] = $v; }
        }
        $ws = implode(' AND ', $where); $per = 20;
        $total = (int)DB::val("SELECT COUNT(*) FROM " . $this->from() . " WHERE $ws", $p);
        [$page, $pages, $off] = paginate($total, $per, (int)input('page', 1));
        $rows = DB::all("SELECT " . ($e['cols'] ?? 't.*') . " FROM " . $this->from() . " WHERE $ws ORDER BY " . ($e['order'] ?? 't.id') . " LIMIT $per OFFSET $off", $p);
        $this->render('crud/list', ['title' => $e['title'], 'e' => $e, 'rows' => $rows, 'q' => $q, 'page' => $page, 'pages' => $pages, 'total' => $total, 'filters' => $filters, 'ctl' => $this]);
    }
    function lockedRow(array $r): bool { return $this->isLocked($r); }

    function action_form(): void {
        $e = $this->ent; $id = (int)input('id', 0);
        $row = null;
        if ($id) { $this->need($e['perm'] . '.edit'); $row = $this->find($id); if ($this->isLocked($row)) { flash('warning', 'This record is locked and can no longer be edited.'); redirect('crud/list', ['e' => $e['slug']]); } }
        else $this->need($e['perm'] . '.create');
        $fields = $e['fields']; foreach ($fields as &$f) { if (in_array($f['type'], ['select'])) $f['_options'] = $this->fieldOptions($f); } unset($f);
        $this->render('crud/form', ['title' => ($id ? 'Edit ' : 'New ') . $e['title'], 'e' => $e, 'fields' => $fields, 'row' => $row, 'errors' => $_SESSION['_errors'] ?? []]);
        unset($_SESSION['_errors']);
    }

    function action_save(): void {
        $this->postOnly(); $e = $this->ent; $id = (int)input('id', 0); $old = null;
        if ($id) { $this->need($e['perm'] . '.edit'); $old = $this->find($id); if ($this->isLocked($old)) $this->abort(403, 'Record is locked.'); } else $this->need($e['perm'] . '.create');
        $data = []; $rules = []; $virt = [];
        foreach ($e['fields'] as $f) {
            $n = $f['name'];
            if (!empty($f['virtual'])) { if ($id) continue; $virt[$n] = trim((string)($_POST[$n] ?? '')); if (isset($f['rules'])) $rules[$n] = ['label' => $f['label'], 'rules' => $f['rules']]; continue; }
            if ($id && !empty($f['readonly_edit'])) continue;
            if (($f['price'] ?? false) && $id && !Auth::can('inventory.products.price') && !Auth::can($e['perm'] . '.edit')) continue;
            if ($f['type'] === 'image') continue;
            if ($f['type'] === 'checkbox') { $data[$n] = isset($_POST[$n]) ? 1 : 0; continue; }
            $v = $_POST[$n] ?? null; $v = is_string($v) ? trim($v) : null;
            if ($v === '' || $v === null) { $v = !empty($f['nullable']) ? null : ($f['default'] ?? (in_array($f['type'], ['number', 'decimal']) ? null : '')); if (!$id && ($f['name'] ?? '') && isset($f['auto'])) $v = null; }
            $data[$n] = $v;
            $r = $f['rules'] ?? ''; if (!empty($f['unique'])) $r .= '|unique:' . $e['table'] . ',' . $n;
            $rules[$n] = ['label' => $f['label'], 'rules' => $r];
        }
        foreach ($e['force'] ?? [] as $k => $v) $data[$k] = $v;
        // select / enum whitelist
        foreach ($e['fields'] as $f) if ($f['type'] === 'select' && isset($f['options']) && isset($data[$f['name']]) && $data[$f['name']] !== null && !array_key_exists($data[$f['name']], $f['options'])) $rules[$f['name']]['rules'] .= '|in:__invalid__';
        $errors = Validator::check($data + $virt, $rules, $id ?: null);
        $errors += EntityHooks::call('validate', $e['slug'], $data + $virt, $id ?: null);
        EntityHooks::$extra = $virt;
        $img = null; $oldImg = $old['image'] ?? null; $hasImage = (bool)array_filter($e['fields'], fn($f) => $f['type'] === 'image');
        if ($hasImage && !$errors) { try { $img = FileUpload::image('image'); } catch (RuntimeException $ex) { $errors['image'] = $ex->getMessage(); } }
        if ($errors) { $_SESSION['_errors'] = $errors; $this->withOld($_POST); redirect('crud/form', ['e' => $e['slug']] + ($id ? ['id' => $id] : [])); }
        foreach ($e['fields'] as $f) {   // numeric normalisation
            if (isset($data[$f['name']]) && in_array($f['type'], ['number', 'decimal'], true) && $data[$f['name']] !== null) $data[$f['name']] = $f['type'] === 'number' ? (int)$data[$f['name']] : round((float)$data[$f['name']], 2);
            if (isset($f['auto']) && ($data[$f['name']] ?? null) === null && !$id) $data[$f['name']] = next_number($f['auto'][0], $f['auto'][1], $f['auto'][2]);
        }
        if ($img) $data['image'] = $img;
        if (!$id) foreach (($e['on_create'] ?? []) as $k => $v) $data[$k] = $v === '@user' ? Auth::id() : $v;
        try {
            $newId = DB::tx(function () use ($e, $id, $old, &$data) {
                EntityHooks::before($e['slug'], $data, $id ?: null);
                if ($id) { DB::update($e['table'], $data, 'id=?', [$id]); $rid = $id; } else { $rid = DB::insert($e['table'], $data); }
                EntityHooks::call('after', $e['slug'], $rid, $old, $data);
                return $rid;
            });
        } catch (PDOException $ex) {
            if ($img) FileUpload::remove($img);
            if ($ex->getCode() === '23000') { $this->withOld($_POST); flash('danger', 'A record with the same unique value already exists.'); redirect('crud/form', ['e' => $e['slug']] + ($id ? ['id' => $id] : [])); }
            throw $ex;
        }
        if ($img && $oldImg) FileUpload::remove($oldImg);
        $diff = []; if ($old) foreach ($data as $k => $v) if (array_key_exists($k, $old) && (string)$old[$k] !== (string)$v) $diff[$k] = [$old[$k], $v];
        audit($id ? 'update' : 'create', $e['table'], $newId, $id ? $diff : ['name' => $data['name'] ?? ($data['code'] ?? '')]);
        flash('success', $e['title'] . ($id ? ' updated.' : ' created.'));
        redirect('crud/list', ['e' => $e['slug']]);
    }

    function action_toggle(): void {
        $this->postOnly(); $e = $this->ent; $this->need($e['perm'] . '.edit'); $id = (int)input('id', 0); $row = $this->find($id);
        [$col, $on, $off] = $e['soft'] ?? ['is_active', 1, 0];
        if ($e['table'] === 'users' || !array_key_exists($col, $row)) $this->abort(400, 'Not supported.');
        $new = ((string)$row[$col] === (string)$on) ? $off : $on;
        DB::update($e['table'], [$col => $new], 'id=?', [$id]);
        audit($new == $on ? 'activate' : 'deactivate', $e['table'], $id);
        flash('success', 'Status updated.'); redirect('crud/list', ['e' => $e['slug']] + array_filter(['q' => input('q'), 'page' => input('page')]));
    }

    function action_delete(): void {
        $this->postOnly(); $e = $this->ent; $this->need($e['perm'] . '.delete'); $id = (int)input('id', 0); $row = $this->find($id);
        if ($this->isLocked($row)) $this->abort(403, 'Record is locked.');
        try {
            if (!empty($e['archive'])) { DB::update($e['table'], ['is_archived' => 1, 'is_active' => 0], 'id=?', [$id]); audit('archive', $e['table'], $id, ['name' => $row['name'] ?? '']); flash('success', 'Archived (hidden from lists, history preserved).'); }
            else { DB::q("DELETE FROM `{$e['table']}` WHERE id=?", [$id]); if (!empty($row['image'])) FileUpload::remove($row['image']); audit('delete', $e['table'], $id, ['name' => $row['name'] ?? ($row['code'] ?? '')]); flash('success', 'Deleted.'); }
        } catch (PDOException $ex) {
            if ($ex->getCode() === '23000') flash('danger', 'This record is in use and cannot be deleted. Deactivate it instead.'); else throw $ex;
        }
        redirect('crud/list', ['e' => $e['slug']]);
    }
}
