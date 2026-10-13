<?php
// Default configuration. Overridden by app/config.local.php (written by install.php).
return [
    'app_name' => 'Supermarket RMS',
    'debug'    => false,
    'timezone' => 'Asia/Colombo',
    'db' => ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'bsrms', 'user' => 'root', 'pass' => ''],
    'session_timeout' => 1800,      // idle seconds before auto logout
    'max_failed_logins' => 5,
    'lockout_minutes' => 15,
    'upload_max_bytes' => 2097152,
];
