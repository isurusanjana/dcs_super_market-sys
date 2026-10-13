<div class="row justify-content-center"><div class="col-md-6 col-lg-4"><div class="card shadow-sm"><div class="card-body">
  <h5 class="mb-3"><i class="bi bi-key"></i> Change password</h5>
  <form method="post" class="needs-validation" novalidate><?= csrf_field() ?>
    <?php foreach (['current' => 'Current password', 'new' => 'New password', 'confirm' => 'Confirm new password'] as $k => $l): ?>
      <div class="mb-3"><label class="form-label"><?= $l ?></label><input type="password" name="<?= $k ?>" class="form-control <?= isset($errors[$k]) ? 'is-invalid' : '' ?>" required autocomplete="<?= $k === 'current' ? 'current-password' : 'new-password' ?>"><div class="invalid-feedback"><?= e($errors[$k] ?? 'Required.') ?></div></div>
    <?php endforeach; ?>
    <div class="form-text mb-3">At least 8 characters with upper-case, lower-case and a digit.</div>
    <button class="btn btn-primary">Update password</button>
  </form></div></div></div></div>
