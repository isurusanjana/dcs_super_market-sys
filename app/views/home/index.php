<div class="d-flex justify-content-between align-items-center mb-4">
  <div><h3 class="mb-0">Welcome, <?= e(Auth::user()['full_name']) ?></h3><div class="text-muted">Choose a system to continue</div></div>
  <div class="d-flex gap-2"><a class="btn btn-outline-primary btn-sm" href="<?= url('help/index') ?>"><i class="bi bi-question-circle"></i> User Guide</a><form method="post" action="<?= url('auth/logout') ?>"><?= csrf_field() ?><button class="btn btn-outline-danger btn-sm"><i class="bi bi-box-arrow-right"></i> Sign out</button></form></div>
</div>
<?php if (!$tiles): ?><div class="alert alert-warning">Your account has no module permissions yet. Please contact an administrator.</div><?php endif; ?>
<div class="row g-4">
<?php foreach ($tiles as $k => [$name, $icon, $desc, $color]): ?>
  <div class="col-md-4"><form class="h-100" method="post" action="<?= url('home/enter') ?>"><?= csrf_field() ?><input type="hidden" name="system" value="<?= e($k) ?>">
    <button class="tile card border-0 shadow-sm w-100 text-start h-100"><div class="card-body p-4">
      <div class="tile-icon bg-<?= $color ?>-subtle text-<?= $color ?>-emphasis"><i class="bi <?= $icon ?>"></i></div>
      <h4 class="mt-3"><?= e($name) ?></h4><p class="text-muted mb-0"><?= e($desc) ?></p></div></button></form></div>
<?php endforeach; ?>
</div>
