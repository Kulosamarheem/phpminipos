<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/cart.php';
require_once __DIR__ . '/config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cart_json_response(['success' => false, 'message' => 'ไม่รองรับ HTTP method นี้'], 405);
}

cart_require_login();
$data = cart_request_data();
cart_verify_csrf($data);

// จำนวนต่อรายการมีขอบบน กันค่ามหาศาลที่ทำให้การคำนวณยอดรวมล้น
const CART_MAX_QTY = 9999;

$productId = filter_var($data['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$quantity = filter_var($data['qty'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 0, 'max_range' => CART_MAX_QTY],
]);

if ($productId === false || $quantity === false) {
    cart_json_response([
        'success' => false,
        'message' => 'ข้อมูลสินค้าหรือจำนวนไม่ถูกต้อง (จำนวนต้องอยู่ระหว่าง 0 ถึง ' . number_format(CART_MAX_QTY) . ')',
    ], 422);
}

try {
    $product = cart_find_active_product_by_id($pdo, $productId);

    if ($product === false) {
        cart_json_response(['success' => false, 'message' => 'ไม่พบสินค้าหรือสินค้าถูกปิดการขาย'], 404);
    }

    cart_update_product($product, $quantity);
    cart_send_state($quantity === 0 ? 'ลบสินค้าออกจากตะกร้าแล้ว' : 'อัปเดตจำนวนสินค้าแล้ว');
} catch (Throwable $e) {
    cart_json_response(['success' => false, 'message' => 'ไม่สามารถอัปเดตตะกร้าได้'], 500);
}
