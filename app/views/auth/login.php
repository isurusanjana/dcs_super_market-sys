<div class="row justify-content-center mt-5"><div class="col-md-5 col-lg-4">
  <div class="text-center mb-4"><i class="bi bi-shop display-4 text-primary"></i><h3 class="mt-2"><?= e(setting('business_name', cfg('app_name'))) ?></h3><p class="text-muted">Sign in to continue</p></div>
  <div class="card shadow-sm"><div class="card-body p-4">
    <form method="post" class="needs-validation" novalidate autocomplete="off"><?= csrf_field() ?>
      <div class="mb-3"><label class="form-label">Username</label><input name="username" class="form-control" required maxlength="50" autofocus autocomplete="username"><div class="invalid-feedback">Enter your username.</div></div>
      <div class="mb-3"><label class="form-label">Password</label><input type="password" name="password" class="form-control" required autocomplete="current-password"><div class="invalid-feedback">Enter your password.</div></div>
      <button class="btn btn-primary w-100"><i class="bi bi-box-arrow-in-right"></i> Sign in</button>
    </form></div></div>
</div></div>
