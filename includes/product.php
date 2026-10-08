<?php

declare(strict_types=1);

require_once __DIR__ . '/cart.php';

// ---------------------------------------------------------
// สินค้า — กติกาตรวจข้อมูลชุดเดียวที่ product_add.php และ product_update.php ใช้ร่วมกัน
// เพดานสต็อก PRODUCT_MAX_STOCK ประกาศไว้ที่ config/settings.php
// ---------------------------------------------------------

/**
 * ตรวจและแปลงข้อมูลสินค้าจาก request ให้พร้อมเขียนลงฐานข้อมูล
 * ตอบ JSON 422 แล้วจบ request ทันทีเมื่อข้อมูลไม่ผ่าน — ผู้เรียกไม่ต้องตรวจซ้ำ
 *
 * @return array{barcode: string, name: string, price: string, stock_qty: int}
 */
function product_validated_input(array $data): array
{
    $barcode = trim((string) ($data['barcode'] ?? ''));
    $name = trim((string) ($data['name'] ?? ''));

    // บาร์โค้ดต้องเป็น ASCII ที่พิมพ์ได้เท่านั้น กันอักขระควบคุมจากเครื่องสแกน
    if (!preg_match('/^[\x20-\x7E]{1,50}$/', $barcode)) {
        cart_json_response(['success' => false, 'message' => 'บาร์โค้ดต้องเป็นตัวอักษรหรือตัวเลขภาษาอังกฤษ ความยาวไม่เกิน 50 ตัว'], 422);
    }

    if ($name === '' || mb_strlen($name) > 150) {
        cart_json_response(['success' => false, 'message' => 'ชื่อสินค้าต้องไม่ว่างและยาวไม่เกิน 150 ตัวอักษร'], 422);
    }

    // ใช้กติกาเดียวกับ checkout.php: ตัวเลขไม่ติดลบ ทศนิยมไม่เกิน 2 ตำแหน่ง
    $priceCents = money_to_cents($data['price'] ?? null);
    if ($priceCents === null) {
        cart_json_response(['success' => false, 'message' => 'ราคาสินค้าต้องเป็นจำนวนเงินตั้งแต่ 0.00 บาท และมีทศนิยมไม่เกิน 2 ตำแหน่ง'], 422);
    }

    $stockQty = filter_var($data['stock_qty'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 0, 'max_range' => PRODUCT_MAX_STOCK],
    ]);
    if ($stockQty === false) {
        cart_json_response([
            'success' => false,
            'message' => 'จำนวนสต็อกต้องเป็นจำนวนเต็มระหว่าง 0 ถึง ' . number_format(PRODUCT_MAX_STOCK),
        ], 422);
    }

    return [
        'barcode' => $barcode,
        'name' => $name,
        'price' => money_from_cents($priceCents),
        'stock_qty' => $stockQty,
    ];
}
