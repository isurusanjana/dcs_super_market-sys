<?php $o = $_SESSION['_old'] ?? []; $v = fn($k, $d = '') => $o[$k] ?? ($po[$k] ?? $d); ?>
<h4 class="mb-3"><?= e($title) ?></h4>
<form method="post" action="<?= url('purchasing/save') ?>" class="needs-validation" novalidate><?= csrf_field() ?><?php if ($po): ?><input type="hidden" name="id" value="<?= (int)$po['id'] ?>"><?php endif; ?>
<div class="card card-body mb-3"><div class="row g-3">
<div class="col-md-4"><label class="form-label required-star">Supplier</label><select name="supplier_id" class="form-select" required><option value="">--</option><?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $v('supplier_id') == $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
<div class="col-md-4"><label class="form-label required-star">Deliver to warehouse</label><select name="warehouse_id" class="form-select" required><?php foreach ($warehouses as $w): ?><option value="<?= (int)$w['id'] ?>" <?= $v('warehouse_id') == $w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option><?php endforeach; ?></select></div>
<div class="col-md-2"><label class="form-label required-star">Order date</label><input type="date" name="order_date" class="form-control" value="<?= e($v('order_date', date('Y-m-d'))) ?>" required></div>
<div class="col-md-2"><label class="form-label">Expected</label><input type="date" name="expected_date" class="form-control" value="<?= e($v('expected_date')) ?>"></div>
<div class="col-12"><label class="form-label">Notes</label><input name="notes" class="form-control" maxlength="255" value="<?= e($v('notes')) ?>"></div></div></div>
<?php require APP_PATH . '/views/partials/lines.php'; ?>
<button class="btn btn-primary once"><i class="bi bi-save"></i> Save as draft</button> <a class="btn btn-link" href="<?= url('purchasing/index') ?>">Cancel</a></form>
