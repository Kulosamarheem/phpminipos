<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/held_bill.php';
require_once __DIR__ . '/config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cart_json_response(['success' => false, 'message' => 'ไม่รองรับ HTTP method นี้'], 405);
}

cart_require_login();
$data = cart_request_data();
cart_verify_csrf($data);

$heldBillId = held_bill_id_input($data);

// DELETE เดี่ยวเป็น atomic อยู่แล้ว และ rowCount เป็นตัวกัน race ในตัว จึงไม่ต้องใช้ทรานแซกชัน
// รายการสินค้าถูกลบตามด้วย ON DELETE CASCADE
try {
    $stmt = $pdo->prepare('DELETE FROM held_bills WHERE id = ?');
    $stmt->execute([$heldBillId]);

    if ($stmt->rowCount() !== 1) {
        cart_json_response([
            'success' => false,
            'message' => 'ไม่พบบิลที่พักไว้ อาจถูกเรียกคืนหรือลบไปแล้ว',
        ], 404);
    }

    held_bill_send_state($pdo, 'ลบบิลที่พักไว้แล้ว');
} catch (Throwable $e) {
    cart_json_response(['success' => false, 'message' => 'ไม่สามารถลบบิลที่พักไว้ได้'], 500);
}
