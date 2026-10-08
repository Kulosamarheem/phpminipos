<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/settings.php';

/** escape ข้อความก่อนแสดงใน HTML */
function page_esc(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** ส่ง security headers พื้นฐาน — เรียกซ้ำได้ ไม่พังถ้า header ถูกส่งไปแล้ว */
function page_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
}

/**
 * อนุญาตให้หน้านี้ถูกฝังใน iframe ของเว็บเดียวกันได้ (หน้าขายฝังใบเสร็จไว้สั่งพิมพ์)
 * header() เขียนทับค่าเดิมที่ page_security_headers() ส่งไว้ตอน include auth.php
 * ต้องเรียก "หลัง" ตรวจสิทธิ์ผ่านแล้วเท่านั้น — หน้า error จาก render_error_page()
 * จะยังเป็น X-Frame-Options: DENY ตามเดิม จึงไม่มีทางถูกฝังแล้วสั่งพิมพ์ออกมาเป็นกระดาษ
 */
function page_allow_same_origin_frame(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: frame-ancestors 'self'");
}

/**
 * แสดงหน้า error ที่ผู้ใช้อ่านเข้าใจ แทนการ die() ข้อความเปล่า
 * ใช้กับหน้า HTML เท่านั้น — endpoint ที่ตอบ JSON ให้ใช้ cart_json_response()
 */
function render_error_page(
    string $message,
    int $status = 500,
    string $title = 'เกิดข้อผิดพลาด',
    ?string $backUrl = null,
    string $backLabel = 'กลับหน้าหลัก'
): never {
    $backUrl ??= BASE_URL . 'index.php';

    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        page_security_headers();
    }

    $safeTitle = page_esc($title);
    $safeMessage = page_esc($message);
    $safeBackUrl = page_esc($backUrl);
    $safeBackLabel = page_esc($backLabel);
    $stylesheet = page_esc(BASE_URL . 'assets/css/style.css');

    echo <<<HTML
    <!DOCTYPE html>
    <html lang="th">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{$safeTitle} - Mini POS</title>
        <link rel="stylesheet" href="{$stylesheet}">
    </head>
    <body class="auth-page">
        <div class="auth-box error-box">
            <h1>{$safeTitle}</h1>
            <p class="error">{$safeMessage}</p>
            <a class="primary-link" href="{$safeBackUrl}">{$safeBackLabel}</a>
        </div>
    </body>
    </html>
    HTML;

    exit;
}
