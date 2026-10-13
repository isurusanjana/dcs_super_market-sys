<div class="row"><div class="col-md-5"><div class="card card-body"><h5>Reset password - <?= e($u['username']) ?></h5>
<form method="post" class="needs-validation" novalidate autocomplete="off"><?= csrf_field() ?>
<?php foreach (['password' => 'New temporary password', 'confirm' => 'Confirm'] as $k => $l): ?><div class="mb-3"><label class="form-label"><?= $l ?></label><input type="password" name="<?= $k ?>" class="form-control <?= isset($errors[$k]) ? 'is-invalid' : '' ?>" required autocomplete="new-password"><div class="invalid-feedback"><?= e($errors[$k] ?? 'Required.') ?></div></div><?php endforeach; ?>
<button class="btn btn-primary once">Reset</button> <a class="btn btn-link" href="<?= url('users/index') ?>">Cancel</a></form></div></div></div>
