<?php

declare(strict_types=1);

/**
 * ทดสอบ race condition ของสต็อก (เฟส 6)
 *
 * ยิง checkout.php จาก 2 session พร้อมกันบนสินค้าที่มีสต็อกเหลือ 1 ชิ้น
 * แล้วตรวจว่ามีบิลสำเร็จเพียงบิลเดียวและสต็อกไม่ติดลบ
 *
 * วิธีใช้ (ต้องเปิดเว็บเซิร์ฟเวอร์ไว้ก่อน เช่นด้วย start.bat):
 *   php tests/concurrent_checkout.php
 *   php tests/concurrent_checkout.php http://localhost:8000/
 *
 * ตั้งค่าเพิ่มเติมผ่าน environment variable ได้:
 *   POS_TEST_URL, POS_TEST_USER, POS_TEST_PASS
 *
 * ข้อจำกัด: PHP built-in server (php -S) รับ request ทีละรายการบน Windows
 * จึงพิสูจน์ได้แค่ว่า "ตรวจสต็อกซ้ำก่อนตัด" ทำงานถูก แต่ไม่ได้ทดสอบ SELECT ... FOR UPDATE จริง
 * ถ้าต้องการทดสอบการชนกันจริงให้รันผ่าน Apache/XAMPP (เช่น http://localhost/pos/)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("สคริปต์นี้รันได้จาก command line เท่านั้น\n");
}

require_once __DIR__ . '/../config/db.php';

$baseUrl = rtrim($argv[1] ?? getenv('POS_TEST_URL') ?: 'http://localhost:8000', '/') . '/';
$username = getenv('POS_TEST_USER') ?: 'admin';
$password = getenv('POS_TEST_PASS') ?: 'admin1234';

const TEST_BARCODE = 'TEST-RACE-0001';
const TEST_PRICE = '10.00';

$failures = [];

function info(string $message): void
{
    echo $message . "\n";
}

function cookie_jar(string $name): string
{
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pos_test_' . $name . '_' . getmypid() . '.txt';
    if (is_file($path)) {
        unlink($path);
    }

    return $path;
}

function curl_handle(string $url, string $jar): CurlHandle
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Accept: application/json, text/html'],
    ]);

    return $ch;
}

/** @return array{status:int, body:string} */
function http_get(string $url, string $jar): array
{
    $ch = curl_handle($url, $jar);
    $body = curl_exec($ch);

    if ($body === false) {
        $error = curl_error($ch);
        curl_close($ch);
        fwrite(STDERR, "เชื่อมต่อ {$url} ไม่ได้: {$error}\nตรวจสอบว่าเว็บเซิร์ฟเวอร์ทำงานอยู่ (start.bat)\n");
        exit(1);
    }

    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return ['status' => $status, 'body' => (string) $body];
}

/** @return array{status:int, body:string} */
function http_post_form(string $url, string $jar, array $fields): array
{
    $ch = curl_handle($url, $jar);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return ['status' => $status, 'body' => (string) $body];
}

/** @return array{status:int, body:string} */
function http_post_json(string $url, string $jar, array $payload): array
{
    $ch = curl_handle($url, $jar);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Content-Type: application/json',
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return ['status' => $status, 'body' => (string) $body];
}

/** อ่าน header Server เพื่อเตือนเมื่อรันบน built-in server ที่ตอบทีละ request */
function server_software(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_NOBODY => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = (string) curl_exec($ch);
    curl_close($ch);

    return preg_match('/^Server:\s*(.+)$/mi', $response, $matches) === 1 ? trim($matches[1]) : '';
}

function extract_attribute(string $html, string $pattern): ?string
{
    return preg_match($pattern, $html, $matches) === 1 ? $matches[1] : null;
}

