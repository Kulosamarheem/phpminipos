<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/settings.php';

// ---------------------------------------------------------
// จำนวนเงิน — คำนวณด้วยหน่วยสตางค์ (int) เพื่อเลี่ยงความคลาดเคลื่อนของ float
// ใช้ร่วมกันทุก endpoint ที่รับหรือคำนวณจำนวนเงิน
// ---------------------------------------------------------

// เพดานของ DECIMAL(10,2) ตาม sql/schema.sql = 99999999.99 บาท
const MONEY_MAX_CENTS = 9999999999;

/**
 * แปลงจำนวนเงินที่รับมาเป็นสตางค์
 * รับเฉพาะรูปแบบ "123" หรือ "123.45" (ทศนิยมไม่เกิน 2 ตำแหน่ง, ไม่ติดลบ)
 * คืน null เมื่อรูปแบบไม่ถูกต้องหรือเกินเพดาน — ผู้เรียกต้องตรวจค่า null เสมอ
 */
function money_to_cents(mixed $value): ?int
{
    if (is_array($value) || is_object($value)) {
        return null;
    }

    $amount = trim((string) $value);
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $amount)) {
        return null;
    }

    [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
    $fraction = str_pad($fraction, 2, '0');
    $cents = ((int) $whole * 100) + (int) $fraction;

    return $cents <= MONEY_MAX_CENTS ? $cents : null;
}

/** แปลงสตางค์กลับเป็นสตริงทศนิยมสำหรับเก็บลงคอลัมน์ DECIMAL */
function money_from_cents(int $cents): string
{
    return number_format($cents / 100, 2, '.', '');
}

/** จัดรูปแบบจำนวนเงินสำหรับแสดงผล (มีตัวคั่นหลักพัน) */
function money_display(mixed $value): string
{
    return number_format((float) $value, 2);
}

/**
 * คำนวณ VAT และยอดสุทธิจากยอดรวมสินค้ากับส่วนลด — หน่วยสตางค์ (int) ทั้งหมด
 *
 * นี่คือสูตรชุดเดียวของระบบ: checkout.php ใช้ตอนบันทึกบิลจริง และ cart_totals.php
 * ใช้ตอนแสดงยอดบนหน้าขาย ตัวเลขทั้งสองฝั่งจึงตรงกันเสมอ
 * ห้ามคำนวณด้วย float — ปัดเศษครึ่งขึ้นด้วยจำนวนเต็มล้วนทุกกรณี
 *
 * $vatMode มี 2 ค่า:
 * - 'exclusive' (ค่าเริ่มต้น): ราคาไม่รวม VAT — บวก VAT เพิ่มจากยอดหลังหักส่วนลด
 *   net = taxable + tax
 * - 'inclusive': ราคารวม VAT อยู่แล้ว — แยกภาษีออกจากยอดหลังหักส่วนลดโดยไม่บวกเพิ่ม
 *   net = gross (ยอดที่ลูกค้าจ่ายไม่เปลี่ยนตามโหมด มีแค่สัดส่วน tax/taxable ที่ต่างกัน)
 *
 * @return array{taxable_cents: int, tax_cents: int, net_cents: int}
 */
function money_order_totals(int $totalCents, int $discountCents, string $vatMode = 'exclusive'): array
{
    $vatBasisPoints = (int) round(VAT_RATE * 10000);

    if ($vatMode === 'inclusive') {
        $grossCents = $totalCents - $discountCents;
        $divisor = 10000 + $vatBasisPoints;
        $taxCents = intdiv(($grossCents * $vatBasisPoints) + intdiv($divisor, 2), $divisor);

        return [
            'taxable_cents' => $grossCents - $taxCents,
            'tax_cents' => $taxCents,
            'net_cents' => $grossCents,
        ];
    }

    $taxableCents = $totalCents - $discountCents;
    $taxCents = intdiv(($taxableCents * $vatBasisPoints) + 5000, 10000);

    return [
        'taxable_cents' => $taxableCents,
        'tax_cents' => $taxCents,
        'net_cents' => $taxableCents + $taxCents,
    ];
}

/** อัตรา VAT สำหรับแสดงผล เช่น "7" (ตัดศูนย์ท้ายทศนิยมทิ้ง) */
function money_vat_percent(): string
{
    return rtrim(rtrim(number_format(VAT_RATE * 100, 2), '0'), '.');
}
