<?php

declare(strict_types=1);

/**
 * ตรวจสูตร VAT ของ money_order_totals() — includes/money.php
 *
 * รัน:  "C:\xampp\php\php.exe" tests/vat_breakdown.php
 *
 * ค่าที่คาดหวังคำนวณด้วยมือ ไม่ได้ลอกมาจากโค้ด จึงจับได้ทั้งกรณีปัดเศษผิดทาง
 * และกรณีที่มีใครเผลอเปลี่ยนสูตรใน checkout.php หรือ cart_totals.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("สคริปต์นี้รันได้จาก command line เท่านั้น\n");
}

require_once __DIR__ . '/../includes/money.php';

// [ยอดรวม(สตางค์), ส่วนลด(สตางค์), VAT ที่คาดหวัง, ยอดสุทธิที่คาดหวัง, คำอธิบาย]
$cases = [
    [10000, 0, 700, 10700, 'ยอดกลม 100.00 — เคสพื้นฐาน'],
    [50, 0, 4, 54, '0.50 บาท → VAT 3.5 สตางค์พอดี ต้องปัดขึ้นเป็น 4 (จับ floor ปนกับปัดครึ่งขึ้น)'],
    [5997, 500, 385, 5882, '59.97 ลด 5.00 → VAT 384.79 สตางค์ ต้องได้ 385'],
    [10000, 10000, 0, 0, 'ส่วนลดเท่ายอดรวม → ฐานภาษีเป็นศูนย์'],
    [0, 0, 0, 0, 'ตะกร้าว่าง'],
    [1, 0, 0, 1, '0.01 บาท → VAT 0.07 สตางค์ ต้องปัดลงเป็น 0'],
    [MONEY_MAX_CENTS, 0, 700000000, 10699999999, 'เพดาน DECIMAL(10,2) — ไม่ล้นและไม่เพี้ยนจาก float'],
];

$failures = [];

foreach ($cases as [$totalCents, $discountCents, $expectedTax, $expectedNet, $label]) {
    $actual = money_order_totals($totalCents, $discountCents);

    if ($actual['tax_cents'] === $expectedTax && $actual['net_cents'] === $expectedNet) {
        echo "PASS — {$label}\n";
        continue;
    }

    $message = sprintf(
        'FAIL — %s (ได้ VAT %d / สุทธิ %d แต่ต้องเป็น VAT %d / สุทธิ %d)',
        $label,
        $actual['tax_cents'],
        $actual['net_cents'],
        $expectedTax,
        $expectedNet
    );
    echo $message . "\n";
    $failures[] = $message;
}

// เทียบกับสูตรเดิมที่เคยเขียนไว้ตรง ๆ ใน checkout.php ก่อน refactor
// ครอบทุกยอดตั้งแต่ 0 ถึง 20 บาท เพื่อยืนยันว่าพฤติกรรมไม่เปลี่ยนแม้แต่สตางค์เดียว
for ($totalCents = 0; $totalCents <= 2000; $totalCents++) {
    $vatBasisPoints = (int) round(VAT_RATE * 10000);
    $legacyTax = intdiv(($totalCents * $vatBasisPoints) + 5000, 10000);
    $actual = money_order_totals($totalCents, 0);

    if ($actual['tax_cents'] !== $legacyTax) {
        $message = "FAIL — ไม่ตรงกับสูตรเดิมที่ยอด {$totalCents} สตางค์ (ได้ {$actual['tax_cents']} ต้องเป็น {$legacyTax})";
        echo $message . "\n";
        $failures[] = $message;
        break;
    }
}

if ($failures === []) {
    echo "PASS — ตรงกับสูตรเดิมของ checkout.php ทุกยอดตั้งแต่ 0.00 ถึง 20.00 บาท\n";
}

// ---------------------------------------------------------
// โหมด inclusive — ราคาที่กรอกรวม VAT อยู่แล้ว แยกภาษีออกจากยอดโดยไม่บวกเพิ่ม
// เคสคำนวณด้วยมือแยกจากโค้ด เช่นเดียวกับตารางด้านบน
// ---------------------------------------------------------

// [ยอดรวม(สตางค์), ส่วนลด(สตางค์), VAT ที่คาดหวัง, ฐานภาษีที่คาดหวัง, ยอดสุทธิที่คาดหวัง, คำอธิบาย]
$inclusiveCases = [
    [10700, 0, 700, 10000, 10700, '107.00 รวม VAT — หารลงตัวพอดี'],
    [50, 0, 3, 47, 50, '0.50 รวม VAT → 35000/10700 = 3.27… ปัดลงเป็น 3'],
    [100, 0, 7, 93, 100, '1.00 รวม VAT → 75350/10700 = 7.04… ปัดขึ้นเป็น 7'],
    [10700, 700, 654, 9346, 10000, '107.00 ลด 7.00 → เหลือ 100.00 รวม VAT → VAT 6.54… ปัดเป็น 654 สตางค์'],
    [10000, 10000, 0, 0, 0, 'ส่วนลดเท่ายอดรวม → ทุกค่าเป็นศูนย์'],
    [0, 0, 0, 0, 0, 'ตะกร้าว่าง'],
    [MONEY_MAX_CENTS, 0, 654205607, 9345794392, MONEY_MAX_CENTS, 'เพดาน DECIMAL(10,2) โหมด inclusive — ไม่ล้นและไม่เพี้ยนจาก float'],
];

foreach ($inclusiveCases as [$grossCents, $discountCents, $expectedTax, $expectedTaxable, $expectedNet, $label]) {
    $actual = money_order_totals($grossCents, $discountCents, 'inclusive');

    if ($actual['tax_cents'] === $expectedTax && $actual['taxable_cents'] === $expectedTaxable && $actual['net_cents'] === $expectedNet) {
        echo "PASS (inclusive) — {$label}\n";
        continue;
    }

    $message = sprintf(
        'FAIL (inclusive) — %s (ได้ VAT %d / ฐานภาษี %d / สุทธิ %d แต่ต้องเป็น VAT %d / ฐานภาษี %d / สุทธิ %d)',
        $label,
        $actual['tax_cents'],
        $actual['taxable_cents'],
        $actual['net_cents'],
        $expectedTax,
        $expectedTaxable,
        $expectedNet
    );
    echo $message . "\n";
    $failures[] = $message;
}

// invariant เชิงโครงสร้างของโหมด inclusive: net ต้องเท่ากับยอดหลังหักส่วนลดเสมอ (ไม่บวก VAT เพิ่ม)
// และ taxable + tax ต้องรวมกันเป็น net พอดีทุกยอด — เทียบกับสูตรอ้างอิงที่เขียนแยกต่างหาก
for ($grossCents = 0; $grossCents <= 2000; $grossCents++) {
    $vatBasisPoints = (int) round(VAT_RATE * 10000);
    $divisor = 10000 + $vatBasisPoints;
    $referenceTax = intdiv(($grossCents * $vatBasisPoints) + intdiv($divisor, 2), $divisor);
    $actual = money_order_totals($grossCents, 0, 'inclusive');

    if ($actual['tax_cents'] !== $referenceTax || $actual['net_cents'] !== $grossCents || $actual['taxable_cents'] + $actual['tax_cents'] !== $actual['net_cents']) {
        $message = "FAIL (inclusive) — invariant พังที่ยอด {$grossCents} สตางค์";
        echo $message . "\n";
        $failures[] = $message;
        break;
    }
}

if ($failures === []) {
    echo "PASS (inclusive) — invariant ถูกต้องทุกยอดตั้งแต่ 0.00 ถึง 20.00 บาท\n";
    echo "\nผ่านทั้งหมด\n";
    exit(0);
}

echo "\nไม่ผ่าน " . count($failures) . " รายการ\n";
exit(1);