/** login แล้วคืน CSRF token ของ session (อ่านจาก meta ในหน้า pos.php) */
function login(string $baseUrl, string $jar, string $username, string $password): string
{
    $loginPage = http_get($baseUrl . 'pages/login.php', $jar);
    $loginToken = extract_attribute(
        $loginPage['body'],
        '/name="csrf_token"\s+value="([^"]+)"/'
    );

    if ($loginToken === null) {
        fwrite(STDERR, "อ่าน CSRF token จากหน้า login ไม่ได้\n");
        exit(1);
    }

    $result = http_post_form($baseUrl . 'pages/login.php', $jar, [
        'csrf_token' => $loginToken,
        'username' => $username,
        'password' => $password,
    ]);

    if ($result['status'] !== 302) {
        fwrite(STDERR, "เข้าสู่ระบบไม่สำเร็จ (HTTP {$result['status']}) — ตรวจสอบ POS_TEST_USER / POS_TEST_PASS\n");
        exit(1);
    }

    $posPage = http_get($baseUrl . 'pages/pos.php', $jar);
    $cartToken = extract_attribute(
        $posPage['body'],
        '/name="cart-csrf-token"\s+content="([^"]+)"/'
    );

    if ($cartToken === null) {
        fwrite(STDERR, "อ่าน CSRF token ของตะกร้าจากหน้า pos.php ไม่ได้\n");
        exit(1);
    }

    return $cartToken;
}

// ---------------------------------------------------------
// 1. เตรียมสินค้าทดสอบให้เหลือสต็อก 1 ชิ้น
//    ใช้แถวเดิมซ้ำทุกครั้ง เพราะ order_items อ้างถึงสินค้าแบบ RESTRICT จึงลบทิ้งไม่ได้
// ---------------------------------------------------------
$stmt = $pdo->prepare('SELECT id FROM products WHERE barcode = ?');
$stmt->execute([TEST_BARCODE]);
$productId = $stmt->fetchColumn();

if ($productId === false) {
    $pdo->prepare(
        'INSERT INTO products (barcode, name, price, stock_qty, is_active) VALUES (?, ?, ?, 1, 1)'
    )->execute([TEST_BARCODE, 'สินค้าทดสอบ race condition', TEST_PRICE]);
    $productId = (int) $pdo->lastInsertId();
} else {
    $productId = (int) $productId;
    $pdo->prepare('UPDATE products SET price = ?, stock_qty = 1, is_active = 1 WHERE id = ?')
        ->execute([TEST_PRICE, $productId]);
}

$soldBefore = (int) $pdo->query(
    'SELECT COUNT(*) FROM order_items WHERE product_id = ' . $productId
)->fetchColumn();

info("สินค้าทดสอบ: id={$productId} barcode=" . TEST_BARCODE . ' stock_qty=1');

// ---------------------------------------------------------
// 2. login 2 session แยก cookie jar และเพิ่มสินค้าลงตะกร้าทั้งคู่
// ---------------------------------------------------------
$sessions = [];

foreach (['a', 'b'] as $name) {
    $jar = cookie_jar($name);
    $token = login($baseUrl, $jar, $username, $password);

    $added = http_post_json($baseUrl . 'api/cart_add.php', $jar, [
        'barcode' => TEST_BARCODE,
        'csrf_token' => $token,
    ]);

    if ($added['status'] !== 200) {
        fwrite(STDERR, "session {$name}: เพิ่มสินค้าลงตะกร้าไม่สำเร็จ (HTTP {$added['status']}) {$added['body']}\n");
        exit(1);
    }

    $sessions[$name] = ['jar' => $jar, 'token' => $token];
}

info('เตรียม 2 session พร้อมตะกร้าที่มีสินค้าเดียวกันเรียบร้อย');

// ---------------------------------------------------------
// 3. ยิง checkout พร้อมกัน
// ---------------------------------------------------------
$multi = curl_multi_init();
$handles = [];

