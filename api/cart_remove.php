<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/cart.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cart_json_response(['success' => false, 'message' => 'ไม่รองรับ HTTP method นี้'], 405);
}

cart_require_login();
$data = cart_request_data();
cart_verify_csrf($data);

$productId = filter_var($data['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($productId === false) {
    cart_json_response(['success' => false, 'message' => 'รหัสสินค้าไม่ถูกต้อง'], 422);
}

$items = cart_items();
if (!isset($items[$productId])) {
    cart_json_response(['success' => false, 'message' => 'ไม่พบสินค้าในตะกร้า'], 404);
}

unset($items[$productId]);
cart_set_items($items);
cart_send_state('ลบสินค้าออกจากตะกร้าแล้ว');
