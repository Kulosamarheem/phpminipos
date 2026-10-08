<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/cart.php';

// ---------------------------------------------------------
// คำนวณยอดสรุปของตะกร้าปัจจุบัน (ยอดรวม / ส่วนลด / VAT / ยอดสุทธิ)
// สำหรับแสดงบนหน้าขายก่อนกดชำระเงิน — ใช้ money_order_totals() ตัวเดียวกับ checkout.php
// จึงไม่มีทางที่ตัวเลขบนจอกับตัวเลขในบิลจะไม่ตรงกัน
// endpoint นี้ไม่แตะฐานข้อมูลและไม่เปลี่ยนแปลงตะกร้า
// ---------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cart_json_response(['success' => false, 'message' => 'ไม่รองรับ HTTP method นี้'], 405);
}

cart_require_login();
$data = cart_request_data();
cart_verify_csrf($data);

$discountCents = money_to_cents($data['discount_amount'] ?? '0');
if ($discountCents === null) {
    cart_json_response(['success' => false, 'message' => 'ส่วนลดต้องเป็นจำนวนเงินตั้งแต่ 0.00 บาท'], 422);
}

$vatMode = (string) ($data['vat_mode'] ?? 'inclusive');
if (!in_array($vatMode, ['inclusive', 'exclusive'], true)) {
    cart_json_response(['success' => false, 'message' => 'ประเภทภาษีไม่ถูกต้อง'], 422);
}

$cart = cart_state();
$totalCents = (int) $cart['total_cents'];

// ส่วนลดเกินยอดรวม: checkout.php จะปฏิเสธอยู่แล้ว แต่หน้าจอต้องไม่แสดงยอดติดลบ
// จึงหนีบส่วนลดไว้ที่ยอดรวมแล้วเตือนล่วงหน้า ให้แคชเชียร์แก้ก่อนกดชำระเงิน
$warning = '';
if ($discountCents > $totalCents) {
    $warning = 'ส่วนลดต้องไม่เกินยอดรวมสินค้า';
    $discountCents = $totalCents;
}

$totals = money_order_totals($totalCents, $discountCents, $vatMode);

cart_json_response([
    'success' => true,
    'message' => '',
    'warning' => $warning,
    'cart' => $cart,
    'totals' => [
        'total_amount' => money_from_cents($totalCents),
        'discount_amount' => money_from_cents($discountCents),
        'tax_amount' => money_from_cents($totals['tax_cents']),
        'net_amount' => money_from_cents($totals['net_cents']),
        'net_cents' => $totals['net_cents'],
        'vat_percent' => money_vat_percent(),
    ],
]);
