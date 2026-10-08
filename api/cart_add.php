<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/cart.php';
require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cart_json_response(['success' => false, 'message' => 'ไม่รองรับ HTTP method นี้'], 405);
}

cart_require_login();
$data = cart_request_data();
cart_verify_csrf($data);

$barcode = trim((string) ($data['barcode'] ?? ''));
if ($barcode === '' || strlen($barcode) > 50) {
    cart_json_response(['success' => false, 'message' => 'กรุณาระบุบาร์โค้ดให้ถูกต้อง'], 422);
}

try {
    $product = cart_find_active_product_by_barcode($pdo, $barcode);

    if ($product === false) {
        cart_json_response(['success' => false, 'message' => 'ไม่พบสินค้าหรือสินค้าถูกปิดการขาย'], 404);
    }

    cart_add_product($product);
    cart_send_state('เพิ่มสินค้าแล้ว');
} catch (Throwable $e) {
    cart_json_response(['success' => false, 'message' => 'ไม่สามารถเพิ่มสินค้าได้'], 500);
}
