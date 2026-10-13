<!doctype html>
<html lang="en" data-bs-theme="light"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> | <?= e(cfg('app_name')) ?></title>
 <link href="<?= asset('css/bootstrap@5.3.3/dist/css/bootstrap.min.css') ?>" rel="stylesheet">
<link href="<?= asset('css/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css') ?>" rel="stylesheet">
<!-- <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"> -->
<!-- <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet"> -->
<link href="<?= asset('css/app.css') ?>" rel="stylesheet">
</head><body class="bg-body-tertiary">
<div class="container py-4">
<?php foreach (flashes() as [$t, $m]): ?><div class="alert alert-<?= e($t) ?> alert-dismissible fade show" role="alert"><?= e($m) ?><button class="btn-close" data-bs-dismiss="alert"></button></div><?php endforeach; ?>
<?= $content ?>
</div>
<script src="<?= asset('js/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js') ?>"></script>
<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->
<script src="<?= asset('js/app.js') ?>"></script>
</body></html>
