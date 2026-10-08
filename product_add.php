<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/product.php';
require_once __DIR__ . '/config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cart_json_response(['success' => false, 'message' => 'ไม่รองรับ HTTP method นี้'], 405);
}

cart_require_login();
$data = cart_request_data();
cart_verify_csrf($data);

['barcode' => $barcode, 'name' => $name, 'price' => $price, 'stock_qty' => $stockQty]
    = product_validated_input($data);

try {
    $stmt = $pdo->prepare(
        'INSERT INTO products (barcode, name, price, stock_qty, is_active)
         VALUES (?, ?, ?, ?, 1)'
    );
    $stmt->execute([$barcode, $name, $price, $stockQty]);

    cart_json_response([
        'success' => true,
        'message' => 'เพิ่มสินค้าแล้ว',
        'product_id' => (int) $pdo->lastInsertId(),
    ], 201);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        $existing = $pdo->prepare('SELECT id, is_active FROM products WHERE barcode = ?');
        $existing->execute([$barcode]);
        $product = $existing->fetch();

        if ($product !== false && (int) $product['is_active'] === 0) {
            $reactivate = $pdo->prepare(
                'UPDATE products
                 SET name = ?, price = ?, stock_qty = ?, is_active = 1
                 WHERE id = ?'
            );
            $reactivate->execute([$name, $price, $stockQty, $product['id']]);
            cart_json_response([
                'success' => true,
                'message' => 'เปิดการขายสินค้าเดิมแล้ว',
                'product_id' => (int) $product['id'],
            ]);
        }

        cart_json_response(['success' => false, 'message' => 'บาร์โค้ดนี้มีอยู่แล้ว'], 409);
    }

    cart_json_response(['success' => false, 'message' => 'ไม่สามารถเพิ่มสินค้าได้'], 500);
} catch (Throwable $e) {
    cart_json_response(['success' => false, 'message' => 'ไม่สามารถเพิ่มสินค้าได้'], 500);
}
