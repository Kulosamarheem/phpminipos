<?php

declare(strict_types=1);

/**
 * ล้างข้อมูลที่ e2e test สร้างไว้
 *   "C:\xampp\php\php.exe" tests/cleanup_test_data.php
 *
 * สินค้าใช้ "ปิดการขาย" (is_active = 0) ไม่ใช่ลบจริง เพราะ order_items.product_id
 * และ stock_movements.product_id เป็น FK แบบ RESTRICT — ลบแถวสินค้าที่เคยขายไม่ได้
 * ส่วนผู้ใช้ทดสอบก็ปิดการใช้งานแทนการลบ ด้วยเหตุผลเดียวกัน (orders.user_id)
 *
 * ยกเว้น held_bill_items.product_id ที่เป็น CASCADE เพราะบิลพักเป็นข้อมูลชั่วคราว
 * บิลพักจึงลบแถวจริงได้ และต้องลบ ไม่งั้นจะค้างกิน HELD_BILL_MAX ในการรันรอบถัดไป
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("สคริปต์นี้รันได้จาก command line เท่านั้น\n");
}

require_once __DIR__ . '/../config/db.php';

$username = getenv('POS_TEST_USER') ?: 'pos_e2e';

$heldBills = $pdo->exec(
    "DELETE hb FROM held_bills hb
     JOIN held_bill_items hbi ON hbi.held_bill_id = hb.id
     WHERE hbi.barcode LIKE 'E2E-%'"
);

$products = $pdo->prepare("UPDATE products SET is_active = 0 WHERE barcode LIKE 'E2E-%' AND is_active = 1");
$products->execute();

$users = $pdo->prepare('UPDATE users SET is_active = 0 WHERE username = ? AND is_active = 1');
$users->execute([$username]);

echo "ลบบิลพักทดสอบ {$heldBills} บิล, ปิดการขายสินค้าทดสอบ {$products->rowCount()} รายการ, ปิดผู้ใช้ทดสอบ {$users->rowCount()} บัญชี\n";
