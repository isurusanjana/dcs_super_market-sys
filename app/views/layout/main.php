<?php
$sys = $_SESSION['system'] ?? null;
$menus = require APP_PATH . '/menus.php';
$systems = HomeController::systems(); $user = Auth::user();
$cur = $_GET['r'] ?? ''; $curE = $_GET['e'] ?? '';
?>
<!doctype html>
<html lang="en" data-bs-theme="light"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> | <?= e(cfg('app_name')) ?></title>
<link href="<?= asset('css/bootstrap@5.3.3/dist/css/bootstrap.min.css') ?>" rel="stylesheet">
<link href="<?= asset('css/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css') ?>" rel="stylesheet">
<link href="<?= asset('css/app.css') ?>" rel="stylesheet">
</head><body>
<nav class="navbar navbar-dark bg-dark sticky-top px-3 no-print">
  <button class="btn btn-outline-light d-lg-none me-2" data-bs-toggle="offcanvas" data-bs-target="#side"><i class="bi bi-list"></i></button>
  <a class="navbar-brand" href="<?= url('home/index') ?>"><i class="bi bi-shop me-1"></i><?= e(setting('business_name', cfg('app_name'))) ?></a>
  <?php if ($sys): ?><span class="badge text-bg-<?= $systems[$sys][3] ?> me-auto"><i class="bi <?= $systems[$sys][1] ?>"></i> <?= e($systems[$sys][0]) ?></span><?php endif; ?>
  <div class="dropdown ms-auto"><button class="btn btn-sm btn-outline-light dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-person-circle"></i> <?= e($user['full_name']) ?></button>
    <ul class="dropdown-menu dropdown-menu-end">
      <li><a class="dropdown-item" href="<?= url('home/index') ?>"><i class="bi bi-grid"></i> Switch system</a></li>
      <li><a class="dropdown-item" href="<?= url('help/index') ?>"><i class="bi bi-question-circle"></i> User Guide</a></li>
      <li><a class="dropdown-item" href="<?= url('auth/password') ?>"><i class="bi bi-key"></i> Change password</a></li>
      <li><hr class="dropdown-divider"></li>
      <li><form method="post" action="<?= url('auth/logout') ?>"><?= csrf_field() ?><button class="dropdown-item text-danger"><i class="bi bi-box-arrow-right"></i> Sign out</button></form></li>
    </ul></div>
</nav>
<div class="d-flex">
  <div class="offcanvas-lg offcanvas-start sidebar bg-body border-end no-print" tabindex="-1" id="side">
    <div class="p-2">
      <?php if ($sys && isset($menus[$sys])): foreach ($menus[$sys] as $group => $items):
        $vis = array_filter($items, fn($i) => Auth::can($i[2])); if (!$vis) continue; ?>
        <div class="menu-group"><?= e($group) ?></div>
        <?php foreach ($vis as [$label, $route, $perm, $icon]):
          parse_str(str_contains($route, '&') ? substr($route, strpos($route, '&') + 1) : '', $q); $base = explode('&', $route)[0];
          $active = ($cur === $base) && (!isset($q['e']) || $q['e'] === $curE); ?>
          <a class="side-link <?= $active ? 'active' : '' ?>" href="<?= base_url() . '/index.php?r=' . $route ?>"><i class="bi <?= $icon ?>"></i> <?= e($label) ?></a>
        <?php endforeach; endforeach; endif; ?>
    </div>
  </div>
  <main class="flex-grow-1 p-3 p-lg-4">
    <?php foreach (flashes() as [$t, $m]): ?><div class="alert alert-<?= e($t) ?> alert-dismissible fade show no-print" role="alert"><?= e($m) ?><button class="btn-close" data-bs-dismiss="alert"></button></div><?php endforeach; ?>
    <?= $content ?>
    <!-- <div class="bottom-div text-center text-muted mt-4 no-print"><small><b>Powered by <a href="https://dcs.ct.ws/" target="_blank">DCS Tech</a></b>  </small></div> -->
  </main>
</div>
<script src="<?= asset('js/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= asset('js/chart.js@4.4.3/dist/chart.umd.min.js') ?>"></script>
<script>window.APP={csrf:<?= json_encode(csrf_token()) ?>,base:<?= json_encode(base_url() . '/index.php') ?>,currency:<?= json_encode(setting('currency', 'Rs.')) ?>};</script>
<script src="<?= asset('js/app.js') ?>"></script>
<?= $scripts ?? '' ?>
</body></html>
