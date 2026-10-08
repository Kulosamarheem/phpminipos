<?php

declare(strict_types=1);

/**
 * ล้างบิลที่พักไว้ซึ่งเกิดจาก e2e test — รันก่อนเทสต์แต่ละตัวเพื่อให้เริ่มจากสถานะว่าง
 *   "C:\xampp\php\php.exe" tests/reset_held_bills.php
 *
 * บิลที่พักไว้อยู่ในฐานข้อมูลร่วมกันทุก session ไม่ได้ผูกกับ browser context ของเทสต์
 * ถ้าไม่ล้าง บิลค้างจากเทสต์ก่อนจะไปโผล่ในรายการของเทสต์ถัดไปและกิน HELD_BILL_MAX
 *
 * ตัดเฉพาะบิลที่มีสินค้า E2E- เท่านั้น บิลจริงของร้านบนเครื่อง dev จะไม่ถูกแตะ
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("สคริปต์นี้รันได้จาก command line เท่านั้น\n");
}

require_once __DIR__ . '/../config/db.php';

// รายการสินค้าถูกลบตามด้วย ON DELETE CASCADE
$deleted = $pdo->exec(
    "DELETE hb FROM held_bills hb
     JOIN held_bill_items hbi ON hbi.held_bill_id = hb.id
     WHERE hbi.barcode LIKE 'E2E-%'"
);

echo "ล้างบิลที่พักไว้ของเทสต์ {$deleted} บิล\n";
