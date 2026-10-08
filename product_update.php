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

$productId = filter_var($data['product_id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if ($productId === false) {
    cart_json_response(['success' => false, 'message' => 'รหัสสินค้าไม่ถูกต้อง'], 422);
}

['barcode' => $barcode, 'name' => $name, 'price' => $price, 'stock_qty' => $stockQty]
    = product_validated_input($data);

try {
    // ตรวจการมีอยู่แยกจาก UPDATE — config/db.php ไม่ได้เปิด MYSQL_ATTR_FOUND_ROWS
    // MySQL จึงคืน rowCount() = 0 เมื่อบันทึกโดยไม่เปลี่ยนค่าใด ๆ ถ้าเอา rowCount มาตัดสิน
    // การกดบันทึกซ้ำจะกลายเป็น 404 ผิด ๆ
    $exists = $pdo->prepare('SELECT id FROM products WHERE id = ? AND is_active = 1');
    $exists->execute([$productId]);

    if ($exists->fetchColumn() === false) {
        cart_json_response(['success' => false, 'message' => 'ไม่พบสินค้าที่ต้องการแก้ไข'], 404);
    }

    $stmt = $pdo->prepare(
        'UPDATE products
         SET barcode = ?, name = ?, price = ?, stock_qty = ?
         WHERE id = ? AND is_active = 1'
    );
    $stmt->execute([$barcode, $name, $price, $stockQty, $productId]);

    cart_json_response([
        'success' => true,
        'message' => 'บันทึกการแก้ไขแล้ว',
        'product_id' => $productId,
    ]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        // บาร์โค้ดเป็น UNIQUE ที่ครอบสินค้าที่ปิดการขายไว้ด้วย ผู้ใช้จึงอาจชนกับแถวที่มองไม่เห็นในตาราง
        cart_json_response([
            'success' => false,
            'message' => 'บาร์โค้ดนี้ถูกใช้กับสินค้าอื่นแล้ว (รวมถึงสินค้าที่ปิดการขายอยู่)',
        ], 409);
    }

    cart_json_response(['success' => false, 'message' => 'ไม่สามารถแก้ไขสินค้าได้'], 500);
} catch (Throwable $e) {
    cart_json_response(['success' => false, 'message' => 'ไม่สามารถแก้ไขสินค้าได้'], 500);
}
