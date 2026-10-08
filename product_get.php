<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/cart.php';
require_once __DIR__ . '/config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    cart_json_response(['success' => false, 'message' => 'ไม่รองรับ HTTP method นี้'], 405);
}

cart_require_login();

try {
    $stmt = $pdo->query(
        'SELECT id, barcode, name, price, stock_qty
         FROM products
         WHERE is_active = 1
         ORDER BY name, id'
    );

    cart_json_response(['success' => true, 'products' => $stmt->fetchAll()]);
} catch (Throwable $e) {
    cart_json_response(['success' => false, 'message' => 'ไม่สามารถโหลดรายการสินค้าได้'], 500);
}
