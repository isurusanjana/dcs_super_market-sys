<?php $id = $role['id'] ?? 0; $o = $_SESSION['_old'] ?? []; $name = $o['name'] ?? ($role['name'] ?? ''); $desc = $o['description'] ?? ($role['description'] ?? ''); $super = !empty($role['is_super']);
$active = isset($o['name']) ? isset($o['is_active']) : ($role['is_active'] ?? 1); ?>
<div class="d-flex justify-content-between mb-3"><h4><?= e($title) ?></h4><a class="btn btn-outline-secondary" href="<?= url('roles/index') ?>"><i class="bi bi-arrow-left"></i> Back</a></div>
<form method="post" action="<?= url('roles/save') ?>" class="needs-validation" novalidate><?= csrf_field() ?><?php if ($id): ?><input type="hidden" name="id" value="<?= (int)$id ?>"><?php endif; ?>
<div class="card card-body mb-3"><div class="row g-3">
  <div class="col-md-4"><label class="form-label required-star">Role name</label><input name="name" class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>" value="<?= e($name) ?>" required maxlength="60"><div class="invalid-feedback"><?= e($errors['name'] ?? 'Required.') ?></div></div>
  <div class="col-md-6"><label class="form-label">Description</label><input name="description" class="form-control" value="<?= e($desc) ?>" maxlength="255"></div>
  <div class="col-md-2"><div class="form-check form-switch mt-4"><input class="form-check-input" type="checkbox" name="is_active" id="ra" value="1" <?= $active ? 'checked' : '' ?> <?= $super ? 'disabled' : '' ?>><label class="form-check-label" for="ra">Active</label></div></div></div></div>
<?php if ($super): ?><div class="alert alert-info">The administrator role always has every permission.</div>
<?php elseif (Auth::can('admin.roles.assign_permission')): ?>
<div class="small text-muted mb-2">Access path: User &rarr; Role &rarr; Permission &rarr; Module / Feature &rarr; Action. A module tile only appears at login if the role has at least one permission in that module.</div>
<?php foreach ($defs as $mod => $m): ?><div class="card mb-3"><div class="card-header d-flex justify-content-between align-items-center"><b><?= e($m['label']) ?></b>
  <div class="form-check"><input class="form-check-input mod-all" type="checkbox" data-mod="<?= e($mod) ?>" id="m_<?= e($mod) ?>"><label class="form-check-label small" for="m_<?= e($mod) ?>">Enable all module access</label></div></div>
  <div class="table-responsive"><table class="table table-sm mb-0 align-middle"><tbody>
  <?php foreach ($m['features'] as $feat => [$flabel, $actions]): ?><tr><td style="width:240px"><?= e($flabel) ?><br><a href="#" class="small feat-all" data-feat="<?= e("$mod.$feat") ?>">toggle row</a></td>
    <td><?php foreach ($actions as $act => $alabel): $code = "$mod.$feat.$act"; ?><div class="form-check form-check-inline"><input class="form-check-input perm perm-<?= e($mod) ?>" data-feat="<?= e("$mod.$feat") ?>" type="checkbox" name="perm[<?= e($code) ?>]" value="1" id="p_<?= e($code) ?>" <?= in_array($code, $granted, true) ? 'checked' : '' ?>><label class="form-check-label small" for="p_<?= e($code) ?>"><?= e($alabel) ?></label></div><?php endforeach; ?></td></tr><?php endforeach; ?>
  </tbody></table></div></div><?php endforeach; ?>
<?php endif; ?>
<button class="btn btn-primary once"><i class="bi bi-check-lg"></i> Save role</button></form>
<?php ob_start(); ?><script>
document.querySelectorAll('.mod-all').forEach(function(c){c.addEventListener('change',function(){document.querySelectorAll('.perm-'+c.dataset.mod).forEach(function(p){p.checked=c.checked;});});});
document.querySelectorAll('.feat-all').forEach(function(a){a.addEventListener('click',function(ev){ev.preventDefault();var l=document.querySelectorAll('.perm[data-feat="'+a.dataset.feat+'"]');var all=Array.prototype.every.call(l,function(x){return x.checked;});l.forEach(function(x){x.checked=!all;});});});
</script><?php $scripts = ob_get_clean(); ?>
