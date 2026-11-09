<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$root = dirname(__DIR__);

if ($path === '/__test-login') {
    $app = require $root.'/app/bootstrap.php';
    $role = preg_replace('/[^a-z_]/','',(string)($_GET['role'] ?? 'system_admin')) ?: 'system_admin';
    $userId = (int) $app->database()->scalar(
        "SELECT TOP 1 u.id FROM users u JOIN user_role_scopes urs ON urs.user_id=u.id JOIN roles r ON r.id=urs.role_id WHERE u.status='active' AND r.code=:role ORDER BY u.id",
        ['role'=>$role]
    );
    $_SESSION['user_id'] = $userId;
    header('Location: /', true, 302);
    exit;
}

$publicFile = dirname(__DIR__).'/public'.$path;
if ($path !== '/' && is_file($publicFile)) {
    return false;
}

require dirname(__DIR__).'/public/index.php';
