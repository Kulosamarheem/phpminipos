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

$note = held_bill_note_input($data);
$snapshot = held_bill_cart_snapshot();

if ($snapshot === []) {
    cart_json_response(['success' => false, 'message' => 'ตะกร้าสินค้าว่างเปล่า ไม่มีอะไรให้พักบิล'], 422);
}

// ไม่ตรวจและไม่จองสต็อกตอนพักบิล — การพักบิลไม่ใช่การขาย เก็บแค่สิ่งที่อยู่บนจอ
// การตรวจสินค้าเกิดตอนเรียกคืน และเกิดอีกครั้งใต้ FOR UPDATE ตอน checkout
try {
    $pdo->beginTransaction();
    $heldBill = held_bill_create($pdo, $snapshot, $note);
    $pdo->commit();

    cart_set_items([]);

    held_bill_send_state($pdo, "พักบิล {$heldBill['hold_number']} แล้ว", ['held_bill' => $heldBill]);
} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    cart_json_response(['success' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    cart_json_response(['success' => false, 'message' => 'ไม่สามารถพักบิลได้'], 500);
}