foreach ($sessions as $name => $session) {
    $ch = curl_handle($baseUrl . 'api/checkout.php', $session['jar']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'csrf_token' => $session['token'],
        'payment_method' => 'cash',
        'discount_amount' => '0',
        'received_amount' => '100.00',
    ], JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Content-Type: application/json',
    ]);

    $handles[$name] = $ch;
    curl_multi_add_handle($multi, $ch);
}

$running = null;
do {
    curl_multi_exec($multi, $running);
    curl_multi_select($multi);
} while ($running > 0);

$results = [];
foreach ($handles as $name => $ch) {
    $results[$name] = [
        'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
        'body' => (string) curl_multi_getcontent($ch),
    ];
    curl_multi_remove_handle($multi, $ch);
    curl_close($ch);
}
curl_multi_close($multi);

foreach ($sessions as $session) {
    if (is_file($session['jar'])) {
        unlink($session['jar']);
    }
}

// ---------------------------------------------------------
// 4. ตรวจผล
// ---------------------------------------------------------
$successes = 0;
$stockErrors = 0;

foreach ($results as $name => $result) {
    $payload = json_decode($result['body'], true);
    $message = is_array($payload) ? (string) ($payload['message'] ?? '') : trim($result['body']);
    info("session {$name}: HTTP {$result['status']} — {$message}");

    if ($result['status'] === 200 && is_array($payload) && ($payload['success'] ?? false) === true) {
        $successes++;
    } elseif ($result['status'] === 422 && str_contains($message, 'สต็อกไม่เพียงพอ')) {
        $stockErrors++;
    }
}

$stockAfter = (int) $pdo->query(
    'SELECT stock_qty FROM products WHERE id = ' . $productId
)->fetchColumn();

$soldAfter = (int) $pdo->query(
    'SELECT COUNT(*) FROM order_items WHERE product_id = ' . $productId
)->fetchColumn();

if ($successes !== 1) {
    $failures[] = "ต้องมี checkout สำเร็จ 1 รายการ แต่ได้ {$successes} รายการ";
}

if ($stockErrors !== 1) {
    $failures[] = "ต้องมีรายการที่ถูกปฏิเสธเพราะสต็อกไม่พอ 1 รายการ แต่ได้ {$stockErrors} รายการ";
}

if ($stockAfter !== 0) {
    $failures[] = "สต็อกหลังทดสอบต้องเป็น 0 แต่ได้ {$stockAfter}" . ($stockAfter < 0 ? ' (ติดลบ!)' : '');
}

if ($soldAfter - $soldBefore !== 1) {
    $failures[] = 'ต้องมีรายการขายเพิ่ม 1 แถว แต่เพิ่มขึ้น ' . ($soldAfter - $soldBefore) . ' แถว';
}

info('');
info("สต็อกคงเหลือหลังทดสอบ: {$stockAfter}");
info('รายการขายของสินค้านี้เพิ่มขึ้น: ' . ($soldAfter - $soldBefore) . ' แถว');
info('');

// php -S ไม่ส่ง header Server ออกมา ต่างจาก Apache/nginx จึงใช้เป็นตัวบอกว่ารันบน built-in server
$server = server_software($baseUrl . 'pages/login.php');
if ($server === '' || str_contains($server, 'Development Server')) {
    info('หมายเหตุ: ดูเหมือนรันบน PHP built-in server ซึ่งรับ request ทีละรายการ');
    info('          ผลลัพธ์นี้ยืนยันว่าการตรวจสต็อกซ้ำก่อนตัดทำงานถูกต้อง');
    info('          แต่ยังไม่ได้ทดสอบการชนกันจริง — ให้รันซ้ำผ่าน Apache/XAMPP เพื่อทดสอบ SELECT ... FOR UPDATE');
    info('');
}

if ($failures === []) {
    info('PASS — checkout พร้อมกัน 2 รายการ ตัดสต็อกได้ถูกต้องและสต็อกไม่ติดลบ');
    exit(0);
}

foreach ($failures as $failure) {
    info('FAIL — ' . $failure);
}
exit(1);
