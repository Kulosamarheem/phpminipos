<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/page.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    // httponly กัน JS อ่าน cookie, samesite=Lax กัน CSRF ข้ามเว็บ,
    // secure จะเปิดเองเมื่อรันผ่าน HTTPS
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => BASE_URL,
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

page_security_headers();

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return isset($_SESSION['user']);
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: ' . BASE_URL . 'pages/login.php');
        exit;
    }
}

function require_role(string $role): void
{
    require_login();

    if (($_SESSION['user']['role'] ?? null) !== $role) {
        render_error_page(
            'บัญชีของคุณไม่มีสิทธิ์เข้าถึงหน้านี้ (ต้องเป็นผู้ใช้ระดับ ' . $role . ')',
            403,
            'ไม่มีสิทธิ์เข้าถึง'
        );
    }
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function is_valid_csrf_token(string $token): bool
{
    return $token !== ''
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}
