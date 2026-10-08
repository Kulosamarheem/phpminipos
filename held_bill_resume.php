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

// ถ้าตะกร้าปัจจุบันยังมีของ ระบบจะไม่เดาแทนผู้ใช้:
// - 'reject' (ค่าเริ่มต้น): ตอบ 409 ให้หน้าจอไปถามแคชเชียร์ก่อน
// - 'park'  : พักตะกร้าปัจจุบันแล้วเรียกบิลที่เลือกขึ้นมา ในทรานแซกชันเดียวกัน
//   จึงไม่มีทางที่ตะกร้าปัจจุบันจะหายโดยที่บิลใหม่ไม่ถูกเรียกขึ้นมา
// ไม่รวมสองตะกร้าเข้าด้วยกันเด็ดขาด — จะได้บิลผิดที่สังเกตไม่เห็น
$onConflict = (string) ($data['on_conflict'] ?? 'reject');
if (!in_array($onConflict, ['reject', 'park'], true)) {
    cart_json_response(['success' => false, 'message' => 'คำสั่งไม่ถูกต้อง'], 422);
}

$cartHasItems = !cart_is_empty();

if ($cartHasItems && $onConflict === 'reject') {
    cart_json_response([
        'success' => false,
        'code' => 'cart_not_empty',
        'message' => 'ตะกร้าปัจจุบันมีสินค้าอยู่ กรุณาพักบิลปัจจุบันหรือล้างตะกร้าก่อนเรียกบิลที่พักไว้',
    ], 409);
}

try {
    $pdo->beginTransaction();

    $parkedNumber = '';
    if ($cartHasItems) {
        $snapshot = held_bill_cart_snapshot();
        if ($snapshot !== []) {
            $parked = held_bill_create($pdo, $snapshot, '');
            $parkedNumber = $parked['hold_number'];
        }
    }

    // locking read — แคชเชียร์สองคนที่กดเรียกคืนบิลเดียวกันจะถูกจัดคิว
    // คนที่สองจะหาแถวไม่เจอหลังคนแรก commit
    $lockBill = $pdo->prepare('SELECT id, hold_number FROM held_bills WHERE id = ? FOR UPDATE');
    $lockBill->execute([$heldBillId]);
    $bill = $lockBill->fetch();

    if ($bill === false) {
        throw new RuntimeException('ไม่พบบิลที่พักไว้ อาจถูกเรียกคืนหรือลบไปแล้ว', 404);
    }

    $holdNumber = (string) ($bill['hold_number'] ?? '') ?: held_bill_format_number($heldBillId);

    $items = [];
    $dropped = [];
    $adjusted = [];
    $repriced = [];

    foreach (held_bill_load_items($pdo, $heldBillId) as $line) {
        $productId = (int) $line['product_id'];
        $heldQty = (int) $line['qty'];
        $snapshotName = (string) $line['product_name'];

        // ไม่ล็อกแถวสินค้า — การเรียกคืนไม่ตัดสต็อก จึงเป็นแค่การตรวจเบื้องต้น
        // checkout.php ตรวจซ้ำใต้ FOR UPDATE จริงอยู่แล้ว การไม่ล็อกทำให้ทรานแซกชันสั้น
        $product = cart_find_active_product_by_id($pdo, $productId);

        if ($product === false || (int) $product['stock_qty'] < 1) {
            $dropped[] = $snapshotName;
            continue;
        }

        $qty = min($heldQty, (int) $product['stock_qty']);
        if ($qty < $heldQty) {
            $adjusted[] = $snapshotName;
        }

        // ราคายึดจาก products เสมอ ไม่ใช่ price_at_hold เพราะ checkout.php อ่านราคาจาก DB
        // ถ้าคืนราคาเก่าเข้าตะกร้า หน้าจอจะโชว์เลขหนึ่งแต่เก็บเงินอีกเลขหนึ่ง
        $currentPrice = (float) $product['price'];
        if (money_to_cents($product['price']) !== money_to_cents($line['price_at_hold'])) {
            $repriced[] = $snapshotName;
        }

        $items[$productId] = [
            'product_id' => $productId,
            'barcode' => (string) $product['barcode'],
            'name' => (string) $product['name'],
            'price' => $currentPrice,
            'qty' => $qty,
        ];
    }

    $deleteBill = $pdo->prepare('DELETE FROM held_bills WHERE id = ?');
    $deleteBill->execute([$heldBillId]);

    if ($deleteBill->rowCount() !== 1) {
        throw new RuntimeException('ไม่พบบิลที่พักไว้ อาจถูกเรียกคืนหรือลบไปแล้ว', 404);
    }

    $pdo->commit();

    // เขียนลง session หลัง commit เท่านั้น — การกำหนดค่า array ล้มเหลวไม่ได้
    cart_set_items($items);

    $warnings = [];
    $parts = ["เรียกคืนบิล {$holdNumber} แล้ว"];

    if ($parkedNumber !== '') {
        $parts[] = "(พักบิลปัจจุบันเป็น {$parkedNumber})";
        $warnings[] = ['type' => 'parked', 'count' => 1, 'hold_number' => $parkedNumber];
    }

    if ($items === [] && $dropped !== []) {
        $parts = ["เรียกคืนบิล {$holdNumber} แล้ว แต่สินค้าทั้งหมดถูกปิดการขายหรือสต็อกหมด ตะกร้าจึงว่าง"];
        if ($parkedNumber !== '') {
            $parts[] = "(พักบิลปัจจุบันเป็น {$parkedNumber})";
        }
    } elseif ($dropped !== []) {
        $parts[] = 'ตัดออก ' . count($dropped) . ' รายการ (ปิดการขาย/สต็อกหมด: ' . implode(', ', $dropped) . ')';
    }

    if ($dropped !== []) {
        $warnings[] = ['type' => 'dropped', 'count' => count($dropped), 'names' => $dropped];
    }

    if ($adjusted !== []) {
        $parts[] = 'ปรับจำนวนตามสต็อก ' . count($adjusted) . ' รายการ';
        $warnings[] = ['type' => 'adjusted', 'count' => count($adjusted), 'names' => $adjusted];
    }

    if ($repriced !== []) {
        $parts[] = 'ราคาสินค้าเปลี่ยนแปลง ' . count($repriced) . ' รายการ';
        $warnings[] = ['type' => 'repriced', 'count' => count($repriced), 'names' => $repriced];
    }

    held_bill_send_state($pdo, implode(' · ', $parts), [
        'warnings' => $warnings,
        'held_bill' => ['id' => $heldBillId, 'hold_number' => $holdNumber],
    ]);
} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // 404 = บิลถูกคนอื่นเรียกคืน/ลบไปแล้ว, นอกนั้นเป็นข้อจำกัดทางธุรกิจ เช่น พักบิลเต็ม
    $status = $e->getCode() === 404 ? 404 : 422;
    cart_json_response(['success' => false, 'message' => $e->getMessage()], $status);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    cart_json_response(['success' => false, 'message' => 'ไม่สามารถเรียกคืนบิลที่พักไว้ได้'], 500);
}
