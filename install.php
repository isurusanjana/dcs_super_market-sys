<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
if (is_file(APP_PATH . '/config.local.php')) { http_response_code(403); exit('Already installed. Delete app/config.local.php to reinstall (this will not drop data).'); }
$errors = []; $done = false; $v = $_POST;
if (is_post()) {
    $t = $_POST['_csrf'] ?? '';
    if (!hash_equals($_SESSION['_csrf'] ?? '', (string)$t)) $errors[] = 'Security token mismatch. Reload the page.';
    $h = trim($v['db_host'] ?? ''); $port = (int)($v['db_port'] ?? 3306); $dn = trim($v['db_name'] ?? ''); $du = trim($v['db_user'] ?? ''); $dp = (string)($v['db_pass'] ?? '');
    $au = trim($v['admin_user'] ?? ''); $af = trim($v['admin_name'] ?? ''); $ap = (string)($v['admin_pass'] ?? ''); $bn = trim($v['business'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_]+$/', $dn)) $errors[] = 'Database name may only contain letters, digits and underscore.';
    if (!$h || !$du) $errors[] = 'Database host and user are required.';
    if (Validator::check(['u' => $au], ['u' => ['label' => 'Admin username', 'rules' => 'required|username']])) $errors[] = 'Admin username must be 3-50 chars (letters, digits, dot, underscore).';
    if (!$af) $errors[] = 'Admin full name is required.';
    if ($e = Auth::passwordError($ap)) $errors[] = $e;
    if ($ap !== (string)($v['admin_pass2'] ?? '')) $errors[] = 'Admin passwords do not match.';
    if (!$bn) $errors[] = 'Business name is required.';
    if (!$errors) {
        try {
            $pdo = new PDO("mysql:host=$h;port=$port;charset=utf8mb4", $du, $dp, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dn` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $GLOBALS['cfg']['db'] = ['host' => $h, 'port' => $port, 'name' => $dn, 'user' => $du, 'pass' => $dp];
            $pdo->exec("USE `$dn`");
            if ((int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=" . $pdo->quote($dn) . " AND table_name='users'")->fetchColumn() > 0) throw new RuntimeException('This database already contains a system. Use an empty database.');
            $pdo->exec(file_get_contents(APP_ROOT . '/database/schema.sql'));
            Seeder::permissions(); Seeder::roles(); Seeder::defaults($bn); Seeder::admin($au, $af, $ap);
            if (!empty($v['demo'])) Seeder::demo();
            $conf = "<?php\nreturn " . var_export(['db' => ['host' => $h, 'port' => $port, 'name' => $dn, 'user' => $du, 'pass' => $dp]], true) . ";\n";
            if (file_put_contents(APP_PATH . '/config.local.php', $conf, LOCK_EX) === false) throw new RuntimeException('Cannot write app/config.local.php - check folder permissions.');
            @chmod(APP_PATH . '/config.local.php', 0640);
            $done = true;
        } catch (Throwable $ex) { $errors[] = 'Installation failed: ' . $ex->getMessage(); }
    }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Install</title>
<link href="<?= asset('css/bootstrap@5.3.3/dist/css/bootstrap.min.css') ?>" rel="stylesheet"></head><body class="bg-body-tertiary"><div class="container py-5" style="max-width:720px">
<h3 class="mb-1">DCS Supermarket RMS - Installer</h3><p class="text-muted">Creates the database, roles/permissions and the first administrator.</p>
<?php if ($done): ?><div class="alert alert-success"><b>Installation complete.</b> <a href="index.php">Go to login</a>. For safety, delete or rename <code>install.php</code>.</div><?php else: ?>
<?php foreach ($errors as $er): ?><div class="alert alert-danger"><?= htmlspecialchars($er) ?></div><?php endforeach; ?>
<form method="post" class="card card-body shadow-sm needs-validation" novalidate><?= csrf_field() ?>
<h6>Database</h6><div class="row g-2 mb-3">
 <div class="col-md-5"><input class="form-control" name="db_host" placeholder="Host" value="<?= htmlspecialchars($v['db_host'] ?? '127.0.0.1') ?>" required></div>
 <div class="col-md-3"><input class="form-control" name="db_port" placeholder="Port" value="<?= htmlspecialchars($v['db_port'] ?? '3306') ?>" required></div>
 <div class="col-md-4"><input class="form-control" name="db_name" placeholder="Database" value="<?= htmlspecialchars($v['db_name'] ?? 'bsrms') ?>" required></div>
 <div class="col-md-6"><input class="form-control" name="db_user" placeholder="User" value="<?= htmlspecialchars($v['db_user'] ?? 'root') ?>" required></div>
 <div class="col-md-6"><input type="password" class="form-control" name="db_pass" placeholder="Password"></div></div>
<h6>Business &amp; administrator</h6><div class="row g-2 mb-3">
 <div class="col-12"><input class="form-control" name="business" placeholder="Business name" value="<?= htmlspecialchars($v['business'] ?? '') ?>" required></div>
 <div class="col-md-6"><input class="form-control" name="admin_name" placeholder="Admin full name" value="<?= htmlspecialchars($v['admin_name'] ?? '') ?>" required></div>
 <div class="col-md-6"><input class="form-control" name="admin_user" placeholder="Admin username" value="<?= htmlspecialchars($v['admin_user'] ?? 'admin') ?>" required></div>
 <div class="col-md-6"><input type="password" class="form-control" name="admin_pass" placeholder="Password (8+, Aa1)" required></div>
 <div class="col-md-6"><input type="password" class="form-control" name="admin_pass2" placeholder="Confirm password" required></div></div>
<div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="demo" value="1" id="demo" <?= !empty($v['demo']) ? 'checked' : '' ?>><label class="form-check-label" for="demo">Load demo data (a sample supermarket: fresh produce, groceries, promotions, a gift card, and users manager / cashier / clerk with password <code>Demo@1234</code>)</label></div>
<button class="btn btn-primary">Install</button></form>
<script>document.querySelectorAll('form.needs-validation').forEach(f=>f.addEventListener('submit',e=>{if(!f.checkValidity()){e.preventDefault();}f.classList.add('was-validated');}));</script>
<?php endif; ?></div></body></html>
