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

$productId = filter_var($data['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($productId === false) {
    cart_json_response(['success' => false, 'message' => 'รหัสสินค้าไม่ถูกต้อง'], 422);
}

try {
    $stmt = $pdo->prepare('UPDATE products SET is_active = 0 WHERE id = ? AND is_active = 1');
    $stmt->execute([$productId]);

    if ($stmt->rowCount() !== 1) {
        cart_json_response(['success' => false, 'message' => 'ไม่พบสินค้าที่ต้องการลบ'], 404);
    }

    $items = cart_items();
    unset($items[$productId]);
    cart_set_items($items);

    cart_json_response(['success' => true, 'message' => 'ปิดการขายสินค้าแล้ว']);
} catch (Throwable $e) {
    cart_json_response(['success' => false, 'message' => 'ไม่สามารถลบสินค้าได้'], 500);
}
