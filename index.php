<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
if (!is_file(APP_PATH . '/config.local.php')) { header('Location: install.php'); exit; }

$route = (string)($_GET['r'] ?? 'home/index');
$parts = explode('/', $route, 2);
$c = $parts[0]; $a = $parts[1] ?? 'index';
if (!preg_match('/^[a-z][a-z0-9_]*$/', $c) || !preg_match('/^[a-z][a-z0-9_]*$/', $a)) { http_response_code(404); exit('Not found'); }
$class = str_replace(' ', '', ucwords(str_replace('_', ' ', $c))) . 'Controller';
if (!class_exists($class) || !is_subclass_of($class, 'Controller')) { http_response_code(404); exit('Not found'); }
(new $class())->run($a);
