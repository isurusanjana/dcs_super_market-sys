<?php
declare(strict_types=1);
define('APP_ROOT', dirname(__DIR__));
define('APP_PATH', __DIR__);

$cfg = require APP_PATH . '/config.php';
if (is_file(APP_PATH . '/config.local.php')) { $cfg = array_replace_recursive($cfg, require APP_PATH . '/config.local.php'); }
$GLOBALS['cfg'] = $cfg;
function cfg(string $k, $d = null) { return $GLOBALS['cfg'][$k] ?? $d; }

date_default_timezone_set(cfg('timezone', 'UTC'));
mb_internal_encoding('UTF-8');
ini_set('display_errors', cfg('debug') ? '1' : '0');
error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) {
    if (!(error_reporting() & $no)) return false;
    throw new ErrorException($str, 0, $no, $file, $line);
});
set_exception_handler(function (Throwable $e) {
    @file_put_contents(APP_ROOT . '/storage/error.log', date('c') . ' ' . $e . "\n", FILE_APPEND);
    http_response_code(500);
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) { header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => cfg('debug') ? $e->getMessage() : 'Server error']); exit; }
    echo '<h3>Something went wrong</h3><p>' . (cfg('debug') ? htmlspecialchars((string)$e) : 'The error has been logged. Please contact your administrator.') . '</p>';
    exit;
});

spl_autoload_register(function ($c) {
    foreach (['core', 'controllers', 'lib'] as $d) { $f = APP_PATH . "/$d/$c.php"; if (is_file($f)) { require $f; return; } }
});
require APP_PATH . '/core/helpers.php';

// Hardened session
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_name('BSRMSSESS');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_start();

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
