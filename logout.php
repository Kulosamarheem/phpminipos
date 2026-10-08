<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$_SESSION = [];

// ลบ cookie ฝั่ง browser ด้วย ไม่งั้น session id เดิมยังถูกส่งกลับมาทุก request
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'] ?: 'Lax',
    ]);
}

session_destroy();

header('Location: ' . BASE_URL . 'login.php');
exit;
