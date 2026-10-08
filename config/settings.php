<?php

declare(strict_types=1);

// ---------------------------------------------------------
// Environment
// ---------------------------------------------------------
// กำหนดผ่าน environment variable APP_ENV ('development' หรือ 'production')
//   PowerShell:  $env:APP_ENV = "production"
//   Bash:        export APP_ENV=production
// ถ้าไม่ตั้งค่าไว้: มีไฟล์ env.production.php → production (โฮสต์ที่ตั้ง env var ไม่ได้ เช่น shared hosting)
//                   ไม่มี → development
define('APP_ENV', getenv('APP_ENV') ?: (is_file(__DIR__ . '/env.production.php') ? 'production' : 'development'));

$envFile = __DIR__ . '/env.' . APP_ENV . '.php';

if (!is_file($envFile)) {
    http_response_code(500);
    die("ไม่พบไฟล์ config สำหรับ environment '" . APP_ENV . "' (คาดว่าจะมี: {$envFile})");
}

require $envFile;

// ---------------------------------------------------------
// Business rules
// ---------------------------------------------------------
define('VAT_RATE', 0.07);

// จำกัดสต็อกไม่ให้เข้าใกล้ขอบเขตของคอลัมน์ INT
// ใช้ร่วมกันทั้งตอนเพิ่มสินค้า (product_add.php) และแก้ไขสินค้า (product_update.php)
define('PRODUCT_MAX_STOCK', 1000000);

// พักบิล (held_bills)
// จำนวนบิลที่พักค้างไว้พร้อมกันได้สูงสุด — กันรายการล้นจอและกันข้อมูลค้างสะสม
define('HELD_BILL_MAX', 20);
// บิลที่พักไว้นานเกินกี่ชั่วโมงจึงติดป้ายเตือนว่าค้าง (ไม่ได้ลบอัตโนมัติ)
define('HELD_BILL_STALE_HOURS', 12);
// ความยาวหมายเหตุสูงสุด นับเป็น "ตัวอักษร" ไม่ใช่ไบต์ (ข้อความไทยกินหลายไบต์ต่อตัว)
define('HELD_BILL_NOTE_MAX', 100);

// ---------------------------------------------------------
// Store info (แสดงบนหัวใบเสร็จ) — แก้เป็นข้อมูลจริงของร้าน
// ค่าว่าง '' = ไม่แสดงบรรทัดนั้นบนใบเสร็จ
// ---------------------------------------------------------
define('STORE_NAME', 'ชื่อร้านค้าตัวอย่าง');
define('STORE_ADDRESS', '123 ถนนตัวอย่าง แขวง/ตำบล เขต/อำเภอ จังหวัด 10000');
define('STORE_PHONE', '02-000-0000');
define('STORE_TAX_ID', '');
define('STORE_FOOTER_NOTE', 'ขอบคุณที่ใช้บริการ');

// ---------------------------------------------------------
// App
// ---------------------------------------------------------
define('SESSION_NAME', 'pos_session');

// ---------------------------------------------------------
// Error handling
// ไฟล์นี้ถูก include จากทุก entry point จึงเป็นที่เดียวที่ตั้ง handler กลางได้
// production: ผู้ใช้เห็นแต่ข้อความสรุป รายละเอียดจริงลง error log เท่านั้น
// ---------------------------------------------------------
function app_is_development(): bool
{
    return APP_ENV === 'development';
}

/** จบ request ด้วยข้อความ error ที่ผู้ใช้อ่านเข้าใจ พร้อมบันทึกรายละเอียดจริงลง log */
function app_fail(string $logMessage, string $userMessage = 'ระบบเกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง'): never
{
    error_log('[POS] ' . $logMessage);

    if (app_is_development()) {
        $userMessage .= ' [dev] ' . $logMessage;
    }

    // request จาก fetch() ของ pos.js/products.js ต้องได้ JSON กลับ ไม่ใช่หน้า HTML
    if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['success' => false, 'message' => $userMessage], JSON_UNESCAPED_UNICODE);
        exit;
    }

    require_once __DIR__ . '/../includes/page.php';
    render_error_page($userMessage, 500);
}

set_exception_handler(static function (Throwable $e): void {
    app_fail(sprintf(
        '%s: %s @ %s:%d',
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));
});

// กัน fatal error กลายเป็นหน้าขาวเปล่าตอน display_errors ถูกปิดใน production
register_shutdown_function(static function (): void {
    $error = error_get_last();
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    if ($error !== null && in_array($error['type'], $fatalTypes, true)) {
        app_fail(sprintf('Fatal: %s @ %s:%d', $error['message'], $error['file'], $error['line']));
    }
});
